<?php
/**
 * Executive dashboard — portfolio view built on the synced `projects` + `alerts`
 * master tables. Headline lifecycle KPIs, an operational stat strip, the main
 * portfolio table (progress → risk), delivery coverage (reports covered, not raw
 * channel counts), and workload/compliance metrics that replace report-count
 * vanity numbers.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';
Admin::autoSync();
require __DIR__ . '/inc/layout.php';

$db = Admin::db();
$one = fn(string $sql, array $a = []) => (function () use ($db, $sql, $a) { $st = $db->prepare($sql); $st->execute($a); return $st->fetchColumn(); })();
$today = date('Y-m-d');

$projects = $db->query("SELECT * FROM projects")->fetchAll();
$lc = array_fill_keys(['Not Started','Active','At Risk','On Hold','Commissioning Pending','Commissioned','Closed'], 0);
foreach ($projects as $p) { if (isset($lc[$p['lifecycle']])) $lc[$p['lifecycle']]++; }
$portfolio = count($projects);
$activeN   = $lc['Active'] + $lc['At Risk'] + $lc['Commissioning Pending'];

// operational counts
$holdClient = 0; $holdVapl = 0; $overdue = 0; $noUpd24 = 0; $noUpd48 = 0; $unassigned = 0; $plannedToday = 0;
foreach ($projects as $p) {
    $liveLc = in_array($p['lifecycle'], ['Active','At Risk','On Hold','Commissioning Pending'], true);
    if ($p['lifecycle'] === 'On Hold') { (stripos((string)$p['hold_owner'], 'client') !== false) ? $holdClient++ : $holdVapl++; }
    if ($p['target_end'] && strtotime($p['target_end']) < strtotime($today) && !in_array($p['lifecycle'], ['Commissioned','Closed'], true)) $overdue++;
    if ($liveLc && $p['primary_pe'] === '') $unassigned++;
    if ($p['next_plan_date'] === $today) $plannedToday++;
    if ($liveLc && $p['last_report_at']) {
        $h = (time() - strtotime($p['last_report_at'])) / 3600;
        if ($h >= 48) $noUpd48++; elseif ($h >= 24) $noUpd24++;
    }
}
$visitsToday = (int)$one("SELECT COUNT(*) FROM submissions WHERE DATE(created_at)=?", [$today]);
$notifFail   = (int)$one("SELECT COUNT(*) FROM alerts WHERE status IN ('open','ack','snoozed') AND rule IN ('notify_missing','pipeline_fail')");

// delivery coverage: reports that got BOTH channels done / live reports
$liveReports = (int)$one("SELECT COUNT(*) FROM submissions WHERE overall_status IN ('done','partial')");
$coveredReports = (int)$one(
    "SELECT COUNT(*) FROM submissions s WHERE s.overall_status IN ('done','partial')
     AND EXISTS (SELECT 1 FROM process_log p WHERE p.submission_id=s.id AND p.step='email' AND p.status='done')
     AND EXISTS (SELECT 1 FROM process_log p WHERE p.submission_id=s.id AND p.step='whatsapp' AND p.status='done')"
);
$covPct = $liveReports > 0 ? round($coveredReports * 100 / $liveReports) : 0;

// contractor-need count (planned contractor-typical step, none on latest visit)
$stepContractor = []; $stepTotal = [];
foreach ($db->query("SELECT type, steps FROM visit_workers WHERE steps<>''") as $r) {
    foreach (array_filter(array_map('trim', explode(',', (string)$r['steps']))) as $st) {
        $k = stepKey($st); $stepTotal[$k] = ($stepTotal[$k] ?? 0) + 1;
        if ($r['type'] === 'Contractor') $stepContractor[$k] = ($stepContractor[$k] ?? 0) + 1;
    }
}
$conStep = fn($n) => ($stepTotal[stepKey($n)] ?? 0) >= 2 && ($stepContractor[stepKey($n)] ?? 0) / max(1, $stepTotal[stepKey($n)]) >= 0.5;
$conNeedByKey = [];
foreach ($projects as $p) {
    if (!in_array($p['lifecycle'], ['Active','At Risk','On Hold','Commissioning Pending'], true)) continue;
    $need = array_values(array_filter(array_filter(array_map('trim', explode(',', (string)$p['next_plan_steps']))), $conStep));
    if (!$need) continue;
    $hasCon = (int)$one("SELECT COUNT(*) FROM visit_workers WHERE submission_id=(SELECT MAX(submission_id) FROM visit_workers WHERE project_key=?) AND type='Contractor'", [$p['project_key']]);
    if (!$hasCon) $conNeedByKey[$p['project_key']] = true;
}
$conNeed = count($conNeedByKey);

// per-project risk from open alerts (max severity)
$riskByKey = [];
foreach ($db->query("SELECT project_key, severity FROM alerts WHERE status IN ('open','ack','snoozed') AND project_key<>''") as $r) {
    $rank = ['critical'=>3,'warning'=>2,'info'=>1][$r['severity']] ?? 0;
    if (($riskByKey[$r['project_key']] ?? 0) < $rank) $riskByKey[$r['project_key']] = $rank;
}

// workload + compliance metrics (replace top-engineers)
$peLoad = [];
foreach ($projects as $p) {
    if (in_array($p['lifecycle'], ['Active','At Risk','Commissioning Pending'], true) && $p['primary_pe']) $peLoad[$p['primary_pe']] = ($peLoad[$p['primary_pe']] ?? 0) + 1;
}
arsort($peLoad); $peMax = $peLoad ? max($peLoad) : 1;
$compliant = 0;
foreach ($projects as $p) {
    if (in_array($p['lifecycle'], ['Active','At Risk','Commissioning Pending'], true) && $p['last_report_at'] && (time() - strtotime($p['last_report_at'])) / 3600 <= 48) $compliant++;
}
$complPct = $activeN > 0 ? round($compliant * 100 / $activeN) : 100;
$holdAges = [];
foreach ($projects as $p) { if ($p['lifecycle'] === 'On Hold' && $p['hold_since']) $holdAges[] = (time() - strtotime($p['hold_since'])) / 86400; }
$avgHoldAge = $holdAges ? round(array_sum($holdAges) / count($holdAges), 1) : 0;
$avgProgress = 0; $nProg = 0;
foreach ($projects as $p) { if ($p['steps_total'] > 0 && !in_array($p['lifecycle'], ['Closed'], true)) { $avgProgress += $p['steps_done'] * 100 / $p['steps_total']; $nProg++; } }
$avgProgress = $nProg ? round($avgProgress / $nProg) : 0;

// trend (14 days)
$days = 14;
$trend = array_fill_keys(array_map(fn($i) => date('Y-m-d', strtotime("-$i day")), range($days - 1, 0)), 0);
$stt = $db->prepare("SELECT DATE(created_at) d, COUNT(*) c FROM submissions WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY DATE(created_at)");
$stt->execute([$days - 1]);
foreach ($stt as $r) { if (isset($trend[$r['d']])) $trend[$r['d']] = (int)$r['c']; }

// sort portfolio: risk desc, then last report
usort($projects, function ($a, $b) use ($riskByKey) {
    $ra = $riskByKey[$a['project_key']] ?? 0; $rb = $riskByKey[$b['project_key']] ?? 0;
    if ($ra !== $rb) return $rb <=> $ra;
    return strcmp((string)$b['last_report_at'], (string)$a['last_report_at']);
});

Layout::head('Dashboard', 'dashboard');

$riskPill = function (int $rank) {
    if ($rank >= 3) return '<span class="pill pill-bad"><span class="dot"></span>High</span>';
    if ($rank == 2) return '<span class="pill pill-warn"><span class="dot"></span>Medium</span>';
    return '<span class="pill pill-ok"><span class="dot"></span>Low</span>';
};
$forecast = function (array $p) {
    if (in_array($p['lifecycle'], ['Commissioned','Closed'], true)) return $p['commissioned_at'] ? fmtDate($p['commissioned_at']) : '—';
    if ($p['steps_done'] < 1 || !$p['first_report_at']) return '—';
    $elapsed = max(1, (time() - strtotime($p['first_report_at'])) / 86400);
    $frac = $p['steps_done'] / max(1, $p['steps_total']);
    if ($frac <= 0) return '—';
    $totalDays = $elapsed / $frac;
    return date('d M Y', strtotime($p['first_report_at']) + (int)($totalDays * 86400));
};
?>
<div class="kpi-grid">
  <div class="kpi grad grad-blue"><div class="kpi-top"><span class="kpi-ic"><i class="bi bi-collection"></i></span><span class="kpi-label">Portfolio</span></div><div class="kpi-value"><?= $portfolio ?></div><div class="kpi-foot"><?= $lc['Not Started'] ?> not started</div></div>
  <div class="kpi grad grad-cyan"><div class="kpi-top"><span class="kpi-ic"><i class="bi bi-lightning-charge"></i></span><span class="kpi-label">Active</span></div><div class="kpi-value"><?= $activeN ?></div><div class="kpi-foot"><?= $lc['Commissioning Pending'] ?> commissioning</div></div>
  <a class="kpi grad grad-coral" href="<?= Admin::BASE ?>/holds.php"><div class="kpi-top"><span class="kpi-ic"><i class="bi bi-pause-circle"></i></span><span class="kpi-label">On Hold</span></div><div class="kpi-value"><?= $lc['On Hold'] ?></div><div class="kpi-foot">Client <?= $holdClient ?> · VAPL <?= $holdVapl ?></div></a>
  <div class="kpi grad grad-violet"><div class="kpi-top"><span class="kpi-ic"><i class="bi bi-exclamation-triangle"></i></span><span class="kpi-label">At Risk</span></div><div class="kpi-value"><?= $lc['At Risk'] ?></div><div class="kpi-foot"><?= $overdue ?> overdue</div></div>
  <div class="kpi grad grad-pink"><div class="kpi-top"><span class="kpi-ic"><i class="bi bi-patch-check"></i></span><span class="kpi-label">Commissioned</span></div><div class="kpi-value"><?= $lc['Commissioned'] ?></div><div class="kpi-foot"><?= $lc['Closed'] ?> closed</div></div>
</div>

<div class="mini-stats">
  <a class="mini <?= $notifFail ? 'bad' : 'ok' ?>" href="<?= Admin::BASE ?>/notifications.php"><div class="mv"><?= $coveredReports ?>/<?= $liveReports ?></div><div class="ml">Reports notified (<?= $covPct ?>%)</div></a>
  <div class="mini <?= $overdue ? 'bad' : '' ?>"><div class="mv"><?= $overdue ?></div><div class="ml">Overdue</div></div>
  <div class="mini <?= $noUpd48 ? 'bad' : ($noUpd24 ? 'warn' : '') ?>"><div class="mv"><?= $noUpd24 ?> / <?= $noUpd48 ?></div><div class="ml">No update 24h / 48h</div></div>
  <div class="mini"><div class="mv"><?= $plannedToday ?></div><div class="ml">Work planned today</div></div>
  <div class="mini"><div class="mv"><?= $visitsToday ?></div><div class="ml">Visits today</div></div>
  <div class="mini <?= $unassigned ? 'warn' : '' ?>"><div class="mv"><?= $unassigned ?></div><div class="ml">Unassigned PE</div></div>
  <a class="mini <?= $conNeed ? 'warn' : '' ?>" href="<?= Admin::BASE ?>/workforce.php"><div class="mv"><?= $conNeed ?></div><div class="ml">Contractor gaps</div></a>
  <a class="mini <?= $notifFail ? 'bad' : '' ?>" href="<?= Admin::BASE ?>/notifications.php"><div class="mv"><?= $notifFail ?></div><div class="ml">Notif / pipeline fails</div></a>
</div>

<div class="card2">
  <div class="card2-head"><i class="bi bi-collection text-primary"></i><h2>Portfolio</h2>
    <span class="spacer"></span><a class="btn btn-ghost btn-sm" href="<?= Admin::BASE ?>/projects.php">All projects <i class="bi bi-arrow-right"></i></a></div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Project</th><th>Progress</th><th>Current Step</th><th>PE</th><th>Last Update</th><th>Next Work</th><th>Hold</th><th>Target</th><th>Forecast</th><th>Contr.</th><th>Risk</th></tr></thead>
      <tbody>
        <?php if (!$projects): ?><tr><td colspan="11" class="t-empty">No projects yet — submit a report or run a sync.</td></tr><?php endif; ?>
        <?php foreach (array_slice($projects, 0, 40) as $p):
          $pct = $p['steps_total'] > 0 ? round($p['steps_done'] * 100 / $p['steps_total']) : 0;
          $holdAge = $p['hold_since'] ? floor((time() - strtotime($p['hold_since'])) / 86400) : 0;
          $overdueRow = $p['target_end'] && strtotime($p['target_end']) < strtotime($today) && !in_array($p['lifecycle'], ['Commissioned','Closed'], true);
        ?>
          <tr>
            <td><a class="row-link" href="<?= Admin::BASE ?>/project.php?key=<?= urlencode($p['project_key']) ?>"><?= Admin::e(snip($p['label'], 26)) ?></a><div style="margin-top:3px"><?= Layout::lifecyclePill((string)$p['lifecycle']) ?></div></td>
            <td style="min-width:120px"><div class="bar-track" style="height:8px"><div class="bar-fill" style="width:<?= max(3,$pct) ?>%"></div></div><div style="font-size:11px;color:#8190a5;margin-top:3px"><?= $p['steps_done'] ?>/<?= $p['steps_total'] ?> · <?= $pct ?>%</div></td>
            <td style="font-size:12.5px"><?= Admin::e(snip($p['current_step'], 20)) ?: '—' ?></td>
            <td style="font-size:12.5px"><?= Admin::e($p['primary_pe']) ?: '<span class="pill pill-warn">none</span>' ?></td>
            <td style="font-size:12px" title="<?= Admin::e(fmtDateTime($p['last_report_at'])) ?>"><?= Admin::e(ago($p['last_report_at'])) ?></td>
            <td style="font-size:12px"><?= Admin::e(snip($p['next_plan_steps'], 22)) ?: '—' ?><?= $p['next_plan_date'] ? '<br><span style="color:#8190a5">' . Admin::e(fmtDate($p['next_plan_date'])) . '</span>' : '' ?></td>
            <td style="font-size:12px"><?= $p['hold_owner'] ? '<span class="pill pill-' . partyTone((string)$p['hold_owner']) . '">' . Admin::e($p['hold_owner']) . ($holdAge ? " {$holdAge}d" : '') . '</span>' : '—' ?></td>
            <td style="font-size:12px;<?= $overdueRow ? 'color:#dc2626;font-weight:700' : '' ?>"><?= Admin::e(fmtDate($p['target_end'])) ?></td>
            <td style="font-size:12px;color:#5b6b82"><?= Admin::e($forecast($p)) ?></td>
            <td><?= isset($conNeedByKey[$p['project_key']]) ? '<span class="pill pill-warn">need</span>' : '' ?></td>
            <td><?= $riskPill($riskByKey[$p['project_key']] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid-2">
  <div class="card2">
    <div class="card2-head"><h2>Report Volume</h2><span class="sub">last 14 days</span></div>
    <div class="card2-body"><div class="chart-box"><canvas id="trendChart"></canvas></div></div>
  </div>
  <div class="card2">
    <div class="card2-head"><h2>Portfolio by Lifecycle</h2></div>
    <div class="card2-body">
      <div class="donut-wrap"><div class="chart-box"><canvas id="lcChart"></canvas></div>
        <div class="donut-center"><div class="dc-num"><?= $portfolio ?></div><div class="dc-lbl">Projects</div></div></div>
    </div>
  </div>
</div>

<div class="grid-3">
  <div class="card2">
    <div class="card2-head"><i class="bi bi-person-badge text-primary"></i><h2>Active PE Workload</h2></div>
    <div class="card2-body">
      <?php if (!$peLoad): ?><div class="t-empty">No active PE load.</div><?php endif; ?>
      <?php foreach ($peLoad as $pe => $c): ?>
        <div class="bar-row"><div class="bl" style="width:110px"><?= Admin::e(snip($pe, 14)) ?></div><div class="bar-track"><div class="bar-fill" style="width:<?= max(8, round($c * 100 / $peMax)) ?>%"></div></div><div class="bv"><?= $c ?></div></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card2">
    <div class="card2-head"><i class="bi bi-speedometer2 text-primary"></i><h2>Delivery &amp; Compliance</h2></div>
    <div class="card2-body">
      <div class="bar-row"><div class="bl" style="width:150px">Notification coverage</div><div class="bar-track"><div class="bar-fill" style="width:<?= max(3,$covPct) ?>%;background:<?= $covPct>=80?'#16a34a':($covPct>=50?'#d97706':'#dc2626') ?>"></div></div><div class="bv"><?= $covPct ?>%</div></div>
      <div class="bar-row"><div class="bl" style="width:150px">On-time updates (48h)</div><div class="bar-track"><div class="bar-fill" style="width:<?= max(3,$complPct) ?>%;background:<?= $complPct>=80?'#16a34a':'#d97706' ?>"></div></div><div class="bv"><?= $complPct ?>%</div></div>
      <div class="bar-row"><div class="bl" style="width:150px">Avg progress (open)</div><div class="bar-track"><div class="bar-fill" style="width:<?= max(3,$avgProgress) ?>%"></div></div><div class="bv"><?= $avgProgress ?>%</div></div>
    </div>
  </div>
  <div class="card2">
    <div class="card2-head"><i class="bi bi-clipboard-data text-primary"></i><h2>At a Glance</h2></div>
    <div class="card2-body">
      <dl class="def-list">
        <dt>Delayed projects</dt><dd><?= $overdue ?></dd>
        <dt>Avg open-hold age</dt><dd><?= $avgHoldAge ?> day(s)</dd>
        <dt>Projects commissioned</dt><dd><?= $lc['Commissioned'] ?></dd>
        <dt>Unassigned / gaps</dt><dd><?= $unassigned ?> PE · <?= $conNeed ?> contractor</dd>
      </dl>
    </div>
  </div>
</div>

<?php
$js = '<script>
Chart.defaults.font.family="Inter, system-ui, sans-serif"; Chart.defaults.color="#7b8aa0";
new Chart(document.getElementById("trendChart"),{type:"line",
 data:{labels:' . json_encode(array_map(fn($d) => date('d M', strtotime($d)), array_keys($trend))) . ',datasets:[{label:"Reports",data:' . json_encode(array_values($trend)) . ',borderColor:"#2f81f7",backgroundColor:"rgba(47,129,247,.12)",fill:true,tension:.35,pointRadius:3,pointBackgroundColor:"#2f81f7",borderWidth:2.5}]},
 options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0},grid:{color:"#eef2f8"}},x:{grid:{display:false}}}}});
new Chart(document.getElementById("lcChart"),{type:"doughnut",
 data:{labels:' . json_encode(array_keys(array_filter($lc))) . ',datasets:[{data:' . json_encode(array_values(array_filter($lc))) . ',backgroundColor:["#94a3b8","#2f81f7","#d97706","#dc2626","#7c5cff","#16a34a","#334155"],borderWidth:0,hoverOffset:6}]},
 options:{responsive:true,maintainAspectRatio:false,cutout:"70%",plugins:{legend:{position:"bottom",labels:{usePointStyle:true,padding:12,boxWidth:8,font:{size:11}}}}}});
</script>';
Layout::foot($js);
