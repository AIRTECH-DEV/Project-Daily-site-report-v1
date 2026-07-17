<?php
/**
 * Project 360 — one dedicated page per project/unit (not just its latest report):
 * full step timeline LS Material Delivery → Commissioning with planned/actual
 * dates + remarks, every status change (date/author/PE), workforce per visit,
 * amendments/drawings/measurements, client delivery events, and current risks.
 * Read-only over submissions + the synced master tables.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';
Admin::autoSync();

$db = Admin::db();

// resolve the project: ?key=<project_key> or ?id=<submission_id>
$key = trim($_GET['key'] ?? '');
if ($key === '' && isset($_GET['id'])) {
    $st = $db->prepare("SELECT client_type, developer, building, flat_no, project FROM submissions WHERE id=?");
    $st->execute([(int)$_GET['id']]);
    if ($row = $st->fetch()) $key = projectKey($row);
}

$pr = null;
if ($key !== '') {
    $st = $db->prepare("SELECT * FROM projects WHERE project_key=?");
    $st->execute([$key]);
    $pr = $st->fetch();
}

// lifecycle manual actions (authorized) — sync respects lifecycle_locked
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pr) {
    Admin::requireEditor();
    if (Admin::checkCsrf()) {
        $act = $_POST['lifecycle_action'] ?? '';
        if ($act === 'commission') {
            $db->prepare("UPDATE projects SET lifecycle='Commissioned', lifecycle_locked=1, commissioned_at=COALESCE(commissioned_at,NOW()) WHERE id=?")->execute([$pr['id']]);
        } elseif ($act === 'close') {
            $db->prepare("UPDATE projects SET lifecycle='Closed', lifecycle_locked=1, closed_at=NOW(), closed_by=? WHERE id=?")->execute([Admin::user()['user'], $pr['id']]);
        } elseif ($act === 'reopen') {
            $db->prepare("UPDATE projects SET lifecycle_locked=0, closed_at=NULL, closed_by=NULL WHERE id=?")->execute([$pr['id']]);
        }
        Admin::audit('project_lifecycle', 'projects', (int)$pr['id'], (string)$pr['lifecycle'], $act);
        header('Location: ' . Admin::BASE . '/project.php?key=' . urlencode($key));
        exit;
    }
}

require __DIR__ . '/inc/layout.php';
if (!$pr) {
    Layout::head('Project not found', 'projects');
    echo '<div class="alert2 bad"><i class="bi bi-exclamation-octagon"></i> Project not found. It may need a sync.</div>';
    echo '<a class="btn btn-ghost" href="' . Admin::BASE . '/projects.php"><i class="bi bi-arrow-left"></i> Back to projects</a>';
    Layout::foot();
    exit;
}

// all reports for this project (visit trail), oldest first
$all = $db->query("SELECT * FROM submissions ORDER BY id ASC")->fetchAll();
$visits = array_values(array_filter($all, fn($r) => projectKey($r) === $key));
$subIds = array_map(fn($r) => (int)$r['id'], $visits);
$inClause = $subIds ? implode(',', array_map('intval', $subIds)) : '0';

// ---- full-step aggregation across visits ----
$canon = canonicalSteps((string)$pr['site_type']);
$steps = [];
foreach ($canon as $i => $nm) {
    $steps[stepKey($nm)] = ['name'=>$nm, 'order'=>$i, 'status'=>'', 'actualStart'=>'', 'doneOn'=>'', 'planned'=>'', 'author'=>'', 'pe'=>'', 'remarks'=>[]];
}
$changes = [];      // chronological status changes
$prevStatus = [];
$lastHoldReason = [];   // remember why a step was held, to carry into its resolution
$remarksLog = [];   // activity / next-plan / hold remarks per visit

foreach ($visits as $v) {
    $pl = json_decode((string)$v['payload_json'], true) ?: [];
    $date = date('Y-m-d', strtotime((string)$v['created_at']));
    $pe = trim((string)$v['engineer']);
    // public form is anonymous — no submitter identity is sent, so submitter_email
    // lands as literal "unknown". fall back to the visit's PE for a useful author.
    $rawAuthor = trim((string)$v['submitter_email']);
    $author = in_array(strtolower($rawAuthor), ['', 'unknown', 'system'], true)
        ? ($pe !== '' ? $pe : 'unknown')
        : $rawAuthor;

    // planned starts from this visit's next-day plan
    $tSteps = $pl['tomorrowSteps'] ?? [];
    if (is_string($tSteps)) $tSteps = json_decode($tSteps, true) ?: [];
    $nsd = (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($pl['nextStepStartDate'] ?? ''))) ? $pl['nextStepStartDate'] : '';
    if (is_array($tSteps) && $nsd) {
        foreach ($tSteps as $ts) {
            $k = stepKey((string)$ts);
            if (isset($steps[$k]) && $steps[$k]['planned'] === '') $steps[$k]['planned'] = $nsd;
        }
    }

    foreach (($pl['stepStatuses'] ?? []) as $e) {
        if (!is_array($e)) continue;
        $nm = trim((string)($e['step'] ?? ''));
        if ($nm === '') continue;
        $k = stepKey($nm);
        if (!isset($steps[$k])) $steps[$k] = ['name'=>$nm,'order'=>999,'status'=>'','actualStart'=>'','doneOn'=>'','planned'=>'','author'=>'','pe'=>'','remarks'=>[]];
        $stt = ucfirst(strtolower(trim((string)($e['status'] ?? ''))));
        if ($stt === '') continue;

        if ($steps[$k]['actualStart'] === '') $steps[$k]['actualStart'] = $date;
        $steps[$k]['status'] = $stt;
        if ($stt === 'Done' && $steps[$k]['doneOn'] === '') { $steps[$k]['doneOn'] = $date; $steps[$k]['author'] = $author; $steps[$k]['pe'] = $pe; }
        $reason = '';
        if ($stt === 'Hold') {
            $party = holdParty((string)($e['holdReason'] ?? ''));
            $detail = trim((string)($e['holdReasonDetail'] ?? ''));
            $reason = trim(($party ? "Stuck on $party" : 'On hold') . ($detail ? " — $detail" : ''));
            if ($reason !== '') $lastHoldReason[$k] = $reason;
            $steps[$k]['remarks'][] = trim(($party ? "Hold by $party" : 'Hold') . ($detail ? ": $detail" : ''));
        }
        if (($prevStatus[$k] ?? '') !== $stt) {
            // moving OUT of hold keeps the earlier hold reason so the log never loses it
            $resolved = (($prevStatus[$k] ?? '') === 'Hold' && $stt !== 'Hold') ? ($lastHoldReason[$k] ?? '') : '';
            $changes[] = ['date'=>$v['created_at'], 'step'=>$nm, 'from'=>$prevStatus[$k] ?? '', 'to'=>$stt, 'author'=>$author, 'pe'=>$pe, 'reason'=>$reason, 'resolved'=>$resolved];
            $prevStatus[$k] = $stt;
        }
    }

    // remarks log
    $remarksLog[] = [
        'date'=>$v['created_at'], 'id'=>(int)$v['id'], 'pe'=>$pe,
        'activity'=>trim((string)$v['activity']), 'next'=>trim((string)$v['next_plan']),
        'hold'=>trim(trim((string)$v['hold_reason'] . ' — ' . (string)$v['hold_reason_detail'], ' —')),
        'status'=>(string)$v['status'],
    ];
}
ksort_by_order($steps);
$changes = array_reverse($changes);   // newest first

// ---- workforce for the project ----
$vw = $db->query("SELECT * FROM visit_workers WHERE project_key = " . $db->quote($key) . " ORDER BY submission_id ASC")->fetchAll();
$vwByVisit = [];
foreach ($vw as $w) $vwByVisit[$w['submission_id']][] = $w;

// ---- delivery events (email/whatsapp) ----
$deliv = $db->query("SELECT * FROM process_log WHERE submission_id IN ($inClause) AND step IN ('email','whatsapp') ORDER BY id DESC")->fetchAll();

// ---- attachments ----
$atts = $db->query("SELECT * FROM attachments WHERE submission_id IN ($inClause) ORDER BY id DESC")->fetchAll();
$photos = array_values(array_filter($atts, fn($a) => $a['kind'] === 'site_photo'));
$docs   = array_values(array_filter($atts, fn($a) => $a['kind'] !== 'site_photo'));

// ---- amendments / drawings / measurements ----
$flags = [];
foreach ($visits as $v) {
    foreach ([['amendment','Amendment','amendment_why'],['drawing_change','Drawing change',null],['measurement','Measurement',null]] as [$col,$lbl,$whyCol]) {
        if (strcasecmp((string)$v[$col], 'Yes') === 0) {
            $flags[] = ['date'=>$v['created_at'],'id'=>(int)$v['id'],'label'=>$lbl,'why'=>$whyCol ? trim((string)$v[$whyCol]) : ''];
        }
    }
}

// ---- current risks (open alerts) ----
// Exclude technical pipeline failures (cURL/upload errors) — those belong to
// Pipeline Health only, not this operational risk list.
$risks = $db->prepare("SELECT * FROM alerts WHERE project_key=? AND status IN ('open','ack','snoozed') AND rule <> 'pipeline_fail' ORDER BY FIELD(severity,'critical','warning','info'), id DESC");
$risks->execute([$key]);
$risks = $risks->fetchAll();

$pct = $pr['steps_total'] > 0 ? round($pr['steps_done'] * 100 / $pr['steps_total']) : 0;
$sevTone = ['critical'=>'bad','warning'=>'warn','info'=>'info'];
$dotTone = ['Done'=>'ok','Hold'=>'bad','Pending'=>'warn','Inprogress'=>'warn'];

Layout::head('Project · ' . $pr['label'], 'projects', 'project');
?>
<div class="breadcrumb2">
  <a href="<?= Admin::BASE ?>/index.php"><i class="bi bi-house-door"></i></a> ›
  <a href="<?= Admin::BASE ?>/projects.php">Projects</a> › <?= Admin::e($pr['label']) ?>
</div>

<div class="card2">
  <div class="detail-head" style="border-bottom:0">
    <div class="dh-ic"><i class="bi bi-buildings"></i></div>
    <div class="dh-titles">
      <h2><?= Admin::e($pr['label']) ?> <?= Layout::lifecyclePill((string)$pr['lifecycle']) ?><?= $pr['hold_owner'] ? ' ' . '<span class="pill pill-' . partyTone((string)$pr['hold_owner']) . '">stuck on ' . Admin::e($pr['hold_owner']) . '</span>' : '' ?></h2>
      <div class="dh-sub"><?= Admin::e($pr['site_type']) ?> · <?= Admin::e($pr['client_type']) ?> · <?= (int)$pr['report_count'] ?> visit(s) · <span class="mono"><?= Admin::e($pr['order_id']) ?: '—' ?></span></div>
    </div>
    <div class="dh-actions">
      <a class="btn btn-ghost btn-sm" href="<?= Admin::BASE ?>/submissions.php?q=<?= urlencode($pr['project_name'] ?: $pr['developer']) ?>"><i class="bi bi-card-list"></i> Reports</a>
      <?php if (!Admin::isViewer()): ?>
      <div class="kebab-wrap">
        <button class="btn btn-ghost btn-sm" id="lcBtn" type="button"><i class="bi bi-flag"></i> Lifecycle <i class="bi bi-chevron-down"></i></button>
        <div class="kebab-menu" id="lcMenu" style="min-width:236px">
          <?php if (!$pr['lifecycle_locked'] && $pr['lifecycle'] !== 'Commissioned'): ?>
            <form method="POST"><?= Admin::csrfField() ?><input type="hidden" name="lifecycle_action" value="commission">
              <button class="lc-item" type="submit"><i class="bi bi-check-circle text-success"></i> Mark Commissioned</button></form>
          <?php endif; ?>
          <?php if ($pr['lifecycle'] !== 'Closed'): ?>
            <form method="POST" onsubmit="return confirm('Close this project? This is an authorized closure.');"><?= Admin::csrfField() ?><input type="hidden" name="lifecycle_action" value="close">
              <button class="lc-item" type="submit"><i class="bi bi-lock text-danger"></i> Close project</button></form>
          <?php endif; ?>
          <?php if ($pr['lifecycle_locked']): ?>
            <form method="POST"><?= Admin::csrfField() ?><input type="hidden" name="lifecycle_action" value="reopen">
              <button class="lc-item" type="submit"><i class="bi bi-arrow-repeat"></i> Reopen (auto status)</button></form>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="proj-hero">
  <div class="phero g-purple"><div class="ph-v"><?= Admin::e($pr['primary_pe']) ?: '—' ?></div><div class="ph-l">Primary PE</div></div>
  <div class="phero g-blue"><div class="ph-v"><?= Admin::e($pr['current_step']) ?: '—' ?></div><div class="ph-l">Current Step</div></div>
  <div class="phero g-cyan ph-prog">
    <div><div class="ph-v"><?= (int)$pr['steps_done'] ?>/<?= (int)$pr['steps_total'] ?> <small>steps</small></div><div class="ph-l">Progress</div></div>
    <div class="ph-ring" style="--p:<?= $pct ?>"><span><?= $pct ?>%</span></div>
  </div>
  <div class="phero g-green"><div class="ph-v"><?= $pr['next_plan_date'] ? Admin::e(fmtDate($pr['next_plan_date'])) : (Admin::e($pr['next_plan_steps']) ?: '—') ?></div><div class="ph-l">Next Plan</div></div>
  <div class="phero g-orange"><div class="ph-v"><?= $pr['target_end'] ? Admin::e(fmtDate($pr['target_end'])) : '—' ?></div><div class="ph-l">Target End</div></div>
  <div class="phero g-pink"><div class="ph-v"><?= Admin::e($pr['hold_owner']) ?: '—' ?></div><div class="ph-l">Hold Owner</div></div>
</div>

<div class="proj-cols">
  <div class="pcol">
<?php if ($risks): ?>
<div class="card2">
  <div class="card2-head"><i class="bi bi-exclamation-triangle text-danger"></i><h2>Current Risks &amp; Unresolved Actions</h2><span class="sub">(<?= count($risks) ?>)</span></div>
  <div class="card2-body">
    <?php foreach ($risks as $a): ?>
      <div class="up-item">
        <div style="width:80px;flex-shrink:0"><span class="pill pill-<?= $sevTone[$a['severity']] ?? 'muted' ?>"><?= Admin::e(ucfirst($a['severity'])) ?></span></div>
        <div class="up-body"><div class="up-proj"><?= Admin::e($a['title']) ?></div><div class="info-val soft" style="margin-top:3px"><?= Admin::e($a['detail']) ?></div></div>
        <div style="align-self:center;color:#8190a5;font-size:12px"><?= Admin::e(ago($a['created_at'])) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card2">
  <div class="card2-head"><i class="bi bi-bar-chart-steps text-primary"></i><h2>Step Timeline — LS Material Delivery → Commissioning</h2></div>
  <div class="card2-body">
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th style="width:34px"></th><th>Step</th><th>Status</th><th>Planned start</th><th>Actual start</th><th>Completed</th><th>By / PE</th><th>Remarks</th></tr></thead>
        <tbody>
          <?php foreach ($steps as $st):
            $stt = $st['status'] ?: '—';
            $tone = $dotTone[$stt] ?? 'muted';
            $ico = $stt === 'Done' ? 'bi-check-lg' : ($stt === 'Hold' ? 'bi-pause' : ($stt === 'Pending' ? 'bi-hourglass-split' : 'bi-circle'));
          ?>
            <tr>
              <td><span class="si-dot <?= $tone ?>" style="width:22px;height:22px;font-size:10px"><i class="bi <?= $ico ?>"></i></span></td>
              <td style="font-weight:600"><?= Admin::e($st['name']) ?></td>
              <td><?php if ($st['status']): ?><?= Layout::statusBadge($stt) ?><?php else: ?><span class="info-val soft">not started</span><?php endif; ?></td>
              <td><?= Admin::e($st['planned'] ? fmtDate($st['planned']) : '—') ?></td>
              <td><?= Admin::e($st['actualStart'] ? fmtDate($st['actualStart']) : '—') ?></td>
              <td><?= Admin::e($st['doneOn'] ? fmtDate($st['doneOn']) : '—') ?></td>
              <td class="info-val soft" style="font-size:12px"><?= $st['pe'] ? Admin::e($st['pe']) : '—' ?></td>
              <td class="info-val soft" style="font-size:12px;max-width:240px"><?= Admin::e(snip(implode(' | ', array_unique($st['remarks'])), 80)) ?: '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
  </div>
  <div class="pcol">
  <div class="card2">
    <div class="card2-head"><i class="bi bi-clock-history text-primary"></i><h2>Status Changes</h2><span class="sub">(<?= count($changes) ?>)</span></div>
    <div class="card2-body" style="max-height:420px;overflow:auto">
      <?php if (!$changes): ?><div class="t-empty">No step status changes recorded.</div><?php endif; ?>
      <ul class="tl2">
        <?php foreach ($changes as $ch): $tone = $dotTone[$ch['to']] ?? 'muted'; ?>
          <li>
            <span class="tl2-dot <?= $tone ?>"><i class="bi <?= $ch['to']==='Done'?'bi-check-lg':($ch['to']==='Hold'?'bi-pause':'bi-arrow-right') ?>"></i></span>
            <div class="tl2-step"><?= Admin::e($ch['step']) ?> <?= Layout::statusBadge($ch['to']) ?></div>
            <?php if (!empty($ch['reason'])): ?><div class="tl2-reason"><i class="bi bi-pause-circle"></i> <?= Admin::e($ch['reason']) ?></div><?php endif; ?>
            <?php if (!empty($ch['resolved'])): ?><div class="tl2-resolved"><i class="bi bi-check-circle"></i> Resolved — earlier hold: <?= Admin::e($ch['resolved']) ?></div><?php endif; ?>
            <div class="tl2-meta"><?= Admin::e(fmtDateTime($ch['date'])) ?> · PE <?= Admin::e($ch['pe']) ?: '—' ?> · by <?= Admin::e($ch['author']) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <div class="card2">
    <div class="card2-head"><i class="bi bi-send-check text-primary"></i><h2>Client Delivery Events</h2></div>
    <div class="card2-body" style="max-height:420px;overflow:auto">
      <?php if (!$deliv): ?><div class="t-empty">No email/WhatsApp delivery events logged.</div><?php endif; ?>
      <?php foreach ($deliv as $dl): $tone = $dl['status']==='done'?'ok':($dl['status']==='failed'?'bad':'muted'); ?>
        <div class="up-item">
          <div style="width:96px;flex-shrink:0"><span class="pill pill-<?= $tone ?>"><i class="bi bi-<?= $dl['step']==='email'?'envelope':'whatsapp' ?>"></i> <?= Admin::e(ucfirst($dl['step'])) ?></span></div>
          <div class="up-body">
            <div class="info-val" style="font-size:12.5px"><?= Admin::e(snip((string)$dl['target'], 46)) ?: Admin::e(ucfirst($dl['status'])) ?></div>
            <div class="info-val soft" style="font-size:11.5px;margin-top:2px"><?= Admin::e(fmtDateTime($dl['finished_at'] ?: $dl['started_at'])) ?> · <?= Admin::e(snip((string)$dl['message'], 40)) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  </div>
</div>

<div class="card2">
  <div class="card2-head"><i class="bi bi-people text-primary"></i><h2>Visits, Remarks &amp; Workforce</h2><span class="sub"><?= count($visits) ?> visit(s)</span></div>
  <div class="card2-body">
    <?php foreach (array_reverse($remarksLog) as $rl): $vwv = $vwByVisit[$rl['id']] ?? []; ?>
      <div style="border:1px solid var(--line);border-radius:12px;padding:14px 16px;margin-bottom:12px">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px">
          <strong style="font-size:13.5px"><?= Admin::e(fmtDateTime($rl['date'])) ?></strong>
          <?= Layout::statusBadge((string)$rl['status']) ?>
          <span class="info-val soft" style="font-size:12px">PE <?= Admin::e($rl['pe']) ?: '—' ?></span>
          <span class="spacer" style="margin-left:auto"></span>
          <a class="row-link" href="<?= Admin::BASE ?>/submission.php?id=<?= $rl['id'] ?>">Report #<?= $rl['id'] ?> →</a>
        </div>
        <?php if ($vwv): ?>
          <div class="up-steps" style="margin:0 0 8px">
            <?php foreach ($vwv as $w): ?>
              <span class="pill pill-<?= $w['type']==='Contractor'?'warn':'type' ?>"><i class="bi bi-<?= $w['type']==='Contractor'?'hammer':'person' ?>"></i> <?= Admin::e($w['worker_name']) ?><?= $w['contractor_name'] ? ' · ' . Admin::e($w['contractor_name']) : '' ?><?= $w['steps'] ? ' <span style="opacity:.75">(' . Admin::e(snip((string)$w['steps'],30)) . ')</span>' : '' ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if ($rl['activity']): ?><div class="tl2-msg" style="margin-top:6px"><i class="bi bi-clipboard-check"></i> <?= Admin::e($rl['activity']) ?></div><?php endif; ?>
        <?php if ($rl['next']): ?><div class="tl2-msg" style="margin-top:6px"><i class="bi bi-signpost-2"></i> <?= Admin::e($rl['next']) ?></div><?php endif; ?>
        <?php if ($rl['hold']): ?><div class="tl2-msg" style="margin-top:6px;background:var(--bad-bg);color:#9a2b2b"><i class="bi bi-pause-circle"></i> <?= Admin::e($rl['hold']) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid-2">
  <div class="card2">
    <div class="card2-head"><i class="bi bi-pencil-square text-primary"></i><h2>Amendments · Drawings · Measurements</h2></div>
    <div class="card2-body">
      <?php if (!$flags && !$docs): ?><div class="t-empty">None recorded.</div><?php endif; ?>
      <?php foreach ($flags as $f): ?>
        <div class="up-item">
          <div style="width:120px;flex-shrink:0"><span class="pill pill-info"><?= Admin::e($f['label']) ?></span></div>
          <div class="up-body"><div class="info-val soft" style="font-size:12.5px"><?= Admin::e(fmtDate($f['date'])) ?><?= $f['why'] ? ' — ' . Admin::e($f['why']) : '' ?></div></div>
          <a class="row-link" style="align-self:center" href="<?= Admin::BASE ?>/submission.php?id=<?= $f['id'] ?>">#<?= $f['id'] ?></a>
        </div>
      <?php endforeach; ?>
      <?php if ($docs): ?>
        <div style="margin-top:12px;display:flex;flex-wrap:wrap;gap:10px">
          <?php foreach ($docs as $d): if (!$d['url']) continue; ?>
            <a class="att-file" href="<?= Admin::e($d['url']) ?>" target="_blank"><span class="pdf-ic"><i class="bi bi-file-earmark"></i></span> <?= Admin::e(ucfirst($d['kind'])) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card2">
    <div class="card2-head"><i class="bi bi-images text-primary"></i><h2>Photos</h2><span class="sub">(<?= count($photos) ?>)</span></div>
    <div class="card2-body">
      <?php if (!$photos): ?><div class="t-empty" style="padding:20px">No photos.</div><?php else: ?>
        <div class="photo-grid">
          <?php foreach (array_slice($photos, 0, 12) as $p): ?>
            <a href="<?= Admin::e($p['url']) ?>" target="_blank"><img loading="lazy" src="<?= Admin::e(driveThumb((string)$p['url'])) ?>" alt="photo" onerror="this.style.display='none'"></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<a class="btn btn-ghost" href="<?= Admin::BASE ?>/projects.php"><i class="bi bi-arrow-left"></i> Back to projects</a>
<?php
$js = '<script>(function(){var b=document.getElementById("lcBtn"),m=document.getElementById("lcMenu");'
    . 'if(b&&m){b.addEventListener("click",function(e){e.stopPropagation();m.classList.toggle("open");});'
    . 'document.addEventListener("click",function(){m.classList.remove("open");});m.addEventListener("click",function(e){e.stopPropagation();});}})();</script>';
Layout::foot($js);

/** Sort the steps map by their canonical order field. */
function ksort_by_order(array &$steps): void
{
    uasort($steps, fn($a, $b) => $a['order'] <=> $b['order']);
}
