<?php
/**
 * Performance & Incentive — the scorecard tab.
 *
 * Answers, for one scoring window:
 *   • Did each project finish before its end date?  Project START comes from the
 *     PMS sheet ("Marking" → Start Date); FINISH from the final Commissioning
 *     step's End Date; TARGET from "Tentitive Project End date".
 *   • Which PE and which VAPL worker earned incentive — ranked, scored, and with
 *     the allocated monthly budget split proportionally to score.
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
// Incentive runs per CALENDAR MONTH. ?m=YYYY-MM picks the month; default = now.
$opt['month'] = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
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
/**
 * Payout cell. No budget allocated yet is NOT the same as "earned nothing" — say so,
 * otherwise a blank budget reads as a zero payout for a qualifying person.
 */
$payCell = function (array $map, string $name, float $pool) use ($money) {
    if (!isset($map[$name])) {
        return '<span class="pf-why">not eligible</span>';
    }
    return $pool > 0 ? $money($map[$name]) : '<span class="pill pill-ok">qualifies</span><div class="pf-why">no budget allocated</div>';
};

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
.pf-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(168px,1fr));gap:14px;align-items:stretch}
/* each cell is a flex column with the input pushed to the bottom — a label that
   wraps to two lines then no longer drops its own input below the neighbours' */
