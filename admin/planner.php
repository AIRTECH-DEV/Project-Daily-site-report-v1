<?php
/**
 * Daily Plan — what each PE has planned for tomorrow (and today), pulled from
 * every report's "planned for tomorrow" steps + next-step start date. Also flags
 * DELAYED plans: a planned date that passed with no report since — those also
 * raise a 'plan_missed' alert in Notifications, so the admin is notified.
 * Read-only over submissions; latest report per project wins.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';
Admin::autoSync();

$db       = Admin::db();
$today    = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$normDate = fn($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($v))) ? trim($v) : '';

// ---- latest plan per project ------------------------------------------------
$plans = [];   // project_key => plan (ascending id → latest report overwrites)
foreach ($db->query(
    "SELECT id, project, developer, building, flat_no, client_type, site_type, engineer, status, created_at, payload_json
     FROM submissions ORDER BY id ASC") as $r) {
    $pl = json_decode((string)$r['payload_json'], true) ?: [];
    $steps = $pl['tomorrowSteps'] ?? null;
    if (is_string($steps)) $steps = json_decode($steps, true);
    if (!is_array($steps)) continue;
    $steps = array_values(array_filter(array_map(fn($x) => trim((string)$x), $steps), fn($x) => $x !== ''));
    if (!$steps) continue;

    $explicit = $normDate($pl['nextStepStartDate'] ?? '');
    $date     = $explicit ?: date('Y-m-d', strtotime($r['created_at'] . ' +1 day'));
    $plans[projectKey($r)] = [
        'pk'         => projectKey($r),
        'label'      => projectLabel($r),
        'steps'      => $steps,
        'date'       => $date,
        'explicit'   => $explicit !== '',
        'pe'         => trim((string)$r['engineer']) ?: '—',
        'id'         => (int)$r['id'],
        'reportDate' => date('Y-m-d', strtotime((string)$r['created_at'])),
        'status'     => (string)$r['status'],
    ];
}

// ---- bucket by date relative to today --------------------------------------
$delayed = []; $bToday = []; $bTomorrow = []; $bLater = [];
foreach ($plans as $p) {
    if      ($p['date'] <  $today)      $delayed[]   = $p;
    elseif  ($p['date'] === $today)     $bToday[]    = $p;
    elseif  ($p['date'] === $tomorrow)  $bTomorrow[] = $p;
    else                                $bLater[]    = $p;
}
usort($delayed, fn($a, $b) => $a['date'] <=> $b['date']);   // most overdue first
usort($bLater,  fn($a, $b) => $a['date'] <=> $b['date']);

$groupByPe = function (array $list): array {
    $g = [];
    foreach ($list as $p) $g[$p['pe']][] = $p;
    ksort($g);
    return $g;
};
$peTomorrow = $groupByPe($bTomorrow);
$peToday    = $groupByPe($bToday);

$peCountTom = count($peTomorrow);
$daysLate   = fn(string $d) => max(0, (int)floor((strtotime($today) - strtotime($d)) / 86400));

require __DIR__ . '/inc/layout.php';
Layout::head('Daily Plan', 'planner');

/** One project plan line: steps + PE + planned date + open link. */
$planRow = function (array $p, bool $showPe = true) use ($tomorrow, $today) {
    $when = $p['date'] === $today ? 'today' : ($p['date'] === $tomorrow ? 'tomorrow' : fmtDate($p['date']));
    ob_start(); ?>
    <div class="plan-row">
      <a class="row-link" style="font-weight:600;min-width:170px" href="<?= Admin::BASE ?>/project.php?key=<?= urlencode($p['pk']) ?>"><?= Admin::e($p['label']) ?></a>
      <div class="up-steps" style="flex:1"><?php foreach ($p['steps'] as $st): ?><span class="pill pill-type"><?= Admin::e($st) ?></span><?php endforeach; ?></div>
      <?php if ($showPe): ?><span class="who"><i class="bi bi-person"></i> <?= Admin::e($p['pe']) ?></span><?php endif; ?>
      <span class="who"><i class="bi bi-calendar-event"></i> <?= Admin::e($when) ?><?= $p['explicit'] ? '' : ' (next day)' ?></span>
      <a class="btn btn-ghost btn-sm" href="<?= Admin::BASE ?>/submission.php?id=<?= $p['id'] ?>">Open</a>
    </div>
    <?php return ob_get_clean();
};

