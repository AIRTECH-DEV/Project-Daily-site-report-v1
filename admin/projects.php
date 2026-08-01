<?php
/**
 * Projects — rolls the raw report ledger up to one row per project/unit, showing
 * its latest step, current status, visit count, and last-update recency. This is
 * the "where does every project stand right now" view.
 *
 * A DEVELOPER unit is a FLAT, and one visit can report on many flats at once
 * (submissions.payload_json flats[]) — so the ledger is expanded per flat before
 * anything is grouped, and developer units are shown as a
 * Developer › Building › Flat tree instead of one long flat list.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';
Admin::autoSync();

$db = Admin::db();
$q  = trim($_GET['q'] ?? '');
$statusF = $_GET['status'] ?? '';

$rows = expandVisits($db->query(
    "SELECT id, site_type, client_type, developer, building, floor, flat_no, project, order_id,
            engineer, current_status, status, overall_status, tentative_end, payload_json, created_at
     FROM submissions ORDER BY id DESC"
)->fetchAll());

// progress / lifecycle rollup per unit (rebuilt by Sync from the same rows)
$master = [];
foreach ($db->query("SELECT project_key, steps_done, steps_total, current_step, lifecycle, hold_owner, target_end FROM projects") as $m) {
    $master[$m['project_key']] = $m;
}

// group latest-first: first row seen for a key is its current state
$proj = [];
foreach ($rows as $r) {
    $k = projectKey($r);
    if (!isset($proj[$k])) {
        $proj[$k] = [
            'key'     => $k,
            'label'   => projectLabel($r),
            'row'     => $r,           // latest
            'visits'  => 0,
            'first'   => $r['created_at'],
            'engineers' => [],
            'master'  => $master[$k] ?? null,
        ];
    }
    $proj[$k]['visits']++;
    $proj[$k]['first'] = $r['created_at']; // keeps overwriting -> ends at oldest (rows are DESC)
    if ($r['engineer'] !== '') $proj[$k]['engineers'][$r['engineer']] = true;
}

// filters
if ($q !== '') {
    $needle = mb_strtolower($q);
    $proj = array_filter($proj, function ($p) use ($needle) {
        $hay = mb_strtolower(implode(' ', [
            $p['label'], (string)$p['row']['order_id'], (string)$p['row']['developer'],
            (string)$p['row']['building'], (string)$p['row']['flat_no'], (string)$p['row']['floor'],
        ]));
        return mb_strpos($hay, $needle) !== false;
    });
}
if ($statusF !== '') {
    $proj = array_filter($proj, fn($p) => $p['row']['status'] === $statusF);
}

$totalProj = count($proj);
$holdProj  = count(array_filter($proj, fn($p) => $p['row']['status'] === 'Hold'));
$doneProj  = count(array_filter($proj, fn($p) => $p['row']['status'] === 'Done'));
$pendProj  = count(array_filter($proj, fn($p) => $p['row']['status'] === 'Pending'));

/** Progress as [done, total, pct] — from the synced rollup, 0 when not synced yet. */
$progress = function (array $p): array {
    $m = $p['master'];
    $done = (int)($m['steps_done'] ?? 0);
    $total = (int)($m['steps_total'] ?? 0);
    return [$done, $total, $total > 0 ? (int)round($done * 100 / $total) : 0];
};

