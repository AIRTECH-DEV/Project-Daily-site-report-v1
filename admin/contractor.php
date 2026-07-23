<?php
/**
 * Contractor 360 — one contractor company: its workers, the projects it worked,
 * the steps/trades it performs, and every visit. Editable trade/phone note.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';

$db = Admin::db();
$id = (int)($_GET['id'] ?? 0);

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Admin::requireEditor();
    if (Admin::checkCsrf()) {
        $db->prepare("UPDATE contractors SET trade=?, phone=? WHERE id=?")
           ->execute([trim($_POST['trade'] ?? ''), trim($_POST['phone'] ?? ''), $id]);
        Admin::audit('update_contractor', 'contractors', $id);
        $flash = 'Saved.';
    }
}

$c = $db->prepare("SELECT * FROM contractors WHERE id=?");
$c->execute([$id]);
$c = $c->fetch();

require __DIR__ . '/inc/layout.php';
if (!$c) {
    Layout::head('Contractor not found', 'workforce');
    echo '<div class="alert2 bad"><i class="bi bi-exclamation-octagon"></i> Contractor not found.</div>';
    echo '<a class="btn btn-ghost" href="' . Admin::BASE . '/workforce.php"><i class="bi bi-arrow-left"></i> Back</a>';
    Layout::foot();
    exit;
}

$rows = $db->prepare(
    "SELECT vw.*, p.label, s.created_at, s.engineer
     FROM visit_workers vw
     LEFT JOIN projects p ON p.project_key = vw.project_key
     JOIN submissions s ON s.id = vw.submission_id
     WHERE vw.contractor_id = ? ORDER BY vw.submission_id DESC"
);
$rows->execute([$id]);
$rows = $rows->fetchAll();

$workers = []; $projects = []; $stepsAll = [];
foreach ($rows as $r) {
    if ($r['worker_name'] !== '') $workers[$r['worker_name']] = ($workers[$r['worker_name']] ?? 0) + 1;
    $lbl = $r['label'] ?: $r['project_key'];
    if (!isset($projects[$r['project_key']])) $projects[$r['project_key']] = ['label' => $lbl, 'visits' => 0];
    $projects[$r['project_key']]['visits']++;
    foreach (array_filter(array_map('trim', explode(',', (string)$r['steps']))) as $st) $stepsAll[$st] = ($stepsAll[$st] ?? 0) + 1;
}
arsort($workers); arsort($stepsAll);

Layout::head('Contractor · ' . $c['name'], 'workforce', 'contractor');
?>
<div class="breadcrumb2"><a href="<?= Admin::BASE ?>/workforce.php">Workforce</a> › <?= Admin::e($c['name']) ?></div>
<?php if ($flash): ?><div class="alert2 ok"><i class="bi bi-check-circle"></i> <?= Admin::e($flash) ?></div><?php endif; ?>

<div class="card2">
  <div class="detail-head">
    <div class="dh-ic" style="background:var(--warn-bg);color:var(--warn)"><i class="bi bi-hammer"></i></div>
    <div class="dh-titles">
      <h2><?= Admin::e($c['name']) ?></h2>
      <div class="dh-sub"><?= (int)$c['visits'] ?> visits · <?= count($workers) ?> workers · <?= count($projects) ?> projects · seen <?= Admin::e(fmtDate($c['first_seen'])) ?> → <?= Admin::e(fmtDate($c['last_seen'])) ?></div>
    </div>
  </div>
  <div class="card2-body">
    <?php if (!Admin::isViewer()): ?>
    <form method="POST" class="filters" style="gap:12px">
      <?= Admin::csrfField() ?>
      <div class="fld"><label>Trade / skill</label><input class="inp" type="text" name="trade" value="<?= Admin::e($c['trade']) ?>" placeholder="e.g. Copper piping, Insulation"></div>
      <div class="fld"><label>Phone</label><input class="inp" type="text" name="phone" value="<?= Admin::e($c['phone']) ?>" placeholder="contact number"></div>
      <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-save"></i> Save</button>
    </form>
    <?php else: ?>
      <div class="info-val soft">Trade: <?= Admin::e($c['trade']) ?: '—' ?> · Phone: <?= Admin::e($c['phone']) ?: '—' ?></div>
    <?php endif; ?>
  </div>
</div>

<div class="grid-3">
  <div class="card2">
    <div class="card2-head"><i class="bi bi-people text-primary"></i><h2>Workers</h2></div>
    <div class="card2-body">
      <?php if (!$workers): ?><div class="t-empty">None.</div><?php endif; ?>
      <?php foreach ($workers as $n => $ct): ?>
        <div class="bar-row"><div style="flex:1"><?= Admin::e($n) ?></div><span class="pill pill-muted"><?= $ct ?> visit(s)</span></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card2">
    <div class="card2-head"><i class="bi bi-diagram-3 text-primary"></i><h2>Projects</h2></div>
    <div class="card2-body">
      <?php if (!$projects): ?><div class="t-empty">None.</div><?php endif; ?>
      <?php foreach ($projects as $pk => $pd): ?>
        <div class="bar-row"><a class="row-link" style="flex:1" href="<?= Admin::BASE ?>/project.php?key=<?= urlencode($pk) ?>"><?= Admin::e($pd['label']) ?></a><span class="pill pill-muted"><?= $pd['visits'] ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card2">
    <div class="card2-head"><i class="bi bi-list-check text-primary"></i><h2>Steps / Trades</h2></div>
    <div class="card2-body">
      <div class="up-steps">
        <?php if (!$stepsAll): ?><div class="t-empty">None.</div><?php endif; ?>
        <?php foreach ($stepsAll as $st => $ct): ?><span class="pill pill-type"><?= Admin::e($st) ?> · <?= $ct ?></span><?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="card2">
  <div class="card2-head"><i class="bi bi-clock-history text-primary"></i><h2>Every Visit</h2><span class="sub"><?= count($rows) ?></span></div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Date</th><th>Worker</th><th>Project</th><th>Steps</th><th>PE</th><th>Report</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= Admin::e(fmtDate($r['visit_date'] ?: $r['created_at'])) ?></td>
            <td><?= Admin::e($r['worker_name']) ?></td>
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
