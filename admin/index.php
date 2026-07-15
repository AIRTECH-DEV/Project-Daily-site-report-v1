<?php
/**
 * Dashboard — at-a-glance health of the whole daily-update pipeline: report
 * volume, project/status mix, notification delivery, and pipeline failures.
 * All figures come from the tracker DB (the authoritative log of every submit).
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/layout.php';

$db = Admin::db();
$one = fn(string $sql, array $a = []) => (function () use ($db, $sql, $a) {
    $st = $db->prepare($sql); $st->execute($a); return $st->fetchColumn();
})();

// ---- KPI numbers ----
$total   = (int)$one("SELECT COUNT(*) FROM submissions");
$today   = (int)$one("SELECT COUNT(*) FROM submissions WHERE DATE(created_at) = CURDATE()");
$week    = (int)$one("SELECT COUNT(*) FROM submissions WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)");
$prevWk  = (int)$one("SELECT COUNT(*) FROM submissions WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND created_at < DATE_SUB(CURDATE(), INTERVAL 6 DAY)");
$genProj = (int)$one("SELECT COUNT(DISTINCT LOWER(TRIM(project))) FROM submissions WHERE client_type='General' AND project<>''");
$devUnit = (int)$one("SELECT COUNT(DISTINCT CONCAT(LOWER(developer),'|',LOWER(building),'|',LOWER(flat_no))) FROM submissions WHERE client_type='Developer'");
$projects = $genProj + $devUnit;
$holdN   = (int)$one("SELECT COUNT(*) FROM submissions WHERE status='Hold'");
$pendN   = (int)$one("SELECT COUNT(*) FROM submissions WHERE status='Pending'");

$stepDone   = (int)$one("SELECT COUNT(*) FROM process_log WHERE status='done'");
$stepFailed = (int)$one("SELECT COUNT(*) FROM process_log WHERE status='failed'");
$succRate   = ($stepDone + $stepFailed) > 0 ? round($stepDone * 100 / ($stepDone + $stepFailed)) : 100;
$notifSent  = (int)$one("SELECT COUNT(*) FROM process_log WHERE step IN ('email','whatsapp') AND status='done'");

$wkDelta = $prevWk > 0 ? round(($week - $prevWk) * 100 / $prevWk) : ($week > 0 ? 100 : 0);

// ---- Trend: reports per day, last 14 days ----
$trend = array_fill_keys(
    array_map(fn($i) => date('Y-m-d', strtotime("-$i day")), range(13, 0)),
    0
);
foreach ($db->query("SELECT DATE(created_at) d, COUNT(*) c FROM submissions WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(created_at)") as $r) {
    $trend[$r['d']] = (int)$r['c'];
}
$trendLabels = array_map(fn($d) => date('d M', strtotime($d)), array_keys($trend));
$trendData   = array_values($trend);

// ---- Distributions ----
$bySite = []; foreach ($db->query("SELECT site_type, COUNT(*) c FROM submissions GROUP BY site_type") as $r) $bySite[$r['site_type'] ?: 'Unknown'] = (int)$r['c'];
$byClient = []; foreach ($db->query("SELECT client_type, COUNT(*) c FROM submissions GROUP BY client_type") as $r) $byClient[$r['client_type'] ?: 'Unknown'] = (int)$r['c'];
$byStatus = ['Done' => 0, 'Pending' => 0, 'Hold' => 0];
foreach ($db->query("SELECT status, COUNT(*) c FROM submissions WHERE status<>'' GROUP BY status") as $r) $byStatus[$r['status']] = (int)$r['c'];

// ---- Pipeline outcomes per step ----
$steps = ['sheet_write','photo_save','pms_update','pdf','email','whatsapp'];
$pipe = array_fill_keys($steps, ['done'=>0,'failed'=>0,'skipped'=>0]);
foreach ($db->query("SELECT step, status, COUNT(*) c FROM process_log GROUP BY step, status") as $r) {
    if (isset($pipe[$r['step']][$r['status']])) $pipe[$r['step']][$r['status']] = (int)$r['c'];
}

// ---- Top engineers ----
$engineers = [];
foreach ($db->query("SELECT engineer, COUNT(*) c FROM submissions WHERE engineer<>'' GROUP BY engineer ORDER BY c DESC LIMIT 6") as $r) {
    $engineers[$r['engineer']] = (int)$r['c'];
}
$engMax = $engineers ? max($engineers) : 1;

// ---- Recent reports ----
$recent = $db->query("SELECT id, public_id, site_type, client_type, project, developer, building, flat_no, engineer, current_status, status, overall_status, created_at FROM submissions ORDER BY id DESC LIMIT 8")->fetchAll();

Layout::head('Dashboard', 'dashboard');
?>

<div class="kpi-grid">
  <div class="kpi"><div class="kpi-ico ic-blue"><i class="bi bi-card-list"></i></div>
    <div class="kpi-label">Total Reports</div><div class="kpi-value"><?= $total ?></div>
    <div class="kpi-foot"><?= $today ?> today</div></div>

  <div class="kpi"><div class="kpi-ico ic-green"><i class="bi bi-graph-up-arrow"></i></div>
    <div class="kpi-label">This Week</div><div class="kpi-value"><?= $week ?></div>
    <div class="kpi-foot"><?php if ($wkDelta >= 0): ?><span class="up">▲ <?= $wkDelta ?>%</span><?php else: ?><span class="down">▼ <?= abs($wkDelta) ?>%</span><?php endif; ?> vs prev week</div></div>

  <div class="kpi"><div class="kpi-ico ic-slate"><i class="bi bi-buildings"></i></div>
    <div class="kpi-label">Active Projects</div><div class="kpi-value"><?= $projects ?></div>
    <div class="kpi-foot"><?= $genProj ?> general · <?= $devUnit ?> dev units</div></div>

  <div class="kpi"><div class="kpi-ico ic-amber"><i class="bi bi-pause-circle"></i></div>
    <div class="kpi-label">On Hold</div><div class="kpi-value"><?= $holdN ?></div>
    <div class="kpi-foot"><?= $pendN ?> pending steps</div></div>

  <div class="kpi"><div class="kpi-ico <?= $stepFailed > 0 ? 'ic-red' : 'ic-green' ?>"><i class="bi bi-heart-pulse"></i></div>
    <div class="kpi-label">Pipeline Health</div><div class="kpi-value"><?= $succRate ?>%</div>
    <div class="kpi-foot"><?= $stepFailed ?> failed step<?= $stepFailed === 1 ? '' : 's' ?></div></div>

  <div class="kpi"><div class="kpi-ico ic-blue"><i class="bi bi-send-check"></i></div>
    <div class="kpi-label">Notifications Sent</div><div class="kpi-value"><?= $notifSent ?></div>
    <div class="kpi-foot">email + WhatsApp</div></div>
</div>

<div class="grid-2">
  <div class="card2">
    <div class="card2-head"><i class="bi bi-activity text-primary"></i><h2>Report Volume</h2><span class="sub">last 14 days</span></div>
    <div class="card2-body"><div class="chart-box"><canvas id="trendChart"></canvas></div></div>
  </div>
  <div class="card2">
    <div class="card2-head"><i class="bi bi-pie-chart text-primary"></i><h2>Report Mix</h2></div>
    <div class="card2-body"><div class="chart-box"><canvas id="mixChart"></canvas></div></div>
  </div>
</div>

<div class="grid-3">
  <div class="card2">
    <div class="card2-head"><i class="bi bi-clipboard-check text-primary"></i><h2>Work Status</h2></div>
    <div class="card2-body"><div class="chart-box sm"><canvas id="statusChart"></canvas></div></div>
  </div>
  <div class="card2">
    <div class="card2-head"><i class="bi bi-diagram-2 text-primary"></i><h2>Pipeline Steps</h2></div>
    <div class="card2-body"><div class="chart-box sm"><canvas id="pipeChart"></canvas></div></div>
  </div>
  <div class="card2">
    <div class="card2-head"><i class="bi bi-person-badge text-primary"></i><h2>Top Engineers</h2></div>
    <div class="card2-body">
      <?php if (!$engineers): ?><div class="t-empty">No data yet.</div><?php endif; ?>
      <?php foreach ($engineers as $name => $c): ?>
        <div class="bar-row">
          <div class="bl" title="<?= Admin::e($name) ?>"><?= Admin::e(snip($name, 16)) ?></div>
          <div class="bar-track"><div class="bar-fill" style="width: <?= max(6, round($c * 100 / $engMax)) ?>%"></div></div>
          <div class="bv"><?= $c ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="card2">
  <div class="card2-head"><i class="bi bi-clock-history text-primary"></i><h2>Recent Reports</h2>
    <span class="spacer"></span><a class="btn btn-ghost btn-sm" href="<?= Admin::BASE ?>/submissions.php">View all <i class="bi bi-arrow-right"></i></a></div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Project / Unit</th><th>Type</th><th>Step</th><th>Status</th><th>Engineer</th><th>Pipeline</th><th>When</th><th></th></tr></thead>
      <tbody>
      <?php if (!$recent): ?><tr><td colspan="8" class="t-empty">No reports yet.</td></tr><?php endif; ?>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td><a class="row-link" href="<?= Admin::BASE ?>/submission.php?id=<?= (int)$r['id'] ?>"><?= Admin::e(projectLabel($r)) ?></a></td>
          <td><span class="pill pill-muted"><?= Admin::e($r['site_type']) ?></span> <?= Admin::e($r['client_type']) ?></td>
          <td><?= Admin::e(snip($r['current_status'], 26)) ?: '—' ?></td>
          <td><?= Layout::statusBadge((string)$r['status']) ?></td>
          <td><?= Admin::e($r['engineer']) ?: '—' ?></td>
          <td><?= Layout::statusBadge((string)$r['overall_status']) ?></td>
          <td title="<?= Admin::e(fmtDateTime($r['created_at'])) ?>"><?= Admin::e(ago($r['created_at'])) ?></td>
          <td><a class="row-link" href="<?= Admin::BASE ?>/submission.php?id=<?= (int)$r['id'] ?>"><i class="bi bi-chevron-right"></i></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$js = '<script>
const BRAND="#2f81f7", GREEN="#16a34a", AMBER="#d97706", RED="#dc2626", SLATE="#94a3b8", PURPLE="#7c5cff";
Chart.defaults.font.family="Inter, system-ui, sans-serif";
Chart.defaults.color="#7b8aa0";
new Chart(document.getElementById("trendChart"), {
  type:"line",
  data:{labels:' . json_encode($trendLabels) . ',datasets:[{label:"Reports",data:' . json_encode($trendData) . ',
    borderColor:BRAND,backgroundColor:"rgba(47,129,247,.12)",fill:true,tension:.35,pointRadius:3,pointBackgroundColor:BRAND,borderWidth:2.5}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},
    scales:{y:{beginAtZero:true,ticks:{precision:0},grid:{color:"#eef2f8"}},x:{grid:{display:false}}}}
});
new Chart(document.getElementById("mixChart"), {
  type:"doughnut",
  data:{labels:' . json_encode(array_keys($bySite)) . ',datasets:[
    {label:"Site type",data:' . json_encode(array_values($bySite)) . ',backgroundColor:[BRAND,PURPLE,SLATE,AMBER]}
  ]},
  options:{responsive:true,maintainAspectRatio:false,cutout:"58%",plugins:{legend:{position:"bottom",labels:{usePointStyle:true,padding:14}}}}
});
new Chart(document.getElementById("statusChart"), {
  type:"bar",
  data:{labels:' . json_encode(array_keys($byStatus)) . ',datasets:[{data:' . json_encode(array_values($byStatus)) . ',
    backgroundColor:[GREEN,AMBER,RED],borderRadius:6,barThickness:46}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},
    scales:{y:{beginAtZero:true,ticks:{precision:0},grid:{color:"#eef2f8"}},x:{grid:{display:false}}}}
});
new Chart(document.getElementById("pipeChart"), {
  type:"bar",
  data:{labels:' . json_encode(array_map(fn($s) => ucfirst(str_replace('_',' ',$s)), $steps)) . ',
    datasets:[
      {label:"Done",data:' . json_encode(array_map(fn($s) => $pipe[$s]['done'], $steps)) . ',backgroundColor:GREEN,borderRadius:4,stack:"s"},
      {label:"Skipped",data:' . json_encode(array_map(fn($s) => $pipe[$s]['skipped'], $steps)) . ',backgroundColor:SLATE,borderRadius:4,stack:"s"},
      {label:"Failed",data:' . json_encode(array_map(fn($s) => $pipe[$s]['failed'], $steps)) . ',backgroundColor:RED,borderRadius:4,stack:"s"}
    ]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:"bottom",labels:{usePointStyle:true,padding:12,boxWidth:8}}},
    scales:{x:{stacked:true,grid:{display:false}},y:{stacked:true,beginAtZero:true,ticks:{precision:0},grid:{color:"#eef2f8"}}}}
});
</script>';
Layout::foot($js);
