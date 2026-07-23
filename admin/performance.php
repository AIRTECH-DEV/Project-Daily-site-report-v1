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
.pf-h3{font-size:14px;font-weight:700;color:#1f2b3d;margin:22px 0 10px;padding-bottom:6px;border-bottom:1px solid #e8edf3}
.pf-h3:first-child{margin-top:0}
.pf-box{background:#f7f9fc;border:1px solid #e3eaf2;border-radius:10px;padding:14px 16px;margin-bottom:4px}
.pf-box p{margin:0 0 10px;font-size:13.5px;line-height:1.65;color:#44546b}
.pf-box p:last-child{margin-bottom:0}
.pf-f{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:12.5px;background:#eef3f9;border:1px solid #dce5ef;border-radius:5px;padding:2px 6px;color:#1f3a5f;display:inline-block;white-space:nowrap}
.pf-box .table-wrap{margin:10px 0}
.pf-box .tbl{background:#fff}
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
<?php
// The live divisors, so the formulas below show the actual numbers in use.
$topOf = function (array $rows, string $key) {
    $m = 0.0;
    foreach ($rows as $r) { $m = max($m, (float)($r[$key] ?? 0)); }
    return $m;
};
$num = fn($v, $dp = 0) => $v > 0 ? number_format((float)$v, $dp) : '—';
$N   = (int)$opt['on_time_step_days'];
?>
<div class="card2">
  <div class="card2-head"><i class="bi bi-calculator text-primary"></i><h2>How the Score Is Calculated</h2>
    <span class="sub">exact formulas · every number traceable to a report or a sheet cell</span>
  </div>
  <div class="card2-body">

    <div class="pf-box">
      <div class="pf-h3">The rule</div>
      <p>Every score is <b>out of 100</b> and is the sum of <b>four components</b>. Each component is
         <span class="pf-f">weight × how well you did on it (0 to 1)</span>. Nothing else enters the score —
         no manual adjustment, no seniority, no opinion.</p>
      <p><b>Two kinds of component.</b>
         <span class="pill pill-info">Absolute</span> ones are a plain percentage of your own work
         (e.g. "how many of your projects met the target date"). They do not depend on anybody else.
         <span class="pill pill-type">Relative</span> ones are divided by <b>the best person in this
         window</b> — so the top performer gets the full weight and everyone else gets a share of it.
         Steps are counted relative because a big tower and a single flat do not have comparable step
         counts; ranking against peers in the same period is the fair comparison.</p>
      <p><b>When a component cannot be measured</b> for someone (for example none of their projects has
         finished yet, so "on-time" has nothing to measure), the score uses the <b>average of the people
         who could be measured</b>, or <b><?= (int)Perf::NEUTRAL ?>%</b> if nobody could. An unmeasurable
         component therefore never hands out a free 100% and never punishes with a 0%.</p>
    </div>

    <div class="pf-h3">1 · Project Engineer — total 100 <span class="pf-why">(incentive)</span></div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Component</th><th>Weight</th><th>Kind</th><th>Exact formula</th><th>Where the number comes from</th></tr></thead>
        <tbody>
          <tr>
            <td><b>On-time delivery</b></td><td><?= Perf::W_PE['ontime'] ?></td>
            <td><span class="pill pill-info">Absolute</span></td>
            <td><span class="pf-f"><?= Perf::W_PE['ontime'] ?> × OnTime% ÷ 100</span>
              <div class="pf-why">OnTime% = 100 × (their finished projects that met the target) ÷ (their finished projects)</div>
              <div class="pf-why">No project finished yet → 100 × (1 − overdue actives ÷ actives)</div>
              <div class="pf-why">Nothing active either → peer average, else <?= (int)Perf::NEUTRAL ?></div></td>
            <td>PMS sheet: Commissioning <b>End Date</b> vs <b>Tentitive Project End date</b><div class="pf-why">projects.actual_end_date · sheet_target_end</div></td>
          </tr>
          <tr>
            <td><b>Reporting discipline</b></td><td><?= Perf::W_PE['discipline'] ?></td>
            <td><span class="pill pill-info">Absolute</span> + <span class="pill pill-type">Relative</span></td>
            <td><span class="pf-f"><?= Perf::W_PE['discipline'] ?> × (0.6 × Compliance% + 0.4 × 100 × reports ÷ <?= $num($topOf($A['pe'], 'reports')) ?>) ÷ 100</span>
              <div class="pf-why">Compliance% = 100 × (their active projects reported inside 48 h) ÷ (their active projects)</div>
              <div class="pf-why">Divisor <?= $num($topOf($A['pe'], 'reports')) ?> = reports filed by the top PE this window</div></td>
            <td>submissions.engineer (count) · projects.last_report_at (48 h check)</td>
          </tr>
          <tr>
            <td><b>Step throughput</b></td><td><?= Perf::W_PE['throughput'] ?></td>
            <td><span class="pill pill-type">Relative</span></td>
            <td><span class="pf-f"><?= Perf::W_PE['throughput'] ?> × their steps ÷ <?= $num($topOf($A['pe'], 'steps')) ?></span>
              <div class="pf-why">Divisor <?= $num($topOf($A['pe'], 'steps')) ?> = steps closed by the top PE this window</div></td>
            <td>submissions.payload_json → stepStatuses with status <b>Done</b>, counted once per project+step</td>
          </tr>
          <tr>
            <td><b>Hold control</b></td><td><?= Perf::W_PE['holds'] ?></td>
            <td><span class="pill pill-type">Relative</span></td>
            <td><span class="pf-f"><?= Perf::W_PE['holds'] ?> × (1 − their hold-days ÷ <?= $num($topOf($A['pe'], 'hold_days')) ?>)</span>
              <div class="pf-why">Hold-days = Σ over their On-Hold projects of (today − hold_since)</div>
              <div class="pf-why">Fewest stuck days scores the full <?= Perf::W_PE['holds'] ?>; the worst scores 0</div></td>
            <td>projects.lifecycle = On Hold · projects.hold_since</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="pf-h3">2 · VAPL Worker — total 100 <span class="pf-why">(incentive)</span></div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Component</th><th>Weight</th><th>Kind</th><th>Exact formula</th><th>Where the number comes from</th></tr></thead>
        <tbody>
          <tr>
            <td><b>Steps completed</b></td><td><?= Perf::W_WORKER['steps'] ?></td>
            <td><span class="pill pill-type">Relative</span></td>
            <td><span class="pf-f"><?= Perf::W_WORKER['steps'] ?> × their steps ÷ <?= $num($topOf($A['workers'], 'steps')) ?></span>
              <div class="pf-why">Counts a step only if the worker was named on the visit <b>and</b> that visit is the first to mark it Done</div></td>
            <td>Report form → People on Site (name + "what work done") ∩ that report's Done steps<div class="pf-why">visit_workers.steps ∩ submissions stepStatuses</div></td>
          </tr>
          <tr>
            <td><b>Attendance</b></td><td><?= Perf::W_WORKER['attendance'] ?></td>
            <td><span class="pill pill-type">Relative</span></td>
            <td><span class="pf-f"><?= Perf::W_WORKER['attendance'] ?> × their days on site ÷ <?= $num($topOf($A['workers'], 'days')) ?></span>
              <div class="pf-why"><b>Days</b> = distinct dates, not visits — two reports on one day still count as one day</div></td>
            <td>visit_workers.visit_date (distinct)</td>
          </tr>
          <tr>
            <td><b>Productivity</b></td><td><?= Perf::W_WORKER['productivity'] ?></td>
            <td><span class="pill pill-type">Relative</span></td>
            <td><span class="pf-f"><?= Perf::W_WORKER['productivity'] ?> × (their steps ÷ their visits) ÷ <?= $num($topOf($A['workers'], 'per_visit'), 2) ?></span>
              <div class="pf-why">Output per trip, so someone who closes more per visit is not beaten by someone who simply attends more</div></td>
            <td>Same two sources as above, divided</td>
          </tr>
          <tr>
            <td><b>Speed</b></td><td><?= Perf::W_WORKER['speed'] ?></td>
            <td><span class="pill pill-info">Absolute</span></td>
            <td><span class="pf-f"><?= Perf::W_WORKER['speed'] ?> × Fast% ÷ 100</span>
              <div class="pf-why">Fast% = 100 × (their steps closed within <b><?= $N ?> day<?= $N === 1 ? '' : 's' ?></b> of that step's Start Date) ÷ (their steps that have both a Start and End Date)</div>
              <div class="pf-why">No step of theirs has both dates → peer average, else <?= (int)Perf::NEUTRAL ?></div></td>
            <td>PMS sheet: each step's <b>Start Date</b> and <b>End Date</b><div class="pf-why">project_step_dates</div></td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="pf-h3">3 · Contractor — total 100 <span class="pf-why">(evaluation only — never paid from the pools)</span></div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Component</th><th>Weight</th><th>Kind</th><th>Exact formula</th><th>Where the number comes from</th></tr></thead>
        <tbody>
          <tr>
            <td><b>Steps completed</b></td><td><?= Perf::W_CON['steps'] ?></td>
            <td><span class="pill pill-type">Relative</span></td>
            <td><span class="pf-f"><?= Perf::W_CON['steps'] ?> × their steps ÷ <?= $num($topOf($A['contractors'], 'steps')) ?></span>
              <div class="pf-why">Every step closed by any of that company's labour, added together</div></td>
            <td>visit_workers where type = Contractor, grouped by contractor_name</td>
          </tr>
          <tr>
            <td><b>Work intensity</b><div class="pf-why">"who works harder"</div></td><td><?= Perf::W_CON['productivity'] ?></td>
            <td><span class="pill pill-type">Relative</span></td>
            <td><span class="pf-f"><?= Perf::W_CON['productivity'] ?> × (their steps ÷ their visits) ÷ <?= $num($topOf($A['contractors'], 'per_visit'), 2) ?></span>
              <div class="pf-why">A company that sends many people who each do little scores lower than one that closes work per trip</div></td>
            <td>Same source, divided by visit count</td>
          </tr>
          <tr>
            <td><b>On-time projects</b></td><td><?= Perf::W_CON['ontime'] ?></td>
            <td><span class="pill pill-info">Absolute</span></td>
            <td><span class="pf-f"><?= Perf::W_CON['ontime'] ?> × OnTime% ÷ 100</span>
              <div class="pf-why">OnTime% = 100 × (finished projects they worked on that met the target) ÷ (finished projects they worked on)</div>
              <div class="pf-why">None of their projects finished yet → peer average, else <?= (int)Perf::NEUTRAL ?></div></td>
            <td>projects.actual_end_date vs sheet_target_end, for every project they touched</td>
          </tr>
          <tr>
            <td><b>Speed</b></td><td><?= Perf::W_CON['speed'] ?></td>
            <td><span class="pill pill-type">Relative</span></td>
            <td><span class="pf-f"><?= Perf::W_CON['speed'] ?> × (1 − their avg step-days ÷ <?= $num($topOf($A['contractors'], 'avg_turn'), 1) ?>)</span>
              <div class="pf-why">Avg step-days = mean of (step End Date − step Start Date) over their steps</div>
              <div class="pf-why">The slowest contractor scores 0 here; the fastest scores close to the full <?= Perf::W_CON['speed'] ?></div></td>
            <td>PMS sheet step Start/End dates<div class="pf-why">project_step_dates</div></td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="pf-h3">4 · Exact definitions</div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Term</th><th>Precise meaning</th></tr></thead>
        <tbody>
          <tr><td><b>Step done (credited)</b></td><td>A step counts <b>once per project</b> — on the <b>first</b> report that marked it <i>Done</i>. Re-reporting the same step later earns nobody anything, so the number cannot be inflated. Credit goes to the PE who filed that report and to every person named on that visit whose "what work done" list includes that step.</td></tr>
          <tr><td><b>Visit</b></td><td>One person appearing on one report. Two people on one report = two visits.</td></tr>
          <tr><td><b>Day on site</b></td><td>One distinct calendar date on which that person appeared on any report. Always ≤ visits.</td></tr>
          <tr><td><b>Finished project</b></td><td>The PMS sheet's final <b>Commissining</b> step has an <b>End Date</b> (the earlier <i>Pre-Commissining</i> step is deliberately ignored), or the project was manually marked Commissioned/Closed.</td></tr>
          <tr><td><b>On-time project</b></td><td>A finished project whose finish date is <b>on or before</b> its target end date. Same-day counts as on time.</td></tr>
          <tr><td><b>Overdue project</b></td><td>Target end date has passed and the project is <b>not</b> finished. Counted for today, not for the window.</td></tr>
          <tr><td><b>Active project</b></td><td>Lifecycle is Active, At Risk, On Hold, or Commissioning Pending.</td></tr>
          <tr><td><b>48 h compliance</b></td><td>Share of that PE's <b>active</b> projects whose most recent report is under 48 hours old, measured right now.</td></tr>
          <tr><td><b>Hold-days</b></td><td>For each of their projects sitting On Hold, the days since <i>hold_since</i>, added together. A project held 5 days and another held 3 contributes 8.</td></tr>
          <tr><td><b>Fast step</b></td><td>A step whose <b>End Date − Start Date ≤ <?= $N ?> day<?= $N === 1 ? '' : 's' ?></b> in the PMS sheet. Change the limit in Incentive Settings.</td></tr>
          <tr><td><b>Project start date</b></td><td>The <b>Marking</b> step's <b>Start Date</b> in the PMS sheet. If that cell is blank the tab falls back, in order, to Marking End Date → LS Material Delivery → the earliest step Start Date on the row, and prints which one it used under the date.</td></tr>
          <tr><td><b>Window</b></td><td>Everything above is measured over the selected period only (<?= Admin::e(fmtDate($A['from'])) ?> → <?= Admin::e(fmtDate($A['to'])) ?>). Overdue and 48 h compliance are "as of now" by nature.</td></tr>
        </tbody>
      </table>
    </div>

    <div class="pf-h3">5 · Worked example — a VAPL worker</div>
    <div class="pf-box">
      <p>Ramesh closed <b>24 steps</b> over <b>18 visits</b> on <b>15 distinct days</b>, and <b>70%</b> of his
         steps were closed within the fast limit. This window the best worker closed <b>30 steps</b>, the
         best attendance was <b>20 days</b>, and the best steps-per-visit was <b>1.60</b>.
         Ramesh's steps per visit = 24 ÷ 18 = <b>1.33</b>.</p>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th>Component</th><th>Calculation</th><th>Points</th></tr></thead>
          <tbody>
            <tr><td>Steps completed</td><td><span class="pf-f">40 × 24 ÷ 30</span></td><td><b>32.0</b></td></tr>
            <tr><td>Attendance</td><td><span class="pf-f">30 × 15 ÷ 20</span></td><td><b>22.5</b></td></tr>
            <tr><td>Productivity</td><td><span class="pf-f">20 × 1.33 ÷ 1.60</span></td><td><b>16.6</b></td></tr>
            <tr><td>Speed</td><td><span class="pf-f">10 × 70 ÷ 100</span></td><td><b>7.0</b></td></tr>
            <tr><td><b>Total</b></td><td></td><td><b>78.1 → grade B</b></td></tr>
          </tbody>
        </table>
      </div>
      <p>If the worker pool is <b>₹50,000</b> and the eligible workers score 78.1, 65.0 and 90.0
         (total <b>233.1</b>), Ramesh receives
         <span class="pf-f">50,000 × 78.1 ÷ 233.1 = <b>₹16,753</b></span>.</p>
    </div>

    <div class="pf-h3">6 · Grades, eligibility and the ₹ split</div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Rule</th><th>Detail</th></tr></thead>
        <tbody>
          <tr><td><b>Grades</b></td><td><span class="pill pill-ok">A</span> 85 and above · <span class="pill pill-info">B</span> 70–84.9 · <span class="pill pill-warn">C</span> 55–69.9 · <span class="pill pill-bad">D</span> below 55</td></tr>
          <tr><td><b>Who is eligible</b></td><td>Score ≥ <b><?= (int)$opt['min_score'] ?></b>. VAPL workers must also have at least <b><?= (int)$opt['min_visits'] ?></b> visit<?= (int)$opt['min_visits'] === 1 ? '' : 's' ?> in the window. Everyone excluded is listed under their table with the reason.</td></tr>
          <tr><td><b>How ₹ is split</b></td><td><span class="pf-f">your ₹ = pool × your score ÷ (sum of all eligible scores)</span> — proportional to score, so the pool is always fully distributed and a higher score always pays more.</td></tr>
          <tr><td><b>Two separate pools</b></td><td>The PE pool is split only between PEs; the VAPL worker pool only between VAPL workers. They never mix.</td></tr>
          <tr><td><b>Contractors</b></td><td>Scored and graded for comparison, but <b>never</b> included in either pool.</td></tr>
          <tr><td><b>Keeping it accurate</b></td><td>Press <b>Refresh start dates from PMS sheets</b> after editing any Marking start date, target end date, or step date — the scores are only as current as the last sheet read.</td></tr>
        </tbody>
      </table>
    </div>

  </div>
</div>
<?php Layout::foot();