.pf-grid>div{display:flex;flex-direction:column;min-width:0}
.pf-grid label{display:block;font-size:12px;line-height:1.45;color:#6b7a90;margin-bottom:6px}
.pf-grid .inp{width:100%;min-width:0;margin-top:auto}
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

/* collapsible card (<details>) — the head is the toggle, closed until clicked */
details.card2-coll>summary{cursor:pointer;list-style:none;-webkit-user-select:none;user-select:none;border-radius:var(--radius) var(--radius) 0 0}
details.card2-coll>summary::-webkit-details-marker{display:none}
details.card2-coll>summary::marker{content:''}
details.card2-coll>summary:hover{background:#fafcff}
details.card2-coll:not([open])>summary{border-bottom:0;border-radius:var(--radius)}
.coll-hint{font-size:12.5px;color:#8190a5;margin-left:auto}
.coll-caret{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:9px;background:#f1f5fb;color:#5b6b82;font-size:15px;flex-shrink:0}
details.card2-coll:not([open])>summary .coll-caret{margin-left:0}
details.card2-coll[open]>summary .coll-caret{margin-left:auto}
details.card2-coll[open] .coll-hint{display:none}
.coll-caret i{transition:transform .18s ease}
details.card2-coll[open] .coll-caret i{transform:rotate(180deg)}

/* ---------------- responsive ---------------- */
/* Wide scorecards keep the sideways scroll down to tablet size. Below 820px the
   footer script flips every .tbl-resp row into a stacked card (adds .is-stacked
   and wraps each cell's content in .td-v), so a 14-column table becomes a
   readable label/value list instead of a 1400px horizontal scroll.
   Without JS nothing changes and the table just scrolls as before. */
.table-wrap{-webkit-overflow-scrolling:touch}
/* the script wraps every cell's content in this; block so it never nests a <div> inside an inline box */
.td-v{display:block}
/* shown by the footer script only while a table is actually wider than its box */
.pf-hint{display:none;align-items:center;gap:6px;font-size:12px;color:#8190a5;padding:8px 20px 2px}
.pf-box .pf-hint{padding:4px 0 0}

@media (max-width:900px){
  .pf-bar{gap:8px}
  .pf-note{font-size:12px}
}

@media (max-width:820px){
  .pf-bar .spacer{display:none}
  .pf-bar>form{width:100%}
  .pf-bar .inp{width:100%;min-width:0}
  .pf-bar .btn{width:100%;justify-content:center}
  .pf-seg{width:100%}
  .pf-seg a.on{flex:1;text-align:center}
  .pf-f{white-space:normal;overflow-wrap:anywhere}
  .pf-legend{flex-direction:column;gap:8px}
  .pf-box{padding:12px 13px}
  .pf-box p{font-size:13px}
  .pf-grid{grid-template-columns:1fr}
  .coll-hint{display:none}
  details.card2-coll:not([open])>summary .coll-caret{margin-left:auto}

  table.tbl-resp.is-stacked{display:block;width:100%;padding:12px;font-size:13px}
  table.tbl-resp.is-stacked tbody{display:block;width:100%}
  table.tbl-resp.is-stacked thead{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
  table.tbl-resp.is-stacked tbody tr{display:block;margin-bottom:12px;border:1px solid #e3eaf3;border-radius:12px;background:#fff;overflow:hidden}
  table.tbl-resp.is-stacked tbody tr:last-child{margin-bottom:0}
  table.tbl-resp.is-stacked tbody tr:hover{background:#fff}
  table.tbl-resp.is-stacked td.t-empty{display:block;padding:26px 14px}
  table.tbl-resp.is-stacked td[data-l]{display:flex;align-items:flex-start;gap:12px;padding:9px 13px;border-bottom:1px solid #f1f5fa;text-align:right}
  table.tbl-resp.is-stacked td[data-l]:first-child{background:#f7f9fc}
  table.tbl-resp.is-stacked td[data-l]:last-child{border-bottom:0}
  table.tbl-resp.is-stacked td[data-l]::before{content:attr(data-l);flex:0 0 40%;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:#8190a5;line-height:1.55}
  table.tbl-resp.is-stacked td[data-l="#"]::before{content:"Rank"}
  table.tbl-resp.is-stacked .td-v{flex:1 1 auto;min-width:0;overflow-wrap:anywhere}
  table.tbl-resp.is-stacked .sc-wrap{min-width:0;justify-content:flex-end}
  table.tbl-resp.is-stacked .sc-track{min-width:60px}
  table.tbl-resp.is-stacked .pf-why{text-align:right}
  table.tbl-resp.is-stacked .pf-money{white-space:normal}

  /* text-heavy tables (formulas, definitions) read better with the label on its own line */
  table.tbl-resp.tbl-resp-stack.is-stacked td[data-l]{display:block;text-align:left;padding:11px 13px}
  table.tbl-resp.tbl-resp-stack.is-stacked td[data-l]::before{display:block;margin-bottom:5px}
  table.tbl-resp.tbl-resp-stack.is-stacked .pf-why{text-align:left}
}

@media (max-width:420px){
  table.tbl-resp.is-stacked{padding:10px}
  table.tbl-resp.is-stacked td[data-l]{padding:8px 11px}
  table.tbl-resp.is-stacked td[data-l]::before{flex-basis:46%}
  .sc-num{width:34px}
}
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

<?php
$curM  = $A['month'];
$prevM = date('Y-m', strtotime($curM . '-01 -1 month'));
$nextM = date('Y-m', strtotime($curM . '-01 +1 month'));
$canNext = ($nextM <= date('Y-m'));
?>
<div class="pf-bar">
  <div class="pf-seg">
    <a href="?m=<?= $prevM ?>" title="Previous month"><i class="bi bi-chevron-left"></i></a>
    <a class="on" style="cursor:default"><?= date('F Y', strtotime($curM . '-01')) ?></a>
    <?php if ($canNext): ?><a href="?m=<?= $nextM ?>" title="Next month"><i class="bi bi-chevron-right"></i></a>
    <?php else: ?><a style="opacity:.35;cursor:not-allowed"><i class="bi bi-chevron-right"></i></a><?php endif; ?>
  </div>
  <form method="GET" style="margin:0">
    <select class="inp" name="m" onchange="this.form.submit()" style="padding:6px 10px;font-size:13px">
      <?php for ($i = 0; $i < 18; $i++): $m = date('Y-m', strtotime('-' . $i . ' months')); ?>
        <option value="<?= $m ?>" <?= $m === $curM ? 'selected' : '' ?>><?= date('F Y', strtotime($m . '-01')) ?></option>
      <?php endfor; ?>
    </select>
  </form>
  <span class="pf-note">
    <?php if ($A['running']): ?>
      <span class="pill pill-warn">month running</span> counted <?= Admin::e(fmtDate($A['from'])) ?> → <?= Admin::e(fmtDate($A['to'])) ?> (so far)
    <?php else: ?>
      <span class="pill pill-ok">month closed</span> <?= Admin::e(fmtDate($A['from'])) ?> → <?= Admin::e(fmtDate($A['month_end'])) ?>
    <?php endif; ?>
  </span>
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
    <div class="pf-box" style="margin-bottom:14px">
      <div class="pf-h3" style="margin-top:0">What is the Total Budget Allocated?</div>
      <p>It is the <b>total incentive money you decide to give the team for one month</b> — for the whole
         team together, not per person. <b>You choose the amount</b>; the system never invents it. It is
         <b>extra money on top of salary</b>, not salary itself — salary is paid as usual and is not touched
         by this page.</p>
      <p>Allocate a budget and the tab divides it between the people who qualified, in proportion to their
         score. <b>Leave it at 0</b> and nothing is paid — the scores and ranking still work, so you can run
         the tab for a few months, watch the numbers, and only allocate real money once you trust them.</p>
    </div>
    <?php if (Admin::isViewer()): ?>
      <div class="t-empty">Read-only account — settings can only be changed by an admin.</div>
    <?php else: ?>
    <form method="POST">
      <?= Admin::csrfField() ?><input type="hidden" name="action" value="save_opts">
      <div class="pf-grid">
        <div><label>Total Budget Allocated — PE, per month (₹)</label><input class="inp" type="number" min="0" step="100" name="pe_pool" value="<?= (int)$opt['pe_pool'] ?>"></div>
        <div><label>Total Budget Allocated — VAPL worker, per month (₹)</label><input class="inp" type="number" min="0" step="100" name="worker_pool" value="<?= (int)$opt['worker_pool'] ?>"></div>
        <div><label>Eligibility min score</label><input class="inp" type="number" min="0" max="100" name="min_score" value="<?= (int)$opt['min_score'] ?>"></div>
        <div><label>Worker min visits</label><input class="inp" type="number" min="0" name="min_visits" value="<?= (int)$opt['min_visits'] ?>"></div>
        <div><label>"Fast step" limit (days)</label><input class="inp" type="number" min="0" name="on_time_step_days" value="<?= (int)$opt['on_time_step_days'] ?>"></div>
      </div>
      <div style="margin-top:12px"><button class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Save settings</button></div>
    </form>
    <?php endif; ?>
    <div class="pf-legend">
      <span><b>One budget per month.</b> The amounts here are paid out for <b><?= date('F Y', strtotime($curM . '-01')) ?></b> and start again next month.</span>
      <span><b>The budget</b> is divided in proportion to score across everyone who clears the eligibility floor.</span>
      <span><b>Contractors are never paid from these budgets</b> — they are scored for evaluation only.</span>
    </div>
  </div>
</div>

<!-- ============ PE scorecard ============ -->
<div class="card2">
  <div class="card2-head"><i class="bi bi-person-badge text-primary"></i><h2>Project Engineer Scorecard</h2>
    <span class="sub">incentive · budget <?= $money($A['pe_share']['pool']) ?> · <?= count($A['pe_share']['eligible']) ?> eligible</span>
  </div>
  <div class="table-wrap">
    <table class="tbl tbl-resp">
      <thead><tr>
        <th>#</th><th>Project Engineer</th><th>Score</th><th>Grade</th>
        <th>Projects</th><th>Active</th><th>Reports</th><th>Days</th><th>Steps done</th>
        <th>Still open</th><th>Delivered on-time</th><th>Coverage</th><th>Holds</th><th>Incentive</th>
      </tr></thead>
      <tbody>
        <?php if (!$A['pe']): ?><tr><td colspan="14" class="t-empty">No PE activity in <?= date('F Y', strtotime($curM . '-01')) ?>.</td></tr><?php endif; ?>
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
            <td><?= (int)$r['pending'] ? '<span class="pill pill-warn">' . (int)$r['pending'] . '</span>' : '—' ?></td>
            <td><?= $r['ontime_pct'] === null ? '<span class="pf-why">none finished</span>' : $pctTxt($r['ontime_pct']) . ' <span class="pf-why">(' . (int)$r['ontime'] . '/' . (int)$r['delivered'] . ')</span>' ?></td>
            <td><?= $pctTxt($r['comply_pct']) ?></td>
            <td><?= (int)$r['holds'] ?><?= (int)$r['hold_days'] ? ' <span class="pf-why">' . (int)$r['hold_days'] . 'd</span>' : '' ?></td>
            <td class="pf-money"><?= $payCell($peMoney, $r['name'], (float)$A['pe_share']['pool']) ?></td>
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
    <span class="sub">incentive · budget <?= $money($A['worker_share']['pool']) ?> · <?= count($A['worker_share']['eligible']) ?> eligible</span>
  </div>
  <div class="table-wrap">
    <table class="tbl tbl-resp">
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
            <td class="pf-money"><?= $payCell($wkMoney, $r['name'], (float)$A['worker_share']['pool']) ?></td>
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
    <table class="tbl tbl-resp">
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
    <table class="tbl tbl-resp">
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
    <table class="tbl tbl-resp">
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
<details class="card2 card2-coll">
  <summary class="card2-head"><i class="bi bi-calculator text-primary"></i><h2>How the Score Is Calculated</h2>
    <span class="sub">exact formulas · every number traceable to a report or a sheet cell</span>
    <span class="coll-hint">click to open</span>
    <span class="coll-caret"><i class="bi bi-chevron-down"></i></span>
  </summary>
  <div class="card2-body">

    <div class="pf-box">
      <div class="pf-h3">The rule</div>
      <p><b>Incentive is monthly.</b> Each calendar month is scored on its own and the allocated budget is paid out
         for that month; next month everyone starts from zero again. A PE gets <b>one combined score for
         all their projects together</b> — not one score per project.</p>
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
      <table class="tbl tbl-resp tbl-resp-stack">
        <thead><tr><th>Component</th><th>Weight</th><th>Kind</th><th>Exact formula</th><th>Where the number comes from</th></tr></thead>
        <tbody>
          <tr>
            <td><b>On-time delivery</b></td><td><?= Perf::W_PE['ontime'] ?></td>
            <td><span class="pill pill-info">Absolute</span></td>
            <td><span class="pf-f"><?= Perf::W_PE['ontime'] ?> × OnTime% ÷ 100</span>
              <div class="pf-why">OnTime% = 100 × (their projects that <b>finished this month</b> on or before target) ÷ (their projects that finished this month)</div>
              <div class="pf-why">Nothing finished this month → 100 × (1 − overdue actives ÷ actives)</div>
              <div class="pf-why">Nothing active either → peer average, else <?= (int)Perf::NEUTRAL ?></div></td>
            <td>PMS sheet: Commissioning <b>End Date</b> vs <b>Tentitive Project End date</b><div class="pf-why">projects.actual_end_date · sheet_target_end</div></td>
          </tr>
          <tr>
            <td><b>Reporting discipline</b></td><td><?= Perf::W_PE['discipline'] ?></td>
            <td><span class="pill pill-info">Absolute</span> + <span class="pill pill-type">Relative</span></td>
            <td><span class="pf-f"><?= Perf::W_PE['discipline'] ?> × (0.6 × Coverage% + 0.4 × 100 × reports ÷ <?= $num($topOf($A['pe'], 'reports')) ?>) ÷ 100</span>
              <div class="pf-why">Coverage% = 100 × (their sites kept reported without a gap over <?= (int)$A['gap_limit'] ?> days) ÷ (their sites this month)</div>
              <div class="pf-why">Divisor <?= $num($topOf($A['pe'], 'reports')) ?> = reports filed by the top PE this month</div></td>
            <td>submissions.engineer + submissions.created_at (gaps between consecutive reports per site)</td>
          </tr>
          <tr>
            <td><b>Step throughput</b></td><td><?= Perf::W_PE['throughput'] ?></td>
            <td><span class="pill pill-type">Relative</span></td>
            <td><span class="pf-f"><?= Perf::W_PE['throughput'] ?> × their steps ÷ <?= $num($topOf($A['pe'], 'steps')) ?></span>
              <div class="pf-why">Divisor <?= $num($topOf($A['pe'], 'steps')) ?> = steps closed by the top PE this month</div>
              <div class="pf-why">Steps left <b>Pending</b> or <b>Hold</b> earn nothing until the month they are actually closed</div></td>
            <td>submissions.payload_json → stepStatuses with status <b>Done</b>, counted once per project+step</td>
          </tr>
          <tr>
            <td><b>Hold control</b></td><td><?= Perf::W_PE['holds'] ?></td>
            <td><span class="pill pill-type">Relative</span></td>
            <td><span class="pf-f"><?= Perf::W_PE['holds'] ?> × (1 − their hold-days ÷ <?= $num($topOf($A['pe'], 'hold_days')) ?>)</span>
              <div class="pf-why">Hold-days = Σ over their On-Hold projects of the days spent on hold <b>inside this month</b></div>
              <div class="pf-why">Fewest stuck days scores the full <?= Perf::W_PE['holds'] ?>; the worst scores 0</div></td>
            <td>projects.lifecycle = On Hold · projects.hold_since (clipped to the month)</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="pf-h3">2 · VAPL Worker — total 100 <span class="pf-why">(incentive)</span></div>
    <div class="table-wrap">
      <table class="tbl tbl-resp tbl-resp-stack">
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

    <div class="pf-h3">3 · Contractor — total 100 <span class="pf-why">(evaluation only — never paid from the budgets)</span></div>
    <div class="table-wrap">
      <table class="tbl tbl-resp tbl-resp-stack">
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
      <table class="tbl tbl-resp tbl-resp-stack">
        <thead><tr><th>Term</th><th>Precise meaning</th></tr></thead>
        <tbody>
          <tr><td><b>Step done (credited)</b></td><td>A step counts <b>once per project</b> — on the <b>first</b> report that marked it <i>Done</i>. Re-reporting the same step later earns nobody anything, so the number cannot be inflated. Credit goes to the PE who filed that report and to every person named on that visit whose "what work done" list includes that step.</td></tr>
          <tr><td><b>Visit</b></td><td>One person appearing on one report. Two people on one report = two visits.</td></tr>
          <tr><td><b>Day on site</b></td><td>One distinct calendar date on which that person appeared on any report. Always ≤ visits.</td></tr>
          <tr><td><b>Finished project</b></td><td>The PMS sheet's final <b>Commissining</b> step has an <b>End Date</b> (the earlier <i>Pre-Commissining</i> step is deliberately ignored), or the project was manually marked Commissioned/Closed.</td></tr>
          <tr><td><b>On-time project</b></td><td>A finished project whose finish date is <b>on or before</b> its target end date. Same-day counts as on time.</td></tr>
          <tr><td><b>Overdue project</b></td><td>Target end date has passed and the project is <b>not</b> finished. Counted for today, not for the window.</td></tr>
          <tr><td><b>Active project</b></td><td>Lifecycle is Active, At Risk, On Hold, or Commissioning Pending.</td></tr>
          <tr><td><b>Coverage</b></td><td>Share of the sites a PE touched this month that were kept reported — no gap of more than <b><?= (int)$A['gap_limit'] ?> days</b> between consecutive reports, from their first report of the month to the month's cut-off. A site started mid-month is only judged from its first report onwards.</td></tr>
          <tr><td><b>Still open (pending)</b></td><td>Steps this PE reported as <b>Pending</b> or <b>Hold</b> that were still not closed by the month's cut-off. They earn <b>no</b> throughput points — a step only pays in the month it is actually marked Done. Shown so you can see work in flight, not to punish it.</td></tr>
          <tr><td><b>Hold-days</b></td><td>For each of their On-Hold projects, the days it spent on hold <b>inside this month</b>, added together. A project held 5 days and another held 3 contributes 8.</td></tr>
          <tr><td><b>Fast step</b></td><td>A step whose <b>End Date − Start Date ≤ <?= $N ?> day<?= $N === 1 ? '' : 's' ?></b> in the PMS sheet. Change the limit in Incentive Settings.</td></tr>
          <tr><td><b>Project start date</b></td><td>The <b>Marking</b> step's <b>Start Date</b> in the PMS sheet. If that cell is blank the tab falls back, in order, to Marking End Date → LS Material Delivery → the earliest step Start Date on the row, and prints which one it used under the date.</td></tr>
          <tr><td><b>Month</b></td><td>Everything above is measured over <b>one calendar month only</b> (<?= Admin::e(fmtDate($A['from'])) ?> → <?= Admin::e(fmtDate($A['month_end'])) ?>)<?= $A['running'] ? ', counted up to today since the month is still running' : '' ?>. A closed month never changes again, so a payout can be checked and re-checked later.</td></tr>
          <tr><td><b>Overall, not per project</b></td><td>A PE gets <b>one score</b> covering <b>all</b> their projects added together — steps from every site, reports from every site, deliveries from every site. There is no per-project payout and no bonus for having more sites; what counts is the total work closed and delivered.</td></tr>
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
        <table class="tbl tbl-resp tbl-resp-stack">
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
      <p>If the VAPL worker budget for the month is <b>₹50,000</b> and the eligible workers score 78.1, 65.0 and 90.0
         (total <b>233.1</b>), Ramesh receives
         <span class="pf-f">50,000 × 78.1 ÷ 233.1 = <b>₹16,753</b></span>.</p>
    </div>

    <div class="pf-h3">6 · Five PE scenarios — how the money actually moves</div>
    <div class="pf-box">
      <p>All five are the <b>same month</b>, same PE budget of <b>₹40,000</b>. Assume the top PE that month
         closed <b>40 steps</b> and filed <b>44 reports</b>, and the worst hold record was <b>20 hold-days</b>
         — those are the divisors everyone is measured against.</p>
      <div class="table-wrap">
        <table class="tbl tbl-resp tbl-resp-stack">
          <thead><tr><th>PE</th><th>What they did</th><th>On-time<br><span class="pf-why">35</span></th><th>Discipline<br><span class="pf-why">25</span></th><th>Throughput<br><span class="pf-why">25</span></th><th>Holds<br><span class="pf-why">15</span></th><th>Score</th><th>₹</th></tr></thead>
          <tbody>
            <tr>
              <td><b>A — the finisher</b></td>
              <td>3 sites. 2 commissioned this month, both before target. 40 steps closed, 44 reports, every site reported without a gap, 0 hold-days.</td>
              <td>35.0<div class="pf-why">2/2 = 100%</div></td><td>25.0<div class="pf-why">cov 100 · rep 44/44</div></td><td>25.0<div class="pf-why">40/40</div></td><td>15.0<div class="pf-why">0 days</div></td>
              <td><b>100.0</b> <span class="pill pill-ok">A</span></td><td class="pf-money">₹11,901</td>
            </tr>
            <tr>
              <td><b>B — busy, nothing finished</b></td>
              <td>5 sites, none finished yet but none overdue. 36 steps, 40 reports, coverage 100%, 0 hold-days.</td>
              <td>35.0<div class="pf-why">no finish → 1−0/5</div></td><td>24.1<div class="pf-why">cov 100 · rep 40/44</div></td><td>22.5<div class="pf-why">36/40</div></td><td>15.0</td>
              <td><b>96.6</b> <span class="pill pill-ok">A</span></td><td class="pf-money">₹11,497</td>
            </tr>
            <tr>
              <td><b>C — lots of pending</b></td>
              <td>4 sites. Ticked 30 steps as <b>Pending</b>, only closed <b>10</b>. 40 reports, coverage 100%, nothing overdue, 0 hold-days.</td>
              <td>35.0</td><td>24.1</td><td><b>6.3</b><div class="pf-why">10/40 — pending pays 0</div></td><td>15.0</td>
              <td><b>80.4</b> <span class="pill pill-info">B</span></td><td class="pf-money">₹9,569</td>
            </tr>
            <tr>
              <td><b>D — finished late</b></td>
              <td>3 sites, 2 commissioned this month but <b>both after target</b>. 32 steps, 40 reports, coverage 100%, 0 hold-days.</td>
              <td><b>0.0</b><div class="pf-why">0/2 = 0%</div></td><td>24.1</td><td>20.0<div class="pf-why">32/40</div></td><td>15.0</td>
              <td><b>59.1</b> <span class="pill pill-warn">C</span></td><td class="pf-money">₹7,034</td>
            </tr>
            <tr>
              <td><b>E — quiet month</b></td>
              <td>2 sites, 1 overdue. 8 steps, 12 reports, one site went 9 days with no report, 20 hold-days.</td>
              <td>17.5<div class="pf-why">1−1/2 = 50%</div></td><td>10.2<div class="pf-why">cov 50 · rep 12/44</div></td><td>5.0<div class="pf-why">8/40</div></td><td><b>0.0</b><div class="pf-why">20/20 — worst</div></td>
              <td><b>32.7</b> <span class="pill pill-bad">D</span></td><td class="pf-money">not eligible</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p><b>Reading the five.</b> A and B are nearly level — <b>B finished nothing at all and still scored 96.6</b>,
         because keeping five sites moving with nothing overdue is real work. Finishing is rewarded; not
         finishing is not punished, as long as nothing slips past its target.
         <b>C is the pending lesson:</b> C filed as many reports as B and covered every site, yet lost
         <b>16 points</b> purely because 30 steps were left <i>Pending</i> instead of closed. Ticking a step
         Pending earns nothing — it pays only in the month it is finally marked <b>Done</b>, and it pays
         whoever closes it.
         <b>D shows the cost of slipping:</b> D worked hard (32 steps) but delivered both sites late and lost
         the entire 35-point on-time block, dropping from grade A to C — that one thing cost D about ₹4,900.
         <b>E fails on all four</b> and falls under the eligibility floor, so E is paid nothing and that share
         flows to the others.</p>
      <p><b>The whole budget is always paid out.</b> A+B+C+D score 100.0+96.6+80.4+59.1 = <b>336.1</b>, so
         A receives <span class="pf-f">40,000 × 100.0 ÷ 336.1 = ₹11,901</span>. Had E qualified, everyone
         else's share would have shrunk — the team shares one fixed monthly budget.</p>
    </div>

    <div class="pf-h3">7 · Grades, eligibility and the ₹ split</div>
    <div class="table-wrap">
      <table class="tbl tbl-resp tbl-resp-stack">
        <thead><tr><th>Rule</th><th>Detail</th></tr></thead>
        <tbody>
          <tr><td><b>Grades</b></td><td><span class="pill pill-ok">A</span> 85 and above · <span class="pill pill-info">B</span> 70–84.9 · <span class="pill pill-warn">C</span> 55–69.9 · <span class="pill pill-bad">D</span> below 55</td></tr>
          <tr><td><b>Who is eligible</b></td><td>Score ≥ <b><?= (int)$opt['min_score'] ?></b>. VAPL workers must also have at least <b><?= (int)$opt['min_visits'] ?></b> visit<?= (int)$opt['min_visits'] === 1 ? '' : 's' ?> in the window. Everyone excluded is listed under their table with the reason.</td></tr>
          <tr><td><b>How ₹ is split</b></td><td><span class="pf-f">your ₹ = budget × your score ÷ (sum of all eligible scores)</span> — proportional to score, so the whole budget is always distributed and a higher score always pays more.</td></tr>
          <tr><td><b>Paid monthly</b></td><td>The amount you enter is <b>one month's</b> budget. Pick the month at the top of the page, read the ₹ column, pay it, then move to the next month — nothing carries over. A closed month's numbers never move again, so a payout can always be re-checked.</td></tr>
          <tr><td><b>Two separate budgets</b></td><td>The PE budget is divided only between PEs; the VAPL worker budget only between VAPL workers. They never mix.</td></tr>
          <tr><td><b>Contractors</b></td><td>Scored and graded for comparison, but <b>never</b> included in either budget.</td></tr>
          <tr><td><b>Keeping it accurate</b></td><td>Press <b>Refresh start dates from PMS sheets</b> after editing any Marking start date, target end date, or step date — the scores are only as current as the last sheet read.</td></tr>
        </tbody>
      </table>
    </div>

  </div>
</details>
<?php
/**
 * Stamps every .tbl-resp cell with its column heading (data-l) and wraps the
 * cell content in .td-v, so the phone CSS above can render each row as a
 * label/value card. Rows whose cell count does not match the header (the
 * colspan "no activity" rows) are skipped and stay as they are.
 */
$respJs = <<<'JS'
<script>
(function(){
  var tables = document.querySelectorAll('table.tbl-resp');
  for (var t = 0; t < tables.length; t++) {
    var tbl = tables[t], ths = tbl.querySelectorAll('thead th'), labels = [];
    for (var h = 0; h < ths.length; h++) {
      var c = ths[h].cloneNode(true), notes = c.querySelectorAll('.pf-why');
      for (var n = 0; n < notes.length; n++) { notes[n].parentNode.removeChild(notes[n]); }
      labels.push(c.textContent.replace(/\s+/g, ' ').trim());
    }
    var rows = tbl.tBodies[0] ? tbl.tBodies[0].rows : [];
    for (var r = 0; r < rows.length; r++) {
      var cells = rows[r].cells;
      if (cells.length !== labels.length) { continue; }
      for (var i = 0; i < cells.length; i++) {
        var td = cells[i];
        if (td.hasAttribute('data-l')) { continue; }
        var v = document.createElement('span');
        v.className = 'td-v';
        while (td.firstChild) { v.appendChild(td.firstChild); }
        td.appendChild(v);
        td.setAttribute('data-l', labels[i] || '');
      }
    }
    tbl.classList.add('is-stacked');
  }

  // Tablet/desktop: a table too wide for its card still scrolls sideways — say so,
  // otherwise the off-screen columns are invisible. Hides itself once it fits.
  function hint() {
    var wraps = document.querySelectorAll('.table-wrap');
    for (var w = 0; w < wraps.length; w++) {
      var box = wraps[w], h = box.nextElementSibling;
      if (!h || h.className !== 'pf-hint') {
        h = document.createElement('div');
        h.className = 'pf-hint';
        h.innerHTML = '<i class="bi bi-arrow-left-right"></i> scroll the table sideways for the rest of the columns';
        box.parentNode.insertBefore(h, box.nextSibling);
      }
      h.style.display = (box.scrollWidth - box.clientWidth > 4) ? 'flex' : 'none';
    }
  }
  hint();
  window.addEventListener('resize', hint);
  // a table inside a closed <details> measures 0 — re-check once it is opened
  var colls = document.querySelectorAll('details.card2-coll');
  for (var d = 0; d < colls.length; d++) { colls[d].addEventListener('toggle', hint); }
})();
</script>
JS;
Layout::foot($respJs);
