<?php
/**
 * Manual master-table sync trigger. Pages auto-sync (throttled) on their own,
 * but this forces an immediate rebuild and shows what changed. Read-only over
 * submissions — cannot affect the report pipeline.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';

$stats = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Admin::requireEditor();
    if (Admin::checkCsrf()) {
        $stats = Admin::runSync();
        Admin::audit('admin_sync', 'projects', null, '', json_encode($stats));
    }
}

$db = Admin::db();
$counts = [
    'projects'      => (int)$db->query("SELECT COUNT(*) FROM projects")->fetchColumn(),
    'workers'       => (int)$db->query("SELECT COUNT(*) FROM workers")->fetchColumn(),
    'contractors'   => (int)$db->query("SELECT COUNT(*) FROM contractors")->fetchColumn(),
    'visit_workers' => (int)$db->query("SELECT COUNT(*) FROM visit_workers")->fetchColumn(),
    'alerts_open'   => (int)$db->query("SELECT COUNT(*) FROM alerts WHERE status IN ('open','ack','snoozed')")->fetchColumn(),
];
$last = Admin::lastSync();

require __DIR__ . '/inc/layout.php';
Layout::head('Data Sync', '', 'sync');
?>
<div class="card2" style="max-width:760px">
  <div class="card2-head"><i class="bi bi-arrow-repeat text-primary"></i><h2>Master-data Sync</h2></div>
  <div class="card2-body">
    <p style="color:#5b6b82;font-size:13.5px;margin:0 0 16px">
      Rebuilds the workforce, contractor, project and alert records from the submitted reports. It only
      <b>reads</b> reports — it never changes the report submission flow. Pages refresh automatically; use this
      to force it now.
    </p>
    <?php if ($stats !== null): ?>
      <div class="alert2 <?= $stats ? 'ok' : 'bad' ?>" style="margin-bottom:16px">
        <i class="bi bi-<?= $stats ? 'check-circle' : 'exclamation-octagon' ?>"></i>
        <?= $stats ? 'Sync complete.' : 'Sync failed — check the DB connection.' ?>
      </div>
      <?php if ($stats): ?>
        <div class="wp-stats" style="margin-bottom:18px">
          <span class="pill pill-info"><?= (int)$stats['projects'] ?> projects</span>
          <span class="pill pill-info"><?= (int)$stats['workers'] ?> workers</span>
          <span class="pill pill-info"><?= (int)$stats['contractors'] ?> contractors</span>
          <span class="pill pill-ok"><?= (int)$stats['alerts_new'] ?> new alerts</span>
          <span class="pill pill-muted"><?= (int)$stats['alerts_closed'] ?> resolved</span>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <dl class="def-list" style="margin-bottom:18px">
      <dt>Last synced</dt><dd><?= $last ? Admin::e(date('d M Y, H:i:s', $last)) . ' (' . Admin::e(ago(date('Y-m-d H:i:s', $last))) . ')' : 'never' ?></dd>
      <dt>Projects</dt><dd><?= $counts['projects'] ?></dd>
      <dt>Workers · Contractors</dt><dd><?= $counts['workers'] ?> · <?= $counts['contractors'] ?></dd>
      <dt>Visit-worker records</dt><dd><?= $counts['visit_workers'] ?></dd>
      <dt>Open alerts</dt><dd><?= $counts['alerts_open'] ?></dd>
    </dl>

    <?php if (!Admin::isViewer()): ?>
      <form method="POST"><?= Admin::csrfField() ?>
        <button class="btn btn-primary" type="submit"><i class="bi bi-arrow-repeat"></i> Sync now</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php Layout::foot();
