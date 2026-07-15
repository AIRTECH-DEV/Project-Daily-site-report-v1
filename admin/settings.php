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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Admin::requireEditor();
    if (!Admin::checkCsrf()) {
        $flash = 'Invalid request token. Refresh and try again.'; $flashType = 'bad';
    } else {
        $names  = $_POST['dev_name']  ?? [];
        $mails  = $_POST['dev_email'] ?? [];
        $phones = $_POST['dev_phone'] ?? [];

        $emailMap = []; $phoneMap = [];
        foreach ($names as $i => $nm) {
            $nm = trim((string)$nm);
            if ($nm === '') continue;
            $emailMap[$nm] = trim((string)($mails[$i]  ?? ''));
            $phoneMap[$nm] = trim((string)($phones[$i] ?? ''));
        }

        $ov = Admin::overrides();
        $ov['developer_emails'] = $emailMap;
        $ov['developer_phones'] = $phoneMap;

        $em = $_POST['email_mode'] ?? '';
        $wa = $_POST['whatsapp_mode'] ?? '';
        if (in_array($em, ['OFF','TEST','LIVE'], true)) $ov['email_mode'] = $em;
        if (in_array($wa, ['OFF','TEST','LIVE'], true)) $ov['whatsapp_mode'] = $wa;
        $ov['notify_delay_seconds'] = max(0, (int)($_POST['notify_delay_seconds'] ?? 0));

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
$rows = [];
foreach ($names as $n) {
    $rows[] = [
        'name'  => $n,
        'email' => (string)($cfg['email']['developer_emails'][$n] ?? ''),
        'phone' => (string)($cfg['whatsapp']['developer_phones'][$n] ?? ''),
        'fixed' => isset($cfg['developer_building_sheets'][$n]),
    ];
}
$rows[] = ['name' => '', 'email' => '', 'phone' => '', 'fixed' => false];
$rows[] = ['name' => '', 'email' => '', 'phone' => '', 'fixed' => false];

$emailMode = $cfg['email']['mode'] ?? 'TEST';
$waMode    = $cfg['whatsapp']['mode'] ?? 'TEST';
$delay     = (int)($cfg['notify_delay_seconds'] ?? 180);

require __DIR__ . '/inc/layout.php';
Layout::head('Settings', 'settings');
?>
<?php if ($flash): ?><div class="alert2 <?= $flashType ?>"><i class="bi bi-<?= $flashType === 'ok' ? 'check-circle' : 'exclamation-octagon' ?>"></i> <?= Admin::e($flash) ?></div><?php endif; ?>
<?php if (Admin::isViewer()): ?><div class="alert2 info"><i class="bi bi-eye"></i> Your account is read-only — changes are disabled.</div><?php endif; ?>

<form method="POST">
  <?= Admin::csrfField() ?>

  <div class="card2">
    <div class="card2-head"><i class="bi bi-person-vcard text-primary"></i><h2>Developer Contacts</h2>
      <span class="sub">client email &amp; WhatsApp number the daily report is sent to</span></div>
    <div class="card2-body">
      <p style="color:#5b6b82;margin:0 0 14px;font-size:13px">
        These replace the hardcoded developer contacts. Email supports comma-separated addresses; phone supports
        multiple numbers separated by <span class="mono">/</span> or <span class="mono">,</span> (country code recommended, e.g. <span class="mono">9198XXXXXXXX</span>).
        Leave blank to send nothing for that developer.
      </p>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th style="width:210px">Developer</th><th>Client Email(s)</th><th>Client Phone(s)</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $r): $slug = preg_replace('/[^a-z0-9]+/i','-',$r['name']); ?>
              <tr id="dev-<?= Admin::e($slug) ?>">
                <td>
                  <?php if ($r['fixed']): ?>
                    <input type="hidden" name="dev_name[]" value="<?= Admin::e($r['name']) ?>">
                    <strong><?= Admin::e($r['name']) ?></strong>
                    <div style="font-size:11px;color:#94a3b8">from progress sheet</div>
                  <?php else: ?>
                    <input class="inp" type="text" name="dev_name[]" value="<?= Admin::e($r['name']) ?>" placeholder="Developer name" style="width:100%">
                  <?php endif; ?>
                </td>
                <td><input class="inp" type="text" name="dev_email[]" value="<?= Admin::e($r['email']) ?>" placeholder="client@example.com" style="width:100%"></td>
                <td><input class="inp" type="text" name="dev_phone[]" value="<?= Admin::e($r['phone']) ?>" placeholder="9198XXXXXXXX" style="width:100%"></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p style="color:#94a3b8;font-size:12px;margin:12px 0 0"><i class="bi bi-info-circle"></i> The last blank rows are for adding new developers. Empty names are ignored.</p>
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

  <?php if (!Admin::isViewer()): ?>
  <div style="display:flex;gap:12px;margin-top:4px">
    <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Save Settings</button>
    <a class="btn btn-ghost" href="<?= Admin::BASE ?>/developers.php">View developers</a>
  </div>
  <?php endif; ?>
</form>

<div class="card2" style="margin-top:22px">
  <div class="card2-head"><i class="bi bi-shield-lock text-primary"></i><h2>What stays in code</h2></div>
  <div class="card2-body">
    <p style="color:#5b6b82;font-size:13px;margin:0">
      Secrets (SMTP password, WhatsApp API token) and Google Sheet IDs remain in <span class="mono">config/app.php</span> for safety and
      are not editable here. This page only controls the contacts and send behaviour above, saved to <span class="mono">config/overrides.json</span>.
    </p>
  </div>
</div>
<?php Layout::foot();
