<?php
/**
 * Performance & Incentive — the scorecard tab.
 *
 * Answers, for one scoring window:
 *   • Did each project finish before its end date?  Project START comes from the
 *     PMS sheet ("Marking" → Start Date); FINISH from the final Commissioning
 *     step's End Date; TARGET from "Tentitive Project End date".
 *   • Which PE and which VAPL worker earned incentive — ranked, scored, and with
 *     a ₹ pool split proportionally to score.
 *   • Which contractor works hardest and finishes on time (evaluation only —
 *     contractors are NOT paid incentive here).
 *
 * Read-only over the report pipeline. The "Refresh from PMS sheets" button reads
 * the progress sheets; it never writes to them.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/Perf.php';
Admin::autoSync();

$db = Admin::db();
Perf::ensureSchema($db);

$flash = ''; $flashKind = 'ok'; $syncStats = null;

/* ---------------- actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Admin::requireEditor();
    if (!Admin::checkCsrf()) {
        http_response_code(400);
        exit('Bad CSRF token.');
    }
    $act = $_POST['action'] ?? '';

    if ($act === 'sync_sheets') {
        $syncStats = Perf::syncSheets($db);
        Admin::audit('perf_sheet_sync', 'projects', null, '', json_encode(['matched' => $syncStats['matched'] ?? 0]));
        $flash = 'Read ' . (int)($syncStats['rows'] ?? 0) . ' sheet rows · matched ' . (int)($syncStats['matched'] ?? 0)
               . ' projects · ' . (int)($syncStats['with_start'] ?? 0) . ' with a start date · '
               . (int)($syncStats['with_end'] ?? 0) . ' finished.';
        if (!empty($syncStats['warnings'])) {
            $flashKind = 'warn';
        }
    } elseif ($act === 'save_opts') {
        $ov = Admin::overrides();
        $perf = is_array($ov['perf'] ?? null) ? $ov['perf'] : [];
        foreach (array_keys(Perf::DEFAULTS) as $k) {
            if (isset($_POST[$k]) && is_numeric($_POST[$k])) {
                $perf[$k] = (float)$_POST[$k];
            }
        }
        $ov['perf'] = $perf;
        Admin::saveOverrides($ov);
        Admin::audit('perf_settings', 'overrides', null, '', json_encode($perf));
        $flash = 'Incentive settings saved.';
    }
}

/* ---------------- data ---------------- */
$opt = Perf::opts(Admin::overrides());
if (isset($_GET['days']) && is_numeric($_GET['days'])) {
    $opt['window_days'] = max(1, (int)$_GET['days']);      // preview a period without saving
}
$A   = Perf::analyse($db, $opt);
$T   = $A['totals'];
$last = Perf::lastSheetSync();

$money  = fn($v) => '₹' . number_format((float)$v, 0);
$pctTxt = fn($v) => $v === null ? '—' : (int)$v . '%';
$gradePill = function (string $g): string {
    $cls = ['A' => 'pill-ok', 'B' => 'pill-info', 'C' => 'pill-warn', 'D' => 'pill-bad'][$g] ?? 'pill-muted';
    return '<span class="pill ' . $cls . '" title="' . Admin::e(Perf::gradeLabel($g)) . '">' . $g . '</span>';
};
$scoreBar = function (float $s): string {
    $col = $s >= 85 ? '#16a34a' : ($s >= 70 ? '#2563eb' : ($s >= 55 ? '#d97706' : '#dc2626'));
    return '<div class="sc-wrap"><div class="sc-track"><div class="sc-fill" style="width:' . max(2, min(100, $s)) . '%;background:' . $col . '"></div></div><b class="sc-num">' . number_format($s, 1) . '</b></div>';
};
$verdictPill = function (string $v): string {
    $map = ['On time' => 'pill-ok', 'Late' => 'pill-bad', 'Overdue' => 'pill-bad',
            'Running' => 'pill-info', 'Done (no target)' => 'pill-muted', 'No dates' => 'pill-muted'];
    return '<span class="pill ' . ($map[$v] ?? 'pill-muted') . '">' . Admin::e($v) . '</span>';
};
$startNote = ['marking_start' => 'Marking · Start Date', 'marking_end' => 'Marking · End Date',
              'ls_delivery' => 'LS Material Delivery', 'earliest_step' => 'earliest step start'];
