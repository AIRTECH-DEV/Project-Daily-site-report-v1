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
$pendN   = (int)$one("SELECT COUNT(*) FROM submissions WHERE status='Pending'");

// current holds at project level (latest report per project == Hold) — matches holds.php
$curHold = 0; $seenH = [];
foreach ($db->query("SELECT client_type, developer, building, flat_no, project, status FROM submissions ORDER BY id DESC") as $r) {
    $k = projectKey($r);
    if (isset($seenH[$k])) continue;
    $seenH[$k] = true;
    if ($r['status'] === 'Hold') $curHold++;
}

$stepDone   = (int)$one("SELECT COUNT(*) FROM process_log WHERE status='done'");
$stepFailed = (int)$one("SELECT COUNT(*) FROM process_log WHERE status='failed'");
$succRate   = ($stepDone + $stepFailed) > 0 ? round($stepDone * 100 / ($stepDone + $stepFailed)) : 100;
$notifSent  = (int)$one("SELECT COUNT(*) FROM process_log WHERE step IN ('email','whatsapp') AND status='done'");

$wkDelta = $prevWk > 0 ? round(($week - $prevWk) * 100 / $prevWk) : ($week > 0 ? 100 : 0);

// ---- Trend: reports per day, selectable window ----
$days = (int)($_GET['days'] ?? 14);
if (!in_array($days, [7, 14, 30], true)) $days = 14;
$trend = array_fill_keys(
    array_map(fn($i) => date('Y-m-d', strtotime("-$i day")), range($days - 1, 0)),
    0
);
$st = $db->prepare("SELECT DATE(created_at) d, COUNT(*) c FROM submissions WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY DATE(created_at)");
$st->execute([$days - 1]);
foreach ($st as $r) { if (isset($trend[$r['d']])) $trend[$r['d']] = (int)$r['c']; }
$trendLabels = array_map(fn($d) => date('d M', strtotime($d)), array_keys($trend));
$trendData   = array_values($trend);

// ---- Distributions ----
$bySite = []; foreach ($db->query("SELECT site_type, COUNT(*) c FROM submissions GROUP BY site_type") as $r) $bySite[$r['site_type'] ?: 'Unknown'] = (int)$r['c'];
$byClient = []; foreach ($db->query("SELECT client_type, COUNT(*) c FROM submissions GROUP BY client_type") as $r) $byClient[$r['client_type'] ?: 'Unknown'] = (int)$r['c'];
$byStatus = ['Done' => 0, 'Pending' => 0, 'Hold' => 0];
foreach ($db->query("SELECT status, COUNT(*) c FROM submissions WHERE status<>'' GROUP BY status") as $r) $byStatus[$r['status']] = (int)$r['c'];

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
  <div class="kpi grad grad-blue">
    <div class="kpi-top"><span class="kpi-ic"><i class="bi bi-file-earmark-text"></i></span><span class="kpi-label">Total Reports</span></div>
    <div class="kpi-value"><?= $total ?></div>
    <div class="kpi-foot"><i class="bi bi-arrow-up-short"></i> <?= $today ?> today</div></div>

  <div class="kpi grad grad-cyan">
    <div class="kpi-top"><span class="kpi-ic"><i class="bi bi-calendar-week"></i></span><span class="kpi-label">This Week</span></div>
    <div class="kpi-value"><?= $week ?></div>
    <div class="kpi-foot"><i class="bi bi-arrow-<?= $wkDelta >= 0 ? 'up' : 'down' ?>-short"></i> <?= abs($wkDelta) ?>% vs last week</div></div>

  <div class="kpi grad grad-violet">
    <div class="kpi-top"><span class="kpi-ic"><i class="bi bi-briefcase"></i></span><span class="kpi-label">Active Projects</span></div>
    <div class="kpi-value"><?= $projects ?></div>
    <div class="kpi-foot"><?= $genProj ?> general · <?= $devUnit ?> dev units</div></div>

  <a class="kpi grad grad-coral" href="<?= Admin::BASE ?>/holds.php">
    <div class="kpi-top"><span class="kpi-ic"><i class="bi bi-pause-circle"></i></span><span class="kpi-label">On Hold</span></div>
    <div class="kpi-value"><?= $curHold ?></div>
    <div class="kpi-foot">View details <i class="bi bi-arrow-right"></i></div></a>

  <div class="kpi grad grad-pink">
    <div class="kpi-top"><span class="kpi-ic"><i class="bi bi-bell"></i></span><span class="kpi-label">Notifications</span></div>
    <div class="kpi-value"><?= $notifSent ?></div>
    <div class="kpi-foot">email + WhatsApp sent</div></div>
