<?php
/**
 * Pipeline Health — the ops view of process_log: per-step success rates and a
 * live feed of failures/skips so nothing fails silently. Each failure links back
 * to its report.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';

$db = Admin::db();

$steps = ['sheet_write','photo_save','pms_update','pdf','email','whatsapp'];
$stat = array_fill_keys($steps, ['done'=>0,'failed'=>0,'skipped'=>0,'running'=>0,'pending'=>0]);
foreach ($db->query("SELECT step, status, COUNT(*) c FROM process_log GROUP BY step, status") as $r) {
    if (isset($stat[$r['step']][$r['status']])) $stat[$r['step']][$r['status']] = (int)$r['c'];
}

$totalDone   = (int)$db->query("SELECT COUNT(*) FROM process_log WHERE status='done'")->fetchColumn();
$totalFailed = (int)$db->query("SELECT COUNT(*) FROM process_log WHERE status='failed'")->fetchColumn();
$totalSkip   = (int)$db->query("SELECT COUNT(*) FROM process_log WHERE status='skipped'")->fetchColumn();
$stuck       = (int)$db->query("SELECT COUNT(*) FROM process_log WHERE status IN ('running','pending') AND started_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)")->fetchColumn();

$issues = $db->query(
    "SELECT p.*, s.project, s.developer, s.building, s.flat_no, s.client_type, s.site_type
     FROM process_log p JOIN submissions s ON s.id = p.submission_id
     WHERE p.status IN ('failed','skipped')
     ORDER BY p.id DESC LIMIT 60"
)->fetchAll();

require __DIR__ . '/inc/layout.php';
Layout::head('Pipeline Health', 'pipeline');
?>
<div class="kpi-grid">
  <div class="kpi"><div class="kpi-ico ic-green"><i class="bi bi-check2-all"></i></div><div class="kpi-label">Steps Done</div><div class="kpi-value"><?= $totalDone ?></div><div class="kpi-foot">completed OK</div></div>
  <div class="kpi"><div class="kpi-ico <?= $totalFailed ? 'ic-red' : 'ic-slate' ?>"><i class="bi bi-x-octagon"></i></div><div class="kpi-label">Failures</div><div class="kpi-value"><?= $totalFailed ?></div><div class="kpi-foot">need attention</div></div>
  <div class="kpi"><div class="kpi-ico ic-slate"><i class="bi bi-skip-forward"></i></div><div class="kpi-label">Skipped</div><div class="kpi-value"><?= $totalSkip ?></div><div class="kpi-foot">mode off / not applicable</div></div>
  <div class="kpi"><div class="kpi-ico <?= $stuck ? 'ic-amber' : 'ic-slate' ?>"><i class="bi bi-hourglass"></i></div><div class="kpi-label">Stuck &gt;30m</div><div class="kpi-value"><?= $stuck ?></div><div class="kpi-foot">running / pending</div></div>
</div>

<div class="card2">
  <div class="card2-head"><i class="bi bi-bar-chart-steps text-primary"></i><h2>Success Rate by Step</h2></div>
  <div class="card2-body">
    <?php foreach ($steps as $s): $d = $stat[$s];
      $tot = $d['done'] + $d['failed'];
      $rate = $tot > 0 ? round($d['done'] * 100 / $tot) : 100;
      $tone = $d['failed'] > 0 ? ($rate < 80 ? '#dc2626' : '#d97706') : '#16a34a';
    ?>
      <div class="bar-row">
        <div class="bl" style="width:150px"><?= Admin::e(ucfirst(str_replace('_',' ',$s))) ?></div>
        <div class="bar-track"><div class="bar-fill" style="width:<?= $rate ?>%;background:<?= $tone ?>"></div></div>
        <div class="bv"><?= $rate ?>%</div>
        <div style="width:170px;text-align:right;font-size:12px;color:#8190a5">
          <span style="color:#16a34a">✓<?= $d['done'] ?></span>
          <?php if ($d['failed']): ?> · <span style="color:#dc2626">✕<?= $d['failed'] ?></span><?php endif; ?>
          <?php if ($d['skipped']): ?> · <span style="color:#64748b">⤼<?= $d['skipped'] ?></span><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card2">
  <div class="card2-head"><i class="bi bi-exclamation-triangle text-primary"></i><h2>Failures &amp; Skips</h2>
    <span class="sub">latest 60</span></div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Report</th><th>Step</th><th>Status</th><th>Message</th><th>When</th><th></th></tr></thead>
      <tbody>
        <?php if (!$issues): ?><tr><td colspan="6" class="t-empty"><i class="bi bi-check-circle text-success"></i> No failures or skips logged. All clean.</td></tr><?php endif; ?>
        <?php foreach ($issues as $i):
          $label = projectLabel($i);
        ?>
          <tr>
            <td><a class="row-link" href="<?= Admin::BASE ?>/submission.php?id=<?= (int)$i['submission_id'] ?>"><?= Admin::e(snip($label, 30)) ?></a></td>
            <td><?= Admin::e(str_replace('_',' ', $i['step'])) ?></td>
            <td><?= Layout::statusBadge((string)$i['status']) ?></td>
            <td style="max-width:420px"><?= Admin::e(snip($i['message'], 110)) ?: '—' ?></td>
            <td title="<?= Admin::e(fmtDateTime($i['finished_at'] ?: $i['started_at'] ?: $i['created_at'])) ?>"><?= Admin::e(ago($i['finished_at'] ?: $i['started_at'] ?: $i['created_at'])) ?></td>
            <td><a class="row-link" href="<?= Admin::BASE ?>/submission.php?id=<?= (int)$i['submission_id'] ?>"><i class="bi bi-chevron-right"></i></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php Layout::foot();