// ---- Developer units -> Developer › Building › Flat tree --------------------
$tree = [];     // developer => building => [units]
$general = [];  // General / order-keyed projects
foreach ($proj as $p) {
    $r = $p['row'];
    if (($r['client_type'] ?? '') === 'Developer') {
        $dev = trim((string)$r['developer']) ?: '(no developer)';
        $bld = trim((string)$r['building'])  ?: '(no building)';
        $tree[$dev][$bld][] = $p;
    } else {
        $general[] = $p;
    }
}
ksort($tree, SORT_NATURAL | SORT_FLAG_CASE);
foreach ($tree as &$blds) {
    ksort($blds, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($blds as &$units) {
        usort($units, fn($a, $b) => strnatcasecmp((string)$a['row']['flat_no'], (string)$b['row']['flat_no']));
    }
    unset($units);
}
unset($blds);

/** Flats + their state rolled up for a building / developer header strip. */
$rollup = function (array $units) use ($progress): array {
    $r = ['flats' => count($units), 'done' => 0, 'hold' => 0, 'pending' => 0, 'pct' => 0, 'commissioned' => 0];
    $pctSum = 0;
    foreach ($units as $u) {
        [$d, $t, $pct] = $progress($u);
        $pctSum += $pct;
        $st = (string)$u['row']['status'];
        if ($st === 'Hold') $r['hold']++;
        elseif ($st === 'Pending') $r['pending']++;
        elseif ($st === 'Done') $r['done']++;
        if (in_array((string)($u['master']['lifecycle'] ?? ''), ['Commissioned', 'Closed'], true)) $r['commissioned']++;
    }
    $r['pct'] = $units ? (int)round($pctSum / count($units)) : 0;
    return $r;
};

$devFlatCount = 0;
foreach ($tree as $blds2) foreach ($blds2 as $units2) $devFlatCount += count($units2);
$openAll = ($q !== '' || $statusF !== '');   // a filtered view opens every group

require __DIR__ . '/inc/layout.php';
Layout::head('Projects', 'projects');
?>
<div class="kpi-grid">
  <div class="kpi"><div class="kpi-ico ic-blue"><i class="bi bi-buildings"></i></div><div class="kpi-label">Units Tracked</div><div class="kpi-value"><?= $totalProj ?></div><div class="kpi-foot"><?= $devFlatCount ?> flat(s) · <?= count($general) ?> site(s)</div></div>
  <div class="kpi"><div class="kpi-ico ic-green"><i class="bi bi-check2-circle"></i></div><div class="kpi-label">Latest Done</div><div class="kpi-value"><?= $doneProj ?></div><div class="kpi-foot">last step complete</div></div>
  <div class="kpi"><div class="kpi-ico ic-amber"><i class="bi bi-hourglass-split"></i></div><div class="kpi-label">Pending</div><div class="kpi-value"><?= $pendProj ?></div><div class="kpi-foot">step in progress</div></div>
  <div class="kpi"><div class="kpi-ico ic-red"><i class="bi bi-pause-circle"></i></div><div class="kpi-label">On Hold</div><div class="kpi-value"><?= $holdProj ?></div><div class="kpi-foot">blocked / stuck</div></div>
</div>

<div class="card2">
  <div class="card2-head"><i class="bi bi-funnel text-primary"></i><h2>Find a project or flat</h2>
    <span class="spacer"></span>
    <form class="filters" method="GET" style="gap:8px">
      <input class="inp" type="text" name="q" value="<?= Admin::e($q) ?>" placeholder="Developer, building, flat, project, order id…" style="min-width:260px">
      <select name="status" class="inp" onchange="this.form.submit()"><option value="">All status</option>
        <?php foreach (['Done','Pending','Hold'] as $o): ?><option value="<?= $o ?>" <?= $statusF === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-search"></i> Search</button>
      <?php if ($q || $statusF): ?><a class="btn btn-ghost btn-sm" href="<?= Admin::BASE ?>/projects.php">Reset</a><?php endif; ?>
    </form>
  </div>
</div>

<?php
/** One flat / one site row inside a building block. */
$unitRow = function (array $p) use ($progress) {
    $r = $p['row'];
    $m = $p['master'];
    [$done, $total, $pct] = $progress($p);
    $link = Admin::BASE . '/project.php?key=' . urlencode($p['key']);
    $step = trim((string)($m['current_step'] ?? '')) ?: trim((string)$r['current_status']);
    ob_start(); ?>
    <tr>
      <td>
        <a class="row-link" href="<?= Admin::e($link) ?>"><?= Admin::e(trim((string)$r['flat_no']) ?: '(no flat no.)') ?></a>
        <?php if (trim((string)$r['floor']) !== ''): ?><div class="unit-sub"><?= Admin::e($r['floor']) ?></div><?php endif; ?>
      </td>
      <td><?= $m ? Layout::lifecyclePill((string)$m['lifecycle']) : '<span class="info-val soft">—</span>' ?></td>
      <td>
        <div class="unit-prog">
          <div class="bar-track" style="height:8px"><div class="bar-fill" style="width:<?= max(2, $pct) ?>%"></div></div>
          <span class="unit-pct"><?= $total ? $done . '/' . $total : '—' ?></span>
        </div>
      </td>
      <td><?= Admin::e(snip($step, 26)) ?: '—' ?></td>
      <td><?= Layout::statusBadge((string)$r['status']) ?><?php if (!empty($m['hold_owner'])): ?> <span class="pill pill-<?= partyTone((string)$m['hold_owner']) ?>">on <?= Admin::e($m['hold_owner']) ?></span><?php endif; ?></td>
      <td><span class="pill pill-info"><?= (int)$p['visits'] ?></span></td>
      <td><?= Admin::e(implode(', ', array_slice(array_keys($p['engineers']), 0, 2))) ?: '—' ?></td>
      <td title="<?= Admin::e(fmtDateTime($r['created_at'])) ?>"><?= Admin::e(ago($r['created_at'])) ?></td>
      <td><?= Admin::e(fmtDate($m['target_end'] ?? $r['tentative_end'])) ?></td>
      <td><a class="row-link" href="<?= Admin::BASE ?>/submission.php?id=<?= (int)$r['id'] ?>" title="Latest report"><i class="bi bi-chevron-right"></i></a></td>
    </tr>
    <?php return ob_get_clean();
};
$unitHead = '<thead><tr><th>Flat</th><th>Lifecycle</th><th>Progress</th><th>Current Step</th><th>Status</th><th>Visits</th><th>PE</th><th>Last Update</th><th>Target End</th><th></th></tr></thead>';
?>

<?php if ($tree): ?>
<div class="card2">
  <div class="card2-head"><i class="bi bi-buildings text-primary"></i><h2>Developer Sites</h2>
    <span class="sub"><?= count($tree) ?> developer(s) · <?= $devFlatCount ?> flat(s) tracked individually</span></div>
  <div class="card2-body">
    <?php foreach ($tree as $dev => $blds): $dr = $rollup(array_merge(...array_values($blds))); ?>
      <details class="tree-dev" open>
        <summary>
          <i class="bi bi-chevron-right tw"></i>
          <span class="tree-ic"><i class="bi bi-building"></i></span>
          <span class="tree-name"><?= Admin::e($dev) ?></span>
          <span class="tree-meta"><?= count($blds) ?> building(s) · <?= $dr['flats'] ?> flat(s)</span>
          <span class="spacer"></span>
          <span class="pill pill-muted"><?= $dr['pct'] ?>% avg</span>
          <?php if ($dr['hold']): ?><span class="pill pill-bad"><?= $dr['hold'] ?> on hold</span><?php endif; ?>
          <?php if ($dr['commissioned']): ?><span class="pill pill-ok"><?= $dr['commissioned'] ?> commissioned</span><?php endif; ?>
        </summary>

        <?php foreach ($blds as $bld => $units): $br = $rollup($units); ?>
          <details class="tree-bld" <?= $openAll || count($blds) <= 3 ? 'open' : '' ?>>
            <summary>
              <i class="bi bi-chevron-right tw"></i>
              <span class="tree-ic sm"><i class="bi bi-columns-gap"></i></span>
              <span class="tree-name"><?= Admin::e($bld) ?></span>
              <span class="tree-meta"><?= $br['flats'] ?> flat(s)</span>
              <span class="spacer"></span>
              <div class="bar-track tree-bar"><div class="bar-fill" style="width:<?= max(2, $br['pct']) ?>%"></div></div>
              <span class="tree-meta"><?= $br['pct'] ?>%</span>
              <?php if ($br['hold']): ?><span class="pill pill-bad"><?= $br['hold'] ?> hold</span><?php endif; ?>
              <?php if ($br['pending']): ?><span class="pill pill-warn"><?= $br['pending'] ?> pending</span><?php endif; ?>
              <?php if ($br['done']): ?><span class="pill pill-ok"><?= $br['done'] ?> done</span><?php endif; ?>
            </summary>
            <div class="table-wrap">
              <table class="tbl">
                <?= $unitHead ?>
                <tbody><?php foreach ($units as $u) echo $unitRow($u); ?></tbody>
              </table>
            </div>
          </details>
        <?php endforeach; ?>
      </details>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card2">
  <div class="card2-head"><i class="bi bi-folder2-open text-primary"></i><h2>General Projects</h2>
    <span class="sub"><?= count($general) ?> site(s) · one row per order</span></div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Project / Site</th><th>Type</th><th>Progress</th><th>Latest Step</th><th>Status</th><th>Visits</th><th>Engineer</th><th>Last Update</th><th>Tentative End</th><th></th></tr></thead>
      <tbody>
        <?php if (!$general): ?><tr><td colspan="10" class="t-empty">No general projects match.</td></tr><?php endif; ?>
        <?php foreach ($general as $p): $r = $p['row']; $m = $p['master']; [$done, $total, $pct] = $progress($p);
          $link = Admin::BASE . '/project.php?key=' . urlencode($p['key']);
        ?>
          <tr>
            <td><a class="row-link" href="<?= Admin::e($link) ?>"><?= Admin::e($p['label']) ?></a><?php if ($r['order_id']): ?><div class="mono" style="color:#94a3b8;font-size:11.5px"><?= Admin::e($r['order_id']) ?></div><?php endif; ?></td>
            <td><span class="pill pill-muted"><?= Admin::e($r['site_type']) ?></span></td>
            <td>
              <div class="unit-prog">
                <div class="bar-track" style="height:8px"><div class="bar-fill" style="width:<?= max(2, $pct) ?>%"></div></div>
                <span class="unit-pct"><?= $total ? $done . '/' . $total : '—' ?></span>
              </div>
            </td>
            <td><?= Admin::e(snip(trim((string)($m['current_step'] ?? '')) ?: (string)$r['current_status'], 26)) ?: '—' ?></td>
            <td><?= Layout::statusBadge((string)$r['status']) ?></td>
            <td><span class="pill pill-info"><?= (int)$p['visits'] ?></span></td>
            <td><?= Admin::e(implode(', ', array_slice(array_keys($p['engineers']), 0, 2))) ?: '—' ?></td>
            <td title="<?= Admin::e(fmtDateTime($r['created_at'])) ?>"><?= Admin::e(ago($r['created_at'])) ?></td>
            <td><?= Admin::e(fmtDate($m['target_end'] ?? $r['tentative_end'])) ?></td>
            <td><a class="row-link" href="<?= Admin::BASE ?>/submission.php?id=<?= (int)$r['id'] ?>" title="Latest report"><i class="bi bi-chevron-right"></i></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php Layout::foot();
