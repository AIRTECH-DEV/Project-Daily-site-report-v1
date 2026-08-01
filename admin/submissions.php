<?php
/**
 * Site Reports — every daily-update submission, filterable and searchable.
 * Same filter set drives the CSV export (?export=csv). This is the raw ledger;
 * projects.php rolls it up per project.
 *
 * One DEVELOPER row can be a multi-flat visit: a single submission holding a
 * complete report per flat. The list stays one row per report (that is what was
 * submitted, mailed and PDF'd) but names every flat on it and links each straight
 * to its own slice; search/filter/CSV all reach inside the flats too.
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
    // flat_no only holds the FIRST flat of a multi-flat visit — the last term
    // reaches the others through the payload so searching "1201" finds the report
    // even when 1201 is the fourth flat on it.
    $where[] = "(project LIKE ? OR flat_no LIKE ? OR building LIKE ? OR engineer LIKE ? OR order_id LIKE ? OR developer LIKE ? OR payload_json LIKE ?)";
    $like = "%$q%";
    array_push($args, $like, $like, $like, $like, $like, $like, '%"flatNo":"%' . $q . '%"%');
}
if ($site !== '')    { $where[] = "site_type = ?";      $args[] = $site; }
if ($client !== '')  { $where[] = "client_type = ?";    $args[] = $client; }
// same reason: match the visit when ANY of its flats is in that state
if ($status !== '')  { $where[] = "(status = ? OR payload_json LIKE ?)"; $args[] = $status; $args[] = '%"status":"' . $status . '"%'; }
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
    // One line per FLAT: a 6-flat visit exported as one line hid five flats' work.
    fputcsv($out, ['ID','Date','Site','Client','Developer','Building','Floor','Flat','Flat # of','Project','Order ID','Engineer','People','Step','Status','Hold Reason','Work Done By','Tentative End','Activity','Next Plan','Pipeline','PDF']);
    $rows = $db->prepare("SELECT * FROM submissions $wsql ORDER BY id DESC");
    $rows->execute($args);
    while ($r = $rows->fetch()) {
        foreach (expandVisit($r) as $fr) {
            fputcsv($out, [
                $fr['id'], $fr['created_at'], $fr['site_type'], $fr['client_type'], $fr['developer'], $fr['building'],
                $fr['floor'], $fr['flat_no'], ($fr['flat_index'] + 1) . ' of ' . $fr['flat_count'],
                $fr['project'], $fr['order_id'], $fr['engineer'], $fr['people'], $fr['current_status'],
                $fr['status'], $fr['hold_reason'], $fr['work_done_by'], $fr['tentative_end'], $fr['activity'],
                $fr['next_plan'], $fr['overall_status'], $fr['pdf_url'],
            ]);
        }
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
        <?php foreach ($rows as $r):
          $frs   = expandVisit($r);
          $multi = count($frs) > 1;
          // For a multi-flat visit the row's own step/status columns describe only
          // its first flat, so summarise all of them instead.
          $tally = ['Done' => 0, 'Pending' => 0, 'Hold' => 0];
          foreach ($frs as $fr) { $k = (string)$fr['status']; if (isset($tally[$k])) $tally[$k]++; }
        ?>
          <tr>
            <td class="mono">#<?= (int)$r['id'] ?></td>
            <td>
              <a class="row-link" href="<?= Admin::BASE ?>/submission.php?id=<?= (int)$r['id'] ?>"><?= Admin::e($multi ? trim(implode(' › ', array_filter([$r['developer'], $r['building']]))) : projectLabel($r)) ?></a>
              <?php if ($multi): ?>
                <div class="flat-steps" style="margin-top:5px">
                  <span class="pill pill-type"><i class="bi bi-door-open"></i> <?= count($frs) ?> flats</span>
                  <?php foreach ($frs as $fr): $stt = strtolower((string)$fr['status']);
                    $tone = $stt === 'done' ? 'ok' : ($stt === 'hold' ? 'bad' : 'warn'); ?>
                    <a class="pill pill-<?= $tone ?>" style="text-decoration:none" title="<?= Admin::e(snip((string)$fr['current_status'], 90)) ?>"
                       href="<?= Admin::BASE ?>/submission.php?id=<?= (int)$r['id'] ?>&flat=<?= urlencode((string)$fr['flat_no']) ?>"><?= Admin::e(trim((string)$fr['flat_no']) ?: '—') ?></a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </td>
            <td><span class="pill pill-muted"><?= Admin::e($r['site_type']) ?></span> <?= Admin::e($r['client_type']) ?></td>
            <td><?= Admin::e(snip($multi ? implode(', ', array_map(fn($f) => trim((string)$f['flat_no']) . ': ' . snip((string)$f['current_status'], 22), $frs)) : (string)$r['current_status'], 24)) ?: '—' ?></td>
            <td>
              <?php if ($multi): ?>
                <?php foreach ($tally as $lbl => $n): if (!$n) continue; ?>
                  <span class="pill pill-<?= $lbl === 'Done' ? 'ok' : ($lbl === 'Hold' ? 'bad' : 'warn') ?>"><?= $n ?> <?= strtolower($lbl) ?></span>
                <?php endforeach; ?>
              <?php else: ?>
                <?= Layout::statusBadge((string)$r['status']) ?>
              <?php endif; ?>
            </td>
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
