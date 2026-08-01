<?php
/**
 * Report History — read-only view of submitted site visits for the PE app.
 *
 * Pure SELECTs against the same tables the submit pipeline writes; nothing here
 * touches the submission flow. Two modes: a filtered list (?engineer=&q=) and a
 * single report (?id=<public_id>) with its project timeline.
 */
declare(strict_types=1);

require_once __DIR__ . '/src/Db.php';

$cfg = require __DIR__ . '/config/app.php';
date_default_timezone_set((string)($cfg['timezone'] ?? 'Asia/Kolkata'));

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function report_date($value, bool $withTime = true): string
{
    $time = strtotime((string)$value);
    if (!$time) return '—';
    return date($withTime ? 'd M Y, h:i A' : 'd M Y', $time);
}

function report_label(array $row): string
{
    if (($row['client_type'] ?? '') === 'Developer') {
        $parts = array_values(array_filter([
            trim((string)($row['developer'] ?? '')),
            trim((string)($row['building'] ?? '')),
            trim((string)($row['flat_no'] ?? '')),
        ], static fn($part) => $part !== ''));
        return $parts ? implode(' › ', $parts) : 'Developer report';
    }
    return trim((string)($row['project'] ?? '')) ?: 'Unnamed project';
}

/**
 * Mirror of admin/inc/helpers.php projectKey(): General reports group on ORDER
 * ID, not the picked name — the dropdown offers a site name AND a billing name
 * for the same order, so keying on the label splits one job into two timelines.
 */
function report_project_key(array $row): string
{
    if (($row['client_type'] ?? '') === 'Developer') {
        return 'D|' . strtolower(trim(($row['developer'] ?? '') . '|' . ($row['building'] ?? '') . '|' . ($row['flat_no'] ?? '')));
    }
    $orderId = strtolower(trim((string)($row['order_id'] ?? '')));
    if ($orderId !== '') {
        return 'O|' . $orderId;
    }
    return 'G|' . strtolower(trim((string)($row['project'] ?? '')));
}

function payload_for_flat(array $payload, string $flatNo): array
{
    if ($flatNo !== '' && isset($payload['flats']) && is_array($payload['flats'])) {
        foreach ($payload['flats'] as $flat) {
            if (is_array($flat) && strcasecmp(trim((string)($flat['flatNo'] ?? '')), $flatNo) === 0) {
                return $flat;
            }
        }
    }
    return $payload;
}

/**
 * The per-flat reports inside a visit payload. A developer visit can cover many
 * flats in one submission — each entry is a complete report of its own, and the
 * row's own columns describe only the first of them.
 */
function report_flats(array $payload): array
{
    $flats = $payload['flats'] ?? null;
    if (!is_array($flats)) return [];
    $flats = array_values(array_filter($flats, 'is_array'));
    return count($flats) > 1 ? $flats : [];
}

function step_entries(array $payload): array
{
    $entries = [];
    foreach (($payload['stepStatuses'] ?? []) as $entry) {
        if (!is_array($entry)) continue;
        $step = trim((string)($entry['step'] ?? ''));
        $status = strtolower(trim((string)($entry['status'] ?? '')));
        if ($step === '' || $status === '') continue;
        if ($status === 'pending') $status = 'open';
        if ($status === 'not required') $status = 'not-required';
        $entries[] = [
            'step' => $step,
            'status' => $status,
            'hold_reason' => trim((string)($entry['holdReason'] ?? '')),
            'hold_detail' => trim((string)($entry['holdReasonDetail'] ?? '')),
        ];
    }
    return $entries;
}

function step_tone(string $status): string
{
    return match (strtolower($status)) {
        'done' => 'done',
        'hold' => 'hold',
        'open', 'pending' => 'open',
        default => 'muted',
    };
}

function step_text(string $status): string
{
    return match (strtolower($status)) {
        'done' => 'Done',
        'hold' => 'On hold',
        'open', 'pending' => 'Open',
        'not-required' => 'Not required',
        '' => '—',
        default => ucfirst($status),
    };
}