</div>

<?php
$siteTotal = array_sum($bySite);
$mixColors = ['#2f81f7', '#7c5cff', '#94a3b8', '#d97706'];
$legend = [];
$ci = 0;
foreach ($bySite as $name => $c) {
    $pct = $siteTotal > 0 ? round($c * 100 / $siteTotal) : 0;
    $legend[] = ['label' => $name, 'pct' => $pct, 'color' => $mixColors[$ci % count($mixColors)]];
    $ci++;
}
?>
<div class="grid-2">
  <div class="card2">
    <div class="card2-head"><h2>Report Volume</h2><span class="spacer"></span>
      <select class="card-select" onchange="location.href='?days='+this.value">
        <?php foreach ([7 => 'Last 7 days', 14 => 'Last 14 days', 30 => 'Last 30 days'] as $v => $t): ?>
          <option value="<?= $v ?>" <?= $days === $v ? 'selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="card2-body"><div class="chart-box"><canvas id="trendChart"></canvas></div></div>
  </div>
  <div class="card2">
    <div class="card2-head"><h2>Report Mix</h2></div>
    <div class="card2-body">
      <div class="donut-wrap">
        <div class="chart-box"><canvas id="mixChart"></canvas></div>
        <div class="donut-center"><div class="dc-num"><?= $siteTotal ?></div><div class="dc-lbl">Total</div></div>
      </div>
      <div class="donut-legend">
        <?php foreach ($legend as $l): ?>
          <span class="dl"><span class="dot" style="background:<?= $l['color'] ?>"></span><?= Admin::e($l['label']) ?> (<?= $l['pct'] ?>%)</span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="grid-2">
  <div class="card2">
    <div class="card2-head"><i class="bi bi-clipboard-check text-primary"></i><h2>Work Status</h2></div>
    <div class="card2-body"><div class="chart-box sm"><canvas id="statusChart"></canvas></div></div>
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
          <td><span class="pill pill-type"><?= Admin::e($r['site_type']) ?></span></td>
          <td><?= Admin::e(snip($r['current_status'], 26)) ?: '—' ?></td>
          <td><?= Layout::statusBadge((string)$r['status']) ?></td>
          <td><?= Admin::e($r['engineer']) ?: '—' ?></td>
          <td><?= Layout::pipelinePill((string)$r['overall_status']) ?></td>
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
    {label:"Site type",data:' . json_encode(array_values($bySite)) . ',backgroundColor:' . json_encode($mixColors) . ',borderWidth:0,hoverOffset:6}
  ]},
  options:{responsive:true,maintainAspectRatio:false,cutout:"72%",plugins:{legend:{display:false}}}
});
new Chart(document.getElementById("statusChart"), {
  type:"bar",
  data:{labels:' . json_encode(array_keys($byStatus)) . ',datasets:[{data:' . json_encode(array_values($byStatus)) . ',
    backgroundColor:[GREEN,AMBER,RED],borderRadius:6,barThickness:46}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},
    scales:{y:{beginAtZero:true,ticks:{precision:0},grid:{color:"#eef2f8"}},x:{grid:{display:false}}}}
});
</script>';
Layout::foot($js);
