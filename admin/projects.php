<?php
/**
 * Projects — rolls the raw report ledger up to one row per project/unit, showing
 * its latest step, current status, visit count, and last-update recency. This is
 * the "where does every project stand right now" view.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';

$db = Admin::db();
$q  = trim($_GET['q'] ?? '');
$statusF = $_GET['status'] ?? '';

$rows = $db->query("SELECT id, site_type, client_type, developer, building, floor, flat_no, project, order_id,
                           engineer, current_status, status, overall_status, tentative_end, created_at
                    FROM submissions ORDER BY id DESC")->fetchAll();

// group latest-first: first row seen for a key is its current state
$proj = [];
foreach ($rows as $r) {
    $k = projectKey($r);
    if (!isset($proj[$k])) {
        $proj[$k] = [
            'label'   => projectLabel($r),
            'row'     => $r,           // latest
            'visits'  => 0,
            'first'   => $r['created_at'],
            'engineers' => [],
        ];
    }
    $proj[$k]['visits']++;
    $proj[$k]['first'] = $r['created_at']; // keeps overwriting -> ends at oldest (rows are DESC)
    if ($r['engineer'] !== '') $proj[$k]['engineers'][$r['engineer']] = true;
}

// filters
if ($q !== '') {
    $needle = mb_strtolower($q);
    $proj = array_filter($proj, fn($p) => mb_strpos(mb_strtolower($p['label']), $needle) !== false
        || mb_strpos(mb_strtolower((string)$p['row']['order_id']), $needle) !== false);
}
if ($statusF !== '') {
    $proj = array_filter($proj, fn($p) => $p['row']['status'] === $statusF);
}

$totalProj = count($proj);
$holdProj  = count(array_filter($proj, fn($p) => $p['row']['status'] === 'Hold'));
$doneProj  = count(array_filter($proj, fn($p) => $p['row']['status'] === 'Done'));
$pendProj  = count(array_filter($proj, fn($p) => $p['row']['status'] === 'Pending'));

require __DIR__ . '/inc/layout.php';
Layout::head('Projects', 'projects');
?>
<div class="kpi-grid">
  <div class="kpi"><div class="kpi-ico ic-blue"><i class="bi bi-buildings"></i></div><div class="kpi-label">Projects Tracked</div><div class="kpi-value"><?= $totalProj ?></div><div class="kpi-foot">unique units / sites</div></div>
  <div class="kpi"><div class="kpi-ico ic-green"><i class="bi bi-check2-circle"></i></div><div class="kpi-label">Latest Done</div><div class="kpi-value"><?= $doneProj ?></div><div class="kpi-foot">last step complete</div></div>
  <div class="kpi"><div class="kpi-ico ic-amber"><i class="bi bi-hourglass-split"></i></div><div class="kpi-label">Pending</div><div class="kpi-value"><?= $pendProj ?></div><div class="kpi-foot">step in progress</div></div>
  <div class="kpi"><div class="kpi-ico ic-red"><i class="bi bi-pause-circle"></i></div><div class="kpi-label">On Hold</div><div class="kpi-value"><?= $holdProj ?></div><div class="kpi-foot">blocked / stuck</div></div>
</div>

<div class="card2">
  <div class="card2-head"><i class="bi bi-buildings text-primary"></i><h2>Project Status Board</h2>
    <span class="spacer"></span>
    <form class="filters" method="GET" style="gap:8px">
      <input class="inp" type="text" name="q" value="<?= Admin::e($q) ?>" placeholder="Search project / order id…" style="min-width:200px">
      <select name="status" class="inp" onchange="this.form.submit()"><option value="">All status</option>
        <?php foreach (['Done','Pending','Hold'] as $o): ?><option value="<?= $o ?>" <?= $statusF === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?>
      </select>
      <?php if ($q || $statusF): ?><a class="btn btn-ghost btn-sm" href="<?= Admin::BASE ?>/projects.php">Reset</a><?php endif; ?>
    </form>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Project / Unit</th><th>Type</th><th>Latest Step</th><th>Status</th><th>Visits</th><th>Engineer</th><th>Last Update</th><th>Tentative End</th><th></th></tr></thead>
      <tbody>
        <?php if (!$proj): ?><tr><td colspan="9" class="t-empty">No projects match.</td></tr><?php endif; ?>
        <?php foreach ($proj as $p): $r = $p['row'];
          $link = Admin::BASE . '/project.php?key=' . urlencode(projectKey($r));
        ?>
          <tr>
            <td><a class="row-link" href="<?= Admin::e($link) ?>"><?= Admin::e($p['label']) ?></a><?php if ($r['order_id']): ?><div class="mono" style="color:#94a3b8;font-size:11.5px"><?= Admin::e($r['order_id']) ?></div><?php endif; ?></td>
            <td><span class="pill pill-muted"><?= Admin::e($r['site_type']) ?></span></td>
            <td><?= Admin::e(snip($r['current_status'], 28)) ?: '—' ?></td>
            <td><?= Layout::statusBadge((string)$r['status']) ?></td>
            <td><span class="pill pill-info"><?= (int)$p['visits'] ?></span></td>
            <td><?= Admin::e(implode(', ', array_slice(array_keys($p['engineers']), 0, 2))) ?: '—' ?></td>
            <td title="<?= Admin::e(fmtDateTime($r['created_at'])) ?>"><?= Admin::e(ago($r['created_at'])) ?></td>
            <td><?= Admin::e(fmtDate($r['tentative_end'])) ?></td>
            <td><a class="row-link" href="<?= Admin::BASE ?>/submission.php?id=<?= (int)$r['id'] ?>" title="Latest report"><i class="bi bi-chevron-right"></i></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php Layout::foot();
