<?php
/**
 * Developers — one card per configured developer: their buildings, the client
 * email/phone the notifier will actually use (config + admin overrides merged),
 * and report activity. Contacts are edited on Settings; this is the read view.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';

$cfg = Admin::cfg();
$db  = Admin::db();

$devSheets = $cfg['developer_building_sheets'] ?? [];
$emails    = $cfg['email']['developer_emails'] ?? [];       // already merged with overrides
$phones    = $cfg['whatsapp']['developer_phones'] ?? [];

// report stats per developer
$stats = [];
foreach ($db->query("SELECT developer, COUNT(*) c, MAX(created_at) last FROM submissions WHERE client_type='Developer' AND developer<>'' GROUP BY developer") as $r) {
    $stats[strtolower($r['developer'])] = ['c' => (int)$r['c'], 'last' => $r['last']];
}

// union of developer names across config + emails + phones
$names = array_values(array_unique(array_merge(array_keys($devSheets), array_keys($emails), array_keys($phones))));
sort($names);

require __DIR__ . '/inc/layout.php';
Layout::head('Developers', 'developers');
?>
<div class="alert2 info"><i class="bi bi-info-circle"></i>
  Client email &amp; phone below are what the daily-report email/WhatsApp will be sent to. Set or change them on
  <a href="<?= Admin::BASE ?>/settings.php" style="font-weight:600;margin-left:4px">Settings → Developer Contacts</a>.
</div>

<div class="grid-2">
<?php foreach ($names as $name):
    $bldgs = $devSheets[$name]['buildings'] ?? [];
    $em = trim((string)($emails[$name] ?? ''));
    $ph = trim((string)($phones[$name] ?? ''));
    $stt = $stats[strtolower($name)] ?? ['c' => 0, 'last' => null];
?>
  <div class="card2">
    <div class="card2-head"><i class="bi bi-diagram-3 text-primary"></i><h2><?= Admin::e($name) ?></h2>
      <span class="spacer"></span><span class="pill pill-info"><?= $stt['c'] ?> report<?= $stt['c'] === 1 ? '' : 's' ?></span></div>
    <div class="card2-body">
      <dl class="def-list">
        <dt>Buildings</dt><dd><?= $bldgs ? Admin::e(implode(', ', $bldgs)) : '<span style="color:#94a3b8">none configured</span>' ?></dd>
        <dt>Client email</dt><dd><?php if ($em): ?><span class="pill pill-ok"><i class="bi bi-envelope-check"></i> set</span> <?= Admin::e($em) ?><?php else: ?><span class="pill pill-warn"><i class="bi bi-exclamation-triangle"></i> not set</span><?php endif; ?></dd>
        <dt>Client phone</dt><dd><?php if ($ph): ?><span class="pill pill-ok"><i class="bi bi-whatsapp"></i> set</span> <?= Admin::e($ph) ?><?php else: ?><span class="pill pill-warn"><i class="bi bi-exclamation-triangle"></i> not set</span><?php endif; ?></dd>
        <dt>Last report</dt><dd><?= $stt['last'] ? Admin::e(fmtDateTime($stt['last'])) . ' (' . Admin::e(ago($stt['last'])) . ')' : '—' ?></dd>
      </dl>
      <div style="margin-top:14px;display:flex;gap:10px">
        <a class="btn btn-ghost btn-sm" href="<?= Admin::BASE ?>/submissions.php?client=Developer&dev=<?= urlencode($name) ?>"><i class="bi bi-card-list"></i> Reports</a>
        <a class="btn btn-ghost btn-sm" href="<?= Admin::BASE ?>/settings.php#dev-<?= Admin::e(preg_replace('/[^a-z0-9]+/i','-',$name)) ?>"><i class="bi bi-pencil"></i> Edit contacts</a>
      </div>
    </div>
  </div>
<?php endforeach; ?>
<?php if (!$names): ?><div class="t-empty">No developers configured.</div><?php endif; ?>
</div>
<?php Layout::foot();