function report_code(array $row): string
{
    $time = strtotime((string)($row['created_at'] ?? '')) ?: time();
    return 'PPR-' . date('Y-m', $time) . '-' . str_pad((string)($row['id'] ?? 0), 5, '0', STR_PAD_LEFT);
}

try {
    $db = (new Db($cfg['db']))->pdo();
} catch (Throwable $error) {
    http_response_code(500);
    echo '<h1>Report history unavailable</h1><p>' . h($error->getMessage()) . '</p>';
    exit;
}

$engineers = $db->query(
    "SELECT DISTINCT engineer FROM submissions
     WHERE engineer IS NOT NULL AND TRIM(engineer) <> ''
     ORDER BY engineer"
)->fetchAll(PDO::FETCH_COLUMN);

$selectedEngineer = trim((string)($_GET['engineer'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));
$publicId = strtolower(trim((string)($_GET['id'] ?? '')));
$detail = null;

if ($publicId !== '' && preg_match('/^[a-f0-9]{32}$/', $publicId)) {
    $statement = $db->prepare('SELECT * FROM submissions WHERE public_id = ? LIMIT 1');
    $statement->execute([$publicId]);
    $detail = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

$where = [];
$params = [];
if ($selectedEngineer !== '') {
    $where[] = 'engineer = :engineer';
    $params['engineer'] = $selectedEngineer;
}
if ($search !== '') {
    $where[] = "(project LIKE :search_project
                OR developer LIKE :search_developer
                OR building LIKE :search_building
                OR flat_no LIKE :search_flat
                OR current_status LIKE :search_status)";
    $searchValue = '%' . $search . '%';
    $params['search_project'] = $searchValue;
    $params['search_developer'] = $searchValue;
    $params['search_building'] = $searchValue;
    $params['search_flat'] = $searchValue;
    $params['search_status'] = $searchValue;
}
$listSql = 'SELECT * FROM submissions' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY created_at DESC, id DESC LIMIT 100';
$listStatement = $db->prepare($listSql);
$listStatement->execute($params);
$reports = $listStatement->fetchAll(PDO::FETCH_ASSOC);

$projectHistory = [];
$stepStates = [];
$stepOrder = [];
$attachments = [];
$pipeline = [];
$detailPayload = [];

$detailFlats = [];
$activeFlat = 0;

if ($detail) {
    $visitPayload = json_decode((string)$detail['payload_json'], true) ?: [];

    // Multi-flat visit: read ONE flat at a time (?flat=…, default the first) so the
    // steps, notes and timeline below all describe the same unit.
    $detailFlats = report_flats($visitPayload);
    if ($detailFlats) {
        $wantFlat = trim((string)($_GET['flat'] ?? ''));
        foreach ($detailFlats as $i => $f) {
            if ($wantFlat !== '' && strcasecmp(trim((string)($f['flatNo'] ?? '')), $wantFlat) === 0) { $activeFlat = $i; break; }
        }
        $detailPayload = $detailFlats[$activeFlat];
        // overlay the flat's own values onto the row the page renders from
        foreach ([
            'floor' => 'floor', 'flat_no' => 'flatNo', 'status' => 'status', 'people' => 'people',
            'activity' => 'activity', 'next_plan' => 'nextPlan', 'current_status' => 'currentStatus',
            'hold_reason' => 'holdReason', 'hold_reason_detail' => 'holdReasonDetail',
        ] as $col => $key) {
            if (array_key_exists($key, $detailPayload)) $detail[$col] = $detailPayload[$key];
        }
    } else {
        $detailPayload = $visitPayload;
    }

    $attachmentStatement = $db->prepare(
        'SELECT * FROM attachments WHERE submission_id = ? ORDER BY id'
    );
    $attachmentStatement->execute([(int)$detail['id']]);
    $attachments = $attachmentStatement->fetchAll(PDO::FETCH_ASSOC);

    $pipelineStatement = $db->prepare(
        'SELECT * FROM process_log WHERE submission_id = ? ORDER BY id'
    );
    $pipelineStatement->execute([(int)$detail['id']]);
    $pipeline = $pipelineStatement->fetchAll(PDO::FETCH_ASSOC);

    // Candidate visits for the same job. Narrow in SQL, then keep only the rows
    // whose projectKey() matches — that is what makes an order's two names
    // (site + billing) resolve to one timeline.
    $detailKey = report_project_key($detail);
    if (($detail['client_type'] ?? '') === 'Developer') {
        $historyStatement = $db->prepare(
            "SELECT * FROM submissions
             WHERE client_type = 'Developer'
               AND COALESCE(developer, '') = ?
               AND COALESCE(building, '') = ?
             ORDER BY created_at, id"
        );
        $historyStatement->execute([
            (string)($detail['developer'] ?? ''),
            (string)($detail['building'] ?? ''),
        ]);
    } elseif (trim((string)($detail['order_id'] ?? '')) !== '') {
        $historyStatement = $db->prepare(
            "SELECT * FROM submissions
             WHERE COALESCE(client_type, '') <> 'Developer'
               AND LOWER(TRIM(COALESCE(order_id, ''))) = ?
             ORDER BY created_at, id"
        );
        $historyStatement->execute([strtolower(trim((string)$detail['order_id']))]);
    } else {
        // Pre-fix rows whose order was never resolved: name is the only handle.
        $historyStatement = $db->prepare(
            "SELECT * FROM submissions
             WHERE COALESCE(client_type, '') <> 'Developer'
               AND COALESCE(order_id, '') = ''
               AND COALESCE(project, '') = ?
             ORDER BY created_at, id"
        );
        $historyStatement->execute([(string)($detail['project'] ?? '')]);
    }

    $candidateHistory = $historyStatement->fetchAll(PDO::FETCH_ASSOC);
    $targetFlat = trim((string)($detail['flat_no'] ?? ''));
    foreach ($candidateHistory as $visit) {
        $payload = json_decode((string)$visit['payload_json'], true) ?: [];
        if (($detail['client_type'] ?? '') === 'Developer') {
            $flatPayload = payload_for_flat($payload, $targetFlat);
            $payloadFlat = trim((string)($flatPayload['flatNo'] ?? $visit['flat_no'] ?? ''));
            if ($targetFlat !== '' && strcasecmp($payloadFlat, $targetFlat) !== 0) continue;
            $payload = $flatPayload;
        } elseif (report_project_key($visit) !== $detailKey) {
            continue;
        }
        $events = step_entries($payload);
        foreach ($events as $event) {
            $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', $event['step']));
            if (!isset($stepStates[$key])) $stepOrder[] = $key;
            $stepStates[$key] = $event + [
                'updated_at' => $visit['created_at'],
                'engineer' => $visit['engineer'],
            ];
        }
        $visit['_payload'] = $payload;
        $visit['_events'] = $events;
        $projectHistory[] = $visit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#d71920">
  <title><?= $detail ? h(report_label($detail)) : 'Report History' ?> · PMS</title>
  <style>
    :root{--red:#d71920;--red-dark:#b51219;--ink:#222b38;--muted:#6f7784;--line:#e6e8ec;--bg:#f5f6f8;--card:#fff;--green:#238b57;--amber:#b56c00;--shadow:0 8px 28px rgba(22,31,45,.08)}
    *{box-sizing:border-box}html,body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}a{color:inherit}
    .top{position:sticky;top:0;z-index:20;background:#fff;border-bottom:1px solid var(--line);padding:12px 16px}.top-inner{max-width:1100px;margin:auto;display:flex;align-items:center;gap:12px}.brand{font-size:18px;font-weight:850;flex:1}.brand span{color:var(--red)}.nav-link{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 14px;border:1px solid var(--line);border-radius:10px;text-decoration:none;font-size:13px;font-weight:750;background:#fff}.nav-link.primary{background:var(--red);border-color:var(--red);color:#fff}
    .wrap{max-width:1100px;margin:auto;padding:18px 16px 60px}.hero{border-radius:18px;padding:22px;background:linear-gradient(135deg,#1d2939,#3b4658);color:#fff;box-shadow:var(--shadow);margin-bottom:16px}.hero h1{margin:0 0 6px;font-size:26px}.hero p{margin:0;color:#d8dde5;font-size:14px;line-height:1.45}
    .filters{display:grid;grid-template-columns:220px 1fr auto;gap:10px;background:#fff;padding:14px;border:1px solid var(--line);border-radius:14px;margin-bottom:16px}.control{width:100%;min-height:44px;border:1px solid #dfe2e7;border-radius:10px;padding:0 12px;background:#fff;font:inherit;color:var(--ink)}.filter-btn{border:0;border-radius:10px;padding:0 18px;background:var(--red);color:#fff;font-weight:800;cursor:pointer}
    .summary{font-size:13px;color:var(--muted);margin:0 2px 10px}.report-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.report-card{display:block;background:#fff;border:1px solid var(--line);border-radius:15px;padding:16px;text-decoration:none;box-shadow:0 2px 8px rgba(22,31,45,.03)}.report-card:active{transform:scale(.99)}.card-top{display:flex;gap:10px;align-items:flex-start}.card-title{font-size:15px;font-weight:850;line-height:1.35;flex:1}.code{font-size:11px;color:var(--muted);margin-top:4px}.badges{display:flex;flex-wrap:wrap;gap:6px;margin:12px 0}.badge{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:800;background:#eef0f3;color:#56606e}.badge.done{background:#e8f6ee;color:var(--green)}.badge.hold{background:#fff0f0;color:#bd2630}.badge.open{background:#fff5df;color:var(--amber)}.meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12px;color:var(--muted)}.meta b{display:block;color:#3c4654;margin-top:2px;font-size:12.5px}.snippet{border-top:1px solid #eff0f2;margin-top:12px;padding-top:11px;font-size:12px;color:#596271;line-height:1.45}
    .empty{background:#fff;border:1px dashed #ccd1d8;border-radius:14px;padding:40px 20px;text-align:center;color:var(--muted)}
    .badge.flats{background:#e9eefb;color:#3160c8}
    .flat-tabs{display:flex;flex-wrap:wrap;gap:7px;align-items:center;margin-top:14px;padding-top:13px;border-top:1px solid var(--line)}
    .flat-tabs-lbl{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);margin-right:2px}
    .flat-tab{display:inline-flex;align-items:center;border:1px solid var(--line);border-radius:999px;padding:7px 14px;font-size:12.5px;font-weight:800;text-decoration:none;color:#3c4654;background:#fff;min-height:36px}
    .flat-tab.done{border-color:#bfe4cd;color:var(--green)}.flat-tab.hold{border-color:#f0bcbc;color:#bd2630}.flat-tab.open{border-color:#f0d59a;color:var(--amber)}
    .flat-tab.on{background:#1d2939;border-color:#1d2939;color:#fff}
    .back{display:inline-flex;gap:7px;align-items:center;text-decoration:none;font-size:13px;font-weight:750;color:var(--red);margin-bottom:12px}.detail-head{background:#fff;border:1px solid var(--line);border-radius:17px;padding:20px;box-shadow:var(--shadow);margin-bottom:14px}.detail-title-row{display:flex;gap:12px;align-items:flex-start}.detail-head h1{font-size:23px;margin:0 0 5px;line-height:1.25;flex:1}.detail-sub{font-size:12px;color:var(--muted)}.detail-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:18px}.fact{background:#f8f9fb;border-radius:11px;padding:11px}.fact span{display:block;color:var(--muted);font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}.fact b{font-size:13px;line-height:1.4;overflow-wrap:anywhere}
    .section{background:#fff;border:1px solid var(--line);border-radius:17px;margin-top:14px;overflow:hidden}.section-head{padding:16px 18px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:10px}.section-head h2{font-size:16px;margin:0;flex:1}.section-head small{color:var(--muted)}.section-body{padding:16px 18px}.step-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.step-card{border:1px solid var(--line);border-left:4px solid #aeb5bf;border-radius:11px;padding:11px 12px}.step-card.done{border-left-color:var(--green);background:#fbfffc}.step-card.hold{border-left-color:#cf3440;background:#fffafa}.step-card.open{border-left-color:#e49a1f;background:#fffdf8}.step-name{font-size:13px;font-weight:850}.step-status{font-size:11px;font-weight:800;margin-top:5px}.step-card.done .step-status{color:var(--green)}.step-card.hold .step-status{color:#bd2630}.step-card.open .step-status{color:var(--amber)}.step-time,.step-note{font-size:11px;color:var(--muted);margin-top:4px;line-height:1.4}
    .timeline{position:relative;margin-left:7px;padding-left:22px}.timeline:before{content:"";position:absolute;left:3px;top:5px;bottom:8px;width:2px;background:#e3e6ea}.visit{position:relative;padding-bottom:20px}.visit:last-child{padding-bottom:0}.visit:before{content:"";position:absolute;width:10px;height:10px;border-radius:50%;background:var(--red);left:-24px;top:5px;box-shadow:0 0 0 4px #fdebed}.visit-date{font-size:12px;font-weight:850}.visit-who{color:var(--muted);font-size:11px;margin:3px 0 9px}.visit-box{background:#f8f9fb;border-radius:11px;padding:12px}.visit-row{font-size:12px;line-height:1.5;margin-bottom:8px}.visit-row:last-child{margin-bottom:0}.visit-row span{display:block;text-transform:uppercase;font-weight:800;font-size:9px;letter-spacing:.04em;color:var(--muted)}.event-list{display:flex;flex-wrap:wrap;gap:6px}.event{border-radius:999px;padding:5px 8px;font-size:10px;font-weight:800;background:#eceff3}.event.done{background:#e8f6ee;color:var(--green)}.event.hold{background:#fff0f0;color:#bd2630}.event.open{background:#fff5df;color:var(--amber)}
    .photos{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.photo{display:block;aspect-ratio:1;border-radius:12px;overflow:hidden;background:#eef0f3}.photo img{width:100%;height:100%;object-fit:cover}.file-link{display:inline-flex;align-items:center;min-height:42px;padding:0 13px;margin:5px 6px 0 0;border:1px solid var(--line);border-radius:10px;text-decoration:none;font-size:12px;font-weight:800}
    .pipeline{display:grid;gap:8px}.pipe{display:grid;grid-template-columns:125px 90px 1fr;gap:8px;align-items:start;border-bottom:1px solid #eff0f2;padding:8px 0;font-size:12px}.pipe:last-child{border-bottom:0}.pipe-name{font-weight:850;text-transform:capitalize}.pipe-time,.pipe-msg{color:var(--muted);line-height:1.4}
    @media(max-width:720px){.top{padding:10px}.brand{font-size:15px}.nav-link{min-height:38px;padding:0 10px;font-size:12px}.wrap{padding:12px 10px 50px}.hero{padding:18px;border-radius:14px}.hero h1{font-size:22px}.filters{grid-template-columns:1fr;padding:11px}.filter-btn{min-height:44px}.report-grid{grid-template-columns:1fr}.detail-grid{grid-template-columns:1fr 1fr}.step-grid{grid-template-columns:1fr}.photos{grid-template-columns:repeat(2,1fr)}.pipeline .pipe{grid-template-columns:1fr 90px}.pipe-msg{grid-column:1/-1}.detail-head{padding:16px}.detail-head h1{font-size:19px}.section-body{padding:14px}.meta{grid-template-columns:1fr 1fr}}
  </style>
</head>
<body>
  <header class="top">
    <div class="top-inner">
      <div class="brand">PMS</div>
      <a class="nav-link" href="./">New report</a>
      <a class="nav-link primary" href="reports.php<?= $selectedEngineer !== '' ? '?engineer=' . rawurlencode($selectedEngineer) : '' ?>">History</a>
    </div>
  </header>

  <main class="wrap">
  <?php if (!$detail): ?>
    <section class="hero">
      <h1>Report History</h1>
      <p>Review every submitted site visit, current project steps, holds, completion times, and upcoming plans.</p>
    </section>

    <form class="filters" method="get" action="reports.php" id="reportFilters">
      <select class="control" name="engineer" id="engineerFilter">
        <option value="">All project engineers</option>
        <?php foreach ($engineers as $engineer): ?>
          <option value="<?= h($engineer) ?>" <?= $selectedEngineer === $engineer ? 'selected' : '' ?>><?= h($engineer) ?></option>
        <?php endforeach; ?>
      </select>
      <input class="control" type="search" name="q" value="<?= h($search) ?>" placeholder="Search project, building, flat or step">
      <button class="filter-btn" type="submit">View reports</button>
    </form>

    <p class="summary"><?= count($reports) ?> report<?= count($reports) === 1 ? '' : 's' ?> shown<?= $selectedEngineer !== '' ? ' for ' . h($selectedEngineer) : '' ?></p>
    <?php if (!$reports): ?>
      <div class="empty">No matching reports were found.</div>
    <?php else: ?>
      <div class="report-grid">
      <?php foreach ($reports as $report):
        $payload = json_decode((string)$report['payload_json'], true) ?: [];
        // A multi-flat visit's own columns describe only its first flat — count the
        // steps across every flat on it so the card isn't quietly under-reporting.
        $cardFlats = report_flats($payload);
        $entries = [];
        foreach ($cardFlats ?: [$payload] as $cf) {
            foreach (step_entries($cf) as $entry) $entries[] = $entry;
        }
        $counts = ['done' => 0, 'hold' => 0, 'open' => 0];
        foreach ($entries as $entry) {
            $tone = step_tone($entry['status']);
            if (isset($counts[$tone])) $counts[$tone]++;
        }
        $query = ['id' => $report['public_id']];
        if ($selectedEngineer !== '') $query['engineer'] = $selectedEngineer;
        $cardLabel = $cardFlats
            ? implode(' › ', array_filter([trim((string)$report['developer']), trim((string)$report['building'])]))
            : report_label($report);
      ?>
        <a class="report-card" href="reports.php?<?= h(http_build_query($query)) ?>">
          <div class="card-top">
            <div class="card-title"><?= h($cardLabel !== '' ? $cardLabel : report_label($report)) ?><div class="code"><?= h(report_code($report)) ?></div></div>
            <span class="badge <?= h(step_tone((string)$report['status'])) ?>"><?= h(step_text((string)$report['status'])) ?></span>
          </div>
          <?php if ($cardFlats): ?>
            <div class="badges">
              <span class="badge flats"><?= count($cardFlats) ?> flats</span>
              <?php foreach ($cardFlats as $cf): ?>
                <span class="badge <?= h(step_tone((string)($cf['status'] ?? ''))) ?>"><?= h(trim((string)($cf['flatNo'] ?? '')) ?: '—') ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="badges">
            <?php if ($counts['done']): ?><span class="badge done"><?= $counts['done'] ?> done</span><?php endif; ?>
            <?php if ($counts['open']): ?><span class="badge open"><?= $counts['open'] ?> open</span><?php endif; ?>
            <?php if ($counts['hold']): ?><span class="badge hold"><?= $counts['hold'] ?> hold</span><?php endif; ?>
            <span class="badge"><?= h(str_replace('_', ' ', (string)$report['overall_status'])) ?></span>
          </div>
          <div class="meta">
            <div>Submitted<b><?= h(report_date($report['created_at'])) ?></b></div>
            <div>Engineer<b><?= h($report['engineer'] ?: '—') ?></b></div>
          </div>
          <?php if (trim((string)$report['next_plan']) !== ''): ?>
            <div class="snippet"><strong>Tomorrow:</strong> <?= h($report['next_plan']) ?></div>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php else:
    $sitePhotos = array_values(array_filter($attachments, static fn($file) => $file['kind'] === 'site_photo'));
    $otherFiles = array_values(array_filter($attachments, static fn($file) => $file['kind'] !== 'site_photo'));
    $backQuery = [];
    if ($selectedEngineer !== '') $backQuery['engineer'] = $selectedEngineer;
  ?>
    <a class="back" href="reports.php<?= $backQuery ? '?' . h(http_build_query($backQuery)) : '' ?>">← Back to report history</a>
    <section class="detail-head">
      <div class="detail-title-row">
        <div>
          <h1><?= h(report_label($detail)) ?></h1>
          <div class="detail-sub"><?= h(report_code($detail)) ?> · <?= h(report_date($detail['created_at'])) ?><?= $detailFlats ? ' · flat ' . ($activeFlat + 1) . ' of ' . count($detailFlats) : '' ?></div>
        </div>
        <span class="badge <?= h(step_tone((string)$detail['status'])) ?>"><?= h(step_text((string)$detail['status'])) ?></span>
      </div>
      <?php if ($detailFlats): ?>
        <div class="flat-tabs">
          <span class="flat-tabs-lbl"><?= count($detailFlats) ?> flats in this visit — pick one:</span>
          <?php foreach ($detailFlats as $fi => $f):
            $fno = trim((string)($f['flatNo'] ?? '')) ?: ('Flat ' . ($fi + 1));
            $q = ['id' => $detail['public_id'], 'flat' => $fno];
            if ($selectedEngineer !== '') $q['engineer'] = $selectedEngineer;
          ?>
            <a class="flat-tab <?= $fi === $activeFlat ? 'on' : '' ?> <?= h(step_tone((string)($f['status'] ?? ''))) ?>"
               href="reports.php?<?= h(http_build_query($q)) ?>"><?= h($fno) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="detail-grid">
        <div class="fact"><span>Engineer</span><b><?= h($detail['engineer'] ?: '—') ?></b></div>
        <div class="fact"><span>Site type</span><b><?= h($detail['site_type'] ?: '—') ?> · <?= h($detail['client_type'] ?: '—') ?></b></div>
        <div class="fact"><span>People on site</span><b><?= h($detail['people'] ?: '—') ?></b></div>
        <div class="fact"><span>Activity</span><b><?= nl2br(h($detail['activity'] ?: '—')) ?></b></div>
        <div class="fact"><span>Tomorrow's plan</span><b><?= nl2br(h($detail['next_plan'] ?: '—')) ?></b></div>
        <div class="fact"><span>Next step date</span><b><?= h(report_date($detailPayload['nextStepStartDate'] ?? '', false)) ?></b></div>
      </div>
    </section>

    <section class="section">
      <div class="section-head"><h2>Current project steps</h2><small><?= count($stepStates) ?> tracked</small></div>
      <div class="section-body">
        <?php if (!$stepStates): ?><div class="empty">No step history recorded yet.</div><?php else: ?>
          <div class="step-grid">
          <?php foreach ($stepOrder as $key):
            $state = $stepStates[$key];
            $tone = step_tone($state['status']);
            $hold = trim($state['hold_reason'] . ($state['hold_detail'] !== '' ? ' — ' . $state['hold_detail'] : ''));
          ?>
            <div class="step-card <?= h($tone) ?>">
              <div class="step-name"><?= h($state['step']) ?></div>
              <div class="step-status"><?= h(step_text($state['status'])) ?></div>
              <div class="step-time"><?= h(report_date($state['updated_at'])) ?> · <?= h($state['engineer'] ?: 'Unknown PE') ?></div>
              <?php if ($hold !== ''): ?><div class="step-note"><?= h($hold) ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="section">
      <div class="section-head"><h2>Complete visit timeline</h2><small><?= count($projectHistory) ?> visit<?= count($projectHistory) === 1 ? '' : 's' ?></small></div>
      <div class="section-body">
        <div class="timeline">
        <?php foreach (array_reverse($projectHistory) as $visit):
          $payload = $visit['_payload'];
          $events = $visit['_events'];
        ?>
          <article class="visit">
            <div class="visit-date"><?= h(report_date($visit['created_at'])) ?></div>
            <div class="visit-who"><?= h($visit['engineer'] ?: 'Unknown PE') ?> · <?= h(report_code($visit)) ?></div>
            <div class="visit-box">
              <?php if ($events): ?>
                <div class="visit-row"><span>Step updates</span><div class="event-list">
                  <?php foreach ($events as $event): ?><b class="event <?= h(step_tone($event['status'])) ?>"><?= h($event['step']) ?> · <?= h(step_text($event['status'])) ?></b><?php endforeach; ?>
                </div></div>
              <?php endif; ?>
              <div class="visit-row"><span>Activity</span><?= nl2br(h($visit['activity'] ?: '—')) ?></div>
              <div class="visit-row"><span>Tomorrow's plan</span><?= nl2br(h($visit['next_plan'] ?: '—')) ?>
                <?php if (!empty($payload['nextStepStartDate'])): ?> · starts <?= h(report_date($payload['nextStepStartDate'], false)) ?><?php endif; ?>
              </div>
              <?php if ($visit['hold_reason'] || $visit['hold_reason_detail']): ?>
                <div class="visit-row"><span>Hold details</span><?= h(trim((string)$visit['hold_reason'] . ' — ' . (string)$visit['hold_reason_detail'], ' —')) ?></div>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="section-head"><h2>Photos and files</h2><small><?= count($attachments) ?> attachment<?= count($attachments) === 1 ? '' : 's' ?></small></div>
      <div class="section-body">
        <?php if ($sitePhotos): ?><div class="photos">
          <?php foreach ($sitePhotos as $photo):
            $url = (string)($photo['url'] ?? '');
            $thumb = $url;
            if (preg_match('#/d/([A-Za-z0-9_-]+)#', $url, $match) || preg_match('#[?&]id=([A-Za-z0-9_-]+)#', $url, $match)) {
                $thumb = 'https://drive.google.com/thumbnail?id=' . $match[1] . '&sz=w500';
            }
          ?>
            <a class="photo" href="<?= h($url) ?>" target="_blank" rel="noopener"><img src="<?= h($thumb) ?>" alt="<?= h($photo['file_name'] ?: 'Site photo') ?>" loading="lazy"></a>
          <?php endforeach; ?>
        </div><?php endif; ?>
        <?php foreach ($otherFiles as $file): ?>
          <a class="file-link" href="<?= h($file['url']) ?>" target="_blank" rel="noopener"><?= h(ucfirst((string)$file['kind'])) ?> · <?= h($file['file_name'] ?: 'Open file') ?></a>
        <?php endforeach; ?>
        <?php if (!$attachments): ?><div class="empty">No uploaded files are available for this report.</div><?php endif; ?>
      </div>
    </section>

    <section class="section">
      <div class="section-head"><h2>Report processing</h2><small><?= h(str_replace('_', ' ', (string)$detail['overall_status'])) ?></small></div>
      <div class="section-body pipeline">
        <?php foreach ($pipeline as $process): ?>
          <div class="pipe">
            <div class="pipe-name"><?= h(str_replace('_', ' ', (string)$process['step'])) ?></div>
            <span class="badge <?= $process['status'] === 'done' ? 'done' : ($process['status'] === 'failed' ? 'hold' : 'open') ?>"><?= h($process['status']) ?></span>
            <div><div class="pipe-time"><?= h(report_date($process['finished_at'] ?: $process['started_at'])) ?></div><div class="pipe-msg"><?= h($process['message'] ?: '') ?></div></div>
          </div>
        <?php endforeach; ?>
        <?php if (!$pipeline): ?><div class="empty">Processing has not started yet.</div><?php endif; ?>
      </div>
    </section>
  <?php endif; ?>
  </main>
  <script>
    (function () {
      var select = document.getElementById('engineerFilter');
      if (select) {
        var saved = localStorage.getItem('pmsHistoryEngineer') || '';
        if (!select.value && saved && Array.from(select.options).some(function (option) { return option.value === saved; })) {
          select.value = saved;
        }
        select.addEventListener('change', function () {
          if (select.value) localStorage.setItem('pmsHistoryEngineer', select.value);
          else localStorage.removeItem('pmsHistoryEngineer');
        });
      }
    })();
  </script>
</body>
</html>
