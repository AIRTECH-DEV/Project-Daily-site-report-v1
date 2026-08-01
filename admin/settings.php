<?php
/**
 * Settings — the admin-editable runtime knobs. Primary job: set each developer's
 * client EMAIL and PHONE (replaces the hardcoded config['email']['developer_emails']
 * / config['whatsapp']['developer_phones']). Also toggles email/WhatsApp send
 * modes and the notify delay. Everything is written to config/overrides.json,
 * which both the web app and the CLI worker read on load.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';

$cfg = Admin::cfg();      // already merged with existing overrides
$flash = ''; $flashType = 'ok';
$action = (string)($_POST['action'] ?? '');

// Selected-report deletion. Accepts many rows per run (submission_ids[]), and
// still honours the old single-row field so a cached form keeps working.
$deleteIds = $_POST['submission_ids'] ?? ($_POST['submission_id'] ?? []);
if (!is_array($deleteIds)) { $deleteIds = [$deleteIds]; }
$deleteIds = array_values(array_unique(array_filter(array_map(
    static fn($v) => ctype_digit(trim((string)$v)) ? (int)$v : 0, $deleteIds
))));
const DELETE_MAX_ROWS = 200;   // one screenful — keeps the rebuild inside a request

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_submission') {
    Admin::requireEditor();
    if (!Admin::checkCsrf()) {
        $flash = 'Invalid request token. Refresh and try again. Nothing was deleted.'; $flashType = 'bad';
    } elseif (trim((string)($_POST['confirm'] ?? '')) !== 'DELETE') {
        $flash = 'Type DELETE exactly to confirm. Nothing was deleted.'; $flashType = 'bad';
    } elseif (!$deleteIds) {
        $flash = 'Select at least one valid report row. Nothing was deleted.'; $flashType = 'bad';
    } elseif (count($deleteIds) > DELETE_MAX_ROWS) {
        $flash = 'Select at most ' . DELETE_MAX_ROWS . ' reports at a time. Nothing was deleted.'; $flashType = 'bad';
    } else {
        $db = null;
        $deleteCommitted = false;
        try {
            $db = Admin::db();
            $ids  = $deleteIds;
            $ph   = implode(',', array_fill(0, count($ids), '?'));
            $db->beginTransaction();

            $find = $db->prepare(
                "SELECT id, public_id, site_type, client_type, developer, building, floor, flat_no, project, order_id,
                        engineer, overall_status, payload_json, created_at
                 FROM submissions WHERE id IN ($ph) FOR UPDATE"
            );
            $find->execute($ids);
            $targets = $find->fetchAll(PDO::FETCH_ASSOC);
            if (count($targets) !== count($ids)) {
                throw new RuntimeException('One or more selected reports no longer exist. Nothing was deleted.');
            }
            $busyIds = array_column(array_filter(
                $targets,
                static fn($t) => in_array($t['overall_status'], ['received','queued','processing','awaiting_notify'], true)
            ), 'id');
            if ($busyIds) {
                throw new RuntimeException('Still processing, so nothing was deleted: #' . implode(', #', $busyIds) . '.');
            }

            $affectedWorkers = $db->prepare(
                "SELECT DISTINCT worker_id, contractor_id FROM visit_workers WHERE submission_id IN ($ph)"
            );
            $affectedWorkers->execute($ids);
            $affected = $affectedWorkers->fetchAll(PDO::FETCH_ASSOC);

            // alert_events has no FK; process logs and attachments cascade from submissions.
            $db->prepare(
                "DELETE ae FROM alert_events ae
                 INNER JOIN alerts a ON a.id = ae.alert_id
                 WHERE a.submission_id IN ($ph)"
            )->execute($ids);
            $db->prepare("DELETE FROM alerts WHERE submission_id IN ($ph)")->execute($ids);
            $db->prepare("DELETE FROM visit_workers WHERE submission_id IN ($ph)")->execute($ids);
            $remove = $db->prepare("DELETE FROM submissions WHERE id IN ($ph)");
            $remove->execute($ids);
            if ($remove->rowCount() !== count($ids)) {
                throw new RuntimeException('The selected reports could not be deleted.');
            }
            $db->commit();
            $deleteCommitted = true;
            foreach ($targets as $deleted) {
                Admin::audit(
                    'delete_submission',
                    'submissions',
                    (int)$deleted['id'],
                    json_encode($deleted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'deleted selected report; rebuilding derived tracker data'
                );
            }

            // Remove a project master only if no surviving submission resolves to the
            // same project key; surviving projects are refreshed by the sync below.
            // expandVisits both sides: a deleted multi-flat visit orphans a project
            // row per flat, and a surviving one keeps several alive — comparing only
            // the rows' own columns would leave every flat but the first stranded.
            $deletedProjectKeys = array_unique(array_map('projectKey', expandVisits($targets)));
            $remaining = expandVisits($db->query(
                "SELECT site_type, client_type, developer, building, floor, flat_no, project, order_id, payload_json
                 FROM submissions"
            )->fetchAll(PDO::FETCH_ASSOC));
            $survivingKeys = [];
            foreach ($remaining as $report) {
                $survivingKeys[projectKey($report)] = true;
            }
            $dropProject = $db->prepare("DELETE FROM projects WHERE project_key = ?");
            foreach ($deletedProjectKeys as $deletedProjectKey) {
                if (!isset($survivingKeys[$deletedProjectKey])) {
                    $dropProject->execute([$deletedProjectKey]);
                }
            }

            // Rebuild project/workforce/alert rollups from all reports that remain.
            @unlink(__DIR__ . '/../storage/.admin_sync');
            Admin::runSync();

            // Preserve shared/manual master rows; remove only newly orphaned rows that
            // were connected to the deleted submission.
            $workerIds = array_values(array_unique(array_filter(array_map(
                static fn($r) => (int)($r['worker_id'] ?? 0), $affected
            ))));
            $contractorIds = array_values(array_unique(array_filter(array_map(
                static fn($r) => (int)($r['contractor_id'] ?? 0), $affected
            ))));
            foreach ($workerIds as $workerId) {
                $db->prepare(
                    "DELETE FROM workers WHERE id = ?
                     AND NOT EXISTS (SELECT 1 FROM visit_workers WHERE worker_id = ?)"
                )->execute([$workerId, $workerId]);
            }
            foreach ($contractorIds as $contractorId) {
                $db->prepare(
                    "DELETE FROM contractors WHERE id = ?
                     AND NOT EXISTS (SELECT 1 FROM visit_workers WHERE contractor_id = ?)"
                )->execute([$contractorId, $contractorId]);
            }

            $flash = count($targets) === 1
                ? 'Report #' . (int)$targets[0]['id'] . ' (' . projectLabel($targets[0]) . ') was permanently deleted. '
                  . 'Google Sheets and Drive files were untouched.'
                : count($targets) . ' reports were permanently deleted (#' . implode(', #', array_column($targets, 'id')) . '). '
                  . 'Google Sheets and Drive files were untouched.';
        } catch (Throwable $e) {
            if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
            $flash = $deleteCommitted
                ? 'The reports were deleted, but some dashboard rollups could not refresh: ' . $e->getMessage()
                : 'Could not delete reports: ' . $e->getMessage();
            $flashType = 'bad';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'clear_data') {
    // Retired endpoint: an old/cached "clear everything" form must never wipe the DB.
    // Deleting many rows is fine — it just has to go through the selected-rows flow.
    $flash = 'Clearing the whole database is disabled. Select the report rows to delete.'; $flashType = 'bad';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Admin::requireEditor();
    if (!Admin::checkCsrf()) {
        $flash = 'Invalid request token. Refresh and try again.'; $flashType = 'bad';
    } else {
        // dev[i] = { name, emails[], phones[] } — each developer can have many of both.
        $clean = function (array $vals): array {
            $out = [];
            foreach ($vals as $v) {
                $v = trim((string)$v);
                if ($v !== '' && !in_array($v, $out, true)) $out[] = $v;
            }
            return $out;
        };
        $emailMap = []; $phoneMap = [];
        foreach (($_POST['dev'] ?? []) as $d) {
            if (!is_array($d)) continue;
            $nm = trim((string)($d['name'] ?? ''));
            if ($nm === '') continue;                 // unnamed rows ignored
            $emailMap[$nm] = implode(',', $clean((array)($d['emails'] ?? [])));
            $phoneMap[$nm] = implode(',', $clean((array)($d['phones'] ?? [])));
        }

        $ov = Admin::overrides();
        $ov['developer_emails'] = $emailMap;
        $ov['developer_phones'] = $phoneMap;

        $em = $_POST['email_mode'] ?? '';
        $wa = $_POST['whatsapp_mode'] ?? '';
        if (in_array($em, ['OFF','TEST','LIVE'], true)) $ov['email_mode'] = $em;
        if (in_array($wa, ['OFF','TEST','LIVE'], true)) $ov['whatsapp_mode'] = $wa;
        $ov['notify_delay_seconds'] = max(0, (int)($_POST['notify_delay_seconds'] ?? 0));

        // team & alerts
        $am = strtoupper($_POST['alerts_mode'] ?? '');
        if (in_array($am, ['OFF','TEST','LIVE'], true)) $ov['alerts_mode'] = $am;
        $ov['alerts_email'] = !empty($_POST['alerts_email']) ? 1 : 0;
        $ov['alert_manager_email'] = trim($_POST['alert_manager_email'] ?? '');
        $team = [];
        foreach (($_POST['team'] ?? []) as $t) {
            if (!is_array($t)) continue;
            $nm = trim((string)($t['name'] ?? ''));
            if ($nm === '') continue;
            $team[$nm] = ['email' => trim((string)($t['email'] ?? '')), 'phone' => trim((string)($t['phone'] ?? ''))];
        }
        $ov['team_contacts'] = $team;

        // PE Plan reminder (WhatsApp image, day-before)
        $pp = [];
        $pm = strtoupper($_POST['pe_plan_mode'] ?? '');
        if (in_array($pm, ['OFF','TEST','LIVE'], true)) $pp['mode'] = $pm;
        $pt = trim($_POST['pe_plan_send_time'] ?? '');
        if (preg_match('/^\d{1,2}:\d{2}$/', $pt)) $pp['send_time'] = sprintf('%02d:%02d', ...array_map('intval', explode(':', $pt)));
        $nums = [];
        foreach ((array)($_POST['pe_plan_numbers'] ?? []) as $n) {
            $n = trim((string)$n);
            if ($n !== '' && !in_array($n, $nums, true)) $nums[] = $n;
        }
        $pp['numbers'] = $nums;
        $ptest = trim($_POST['pe_plan_test_to'] ?? '');
        if ($ptest !== '') $pp['test_to'] = $ptest;
        $ov['pe_plan'] = $pp;

        if (Admin::saveOverrides($ov)) {
            Admin::audit('update_settings', 'overrides', null, '', json_encode($ov));
            $flash = 'Settings saved. Applies to the next report the app or worker processes.';
            $cfg = require __DIR__ . '/../config/app.php';   // reload merged view
        } else {
            $flash = 'Could not write config/overrides.json — check folder permissions.'; $flashType = 'bad';
        }
    }
}

// developer names: config sheets + any contacts already set, sorted, + 2 blanks to add new
$names = array_values(array_unique(array_merge(
    array_keys($cfg['developer_building_sheets'] ?? []),
    array_keys($cfg['email']['developer_emails'] ?? []),
    array_keys($cfg['whatsapp']['developer_phones'] ?? [])
)));
sort($names);
// split a stored "a@x, b@y" / "9198.. / 9199.." string into individual values
$split = function (string $s): array {
    $parts = array_filter(array_map('trim', preg_split('/[,;\/]+/', $s) ?: []), fn($v) => $v !== '');
    return $parts ? array_values($parts) : [''];   // always at least one blank input
};
$rows = [];
foreach ($names as $n) {
    $rows[] = [
        'name'   => $n,
        'emails' => $split((string)($cfg['email']['developer_emails'][$n] ?? '')),
        'phones' => $split((string)($cfg['whatsapp']['developer_phones'][$n] ?? '')),
        'fixed'  => isset($cfg['developer_building_sheets'][$n]),
    ];
}

$emailMode = $cfg['email']['mode'] ?? 'TEST';
$waMode    = $cfg['whatsapp']['mode'] ?? 'TEST';
$delay     = (int)($cfg['notify_delay_seconds'] ?? 180);

// team & alerts prefill
$ov = Admin::overrides();
$teamContacts = is_array($ov['team_contacts'] ?? null) ? $ov['team_contacts'] : [];
$alertsMode   = $ov['alerts_mode'] ?? 'OFF';
$alertsEmail  = !empty($ov['alerts_email']);
$managerEmail = (string)($ov['alert_manager_email'] ?? '');
$peNames = array_keys($teamContacts);
foreach (($cfg['engineers'] ?? []) as $e) { if (!empty($e['active'])) $peNames[] = $e['name']; }   // roster from admin/users.php
try { foreach (Admin::db()->query("SELECT DISTINCT primary_pe FROM projects WHERE primary_pe<>''") as $r) $peNames[] = $r['primary_pe']; } catch (Throwable $e) {}
$peNames = array_values(array_unique($peNames));
sort($peNames);
$teamRows = [];
foreach ($peNames as $n) $teamRows[] = ['name' => $n, 'email' => $teamContacts[$n]['email'] ?? '', 'phone' => $teamContacts[$n]['phone'] ?? ''];
$teamRows[] = ['name' => '', 'email' => '', 'phone' => ''];

// PE Plan reminder prefill
$peMode     = $cfg['pe_plan']['mode'] ?? 'OFF';
$peSendTime = $cfg['pe_plan']['send_time'] ?? '20:00';
$peNumbers  = $cfg['pe_plan']['numbers'] ?? [];
if (!$peNumbers) $peNumbers = [''];
$peTestTo   = $cfg['pe_plan']['test_to'] ?? '';
$peTpl      = $cfg['pe_plan']['template_name'] ?? 'pe_plan_reminder';
$peTestDate = date('Y-m-d', strtotime('+1 day'));   // test defaults to tomorrow (the real reminder day)

// Danger Zone — database-backed report search for guarded deletion.
$deleteRows = [];
$deleteSearch = trim((string)($_GET['delete_q'] ?? ''));
$subCount = 0;
try {
    $deleteDb = Admin::db();
    $subCount = (int)$deleteDb->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
    // payload_json comes along so the picker can say how many flats a report covers
    // (deleting one row can drop several tracked flats) and so a flat number that is
    // not the visit's first one is still searchable.
    $deleteSql =
        "SELECT id, public_id, site_type, client_type, developer, building, floor, flat_no, project,
                engineer, overall_status, payload_json, created_at
         FROM submissions";
    if ($deleteSearch !== '') {
        $deleteSql .=
            " WHERE CONCAT_WS(' ', id, public_id, site_type, client_type, developer, building,
                       flat_no, project, engineer, overall_status,
                       DATE_FORMAT(created_at, '%d %b %Y %h:%i %p')) LIKE ?
               OR payload_json LIKE ?";
    }
    $deleteSql .= " ORDER BY id DESC LIMIT 200";
    $deleteStmt = $deleteDb->prepare($deleteSql);
    $deleteStmt->execute($deleteSearch !== ''
        ? ['%' . $deleteSearch . '%', '%"flatNo":"%' . $deleteSearch . '%"%']
        : []);
    $deleteRows = $deleteStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
$deleteShown = count($deleteRows);

// one-shot flash from the "Send test now" endpoint (pe_plan_test.php)
if (!empty($_SESSION['pe_plan_flash'])) {
    $flash = $_SESSION['pe_plan_flash']['msg'];
    $flashType = $_SESSION['pe_plan_flash']['type'];
    unset($_SESSION['pe_plan_flash']);
}

require __DIR__ . '/inc/layout.php';
Layout::head('Settings', 'settings');
?>
<?php if ($flash): ?><div class="alert2 <?= $flashType ?>"><i class="bi bi-<?= $flashType === 'ok' ? 'check-circle' : 'exclamation-octagon' ?>"></i> <?= Admin::e($flash) ?></div><?php endif; ?>
<?php if (Admin::isViewer()): ?><div class="alert2 info"><i class="bi bi-eye"></i> Your account is read-only — changes are disabled.</div><?php endif; ?>

<form method="POST">
  <?= Admin::csrfField() ?>
  <fieldset <?= Admin::isViewer() ? 'disabled' : '' ?> class="ro-fieldset">

  <div class="card2">
    <div class="card2-head"><i class="bi bi-person-vcard text-primary"></i><h2>Developer Contacts</h2>
      <span class="sub">every email &amp; WhatsApp number the daily report is sent to</span></div>
    <div class="card2-body">
      <p style="color:#5b6b82;margin:0 0 16px;font-size:13px">
        Each report for a developer is sent to <b>all</b> of that developer's emails and <b>all</b> of their phone numbers.
        Use <b>+ Add email</b> / <b>+ Add number</b> for more than one. Phones want a country code, e.g. <span class="mono">9198XXXXXXXX</span>.
        Leave a developer with no email/phone to send nothing for them.
      </p>

      <div id="devList">
        <?php foreach ($rows as $i => $r): $slug = preg_replace('/[^a-z0-9]+/i','-', strtolower($r['name'])); ?>
          <div class="dev-block" id="dev-<?= Admin::e($slug) ?>" data-idx="<?= $i ?>">
            <div class="dev-head">
              <i class="bi bi-building" style="color:#2f81f7"></i>
              <?php if ($r['fixed']): ?>
                <input type="hidden" name="dev[<?= $i ?>][name]" value="<?= Admin::e($r['name']) ?>">
                <span class="dev-nm"><?= Admin::e($r['name']) ?></span>
                <span class="tag">from progress sheet</span>
              <?php else: ?>
                <input class="inp dev-name-in" type="text" name="dev[<?= $i ?>][name]" value="<?= Admin::e($r['name']) ?>" placeholder="Developer name">
                <button type="button" class="btn-x" title="Remove developer" onclick="rmDev(this)"><i class="bi bi-trash"></i></button>
              <?php endif; ?>
            </div>
            <div class="dev-cols">
              <div class="dev-col">
                <label><i class="bi bi-envelope"></i> Client email(s)</label>
                <div class="email-list">
                  <?php foreach ($r['emails'] as $em): ?>
                    <div class="multi-row">
                      <input class="inp" type="text" name="dev[<?= $i ?>][emails][]" value="<?= Admin::e($em) ?>" placeholder="client@example.com">
                      <button type="button" class="btn-x" onclick="rmRow(this)"><i class="bi bi-x-lg"></i></button>
                    </div>
                  <?php endforeach; ?>
                </div>
                <button type="button" class="btn-add" onclick="addField(this,'emails')"><i class="bi bi-plus-lg"></i> Add email</button>
              </div>
              <div class="dev-col">
                <label><i class="bi bi-whatsapp"></i> Client phone(s)</label>
                <div class="phone-list">
                  <?php foreach ($r['phones'] as $ph): ?>
                    <div class="multi-row">
                      <input class="inp" type="text" name="dev[<?= $i ?>][phones][]" value="<?= Admin::e($ph) ?>" placeholder="9198XXXXXXXX">
                      <button type="button" class="btn-x" onclick="rmRow(this)"><i class="bi bi-x-lg"></i></button>
                    </div>
                  <?php endforeach; ?>
                </div>
                <button type="button" class="btn-add" onclick="addField(this,'phones')"><i class="bi bi-plus-lg"></i> Add number</button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <button type="button" class="btn btn-ghost btn-sm" onclick="addDev()" style="margin-top:6px"><i class="bi bi-plus-circle"></i> Add developer</button>
    </div>
  </div>

  <div class="grid-3">
    <div class="card2">
      <div class="card2-head"><i class="bi bi-envelope text-primary"></i><h2>Email Sending</h2></div>
      <div class="card2-body">
        <label class="form-lbl">Mode</label>
        <select name="email_mode" class="inp" style="width:100%;margin-top:6px">
          <?php foreach (['OFF'=>'OFF — send nothing','TEST'=>'TEST — send only to test inbox','LIVE'=>'LIVE — send to real client + CC'] as $v=>$t): ?>
            <option value="<?= $v ?>" <?= $emailMode === $v ? 'selected' : '' ?>><?= $t ?></option>
          <?php endforeach; ?>
        </select>
        <div style="margin-top:10px;font-size:12px;color:#8190a5">Test inbox: <span class="mono"><?= Admin::e($cfg['email']['test_to'] ?? '') ?></span></div>
      </div>
    </div>
    <div class="card2">
      <div class="card2-head"><i class="bi bi-whatsapp text-primary"></i><h2>WhatsApp Sending</h2></div>
      <div class="card2-body">
        <label class="form-lbl">Mode</label>
        <select name="whatsapp_mode" class="inp" style="width:100%;margin-top:6px">
          <?php foreach (['OFF'=>'OFF — send nothing','TEST'=>'TEST — send only to test number','LIVE'=>'LIVE — send to real client'] as $v=>$t): ?>
            <option value="<?= $v ?>" <?= $waMode === $v ? 'selected' : '' ?>><?= $t ?></option>
          <?php endforeach; ?>
        </select>
        <div style="margin-top:10px;font-size:12px;color:#8190a5">Test number: <span class="mono"><?= Admin::e($cfg['whatsapp']['test_to'] ?? '') ?></span></div>
      </div>
    </div>
    <div class="card2">
      <div class="card2-head"><i class="bi bi-stopwatch text-primary"></i><h2>Notify Delay</h2></div>
      <div class="card2-body">
        <label class="form-lbl">Seconds after submit before email/WhatsApp</label>
        <input class="inp" type="number" name="notify_delay_seconds" min="0" value="<?= $delay ?>" style="width:100%;margin-top:6px">
        <div style="margin-top:10px;font-size:12px;color:#8190a5">Gives the site engineer time to fix a wrong entry before the client is notified.</div>
      </div>
    </div>
  </div>

  <div class="card2">
    <div class="card2-head"><i class="bi bi-calendar2-check text-primary"></i><h2>PE Plan Reminder <span class="sub">(WhatsApp)</span></h2>
      <span class="sub">image of tomorrow's plan, grouped by engineer, sent the evening before</span></div>
    <div class="card2-body">
      <p style="color:#5b6b82;margin:0 0 16px;font-size:13px">
        One day before, this sends a WhatsApp <b>image</b> of the next day's site plan (which engineer goes to which
        site, and the work step) to every number below. Runs from the scheduled task at the <b>send time</b>.
        Needs the <span class="mono"><?= Admin::e($peTpl) ?></span> template <b>APPROVED</b> by Meta
        (<span class="mono">php scripts/create_pe_plan_template.php</span> once, then
        <span class="mono">php scripts/check_wa_template.php</span>).
      </p>

      <div class="grid-3" style="margin-bottom:14px">
        <div>
          <label class="form-lbl">Mode</label>
          <select name="pe_plan_mode" class="inp" style="width:100%;margin-top:6px">
            <?php foreach (['OFF'=>'OFF — send nothing','TEST'=>'TEST — send only to test number','LIVE'=>'LIVE — send to the numbers below'] as $v=>$t): ?>
              <option value="<?= $v ?>" <?= $peMode === $v ? 'selected' : '' ?>><?= $t ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-lbl">Send time (day before)</label>
          <input class="inp" type="time" name="pe_plan_send_time" value="<?= Admin::e($peSendTime) ?>" style="width:100%;margin-top:6px">
          <div style="margin-top:8px;font-size:12px;color:#8190a5">e.g. 20:00 — evening before the planned work.</div>
        </div>
        <div>
          <label class="form-lbl">Test number</label>
          <input class="inp" type="text" name="pe_plan_test_to" value="<?= Admin::e($peTestTo) ?>" placeholder="9198XXXXXXXX" style="width:100%;margin-top:6px">
          <div style="margin-top:8px;font-size:12px;color:#8190a5">Used by TEST mode + the button below.</div>
        </div>
      </div>

      <label class="form-lbl"><i class="bi bi-whatsapp"></i> Reminder numbers (LIVE)</label>
      <div id="pePlanNums" style="margin-top:8px;max-width:520px">
        <?php foreach ($peNumbers as $n): ?>
          <div class="multi-row">
            <input class="inp" type="text" name="pe_plan_numbers[]" value="<?= Admin::e($n) ?>" placeholder="9198XXXXXXXX">
            <button type="button" class="btn-x" onclick="pePlanRm(this)"><i class="bi bi-x-lg"></i></button>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn-add" onclick="pePlanAdd()" style="margin-top:2px"><i class="bi bi-plus-lg"></i> Add number</button>

      <?php if (!Admin::isViewer()): ?>
      <div style="margin-top:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label style="font-size:13px;color:#5b6b82;display:flex;align-items:center;gap:6px">Preview plan for
          <input class="inp" type="date" name="pe_plan_test_date" value="<?= Admin::e($peTestDate) ?>" style="width:170px">
        </label>
        <button class="btn btn-ghost" type="submit" formaction="<?= Admin::BASE ?>/pe_plan_test.php" formmethod="post">
          <i class="bi bi-send"></i> Send test now
        </button>
      </div>
      <p style="font-size:12px;color:#8190a5;margin:8px 0 0">
        Sends <b>that day's</b> plan image to the test number now. Default = tomorrow (the real day-before reminder).
        Pick a day that has planned work to see a populated card. Save first if you changed the test number.
      </p>
      <?php endif; ?>
    </div>
  </div>

  <div class="card2">
    <div class="card2-head"><i class="bi bi-bell text-primary"></i><h2>Team &amp; Alerts</h2>
      <span class="sub">internal alert recipients — never client contacts</span></div>
    <div class="card2-body">
      <div class="grid-3" style="margin-bottom:8px">
        <div>
          <label class="form-lbl">Alerts mode</label>
          <select name="alerts_mode" class="inp" style="width:100%;margin-top:6px">
            <?php foreach (['OFF'=>'OFF — no sending (inbox only)','TEST'=>'TEST — send only to test inbox','LIVE'=>'LIVE — send to PE + manager'] as $v=>$t): ?>
              <option value="<?= $v ?>" <?= $alertsMode === $v ? 'selected' : '' ?>><?= $t ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-lbl">Email channel</label>
          <label style="display:flex;align-items:center;gap:8px;margin-top:12px;font-size:13.5px;color:#5b6b82">
            <input type="checkbox" name="alerts_email" value="1" <?= $alertsEmail ? 'checked' : '' ?>> Send critical alerts + digests by email
          </label>
        </div>
        <div>
          <label class="form-lbl">Manager / ops email(s)</label>
          <input class="inp" type="text" name="alert_manager_email" value="<?= Admin::e($managerEmail) ?>" placeholder="ops@…, manager@…" style="width:100%;margin-top:6px">
        </div>
      </div>
      <p style="color:#8190a5;font-size:12px;margin:4px 0 14px"><i class="bi bi-info-circle"></i> WhatsApp alerts need an approved template (like the report one) — email is used for now. Digests run from the scheduled task (<span class="mono">admin_sync.php --digest=morning|evening|weekly</span>).</p>

      <div class="section-title" style="margin-top:6px">PE / Staff contacts</div>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th style="width:220px">Name (PE)</th><th>Email</th><th>Phone</th></tr></thead>
          <tbody>
            <?php foreach ($teamRows as $i => $t): ?>
              <tr>
                <td><input class="inp" type="text" name="team[<?= $i ?>][name]" value="<?= Admin::e($t['name']) ?>" placeholder="e.g. Paresh" style="width:100%"></td>
                <td><input class="inp" type="text" name="team[<?= $i ?>][email]" value="<?= Admin::e($t['email']) ?>" placeholder="pe@example.com" style="width:100%"></td>
                <td><input class="inp" type="text" name="team[<?= $i ?>][phone]" value="<?= Admin::e($t['phone']) ?>" placeholder="9198XXXXXXXX" style="width:100%"></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p style="color:#94a3b8;font-size:12px;margin:10px 0 0"><i class="bi bi-info-circle"></i> Names auto-filled from project PEs. Critical alerts route to the owning PE's email + manager email when mode is LIVE.</p>
    </div>
  </div>

  <?php if (!Admin::isViewer()): ?>
  <div style="display:flex;gap:12px;margin-top:4px">
    <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Save Settings</button>
    <a class="btn btn-ghost" href="<?= Admin::BASE ?>/developers.php">View developers</a>
  </div>
  <?php endif; ?>
  </fieldset>
</form>

<style>
  .delete-search{display:flex;align-items:center;gap:9px;margin:0 0 14px}
  .delete-search-field{position:relative;flex:1;max-width:650px}
  .delete-search-field i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#8b9ab0;pointer-events:none}
  .delete-search-field input{width:100%;height:42px;border:1px solid #dce3ec;border-radius:8px;background:#fff;padding:0 13px 0 38px;color:#2c3d55;outline:0;font:inherit;font-size:13px}
  .delete-search-field input:focus{border-color:#76a9f8;box-shadow:0 0 0 3px rgba(47,129,247,.10)}
  .delete-table-wrap{border:1px solid #e5eaf1;border-radius:10px;overflow:auto;max-height:390px}
  .delete-table{width:100%;border-collapse:collapse;min-width:760px;font-size:12.5px}
  .delete-table th{position:sticky;top:0;z-index:1;background:#f7f9fc;color:#697a91;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;padding:10px 12px;border-bottom:1px solid #e5eaf1}
  .delete-table td{padding:11px 12px;border-bottom:1px solid #edf0f5;color:#42526a;vertical-align:middle}
  .delete-table tr:last-child td{border-bottom:0}
  .delete-table tbody tr{cursor:pointer;transition:background .15s ease}
  .delete-table tbody tr:hover{background:#f8fbff}
  .delete-table tbody tr.is-selected{background:#fff3f2;box-shadow:inset 3px 0 #c0392b}
  .delete-table tbody tr.is-busy{cursor:not-allowed;opacity:.58;background:#fafafa}
  .delete-table td b{display:block;color:#22334c;font-size:12.5px}
  .delete-table td small{display:block;color:#8a99ad;margin-top:3px}
  .delete-row-check{width:16px;height:16px;margin:0;accent-color:#c0392b;cursor:pointer}
  .delete-row-check:disabled{cursor:not-allowed}
  .delete-bulkbar{display:flex;align-items:center;gap:10px;margin:0 0 12px;padding:9px 12px;border:1px solid #f0d5d2;border-radius:9px;background:#fdf6f5}
  .delete-count{flex:1;color:#7a4b45;font-size:12.5px;font-weight:600}
  .delete-count.is-over{color:#c0392b}
  .delete-bulk-btn{background:#c0392b!important;color:#fff!important;border-color:#c0392b!important}
  .delete-bulk-btn:disabled{opacity:.45;cursor:not-allowed}
  @media(max-width:700px){.delete-bulkbar{flex-wrap:wrap}.delete-count{flex-basis:100%}}
  .delete-status{display:inline-flex;border-radius:12px;padding:3px 8px;background:#e8f7ee;color:#257047;font-size:10.5px;font-weight:700;text-transform:capitalize}
  .delete-status.busy{background:#fff3d8;color:#8a6400}
  .delete-wait{color:#9aa6b6;font-size:11px}
  .delete-row-action{padding:7px 10px!important;background:#c0392b!important;color:#fff!important;border-color:#c0392b!important}
  .delete-row-action[hidden]{display:none}
  .delete-next-btn,.delete-final-btn{background:#c0392b!important;color:#fff!important;border-color:#c0392b!important}
  .delete-next-btn:disabled{opacity:.45;cursor:not-allowed}
  .delete-empty{padding:26px;border:1px dashed #d9e0e9;border-radius:10px;color:#8190a5;text-align:center}
  .delete-empty i{margin-right:6px}
  .delete-modal[hidden]{display:none}
  .delete-modal{position:fixed;inset:0;z-index:2000;display:flex;align-items:center;justify-content:center;padding:20px}
  .delete-modal-backdrop{position:absolute;inset:0;background:rgba(12,25,45,.58);backdrop-filter:blur(2px)}
  .delete-modal-card{position:relative;z-index:1;width:min(460px,100%);background:#fff;border-radius:14px;box-shadow:0 24px 70px rgba(6,20,42,.28);padding:28px}
  .delete-modal-x{position:absolute;right:14px;top:14px;border:0;background:transparent;color:#8190a5;cursor:pointer;font-size:16px}
  .delete-modal-icon{width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;background:#fff0ed;color:#c0392b;font-size:22px}
  .delete-modal-icon.final{background:#c0392b;color:#fff}
  .delete-modal-card h3{text-align:center;margin:0 0 9px;color:#1b2b42;font-size:18px}
  .delete-modal-card p{text-align:center;margin:0 0 18px;color:#617188;font-size:13px;line-height:1.55}
  .delete-confirm-input{width:100%;text-align:center;font-weight:800;letter-spacing:.18em;text-transform:uppercase}
  .delete-modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:20px}
  @media(max-width:700px){.delete-search{align-items:stretch;flex-wrap:wrap}.delete-search-field{flex-basis:100%;max-width:none}.delete-modal-actions{flex-direction:column-reverse}.delete-modal-actions .btn{width:100%}}
</style>

<?php if (!Admin::isViewer()): ?>
<div class="card2" id="delete-reports" style="margin-top:22px;border:1px solid #f0b4b4">
  <div class="card2-head"><i class="bi bi-exclamation-triangle" style="color:#c0392b"></i><h2>Danger Zone — Delete Reports</h2>
    <span class="sub">select one or many tracker reports and delete them permanently</span></div>
  <div class="card2-body">
    <p style="color:#5b6b82;font-size:13px;margin:0 0 12px">
      Tick every report row you want to remove. Their tracker process logs, attachment records,
      visit records, and affected dashboard rollups will be updated. <b>Google Sheets and Drive files are
      NOT touched.</b> Admin logins, audit history, and Settings are kept. <b>This cannot be undone.</b>
    </p>
    <p style="color:#8190a5;font-size:12.5px;margin:0 0 14px">
      Currently in DB: <b><?= $subCount ?></b> report<?= $subCount === 1 ? '' : 's' ?>.
      Showing <b><?= $deleteShown ?></b><?= $deleteSearch !== '' ? ' matching' : ' most recent' ?> report<?= $deleteShown === 1 ? '' : 's' ?>.
      Reports still processing cannot be selected. Up to <b><?= DELETE_MAX_ROWS ?></b> per delete.
    </p>

    <form class="delete-search" method="GET" action="<?= Admin::BASE ?>/settings.php#delete-reports">
      <div class="delete-search-field">
        <i class="bi bi-search"></i>
        <input type="search" name="delete_q" value="<?= Admin::e($deleteSearch) ?>"
          placeholder="Search report ID, project, engineer, status, site type, or date…" autocomplete="off">
      </div>
      <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Search</button>
      <?php if ($deleteSearch !== ''): ?>
        <a class="btn btn-ghost" href="<?= Admin::BASE ?>/settings.php#delete-reports"><i class="bi bi-x-lg"></i> Clear</a>
      <?php endif; ?>
    </form>

    <?php if (!$deleteRows): ?>
      <div class="delete-empty"><i class="bi bi-inbox"></i>
        <?= $deleteSearch !== '' ? 'No reports match “' . Admin::e($deleteSearch) . '”.' : 'No tracker reports are available to delete.' ?>
      </div>
    <?php else: ?>
      <div class="delete-bulkbar">
        <span class="delete-count" id="deleteCount">No reports selected</span>
        <button class="btn btn-ghost delete-clear-btn" type="button" id="deleteClearBtn" hidden>
          <i class="bi bi-x-lg"></i> Clear selection</button>
        <button class="btn delete-bulk-btn" type="button" id="deleteBulkBtn" disabled>
          <i class="bi bi-trash3"></i> Delete selected</button>
      </div>
      <div class="delete-table-wrap">
        <table class="delete-table">
          <thead><tr>
            <th style="width:42px"><input class="delete-row-check" type="checkbox" id="deleteAllCheck"
              aria-label="Select every listed report"></th>
            <th>Report</th><th>Engineer</th><th>Status</th><th>Submitted</th><th style="width:105px">Action</th>
          </tr></thead>
          <tbody>
          <?php foreach ($deleteRows as $report):
            $busy = in_array($report['overall_status'], ['received','queued','processing','awaiting_notify'], true);
            $nFlats = visitFlatCount($report);
            // Say it plainly: one multi-flat report carries several tracked flats with it.
            $reportLabel = projectLabel($report) . ($nFlats > 1 ? ' (+' . ($nFlats - 1) . ' more flat(s))' : '');
          ?>
            <tr class="<?= $busy ? 'is-busy' : '' ?>">
              <td>
                <input class="delete-row-check" type="checkbox" name="delete_rows[]"
                  value="<?= (int)$report['id'] ?>"
                  data-label="<?= Admin::e($reportLabel) ?>"
                  data-engineer="<?= Admin::e($report['engineer'] ?: 'Unassigned') ?>"
                  <?= $busy ? 'disabled' : '' ?>
                  aria-label="Select report #<?= (int)$report['id'] ?>">
              </td>
              <td>
                <b>#<?= (int)$report['id'] ?> · <?= Admin::e($reportLabel) ?></b>
                <small><?= Admin::e($report['site_type']) ?> · <?= Admin::e($report['client_type'] ?: 'General') ?></small>
              </td>
              <td><?= Admin::e($report['engineer'] ?: '—') ?></td>
              <td><span class="delete-status <?= $busy ? 'busy' : '' ?>"><?= Admin::e(str_replace('_', ' ', $report['overall_status'])) ?></span></td>
              <td><?= Admin::e(date('d M Y, h:i a', strtotime($report['created_at']))) ?></td>
              <td>
                <?php if ($busy): ?>
                  <span class="delete-wait">Wait</span>
                <?php else: ?>
                  <button class="btn delete-row-action" type="button" hidden><i class="bi bi-trash3"></i> Delete</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($deleteRows): ?>
<div class="delete-modal" id="deleteModal" hidden>
  <div class="delete-modal-backdrop" data-delete-close></div>
  <div class="delete-modal-card" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
    <button class="delete-modal-x" type="button" data-delete-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
    <form method="POST" id="deleteReportForm">
      <?= Admin::csrfField() ?>
      <input type="hidden" name="action" value="delete_submission">
      <!-- one submission_ids[] hidden input per ticked row, filled when the dialog opens -->
      <div id="deleteIdInputs"></div>

      <div id="deleteConfirmStep">
        <div class="delete-modal-icon"><i class="bi bi-shield-exclamation"></i></div>
        <h3 id="deleteModalTitle">Confirm report deletion</h3>
        <p>You selected <b id="deleteModalReport"></b>. Type <span class="mono">DELETE</span> exactly to continue.</p>
        <input class="inp delete-confirm-input" type="text" name="confirm" id="deleteConfirmInput"
          autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="DELETE">
        <div class="delete-modal-actions">
          <button class="btn btn-ghost" type="button" data-delete-close>Cancel</button>
          <button class="btn delete-next-btn" type="button" id="deleteNextBtn" disabled>Next <i class="bi bi-arrow-right"></i></button>
        </div>
      </div>

      <div id="deleteFinalStep" hidden>
        <div class="delete-modal-icon final"><i class="bi bi-trash3"></i></div>
        <h3 id="deleteFinalTitle">Delete this report permanently?</h3>
        <p><b id="deleteFinalReport"></b> and the tracker-related records will be removed. This action cannot be undone.</p>
        <div class="delete-modal-actions">
          <button class="btn btn-ghost" type="button" id="deleteBackBtn"><i class="bi bi-arrow-left"></i> Back</button>
          <button class="btn delete-final-btn" type="submit"><i class="bi bi-trash3"></i> <span id="deleteFinalBtnText">Delete report</span></button>
        </div>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="card2" style="margin-top:22px">
  <div class="card2-head"><i class="bi bi-shield-lock text-primary"></i><h2>What stays in code</h2></div>
  <div class="card2-body">
    <p style="color:#5b6b82;font-size:13px;margin:0">
      Secrets (SMTP password, WhatsApp API token) and Google Sheet IDs remain in <span class="mono">config/app.php</span> for safety and
      are not editable here. This page only controls the contacts and send behaviour above, saved to <span class="mono">config/overrides.json</span>.
    </p>
  </div>
</div>
<script>
(function () {
  // Guarded deletion of one OR many reports: tick rows -> type DELETE -> Next -> Delete.
  const deleteChecks = Array.from(document.querySelectorAll('.delete-row-check')).filter(
    function (box) { return box.name === 'delete_rows[]'; });
  const deleteAll = document.getElementById('deleteAllCheck');
  const deleteModal = document.getElementById('deleteModal');
  const deleteIdInputs = document.getElementById('deleteIdInputs');
  const deleteInput = document.getElementById('deleteConfirmInput');
  const deleteNext = document.getElementById('deleteNextBtn');
  const deleteConfirmStep = document.getElementById('deleteConfirmStep');
  const deleteFinalStep = document.getElementById('deleteFinalStep');
  const deleteBulkBtn = document.getElementById('deleteBulkBtn');
  const deleteClearBtn = document.getElementById('deleteClearBtn');
  const deleteCount = document.getElementById('deleteCount');
  const DELETE_MAX = <?= DELETE_MAX_ROWS ?>;
  // The rows the modal will act on. The per-row Delete button narrows this to one
  // row for that click only, without disturbing the ticks.
  let deleteTargets = [];

  function selectableChecks() {
    return deleteChecks.filter(function (box) { return !box.disabled; });
  }
  function checkedBoxes() {
    return selectableChecks().filter(function (box) { return box.checked; });
  }
  function rowSummary(box) {
    return '#' + box.value + ' · ' + box.dataset.label + ' · ' + box.dataset.engineer;
  }
  function syncDeleteUi() {
    const picked = checkedBoxes();
    const all = selectableChecks();
    deleteChecks.forEach(function (box) {
      const row = box.closest('tr');
      if (!row) return;
      row.classList.toggle('is-selected', box.checked && !box.disabled);
      const action = row.querySelector('.delete-row-action');
      if (action) action.hidden = !box.checked || box.disabled;
    });
    if (deleteAll) {
      deleteAll.checked = all.length > 0 && picked.length === all.length;
      deleteAll.indeterminate = picked.length > 0 && picked.length < all.length;
    }
    const over = picked.length > DELETE_MAX;
    if (deleteCount) {
      deleteCount.textContent = picked.length === 0
        ? 'No reports selected'
        : picked.length + ' report' + (picked.length === 1 ? '' : 's') + ' selected'
          + (over ? ' — max ' + DELETE_MAX + ' per delete' : '');
      deleteCount.classList.toggle('is-over', over);
    }
    if (deleteBulkBtn) {
      deleteBulkBtn.disabled = picked.length === 0 || over;
      deleteBulkBtn.innerHTML = '<i class="bi bi-trash3"></i> Delete selected'
        + (picked.length ? ' (' + picked.length + ')' : '');
    }
    if (deleteClearBtn) deleteClearBtn.hidden = picked.length === 0;
  }

  deleteChecks.forEach(function (box) {
    box.addEventListener('change', syncDeleteUi);
    const row = box.closest('tr');
    if (row) row.addEventListener('click', function (event) {
      if (event.target.closest('a,button,input') || box.disabled) return;
      box.checked = !box.checked;
      syncDeleteUi();
    });
  });
  if (deleteAll) deleteAll.addEventListener('change', function () {
    const on = deleteAll.checked;
    selectableChecks().slice(0, on ? DELETE_MAX : undefined).forEach(function (box) { box.checked = on; });
    if (on) selectableChecks().slice(DELETE_MAX).forEach(function (box) { box.checked = false; });
    syncDeleteUi();
  });
  if (deleteClearBtn) deleteClearBtn.addEventListener('click', function () {
    selectableChecks().forEach(function (box) { box.checked = false; });
    syncDeleteUi();
  });

  function resetDeleteDialog() {
    if (deleteConfirmStep) deleteConfirmStep.hidden = false;
    if (deleteFinalStep) deleteFinalStep.hidden = true;
    if (deleteInput) deleteInput.value = '';
    if (deleteNext) deleteNext.disabled = true;
  }
  function closeDeleteDialog() {
    if (deleteModal) deleteModal.hidden = true;
    document.body.style.overflow = '';
    resetDeleteDialog();
  }

  function openDeleteDialog(boxes) {
    deleteTargets = (boxes || []).filter(function (box) { return box && !box.disabled; });
    if (!deleteTargets.length || !deleteModal || deleteTargets.length > DELETE_MAX) return;

    const n = deleteTargets.length;
    const summary = n === 1
      ? rowSummary(deleteTargets[0])
      : n + ' reports · ' + deleteTargets.slice(0, 4).map(function (box) { return '#' + box.value; }).join(', ')
        + (n > 4 ? ' and ' + (n - 4) + ' more' : '');
    // one hidden field per target — what the server actually deletes
    deleteIdInputs.innerHTML = '';
    deleteTargets.forEach(function (box) {
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'submission_ids[]';
      hidden.value = box.value;
      deleteIdInputs.appendChild(hidden);
    });
    document.getElementById('deleteModalReport').textContent = summary;
    document.getElementById('deleteFinalReport').textContent = summary;
    document.getElementById('deleteFinalTitle').textContent =
      n === 1 ? 'Delete this report permanently?' : 'Delete these ' + n + ' reports permanently?';
    document.getElementById('deleteFinalBtnText').textContent =
      n === 1 ? 'Delete report' : 'Delete ' + n + ' reports';
    resetDeleteDialog();
    deleteModal.hidden = false;
    document.body.style.overflow = 'hidden';
    setTimeout(function () { deleteInput.focus(); }, 0);
  }
  document.querySelectorAll('.delete-row-action').forEach(function (button) {
    button.addEventListener('click', function (event) {
      event.stopPropagation();
      openDeleteDialog([button.closest('tr').querySelector('.delete-row-check')]);
    });
  });
  if (deleteBulkBtn) deleteBulkBtn.addEventListener('click', function () {
    openDeleteDialog(checkedBoxes());
  });

  document.querySelectorAll('[data-delete-close]').forEach(function (button) {
    button.addEventListener('click', closeDeleteDialog);
  });
  if (deleteInput) deleteInput.addEventListener('input', function () {
    deleteNext.disabled = deleteInput.value === 'DELETE' ? false : true;
  });
  if (deleteNext) deleteNext.addEventListener('click', function () {
    if (!deleteInput || deleteInput.value !== 'DELETE') return;
    deleteConfirmStep.hidden = true;
    deleteFinalStep.hidden = false;
    document.querySelector('.delete-final-btn').focus();
  });
  const deleteBack = document.getElementById('deleteBackBtn');
  if (deleteBack) deleteBack.addEventListener('click', function () {
    deleteFinalStep.hidden = true;
    deleteConfirmStep.hidden = false;
    deleteInput.focus();
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && deleteModal && !deleteModal.hidden) closeDeleteDialog();
  });
  syncDeleteUi();

  let nextIdx = <?= count($rows) ?>;   // fresh group index for newly-added developers

  window.rmRow = function (btn) {
    const list = btn.closest('.email-list, .phone-list');
    btn.closest('.multi-row').remove();
    if (list && !list.querySelector('.multi-row')) addFieldTo(list); // keep one blank input
  };
  window.rmDev = function (btn) { btn.closest('.dev-block').remove(); };

  function addFieldTo(list) {
    const block = list.closest('.dev-block');
    const idx = block.dataset.idx;
    const isEmail = list.classList.contains('email-list');
    const key = isEmail ? 'emails' : 'phones';
    const ph  = isEmail ? 'client@example.com' : '9198XXXXXXXX';
    const row = document.createElement('div');
    row.className = 'multi-row';
    row.innerHTML =
      '<input class="inp" type="text" name="dev[' + idx + '][' + key + '][]" placeholder="' + ph + '">' +
      '<button type="button" class="btn-x" onclick="rmRow(this)"><i class="bi bi-x-lg"></i></button>';
    list.appendChild(row);
    row.querySelector('input').focus();
  }

  window.addField = function (btn, key) {
    const block = btn.closest('.dev-block');
    addFieldTo(block.querySelector(key === 'emails' ? '.email-list' : '.phone-list'));
  };

  window.addDev = function () {
    const idx = nextIdx++;
    const b = document.createElement('div');
    b.className = 'dev-block';
    b.dataset.idx = idx;
    b.innerHTML =
      '<div class="dev-head"><i class="bi bi-building" style="color:#2f81f7"></i>' +
        '<input class="inp dev-name-in" type="text" name="dev[' + idx + '][name]" placeholder="Developer name">' +
        '<button type="button" class="btn-x" title="Remove developer" onclick="rmDev(this)"><i class="bi bi-trash"></i></button></div>' +
      '<div class="dev-cols">' +
        '<div class="dev-col"><label><i class="bi bi-envelope"></i> Client email(s)</label>' +
          '<div class="email-list"><div class="multi-row">' +
            '<input class="inp" type="text" name="dev[' + idx + '][emails][]" placeholder="client@example.com">' +
            '<button type="button" class="btn-x" onclick="rmRow(this)"><i class="bi bi-x-lg"></i></button></div></div>' +
          '<button type="button" class="btn-add" onclick="addField(this,\'emails\')"><i class="bi bi-plus-lg"></i> Add email</button></div>' +
        '<div class="dev-col"><label><i class="bi bi-whatsapp"></i> Client phone(s)</label>' +
          '<div class="phone-list"><div class="multi-row">' +
            '<input class="inp" type="text" name="dev[' + idx + '][phones][]" placeholder="9198XXXXXXXX">' +
            '<button type="button" class="btn-x" onclick="rmRow(this)"><i class="bi bi-x-lg"></i></button></div></div>' +
          '<button type="button" class="btn-add" onclick="addField(this,\'phones\')"><i class="bi bi-plus-lg"></i> Add number</button></div>' +
      '</div>';
    document.getElementById('devList').appendChild(b);
    b.querySelector('.dev-name-in').focus();
  };
})();

// PE Plan reminder — recipient number add/remove
window.pePlanRm = function (btn) {
  const list = document.getElementById('pePlanNums');
  btn.closest('.multi-row').remove();
  if (!list.querySelector('.multi-row')) pePlanAdd();   // keep one blank input
};
window.pePlanAdd = function () {
  const list = document.getElementById('pePlanNums');
  const row = document.createElement('div');
  row.className = 'multi-row';
  row.innerHTML =
    '<input class="inp" type="text" name="pe_plan_numbers[]" placeholder="9198XXXXXXXX">' +
    '<button type="button" class="btn-x" onclick="pePlanRm(this)"><i class="bi bi-x-lg"></i></button>';
  list.appendChild(row);
  row.querySelector('input').focus();
};
</script>
<?php Layout::foot();
