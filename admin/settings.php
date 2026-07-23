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

// Operational/tracker tables holding submitted report data + everything derived
// from it. Cleared by the Danger Zone reset. Auth/ops tables (admin_users,
// rate_limits, audit_logs) and config/overrides.json are intentionally kept.
$DATA_TABLES = [
    'submissions', 'process_log', 'attachments',   // live pipeline (schema.sql)
    'visit_workers', 'projects', 'contractors', 'workers', 'alerts', 'alert_events', // derived (admin_ext)
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_data') {
    Admin::requireEditor();
    if (!Admin::checkCsrf()) {
        $flash = 'Invalid request token. Refresh and try again.'; $flashType = 'bad';
    } elseif (trim((string)($_POST['confirm'] ?? '')) !== 'DELETE') {
        $flash = 'Type DELETE in the box to confirm. Nothing was cleared.'; $flashType = 'bad';
    } else {
        try {
            $db = Admin::db();
            $db->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($DATA_TABLES as $t) $db->exec("TRUNCATE TABLE `$t`");
            $db->exec('SET FOREIGN_KEY_CHECKS=1');
            @unlink(__DIR__ . '/../storage/.admin_sync');   // force fresh sync next load
            Admin::audit('clear_tracker_data', 'submissions', null, '', 'truncated: ' . implode(',', $DATA_TABLES));
            $flash = 'All tracker data cleared. Sheets untouched. Dashboard is now empty — new submissions rebuild it.';
        } catch (Throwable $e) {
            $flash = 'Could not clear data: ' . $e->getMessage(); $flashType = 'bad';
        }
    }
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

// Danger Zone — how many submissions currently in the DB (guides the reset copy)
$subCount = 0;
try { $subCount = (int)Admin::db()->query("SELECT COUNT(*) FROM submissions")->fetchColumn(); } catch (Throwable $e) {}

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

<?php if (!Admin::isViewer()): ?>
<div class="card2" style="margin-top:22px;border:1px solid #f0b4b4">
  <div class="card2-head"><i class="bi bi-exclamation-triangle" style="color:#c0392b"></i><h2>Danger Zone — Clear Tracker Data</h2>
    <span class="sub">wipe all submitted reports &amp; everything derived from them</span></div>
  <div class="card2-body">
    <p style="color:#5b6b82;font-size:13px;margin:0 0 12px">
      Deletes <b>every submission</b> and all data built from it — process log, attachments, projects,
      contractors, workers, visits, and alerts. Use once after go-live to drop test data.
      <b>Google Sheets are NOT touched</b> (clear those manually, as you did). Admin logins, audit trail,
      and your Settings above are kept. <b>This cannot be undone.</b>
    </p>
    <p style="color:#8190a5;font-size:12.5px;margin:0 0 14px">
      Currently in DB: <b><?= $subCount ?></b> submission<?= $subCount === 1 ? '' : 's' ?> (+ derived rows).
    </p>
    <form method="POST" onsubmit="return confirm('Permanently delete ALL tracker data? Sheets stay untouched. This cannot be undone.');">
      <?= Admin::csrfField() ?>
      <input type="hidden" name="action" value="clear_data">
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label style="font-size:13px;color:#5b6b82">Type <span class="mono">DELETE</span> to confirm
          <input class="inp" type="text" name="confirm" autocomplete="off" placeholder="DELETE" style="width:140px;margin-left:8px">
        </label>
        <button class="btn" type="submit" style="background:#c0392b;color:#fff;border-color:#c0392b">
          <i class="bi bi-trash3"></i> Clear all tracker data
        </button>
      </div>
    </form>
  </div>
</div>
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