/** A PE-grouped block: PE header + their plan rows. */
$peBlock = function (array $grouped) use ($planRow) {
    foreach ($grouped as $pe => $list) {
        $init = strtoupper(substr($pe === '—' ? '?' : $pe, 0, 1));
        echo '<div class="pe-group"><div class="pe-name"><span class="avatar-sm">' . Admin::e($init) . '</span>'
           . Admin::e($pe) . ' <span class="who">· ' . count($list) . ' site(s)</span></div>';
        foreach ($list as $p) echo $planRow($p, false);
        echo '</div>';
    }
};
?>
<div class="kpi-grid">
  <div class="kpi"><div class="kpi-ico ic-blue"><i class="bi bi-calendar2-check"></i></div><div class="kpi-label">Planned Tomorrow</div><div class="kpi-value"><?= count($bTomorrow) ?></div><div class="kpi-foot"><?= $peCountTom ?> PE(s) active</div></div>
  <div class="kpi"><div class="kpi-ico ic-green"><i class="bi bi-sun"></i></div><div class="kpi-label">Planned Today</div><div class="kpi-value"><?= count($bToday) ?></div><div class="kpi-foot">scheduled for today</div></div>
  <div class="kpi"><div class="kpi-ico ic-red"><i class="bi bi-exclamation-triangle"></i></div><div class="kpi-label">Delayed</div><div class="kpi-value"><?= count($delayed) ?></div><div class="kpi-foot">planned day passed</div></div>
  <div class="kpi"><div class="kpi-ico ic-slate"><i class="bi bi-calendar3-week"></i></div><div class="kpi-label">Upcoming Later</div><div class="kpi-value"><?= count($bLater) ?></div><div class="kpi-foot">future days</div></div>
</div>

<?php if ($delayed): ?>
<div class="card2 card-accent-red">
  <div class="card2-head"><i class="bi bi-exclamation-triangle text-danger"></i><h2>Delayed Work</h2>
    <span class="sub">(<?= count($delayed) ?>)</span>
    <span class="spacer"></span>
    <span class="who"><i class="bi bi-bell"></i> admin is alerted in Notifications</span>
  </div>
  <div class="card2-body">
    <?php foreach ($delayed as $p): $late = $daysLate($p['date']); ?>
      <div class="plan-row">
        <span class="pill pill-bad" style="min-width:78px;justify-content:center"><?= $late ?> day<?= $late === 1 ? '' : 's' ?> late</span>
        <a class="row-link" style="font-weight:600;min-width:160px" href="<?= Admin::BASE ?>/project.php?key=<?= urlencode($p['pk']) ?>"><?= Admin::e($p['label']) ?></a>
        <div class="up-steps" style="flex:1"><?php foreach ($p['steps'] as $st): ?><span class="pill pill-type"><?= Admin::e($st) ?></span><?php endforeach; ?></div>
        <span class="who"><i class="bi bi-person"></i> <?= Admin::e($p['pe']) ?></span>
        <span class="who"><i class="bi bi-calendar-event"></i> was <?= Admin::e(fmtDate($p['date'])) ?></span>
        <a class="btn btn-ghost btn-sm" href="<?= Admin::BASE ?>/submission.php?id=<?= $p['id'] ?>">Open</a>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card2 card-accent-purple">
  <div class="card2-head"><i class="bi bi-sun text-primary"></i><h2>Today — <?= Admin::e(fmtDate($today)) ?></h2><span class="sub">by PE</span></div>
  <div class="card2-body">
    <?php if (!$bToday): ?><div class="t-empty">Nothing scheduled for today.</div>
    <?php else: $peBlock($peToday); endif; ?>
  </div>
</div>

<div class="card2 card-accent-blue">
  <div class="card2-head"><i class="bi bi-calendar2-check text-primary"></i><h2>Tomorrow — <?= Admin::e(fmtDate($tomorrow)) ?></h2><span class="sub">by PE</span></div>
  <div class="card2-body">
    <?php if (!$bTomorrow): ?><div class="t-empty">No work planned for tomorrow yet. Plans appear here from each report's "planned for tomorrow" steps.</div>
    <?php else: $peBlock($peTomorrow); endif; ?>
  </div>
</div>

<?php if ($bLater): ?>
<div class="card2">
  <div class="card2-head"><i class="bi bi-calendar3-week text-primary"></i><h2>Upcoming (later days)</h2><span class="sub">(<?= count($bLater) ?>)</span></div>
  <div class="card2-body">
    <?php foreach ($bLater as $p) echo $planRow($p); ?>
  </div>
</div>
<?php endif; ?>
<?php Layout::foot();