// name => ₹ lookups for the scorecard tables
$peMoney = []; foreach ($A['pe_share']['eligible'] as $e)     $peMoney[$e['name']] = $e['amount'];
$wkMoney = []; foreach ($A['worker_share']['eligible'] as $e) $wkMoney[$e['name']] = $e['amount'];

require __DIR__ . '/inc/layout.php';
Layout::head('Performance & Incentive', 'performance');
?>
<style>
.sc-wrap{display:flex;align-items:center;gap:8px;min-width:150px}
.sc-track{flex:1;height:8px;border-radius:6px;background:#eef2f7;overflow:hidden;min-width:80px}
.sc-fill{height:100%;border-radius:6px}
.sc-num{font-size:12.5px;color:#33415a;width:38px;text-align:right}
.pf-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:14px}
.pf-seg{display:inline-flex;border:1px solid #dbe3ec;border-radius:8px;overflow:hidden}
.pf-seg a{padding:6px 12px;font-size:13px;color:#44546b;text-decoration:none;border-right:1px solid #dbe3ec}
.pf-seg a:last-child{border-right:0}
.pf-seg a.on{background:#2563eb;color:#fff}
.pf-note{font-size:12.5px;color:#6b7a90}
.pf-flash{padding:10px 14px;border-radius:8px;font-size:13.5px;margin-bottom:14px}
.pf-flash.ok{background:#eaf7ef;color:#166534;border:1px solid #bfe6cd}
.pf-flash.warn{background:#fef6e7;color:#92400e;border:1px solid #f5dfae}
.pf-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.pf-grid label{display:block;font-size:12px;color:#6b7a90;margin-bottom:4px}
.pf-grid .inp{width:100%}
.pf-money{font-weight:700;color:#166534;white-space:nowrap}
.pf-why{font-size:12.5px;color:#8a94a6}
.pf-legend{display:flex;flex-wrap:wrap;gap:14px;font-size:12.5px;color:#5b6b82;margin-top:10px}
.pf-legend b{color:#33415a}
.table-wrap{overflow-x:auto}
</style>

<?php if ($flash): ?>
  <div class="pf-flash <?= $flashKind ?>"><?= Admin::e($flash) ?></div>
<?php endif; ?>
<?php if (!empty($syncStats['warnings'])): ?>
  <div class="pf-flash warn">
    <b>Sheet warnings:</b>
    <?php foreach (array_slice($syncStats['warnings'], 0, 6) as $w): ?>
      <div>· <?= Admin::e($w) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="pf-bar">
  <div class="pf-seg">
    <?php foreach ([30 => '30d', 90 => '90d', 180 => '6m', 365 => '1y', 3650 => 'All'] as $d => $lbl): ?>
      <a class="<?= (int)$opt['window_days'] === $d ? 'on' : '' ?>" href="?days=<?= $d ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
  <span class="pf-note">Window <?= Admin::e(fmtDate($A['from'])) ?> → <?= Admin::e(fmtDate($A['to'])) ?></span>
  <span class="spacer" style="flex:1"></span>
  <span class="pf-note">
    PMS-sheet dates: <?= $last ? Admin::e(ago(date('Y-m-d H:i:s', $last))) : '<b>never synced</b>' ?>
  </span>
  <?php if (!Admin::isViewer()): ?>
  <form method="POST" style="margin:0">
    <?= Admin::csrfField() ?><input type="hidden" name="action" value="sync_sheets">
    <button class="btn btn-primary btn-sm"><i class="bi bi-arrow-repeat"></i> Refresh start dates from PMS sheets</button>
  </form>
  <?php endif; ?>
</div>

<div class="kpi-grid">
  <div class="kpi"><div class="kpi-ico ic-blue"><i class="bi bi-calendar-check"></i></div><div class="kpi-label">Projects with Start Date</div><div class="kpi-value"><?= (int)$T['with_start'] ?></div><div class="kpi-foot">of <?= (int)$T['projects'] ?> · from Marking step</div></div>
  <div class="kpi"><div class="kpi-ico <?= ($T['ontime_pct'] ?? 0) >= 80 ? 'ic-green' : 'ic-amber' ?>"><i class="bi bi-patch-check"></i></div><div class="kpi-label">On-Time Delivery</div><div class="kpi-value"><?= $pctTxt($T['ontime_pct']) ?></div><div class="kpi-foot"><?= (int)$T['ontime'] ?> of <?= (int)$T['finished'] ?> finished</div></div>
  <div class="kpi"><div class="kpi-ico <?= $T['overdue'] ? 'ic-red' : 'ic-green' ?>"><i class="bi bi-clock-history"></i></div><div class="kpi-label">Overdue Now</div><div class="kpi-value"><?= (int)$T['overdue'] ?></div><div class="kpi-foot">past target, not finished</div></div>
  <div class="kpi"><div class="kpi-ico ic-slate"><i class="bi bi-person-badge"></i></div><div class="kpi-label">Rated This Window</div><div class="kpi-value"><?= (int)$T['pe_count'] ?> / <?= (int)$T['worker_count'] ?></div><div class="kpi-foot">PEs / VAPL workers · <?= (int)$T['con_count'] ?> contractors</div></div>
</div>

<!-- ============ incentive settings ============ -->
<div class="card2">
  <div class="card2-head"><i class="bi bi-sliders text-primary"></i><h2>Incentive Settings</h2><span class="sub">saved to overrides.json</span></div>
  <div class="card2-body">
    <?php if (Admin::isViewer()): ?>
      <div class="t-empty">Read-only account — settings can only be changed by an admin.</div>
    <?php else: ?>
    <form method="POST">
      <?= Admin::csrfField() ?><input type="hidden" name="action" value="save_opts">
      <div class="pf-grid">
        <div><label>Scoring window (days)</label><input class="inp" type="number" min="1" name="window_days" value="<?= (int)$opt['window_days'] ?>"></div>
        <div><label>PE incentive pool (₹)</label><input class="inp" type="number" min="0" step="100" name="pe_pool" value="<?= (int)$opt['pe_pool'] ?>"></div>
        <div><label>VAPL worker pool (₹)</label><input class="inp" type="number" min="0" step="100" name="worker_pool" value="<?= (int)$opt['worker_pool'] ?>"></div>
        <div><label>Eligibility min score</label><input class="inp" type="number" min="0" max="100" name="min_score" value="<?= (int)$opt['min_score'] ?>"></div>
        <div><label>Worker min visits</label><input class="inp" type="number" min="0" name="min_visits" value="<?= (int)$opt['min_visits'] ?>"></div>
        <div><label>"Fast step" limit (days)</label><input class="inp" type="number" min="0" name="on_time_step_days" value="<?= (int)$opt['on_time_step_days'] ?>"></div>
      </div>
      <div style="margin-top:12px"><button class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Save settings</button></div>
    </form>
    <?php endif; ?>
    <div class="pf-legend">
      <span><b>Pools</b> split proportionally to score across everyone who clears the eligibility floor.</span>
      <span><b>Contractors are never paid from these pools</b> — they are scored for evaluation only.</span>
    </div>
  </div>
</div>

<!-- ============ PE scorecard ============ -->
<div class="card2">
  <div class="card2-head"><i class="bi bi-person-badge text-primary"></i><h2>Project Engineer Scorecard</h2>
    <span class="sub">incentive · pool <?= $money($A['pe_share']['pool']) ?> · <?= count($A['pe_share']['eligible']) ?> eligible</span>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr>
        <th>#</th><th>Project Engineer</th><th>Score</th><th>Grade</th>
        <th>Projects</th><th>Active</th><th>Reports</th><th>Days</th><th>Steps done</th>
        <th>On-time</th><th>48h compliance</th><th>Holds</th><th>Incentive</th>
      </tr></thead>
      <tbody>
        <?php if (!$A['pe']): ?><tr><td colspan="13" class="t-empty">No PE activity in this window.</td></tr><?php endif; ?>
        <?php foreach ($A['pe'] as $i => $r): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><b><?= Admin::e($r['name']) ?></b><?php if ($r['last']): ?><div class="pf-why">last report <?= Admin::e(fmtDate($r['last'])) ?></div><?php endif; ?></td>
            <td><?= $scoreBar((float)$r['score']) ?></td>
            <td><?= $gradePill($r['grade']) ?></td>
            <td><?= (int)$r['projects'] ?></td>
            <td><?= (int)$r['active'] ?><?= (int)$r['overdue'] ? ' <span class="pill pill-bad">' . (int)$r['overdue'] . ' overdue</span>' : '' ?></td>
            <td><?= (int)$r['reports'] ?></td>
            <td><?= (int)$r['days'] ?></td>
            <td><b><?= (int)$r['steps'] ?></b></td>
            <td><?= $r['ontime_pct'] === null ? '<span class="pf-why">no delivery yet</span>' : $pctTxt($r['ontime_pct']) . ' <span class="pf-why">(' . (int)$r['ontime'] . '/' . (int)$r['delivered'] . ')</span>' ?></td>
            <td><?= $pctTxt($r['comply_pct']) ?></td>
            <td><?= (int)$r['holds'] ?><?= (int)$r['hold_days'] ? ' <span class="pf-why">' . (int)$r['hold_days'] . 'd</span>' : '' ?></td>
            <td class="pf-money"><?= isset($peMoney[$r['name']]) ? $money($peMoney[$r['name']]) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($A['pe_share']['excluded']): ?>
  <div class="card2-body">
    <div class="pf-why"><b>Not eligible:</b>
      <?php foreach ($A['pe_share']['excluded'] as $i => $x): ?>
        <?= Admin::e($x['name']) ?> (<?= Admin::e($x['why']) ?>)<?= $i < count($A['pe_share']['excluded']) - 1 ? ' · ' : '' ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ============ VAPL worker scorecard ============ -->
<div class="card2">
  <div class="card2-head"><i class="bi bi-person-workspace text-primary"></i><h2>VAPL Worker Scorecard</h2>
    <span class="sub">incentive · pool <?= $money($A['worker_share']['pool']) ?> · <?= count($A['worker_share']['eligible']) ?> eligible</span>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr>
        <th>#</th><th>Worker</th><th>Score</th><th>Grade</th>
        <th>Visits</th><th>Days on site</th><th>Projects</th><th>Steps done</th><th>Steps / visit</th>
        <th>Fast steps</th><th>Avg step days</th><th>Last seen</th><th>Incentive</th>
      </tr></thead>
      <tbody>
        <?php if (!$A['workers']): ?><tr><td colspan="13" class="t-empty">No VAPL worker activity in this window.</td></tr><?php endif; ?>
        <?php foreach ($A['workers'] as $i => $r): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><b><?= Admin::e($r['name']) ?></b></td>
            <td><?= $scoreBar((float)$r['score']) ?></td>
            <td><?= $gradePill($r['grade']) ?></td>
            <td><?= (int)$r['visits'] ?></td>
            <td><?= (int)$r['days'] ?></td>
            <td><?= (int)$r['projects'] ?></td>
            <td><b><?= (int)$r['steps'] ?></b></td>
            <td><?= number_format((float)$r['per_visit'], 2) ?></td>
            <td><?= $pctTxt($r['fast_pct']) ?></td>
            <td><?= $r['avg_turn'] === null ? '—' : number_format((float)$r['avg_turn'], 1) . 'd' ?></td>
            <td><?= Admin::e(fmtDate($r['last'])) ?></td>
            <td class="pf-money"><?= isset($wkMoney[$r['name']]) ? $money($wkMoney[$r['name']]) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($A['worker_share']['excluded']): ?>
  <div class="card2-body">
    <div class="pf-why"><b>Not eligible:</b>
      <?php foreach ($A['worker_share']['excluded'] as $i => $x): ?>
        <?= Admin::e($x['name']) ?> (<?= Admin::e($x['why']) ?>)<?= $i < count($A['worker_share']['excluded']) - 1 ? ' · ' : '' ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ============ contractor evaluation ============ -->
<div class="card2">
  <div class="card2-head"><i class="bi bi-hammer text-primary"></i><h2>Contractor Evaluation</h2>
    <span class="sub">who works hardest &amp; finishes on time — no incentive paid</span>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr>
        <th>#</th><th>Contractor</th><th>Score</th><th>Grade</th>
        <th>Labour</th><th>Visits</th><th>Days</th><th>Projects</th><th>Steps done</th>
        <th>Steps / visit</th><th>On-time projects</th><th>Fast steps</th><th>Avg step days</th><th>Last seen</th>
      </tr></thead>
      <tbody>
        <?php if (!$A['contractors']): ?><tr><td colspan="14" class="t-empty">No contractor activity in this window.</td></tr><?php endif; ?>
        <?php foreach ($A['contractors'] as $i => $r): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><b><?= Admin::e($r['name']) ?></b></td>
            <td><?= $scoreBar((float)$r['score']) ?></td>
            <td><?= $gradePill($r['grade']) ?></td>
            <td><?= (int)$r['people'] ?></td>
            <td><?= (int)$r['visits'] ?></td>
            <td><?= (int)$r['days'] ?></td>
            <td><?= (int)$r['projects'] ?></td>
            <td><b><?= (int)$r['steps'] ?></b></td>
            <td><?= number_format((float)$r['per_visit'], 2) ?></td>
            <td><?= $r['ontime_pct'] === null ? '<span class="pf-why">none finished</span>' : $pctTxt($r['ontime_pct']) . ' <span class="pf-why">(' . (int)$r['ontime'] . '/' . (int)$r['delivered'] . ')</span>' ?></td>
            <td><?= $pctTxt($r['fast_pct']) ?></td>
            <td><?= $r['avg_turn'] === null ? '—' : number_format((float)$r['avg_turn'], 1) . 'd' ?></td>
            <td><?= Admin::e(fmtDate($r['last'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($A['con_labour']): ?>
<div class="card2">
  <div class="card2-head"><i class="bi bi-people text-primary"></i><h2>Contractor Labour — Individuals</h2><span class="sub">hardest-working hands on site · evaluation only</span></div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>#</th><th>Worker</th><th>Company</th><th>Score</th><th>Grade</th><th>Visits</th><th>Days</th><th>Projects</th><th>Steps done</th><th>Steps / visit</th><th>Last seen</th></tr></thead>
      <tbody>
        <?php foreach ($A['con_labour'] as $i => $r): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><b><?= Admin::e($r['name']) ?></b></td>
            <td><?= Admin::e($r['company']) ?: '—' ?></td>
            <td><?= $scoreBar((float)$r['score']) ?></td>
            <td><?= $gradePill($r['grade']) ?></td>
            <td><?= (int)$r['visits'] ?></td>
            <td><?= (int)$r['days'] ?></td>
            <td><?= (int)$r['projects'] ?></td>
            <td><b><?= (int)$r['steps'] ?></b></td>
            <td><?= number_format((float)$r['per_visit'], 2) ?></td>
            <td><?= Admin::e(fmtDate($r['last'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ============ project delivery ============ -->
<div class="card2">
  <div class="card2-head"><i class="bi bi-calendar-range text-primary"></i><h2>Project Delivery — Start vs Target vs Actual</h2>
    <span class="sub">start = Marking step's Start Date in the PMS sheet</span>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr>
        <th>Project</th><th>PE</th><th>Lifecycle</th><th>Start</th><th>Target end</th><th>Actual end</th>
        <th>Planned days</th><th>Actual days</th><th>Variance</th><th>Progress</th><th>Verdict</th>
      </tr></thead>
      <tbody>
        <?php if (!$A['delivery']): ?><tr><td colspan="11" class="t-empty">No projects yet.</td></tr><?php endif; ?>
        <?php foreach ($A['delivery'] as $d): ?>
          <tr>
            <td><a class="row-link" href="<?= Admin::BASE ?>/project.php?key=<?= urlencode($d['project_key']) ?>"><?= Admin::e(snip($d['label'], 34)) ?></a></td>
            <td><?= Admin::e($d['pe']) ?: '—' ?></td>
            <td><?= Layout::lifecyclePill((string)$d['lifecycle']) ?></td>
            <td><?= $d['start'] ? Admin::e(fmtDate($d['start'])) : '<span class="pf-why">not set</span>' ?>
              <?php if ($d['start'] && ($d['start_source'] ?? '') && ($d['start_source'] !== 'marking_start')): ?>
                <div class="pf-why"><?= Admin::e($startNote[$d['start_source']] ?? $d['start_source']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= $d['target'] ? Admin::e(fmtDate($d['target'])) : '—' ?></td>
            <td><?= $d['end'] ? Admin::e(fmtDate($d['end'])) : '—' ?></td>
            <td><?= $d['planned_days'] === null ? '—' : (int)$d['planned_days'] . 'd' ?></td>
            <td><?= $d['actual_days'] === null ? '—' : (int)$d['actual_days'] . 'd' . ($d['end'] ? '' : ' <span class="pf-why">so far</span>') ?></td>
            <td><?php if ($d['variance'] === null): ?>—<?php else: ?>
              <b style="color:<?= $d['variance'] > 0 ? '#dc2626' : '#16a34a' ?>"><?= $d['variance'] > 0 ? '+' : '' ?><?= (int)$d['variance'] ?>d</b>
            <?php endif; ?></td>
            <td><?= (int)$d['progress'] ?>%</td>
            <td><?= $verdictPill($d['verdict']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ============ how it is scored ============ -->
<div class="card2">
  <div class="card2-head"><i class="bi bi-calculator text-primary"></i><h2>How the Score Is Calculated</h2></div>
  <div class="card2-body">
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Group</th><th>Component</th><th>Weight</th><th>Meaning</th></tr></thead>
        <tbody>
          <tr><td rowspan="4"><b>Project Engineer</b></td><td>On-time delivery</td><td><?= Perf::W_PE['ontime'] ?></td><td>Share of their finished projects that met the target end date. No deliveries yet → schedule health (active projects not overdue).</td></tr>
          <tr><td>Reporting discipline</td><td><?= Perf::W_PE['discipline'] ?></td><td>60% = active projects with a report inside 48h · 40% = reports filed, relative to the top PE.</td></tr>
          <tr><td>Step throughput</td><td><?= Perf::W_PE['throughput'] ?></td><td>Steps first completed on their visits, relative to the top PE.</td></tr>
          <tr><td>Hold control</td><td><?= Perf::W_PE['holds'] ?></td><td>Inverse of days their projects sat on hold — fewer stuck days scores higher.</td></tr>

          <tr><td rowspan="4"><b>VAPL worker</b></td><td>Steps completed</td><td><?= Perf::W_WORKER['steps'] ?></td><td>Steps they were named on that first went Done that visit, relative to the top worker.</td></tr>
          <tr><td>Attendance</td><td><?= Perf::W_WORKER['attendance'] ?></td><td>Distinct days on site, relative to the top worker.</td></tr>
          <tr><td>Productivity</td><td><?= Perf::W_WORKER['productivity'] ?></td><td>Steps per visit — output density, not just turning up.</td></tr>
          <tr><td>Speed</td><td><?= Perf::W_WORKER['speed'] ?></td><td>Share of their steps closed within <?= (int)$opt['on_time_step_days'] ?> day(s) of the step's start date.</td></tr>

          <tr><td rowspan="4"><b>Contractor</b></td><td>Steps completed</td><td><?= Perf::W_CON['steps'] ?></td><td>Total steps their labour closed, relative to the top contractor.</td></tr>
          <tr><td>Work intensity</td><td><?= Perf::W_CON['productivity'] ?></td><td>Steps per visit — "who works harder".</td></tr>
          <tr><td>On-time projects</td><td><?= Perf::W_CON['ontime'] ?></td><td>Share of the finished projects they worked on that met the target end date.</td></tr>
          <tr><td>Speed</td><td><?= Perf::W_CON['speed'] ?></td><td>Inverse of average days from a step's start date to its end date.</td></tr>
        </tbody>
      </table>
    </div>
    <div class="pf-legend">
      <span><b>Grades</b> A ≥ 85 · B ≥ 70 · C ≥ 55 · D &lt; 55</span>
      <span><b>Steps done</b> counts a step once — on the first visit that marked it Done, so re-reporting cannot inflate it.</span>
      <span><b>Dates</b> come from the PMS sheets; press <i>Refresh start dates</i> after updating a Marking start date.</span>
    </div>
  </div>
</div>
<?php Layout::foot();
