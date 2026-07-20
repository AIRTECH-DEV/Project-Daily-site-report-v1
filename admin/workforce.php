<?php
/**
 * Workforce & contractors — searchable records normalized from every report's
 * peopleRows (no free-text guessing). Answers: PE workload, which VAPL workers /
 * contractors worked where, VAPL-vs-contractor split, and planned work that has
 * no contractor on the latest visit. Hours are intentionally not tracked.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';
Admin::autoSync();

$db = Admin::db();
$q = trim($_GET['q'] ?? '');
$like = '%' . $q . '%';

// KPIs
$vaplN   = (int)$db->query("SELECT COUNT(*) FROM workers WHERE type='VAPL'")->fetchColumn();
$conWkrN = (int)$db->query("SELECT COUNT(*) FROM workers WHERE type='Contractor'")->fetchColumn();
$conN    = (int)$db->query("SELECT COUNT(*) FROM contractors")->fetchColumn();
$peN     = (int)$db->query("SELECT COUNT(DISTINCT primary_pe) FROM projects WHERE primary_pe<>''")->fetchColumn();

// PE workload
$peLoad = $db->query(
    "SELECT primary_pe,
            COUNT(*) projects,
            SUM(lifecycle IN ('Active','At Risk','Commissioning Pending')) actives,
            SUM(lifecycle='On Hold') onhold,
            SUM(report_count) reports
     FROM projects WHERE primary_pe<>'' GROUP BY primary_pe ORDER BY actives DESC, projects DESC"
)->fetchAll();
$peOverload = (int)(Admin::overrides()['alert_rules']['pe_overload_projects'] ?? 6);
$peMax = 1; foreach ($peLoad as $r) $peMax = max($peMax, (int)$r['projects']);

// workers
$wSt = $db->prepare(
    "SELECT w.id, w.name, w.type, w.visits, w.last_seen,
            COUNT(DISTINCT vw.project_key) projects,
            (SELECT name FROM contractors c WHERE c.id=w.contractor_id) contractor
     FROM workers w LEFT JOIN visit_workers vw ON vw.worker_id=w.id
     WHERE (? = '' OR w.name LIKE ?)
     GROUP BY w.id ORDER BY w.type, w.visits DESC"
);
$wSt->execute([$q, $like]);
$workers = $wSt->fetchAll();

// contractors
$cSt = $db->prepare(
    "SELECT c.id, c.name, c.trade, c.visits, c.last_seen,
            COUNT(DISTINCT vw.worker_id) workers, COUNT(DISTINCT vw.project_key) projects
     FROM contractors c LEFT JOIN visit_workers vw ON vw.contractor_id=c.id
     WHERE (? = '' OR c.name LIKE ?)
     GROUP BY c.id ORDER BY c.visits DESC"
);
$cSt->execute([$q, $like]);
$contractors = $cSt->fetchAll();

// contractor-need heuristic: which steps are usually done by contractors
$stepContractor = []; $stepTotal = [];
foreach ($db->query("SELECT type, steps FROM visit_workers WHERE steps<>''") as $r) {
    foreach (array_filter(array_map('trim', explode(',', (string)$r['steps']))) as $st) {
        $k = stepKey($st);
        $stepTotal[$k] = ($stepTotal[$k] ?? 0) + 1;
        if ($r['type'] === 'Contractor') $stepContractor[$k] = ($stepContractor[$k] ?? 0) + 1;
    }
}
$contractorStep = fn($name) => ($stepTotal[stepKey($name)] ?? 0) >= 2
    && ($stepContractor[stepKey($name)] ?? 0) / max(1, $stepTotal[stepKey($name)]) >= 0.5;

// planned work needing a contractor but none on the latest visit
$needing = [];
foreach ($db->query("SELECT * FROM projects WHERE next_plan_steps<>'' AND lifecycle IN ('Active','At Risk','On Hold','Commissioning Pending')") as $p) {
    $plannedSteps = array_filter(array_map('trim', explode(',', (string)$p['next_plan_steps'])));
    $needSteps = array_values(array_filter($plannedSteps, $contractorStep));
    if (!$needSteps) continue;
    // contractor present on the most recent visit?
    $hasCon = (int)$db->query("SELECT COUNT(*) FROM visit_workers WHERE submission_id=(SELECT MAX(submission_id) FROM visit_workers WHERE project_key=" . $db->quote($p['project_key']) . ") AND type='Contractor'")->fetchColumn();
    if (!$hasCon) $needing[] = ['p' => $p, 'steps' => $needSteps];
}

require __DIR__ . '/inc/layout.php';
Layout::head('Workforce', 'workforce');
?>
<div class="kpi-grid">
  <div class="kpi"><div class="kpi-ico ic-blue"><i class="bi bi-person-workspace"></i></div><div class="kpi-label">VAPL Workers</div><div class="kpi-value"><?= $vaplN ?></div><div class="kpi-foot">on record</div></div>
  <div class="kpi"><div class="kpi-ico ic-amber"><i class="bi bi-hammer"></i></div><div class="kpi-label">Contractor Labour</div><div class="kpi-value"><?= $conWkrN ?></div><div class="kpi-foot"><?= $conN ?> companies</div></div>
  <div class="kpi"><div class="kpi-ico ic-slate"><i class="bi bi-person-badge"></i></div><div class="kpi-label">Active PEs</div><div class="kpi-value"><?= $peN ?></div><div class="kpi-foot">carrying projects</div></div>
  <div class="kpi"><div class="kpi-ico <?= $needing ? 'ic-red' : 'ic-green' ?>"><i class="bi bi-exclamation-diamond"></i></div><div class="kpi-label">Contractor Gaps</div><div class="kpi-value"><?= count($needing) ?></div><div class="kpi-foot">planned work, none assigned</div></div>
</div>

<div class="card2">
  <div class="card2-head"><i class="bi bi-person-badge text-primary"></i><h2>PE Workload &amp; Allocation</h2></div>
  <div class="card2-body">
    <?php if (!$peLoad): ?><div class="t-empty">No PE data yet.</div><?php endif; ?>
    <?php foreach ($peLoad as $r): $over = (int)$r['actives'] >= $peOverload; ?>
      <div class="bar-row">
        <div class="bl" style="width:150px"><?= Admin::e($r['primary_pe']) ?></div>
        <div class="bar-track"><div class="bar-fill" style="width:<?= max(6, round($r['projects'] * 100 / $peMax)) ?>%;<?= $over ? 'background:linear-gradient(90deg,#f0736a,#dc2626)' : '' ?>"></div></div>
        <div style="width:230px;text-align:right;font-size:12.5px;color:#5b6b82">
          <b><?= (int)$r['actives'] ?></b> active<?= (int)$r['onhold'] ? ' · <span style="color:#dc2626">' . (int)$r['onhold'] . ' hold</span>' : '' ?>
          · <?= (int)$r['projects'] ?> total · <?= (int)$r['reports'] ?> reports
          <?= $over ? ' <span class="pill pill-bad" style="margin-left:6px">overloaded</span>' : '' ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($needing): ?>
<div class="card2">
  <div class="card2-head"><i class="bi bi-exclamation-diamond text-danger"></i><h2>Planned Work Needing a Contractor</h2><span class="sub">no contractor on latest visit</span></div>
  <div class="card2-body">
    <?php foreach ($needing as $n): $p = $n['p']; ?>
      <div class="up-item">
        <div class="up-date"><div class="d"><?= $p['next_plan_date'] ? date('d', strtotime($p['next_plan_date'])) : '—' ?></div><div class="m"><?= $p['next_plan_date'] ? date('M', strtotime($p['next_plan_date'])) : '' ?></div></div>
        <div class="up-body">
          <div class="up-proj"><a class="row-link" href="<?= Admin::BASE ?>/project.php?key=<?= urlencode($p['project_key']) ?>"><?= Admin::e($p['label']) ?></a> <span class="who">· PE <?= Admin::e($p['primary_pe']) ?></span></div>
          <div class="up-steps"><?php foreach ($n['steps'] as $st): ?><span class="pill pill-warn"><?= Admin::e($st) ?></span><?php endforeach; ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card2">
  <div class="card2-head"><i class="bi bi-people-fill text-primary"></i><h2>Workers</h2>
    <span class="spacer"></span>
    <form class="filters" method="GET" style="gap:8px"><input class="inp" type="text" name="q" value="<?= Admin::e($q) ?>" placeholder="Search name…" style="min-width:200px"><button class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button><?php if ($q): ?><a class="btn btn-ghost btn-sm" href="<?= Admin::BASE ?>/workforce.php">Reset</a><?php endif; ?></form>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Worker</th><th>Type</th><th>Company</th><th>Visits</th><th>Projects</th><th>Last seen</th><th></th></tr></thead>
      <tbody>
        <?php if (!$workers): ?><tr><td colspan="7" class="t-empty">No workers match.</td></tr><?php endif; ?>
        <?php foreach ($workers as $w): ?>
          <tr>
            <td><a class="row-link" href="<?= Admin::BASE ?>/worker.php?id=<?= (int)$w['id'] ?>"><?= Admin::e($w['name']) ?></a></td>
            <td><span class="pill pill-<?= $w['type']==='Contractor'?'warn':'type' ?>"><?= Admin::e($w['type']) ?></span></td>
            <td><?= Admin::e($w['contractor']) ?: '—' ?></td>
            <td><?= (int)$w['visits'] ?></td>
            <td><?= (int)$w['projects'] ?></td>
            <td><?= Admin::e(fmtDate($w['last_seen'])) ?></td>
            <td><a class="row-link" href="<?= Admin::BASE ?>/worker.php?id=<?= (int)$w['id'] ?>"><i class="bi bi-chevron-right"></i></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card2">
  <div class="card2-head"><i class="bi bi-hammer text-primary"></i><h2>Contractor Companies</h2></div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Contractor</th><th>Trade</th><th>Visits</th><th>Workers</th><th>Projects</th><th>Last seen</th><th></th></tr></thead>
      <tbody>
        <?php if (!$contractors): ?><tr><td colspan="7" class="t-empty">No contractors match.</td></tr><?php endif; ?>
        <?php foreach ($contractors as $c): ?>
          <tr>
            <td><a class="row-link" href="<?= Admin::BASE ?>/contractor.php?id=<?= (int)$c['id'] ?>"><?= Admin::e($c['name']) ?></a></td>
            <td><?= Admin::e($c['trade']) ?: '—' ?></td>
            <td><?= (int)$c['visits'] ?></td>
            <td><?= (int)$c['workers'] ?></td>
            <td><?= (int)$c['projects'] ?></td>
            <td><?= Admin::e(fmtDate($c['last_seen'])) ?></td>
            <td><a class="row-link" href="<?= Admin::BASE ?>/contractor.php?id=<?= (int)$c['id'] ?>"><i class="bi bi-chevron-right"></i></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php Layout::foot();
