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

require __DIR__ . '/inc/layout.php';
Layout::head('Settings', 'settings');
?>
<?php if ($flash): ?><div class="alert2 <?= $flashType ?>"><i class="bi bi-<?= $flashType === 'ok' ? 'check-circle' : 'exclamation-octagon' ?>"></i> <?= Admin::e($flash) ?></div><?php endif; ?>
<?php if (Admin::isViewer()): ?><div class="alert2 info"><i class="bi bi-eye"></i> Your account is read-only — changes are disabled.</div><?php endif; ?>

<form method="POST">
  <?= Admin::csrfField() ?>

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
</script>
<?php Layout::foot();
