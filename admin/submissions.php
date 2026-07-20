<?php
/**
 * Site Reports — every daily-update submission, filterable and searchable.
 * Same filter set drives the CSV export (?export=csv). This is the raw ledger;
 * projects.php rolls it up per project.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';

$db = Admin::db();

// ---- build filter WHERE ----
$where = [];
$args  = [];
$q  = trim($_GET['q'] ?? '');
$site   = $_GET['site'] ?? '';
$client = $_GET['client'] ?? '';
$status = $_GET['status'] ?? '';
$overall= $_GET['overall'] ?? '';
$dev    = trim($_GET['dev'] ?? '');
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');

if ($q !== '') {
    $where[] = "(project LIKE ? OR flat_no LIKE ? OR building LIKE ? OR engineer LIKE ? OR order_id LIKE ? OR developer LIKE ?)";
    $like = "%$q%";
    array_push($args, $like, $like, $like, $like, $like, $like);
}
if ($site !== '')    { $where[] = "site_type = ?";      $args[] = $site; }
if ($client !== '')  { $where[] = "client_type = ?";    $args[] = $client; }
if ($status !== '')  { $where[] = "status = ?";         $args[] = $status; }
if ($overall !== '') { $where[] = "overall_status = ?"; $args[] = $overall; }
if ($dev !== '')     { $where[] = "developer = ?";      $args[] = $dev; }
if ($from !== '')    { $where[] = "created_at >= ?";    $args[] = $from . ' 00:00:00'; }
if ($to !== '')      { $where[] = "created_at <= ?";    $args[] = $to . ' 23:59:59'; }
$wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ---- CSV export ----
if (($_GET['export'] ?? '') === 'csv') {
    Admin::audit('export_submissions', 'submissions');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="site_reports_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Date','Site','Client','Developer','Building','Flat','Project','Order ID','Engineer','People','Step','Status','Hold Reason','Work Done By','Tentative End','Activity','Next Plan','Pipeline','PDF']);
    $rows = $db->prepare("SELECT * FROM submissions $wsql ORDER BY id DESC");
    $rows->execute($args);
    while ($r = $rows->fetch()) {
        fputcsv($out, [
            $r['id'], $r['created_at'], $r['site_type'], $r['client_type'], $r['developer'], $r['building'],
            $r['flat_no'], $r['project'], $r['order_id'], $r['engineer'], $r['people'], $r['current_status'],
            $r['status'], $r['hold_reason'], $r['work_done_by'], $r['tentative_end'], $r['activity'],
            $r['next_plan'], $r['overall_status'], $r['pdf_url'],
        ]);
    }
    fclose($out);
    exit;
}

// ---- pagination ----
$page  = max(1, (int)($_GET['page'] ?? 1));
$per   = 25;
$stC = $db->prepare("SELECT COUNT(*) FROM submissions $wsql"); $stC->execute($args);
$totalRows = (int)$stC->fetchColumn();
$pages = max(1, (int)ceil($totalRows / $per));
$page  = min($page, $pages);
$off   = ($page - 1) * $per;

$st = $db->prepare("SELECT * FROM submissions $wsql ORDER BY id DESC LIMIT $per OFFSET $off");
$st->execute($args);
$rows = $st->fetchAll();

// filter option sources
$devs = $db->query("SELECT DISTINCT developer FROM submissions WHERE developer<>'' ORDER BY developer")->fetchAll(PDO::FETCH_COLUMN);

$qs = function (array $over = []) {
    $p = array_merge($_GET, $over);
    unset($p['export']);
    return http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
};

require __DIR__ . '/inc/layout.php';
Layout::head('Site Reports', 'submissions');
?>

<div class="card2">
  <div class="card2-head"><i class="bi bi-funnel text-primary"></i><h2>Filters</h2>
    <span class="spacer"></span>
    <a class="btn btn-ghost btn-sm" href="?<?= Admin::e($qs(['export' => 'csv'])) ?>&export=csv"><i class="bi bi-download"></i> Export CSV</a>
  </div>
  <div class="card2-body">
    <form class="filters" method="GET">
      <div class="fld"><label>Search</label><input type="text" name="q" value="<?= Admin::e($q) ?>" placeholder="project, flat, engineer, order id…" style="min-width:230px"></div>
      <div class="fld"><label>Site</label>
        <select name="site"><option value="">All</option>
          <?php foreach (['VRV','Non-VRV'] as $o): ?><option value="<?= $o ?>" <?= $site === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?>
        </select></div>
      <div class="fld"><label>Client</label>
        <select name="client"><option value="">All</option>
          <?php foreach (['General','Developer'] as $o): ?><option value="<?= $o ?>" <?= $client === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?>
        </select></div>
      <div class="fld"><label>Work status</label>
        <select name="status"><option value="">All</option>
          <?php foreach (['Done','Pending','Hold'] as $o): ?><option value="<?= $o ?>" <?= $status === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?>
        </select></div>
      <div class="fld"><label>Pipeline</label>
        <select name="overall"><option value="">All</option>
          <?php foreach (['done','partial','failed','processing','awaiting_notify','queued'] as $o): ?><option value="<?= $o ?>" <?= $overall === $o ? 'selected' : '' ?>><?= ucfirst($o) ?></option><?php endforeach; ?>
        </select></div>
      <?php if ($devs): ?>
      <div class="fld"><label>Developer</label>
        <select name="dev"><option value="">All</option>
          <?php foreach ($devs as $o): ?><option value="<?= Admin::e($o) ?>" <?= $dev === $o ? 'selected' : '' ?>><?= Admin::e($o) ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <div class="fld"><label>From</label><input type="date" name="from" value="<?= Admin::e($from) ?>"></div>
      <div class="fld"><label>To</label><input type="date" name="to" value="<?= Admin::e($to) ?>"></div>
      <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Apply</button>
      <a class="btn btn-ghost" href="<?= Admin::BASE ?>/submissions.php">Reset</a>
    </form>
  </div>
</div>

<div class="card2">
  <div class="card2-head"><i class="bi bi-card-list text-primary"></i><h2>Reports</h2>
    <span class="sub"><?= number_format($totalRows) ?> match<?= $totalRows === 1 ? '' : 'es' ?></span></div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>#</th><th>Project / Unit</th><th>Type</th><th>Step</th><th>Status</th><th>Engineer</th><th>Order ID</th><th>Pipeline</th><th>Date</th><th></th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="10" class="t-empty">No reports match these filters.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="mono">#<?= (int)$r['id'] ?></td>
            <td><a class="row-link" href="<?= Admin::BASE ?>/submission.php?id=<?= (int)$r['id'] ?>"><?= Admin::e(projectLabel($r)) ?></a></td>
            <td><span class="pill pill-muted"><?= Admin::e($r['site_type']) ?></span> <?= Admin::e($r['client_type']) ?></td>
            <td><?= Admin::e(snip($r['current_status'], 24)) ?: '—' ?></td>
            <td><?= Layout::statusBadge((string)$r['status']) ?></td>
            <td><?= Admin::e($r['engineer']) ?: '—' ?></td>
            <td class="mono"><?= Admin::e($r['order_id']) ?: '—' ?></td>
            <td><?= Layout::statusBadge((string)$r['overall_status']) ?></td>
            <td title="<?= Admin::e(fmtDateTime($r['created_at'])) ?>"><?= Admin::e(fmtDate($r['created_at'])) ?></td>
            <td><a class="row-link" href="<?= Admin::BASE ?>/submission.php?id=<?= (int)$r['id'] ?>"><i class="bi bi-chevron-right"></i></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <div class="pager">
    <?php if ($page > 1): ?><a href="?<?= Admin::e($qs(['page' => $page - 1])) ?>">‹ Prev</a><?php else: ?><span class="disabled">‹ Prev</span><?php endif; ?>
    <span class="cur"><?= $page ?> / <?= $pages ?></span>
    <?php if ($page < $pages): ?><a href="?<?= Admin::e($qs(['page' => $page + 1])) ?>">Next ›</a><?php else: ?><span class="disabled">Next ›</span><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php Layout::foot();
