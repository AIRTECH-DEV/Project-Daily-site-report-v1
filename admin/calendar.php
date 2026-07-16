<?php
/**
 * Calendar — day-wise scheduled site work. Each report captures the next working
 * day's plan (payload tomorrowSteps) and, on newer reports, an explicit
 * nextStepStartDate; plus the project's tentative end date. This lays those out
 * on a month grid + an upcoming list so you can see when which work is scheduled
 * per site. Falls back to (visit date + 1 day) when no explicit start date.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';

$db = Admin::db();
$today = date('Y-m-d');

// month to render
$month = $_GET['m'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$first = DateTime::createFromFormat('Y-m-d', $month . '-01') ?: new DateTime('first day of this month');
$first->setTime(0, 0);

// ---- build events -------------------------------------------------------
// plan events keyed by project+date (latest report wins); end-date per project.
$plans = [];      // key => event
$endByProj = [];  // projectKey => event
$normDate = fn($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($v))) ? trim($v) : '';

foreach ($db->query("SELECT id, project, developer, building, flat_no, client_type, site_type, engineer, created_at, tentative_end, payload_json FROM submissions ORDER BY id ASC") as $r) {
    $pl = json_decode((string)$r['payload_json'], true) ?: [];

    // planned steps for the next working day
    $steps = $pl['tomorrowSteps'] ?? null;
    if (is_string($steps)) $steps = json_decode($steps, true);
    if (is_array($steps)) $steps = array_values(array_filter(array_map(fn($x) => trim((string)$x), $steps), fn($x) => $x !== ''));
    if (is_array($steps) && $steps) {
        $date = $normDate($pl['nextStepStartDate'] ?? '') ?: date('Y-m-d', strtotime($r['created_at'] . ' +1 day'));
        $plans[projectKey($r) . '|' . $date] = [
            'date' => $date, 'type' => 'plan', 'label' => projectLabel($r),
            'steps' => $steps, 'engineer' => (string)$r['engineer'], 'id' => (int)$r['id'],
            'explicit' => $normDate($pl['nextStepStartDate'] ?? '') !== '',
        ];
    }

    // project target end date (latest report per project wins)
    $end = $normDate($r['tentative_end']);
    if ($end) {
        $endByProj[projectKey($r)] = ['date' => $end, 'type' => 'end', 'label' => projectLabel($r), 'id' => (int)$r['id']];
    }
}

$byDate = [];   // Y-m-d => [events]
foreach ($plans as $e)     $byDate[$e['date']][] = $e;
foreach ($endByProj as $e) $byDate[$e['date']][] = $e;
foreach ($byDate as $d => &$list) {
    usort($list, fn($a, $b) => ($a['type'] === $b['type']) ? 0 : ($a['type'] === 'plan' ? -1 : 1));
}
unset($list);

// upcoming (today onward) for the side list
$upcoming = [];
foreach ($byDate as $d => $list) {
    if ($d >= $today) foreach ($list as $e) $upcoming[] = $e;
}
usort($upcoming, fn($a, $b) => $a['date'] <=> $b['date']);
$upcoming = array_slice($upcoming, 0, 16);

// grid geometry: 6 weeks from the Sunday on/before the 1st
$firstDow    = (int)$first->format('w');
$daysInMonth = (int)$first->format('t');
$gridStart   = (clone $first)->modify('-' . $firstDow . ' days');
$prevM = (clone $first)->modify('-1 month')->format('Y-m');
$nextM = (clone $first)->modify('+1 month')->format('Y-m');
$planCount = 0;
foreach ($plans as $e) if (strpos($e['date'], $month) === 0) $planCount++;

require __DIR__ . '/inc/layout.php';
Layout::head('Calendar', 'calendar');
?>
<div class="card2">
  <div class="card2-head">
    <div class="cal-toolbar">
      <a class="cal-nav" href="?m=<?= $prevM ?>" title="Previous month"><i class="bi bi-chevron-left"></i></a>
      <span class="cal-title"><?= Admin::e($first->format('F Y')) ?></span>
      <a class="cal-nav" href="?m=<?= $nextM ?>" title="Next month"><i class="bi bi-chevron-right"></i></a>
      <a class="btn btn-ghost btn-sm" href="?m=<?= date('Y-m') ?>">Today</a>
    </div>
    <span class="spacer"></span>
    <div class="cal-legend">
      <span class="sw"><i style="background:#cfe0fb"></i> Planned work</span>
      <span class="sw"><i style="background:#fbe3bd"></i> Target end</span>
      <span style="color:#8190a5"><?= $planCount ?> planned this month</span>
    </div>
  </div>
  <div class="card2-body">
    <div class="cal-scroll">
      <div class="cal-grid">
        <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dow): ?>
          <div class="cal-dow"><?= $dow ?></div>
        <?php endforeach; ?>

        <?php
        $cur = clone $gridStart;
        for ($i = 0; $i < 42; $i++):
            $d = $cur->format('Y-m-d');
            $inMonth = $cur->format('Y-m') === $month;
            $evs = $byDate[$d] ?? [];
            $cls = 'cal-cell' . ($inMonth ? '' : ' other') . ($d === $today ? ' today' : '');
        ?>
          <div class="<?= $cls ?>">
            <div class="cal-daynum"><?= (int)$cur->format('j') ?></div>
            <?php foreach ($evs as $k => $e): $extra = $k >= 3 ? ' cal-ev-extra' : ''; ?>
              <?php if ($e['type'] === 'plan'): ?>
                <a class="cal-ev<?= $extra ?>" href="<?= Admin::BASE ?>/submission.php?id=<?= $e['id'] ?>" title="<?= Admin::e($e['label'] . ' — ' . implode(', ', $e['steps'])) ?>">
                  <span class="evp"><?= Admin::e($e['label']) ?></span>
                  <span class="evs"><?= Admin::e(implode(', ', $e['steps'])) ?></span>
                </a>
              <?php else: ?>
                <a class="cal-ev end<?= $extra ?>" href="<?= Admin::BASE ?>/submission.php?id=<?= $e['id'] ?>" title="Target end — <?= Admin::e($e['label']) ?>">
                  <span class="evp"><i class="bi bi-flag"></i> <?= Admin::e($e['label']) ?></span>
                  <span class="evs">Target end</span>
                </a>
              <?php endif; ?>
            <?php endforeach; ?>
            <?php if (count($evs) > 3): ?><button type="button" class="cal-more" data-n="<?= count($evs) - 3 ?>" onclick="calMore(this)">+<?= count($evs) - 3 ?> more</button><?php endif; ?>
          </div>
        <?php $cur->modify('+1 day'); endfor; ?>
      </div>
    </div>
  </div>
</div>

<div class="card2">
  <div class="card2-head"><i class="bi bi-calendar-week text-primary"></i><h2>Upcoming Scheduled Work</h2>
    <span class="sub">from today</span></div>
  <div class="card2-body">
    <?php if (!$upcoming): ?>
      <div class="t-empty">No upcoming work scheduled. Plans appear here from each report's "planned for tomorrow" steps.</div>
    <?php endif; ?>
    <?php foreach ($upcoming as $e): $dt = strtotime($e['date']); ?>
      <div class="up-item">
        <div class="up-date">
          <div class="d"><?= date('d', $dt) ?></div>
          <div class="m"><?= date('M', $dt) ?></div>
          <div class="w"><?= date('D', $dt) ?><?= $e['date'] === $today ? ' •' : '' ?></div>
        </div>
        <div class="up-body">
          <?php if ($e['type'] === 'plan'): ?>
            <div class="up-proj"><?= Admin::e($e['label']) ?><?= $e['engineer'] ? ' <span class="who">· ' . Admin::e($e['engineer']) . '</span>' : '' ?><?= $e['explicit'] ? '' : ' <span class="who">(next day)</span>' ?></div>
            <div class="up-steps">
              <?php foreach ($e['steps'] as $st): ?><span class="pill pill-type"><?= Admin::e($st) ?></span><?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="up-proj"><i class="bi bi-flag text-warning"></i> <?= Admin::e($e['label']) ?> <span class="who">· target project end</span></div>
          <?php endif; ?>
        </div>
        <a class="btn btn-ghost btn-sm" href="<?= Admin::BASE ?>/submission.php?id=<?= $e['id'] ?>" style="align-self:center">Open</a>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php Layout::foot('<script>function calMore(b){var c=b.closest(".cal-cell");var open=c.classList.toggle("evs-open");b.textContent=open?"− less":("+"+b.dataset.n+" more");}</script>');
