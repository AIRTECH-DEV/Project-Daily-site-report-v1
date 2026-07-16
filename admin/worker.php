<?php
/**
 * Worker 360 — one VAPL/contractor worker: which projects they worked, which
 * steps they completed, activity by month, and every visit. Built from
 * visit_workers (normalized), so names are records, not free text.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';

$db = Admin::db();
$id = (int)($_GET['id'] ?? 0);
$w = $db->prepare("SELECT w.*, (SELECT name FROM contractors c WHERE c.id=w.contractor_id) contractor FROM workers w WHERE w.id=?");
$w->execute([$id]);
$w = $w->fetch();

require __DIR__ . '/inc/layout.php';
if (!$w) {
    Layout::head('Worker not found', 'workforce');
    echo '<div class="alert2 bad"><i class="bi bi-exclamation-octagon"></i> Worker not found.</div>';
    echo '<a class="btn btn-ghost" href="' . Admin::BASE . '/workforce.php"><i class="bi bi-arrow-left"></i> Back</a>';
    Layout::foot();
    exit;
}

$rows = $db->prepare(
    "SELECT vw.*, p.label, s.created_at, s.engineer, s.status
     FROM visit_workers vw
     LEFT JOIN projects p ON p.project_key = vw.project_key
     JOIN submissions s ON s.id = vw.submission_id
     WHERE vw.worker_id = ? ORDER BY vw.submission_id DESC"
);
$rows->execute([$id]);
$rows = $rows->fetchAll();

$projects = []; $stepsAll = []; $months = [];
foreach ($rows as $r) {
    $lbl = $r['label'] ?: $r['project_key'];
    if (!isset($projects[$r['project_key']])) $projects[$r['project_key']] = ['label' => $lbl, 'visits' => 0, 'steps' => []];
    $projects[$r['project_key']]['visits']++;
    foreach (array_filter(array_map('trim', explode(',', (string)$r['steps']))) as $st) {
        $projects[$r['project_key']]['steps'][$st] = true;
        $stepsAll[$st] = ($stepsAll[$st] ?? 0) + 1;
    }
    $m = date('Y-m', strtotime((string)$r['visit_date'] ?: $r['created_at']));
    $months[$m] = ($months[$m] ?? 0) + 1;
}
arsort($stepsAll);
ksort($months);
$monthMax = $months ? max($months) : 1;

Layout::head('Worker · ' . $w['name'], 'workforce', 'worker');
?>
<div class="breadcrumb2"><a href="<?= Admin::BASE ?>/workforce.php">Workforce</a> › <?= Admin::e($w['name']) ?></div>

<div class="card2">
  <div class="detail-head">
    <div class="dh-ic" style="background:<?= $w['type']==='Contractor'?'var(--warn-bg)':'var(--info-bg)' ?>;color:<?= $w['type']==='Contractor'?'var(--warn)':'var(--info)' ?>"><i class="bi bi-<?= $w['type']==='Contractor'?'hammer':'person-workspace' ?>"></i></div>
    <div class="dh-titles">
      <h2><?= Admin::e($w['name']) ?> <span class="pill pill-<?= $w['type']==='Contractor'?'warn':'type' ?>"><?= Admin::e($w['type']) ?></span></h2>
      <div class="dh-sub"><?= $w['contractor'] ? 'Company: ' . Admin::e($w['contractor']) . ' · ' : '' ?><?= (int)$w['visits'] ?> visits · <?= count($projects) ?> projects · seen <?= Admin::e(fmtDate($w['first_seen'])) ?> → <?= Admin::e(fmtDate($w['last_seen'])) ?></div>
    </div>
  </div>
  <div class="info-grid">
    <div class="info-col">
      <div class="info-row"><div class="info-key"><i class="bi bi-diagram-3"></i>Projects</div><div class="info-val"><?= count($projects) ?></div></div>
      <div class="info-row"><div class="info-key"><i class="bi bi-list-check"></i>Distinct steps</div><div class="info-val"><?= count($stepsAll) ?></div></div>
    </div>
    <div class="info-col">
      <div class="info-row"><div class="info-key"><i class="bi bi-calendar-check"></i>Total visits</div><div class="info-val"><?= (int)$w['visits'] ?></div></div>
      <div class="info-row"><div class="info-key"><i class="bi bi-clock-history"></i>Last active</div><div class="info-val"><?= Admin::e(fmtDate($w['last_seen'])) ?> <span class="info-val soft">(<?= Admin::e(ago($w['last_seen'])) ?>)</span></div></div>
    </div>
    <div class="info-col">
      <div class="info-row"><div class="info-key"><i class="bi bi-graph-up"></i>Active months</div><div class="info-val"><?= count($months) ?></div></div>
    </div>
  </div>
</div>

<div class="grid-2">
  <div class="card2">
    <div class="card2-head"><i class="bi bi-diagram-3 text-primary"></i><h2>Projects Worked</h2></div>
    <div class="card2-body">
      <?php if (!$projects): ?><div class="t-empty">None.</div><?php endif; ?>
      <?php foreach ($projects as $pk => $pd): ?>
        <div class="up-item">
          <div class="up-body">
            <div class="up-proj"><a class="row-link" href="<?= Admin::BASE ?>/project.php?key=<?= urlencode($pk) ?>"><?= Admin::e($pd['label']) ?></a> <span class="who">· <?= $pd['visits'] ?> visit(s)</span></div>
            <div class="up-steps"><?php foreach (array_keys($pd['steps']) as $st): ?><span class="pill pill-type"><?= Admin::e($st) ?></span><?php endforeach; ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card2">
    <div class="card2-head"><i class="bi bi-bar-chart text-primary"></i><h2>Activity by Month</h2></div>
    <div class="card2-body">
      <?php if (!$months): ?><div class="t-empty">None.</div><?php endif; ?>
      <?php foreach ($months as $m => $c): ?>
        <div class="bar-row">
          <div class="bl" style="width:90px"><?= Admin::e(date('M Y', strtotime($m . '-01'))) ?></div>
          <div class="bar-track"><div class="bar-fill" style="width:<?= max(8, round($c * 100 / $monthMax)) ?>%"></div></div>
          <div class="bv"><?= $c ?></div>
        </div>
      <?php endforeach; ?>
      <div class="section-title">Steps completed most</div>
      <div class="up-steps">
        <?php $i=0; foreach ($stepsAll as $st => $c): if ($i++ >= 12) break; ?><span class="pill pill-muted"><?= Admin::e($st) ?> · <?= $c ?></span><?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="card2">
  <div class="card2-head"><i class="bi bi-clock-history text-primary"></i><h2>Every Visit</h2><span class="sub"><?= count($rows) ?></span></div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Date</th><th>Project</th><th>Steps</th><th>PE</th><th>Report</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= Admin::e(fmtDate($r['visit_date'] ?: $r['created_at'])) ?></td>
            <td><a class="row-link" href="<?= Admin::BASE ?>/project.php?key=<?= urlencode($r['project_key']) ?>"><?= Admin::e($r['label'] ?: $r['project_key']) ?></a></td>
            <td class="info-val soft" style="font-size:12.5px"><?= Admin::e($r['steps']) ?: '—' ?></td>
            <td><?= Admin::e($r['engineer']) ?: '—' ?></td>
            <td><a class="row-link" href="<?= Admin::BASE ?>/submission.php?id=<?= (int)$r['submission_id'] ?>">#<?= (int)$r['submission_id'] ?></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<a class="btn btn-ghost" href="<?= Admin::BASE ?>/workforce.php"><i class="bi bi-arrow-left"></i> Back to workforce</a>
<?php Layout::foot();
