<?php
/**
 * Shared Links — every client link this panel has issued, live or dead.
 *
 * This is the kill switch: a link is a bearer credential, so somebody has to be
 * able to see who handed out what, how often it was opened, and cut it off. Also
 * purges links whose retention window has passed.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/../src/ShareLink.php';

$db  = Admin::db();
$cfg = Admin::cfg();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Admin::checkCsrf()) {
    Admin::requireSharer();
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['do'] ?? '') === 'revoke' && $id > 0) {
        ShareLink::revoke($db, $id, Admin::user()['user']);
        Admin::audit('share_revoke', 'share_links', $id);
    }
    header('Location: ' . Admin::BASE . '/shares.php');
    exit;
}

// Housekeeping: dead links (and their events) drop off after the retention window.
try { ShareLink::purge($db, (int)($cfg['share']['retain_days'] ?? 30)); } catch (Throwable $e) {}

$filter = (string)($_GET['state'] ?? 'live');
$where  = match ($filter) {
    'dead' => "revoked_at IS NOT NULL OR expires_at <= NOW()",
    'all'  => "1",
    default => "revoked_at IS NULL AND expires_at > NOW()",
};
$links = $db->query("SELECT * FROM share_links WHERE $where ORDER BY id DESC LIMIT 200")->fetchAll();

$liveN = (int)$db->query("SELECT COUNT(*) FROM share_links WHERE revoked_at IS NULL AND expires_at > NOW()")->fetchColumn();
$viewN = (int)$db->query("SELECT COUNT(*) FROM share_events WHERE event='view' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

// Recent activity across all links, newest first.
$events = $db->query(
    "SELECT e.*, l.label FROM share_events e
       JOIN share_links l ON l.id = e.link_id
      WHERE e.event IN ('view','download','sent','denied','revoked')
      ORDER BY e.id DESC LIMIT 60"
)->fetchAll();

require __DIR__ . '/inc/layout.php';
Layout::head('Shared Links', 'shares');
?>
<div class="kpi-row">
  <div class="kpi"><div class="kpi-ico ic-blue"><i class="bi bi-link-45deg"></i></div>
    <div class="kpi-label">Live client links</div><div class="kpi-value"><?= $liveN ?></div>
    <div class="kpi-foot">expire automatically</div></div>
  <div class="kpi"><div class="kpi-ico ic-green"><i class="bi bi-eye"></i></div>
    <div class="kpi-label">Client views (7 days)</div><div class="kpi-value"><?= $viewN ?></div>
    <div class="kpi-foot">bots and mail scanners excluded</div></div>
</div>

<div class="card2">
  <div class="card2-head"><i class="bi bi-share text-primary"></i><h2>Client share links</h2>
    <span class="sub">
      <a class="row-link" href="?state=live">Live</a> ·
      <a class="row-link" href="?state=dead">Expired/revoked</a> ·
      <a class="row-link" href="?state=all">All</a>
    </span></div>
  <div class="card2-body">
    <?php if (!$links): ?><div class="t-empty">No links in this view.</div><?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Project</th><th>Scope</th><th>Shared by</th><th>Sent</th><th>Opened</th><th>Expires</th><th>State</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($links as $l):
          $dead = $l['revoked_at'] || strtotime((string)$l['expires_at']) <= time();
        ?>
          <tr>
            <td style="font-weight:600;max-width:280px"><?= Admin::e($l['label']) ?></td>
            <td><span class="pill pill-type"><?= Admin::e($l['scope']) ?></span></td>
            <td class="info-val soft" style="font-size:12px"><?= Admin::e($l['created_by']) ?><br><?= Admin::e(ago((string)$l['created_at'])) ?></td>
            <td class="info-val soft" style="font-size:12px"><?= Admin::e($l['sent_summary'] ?: 'link only') ?></td>
            <td><?= (int)$l['views'] ?><?= $l['last_view_at'] ? '<div class="info-val soft" style="font-size:11.5px">' . Admin::e(ago((string)$l['last_view_at'])) . '</div>' : '' ?></td>
            <td class="info-val soft" style="font-size:12px"><?= Admin::e(fmtDateTime($l['expires_at'])) ?></td>
            <td>
              <?php if ($l['revoked_at']): ?><span class="pill pill-muted">Revoked</span>
              <?php elseif ($dead): ?><span class="pill pill-muted">Expired</span>
              <?php else: ?><span class="pill pill-ok"><?= Admin::e(ShareLink::timeLeft((string)$l['expires_at'])) ?> left</span><?php endif; ?>
            </td>
            <td>
              <?php if (!$dead && Admin::canShare()): ?>
                <form method="POST" onsubmit="return confirm('Revoke this link? The client will not be able to open it again.');">
                  <?= Admin::csrfField() ?><input type="hidden" name="do" value="revoke"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit"><i class="bi bi-slash-circle text-danger"></i> Revoke</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card2">
  <div class="card2-head"><i class="bi bi-activity text-primary"></i><h2>Recent link activity</h2>
    <span class="sub">recipients are stored masked</span></div>
  <div class="card2-body" style="max-height:460px;overflow:auto">
    <?php if (!$events): ?><div class="t-empty">Nothing yet.</div><?php endif; ?>
    <?php foreach ($events as $e):
      $tone = ['view'=>'ok','download'=>'info','sent'=>'info','denied'=>'bad','revoked'=>'muted'][$e['event']] ?? 'muted';
    ?>
      <div class="up-item">
        <div style="width:96px;flex-shrink:0"><span class="pill pill-<?= $tone ?>"><?= Admin::e(ucfirst((string)$e['event'])) ?></span></div>
        <div class="up-body">
          <div class="info-val" style="font-size:12.5px"><?= Admin::e($e['label']) ?><?= $e['target'] ? ' → ' . Admin::e($e['target']) : '' ?></div>
          <div class="info-val soft" style="font-size:11.5px;margin-top:2px"><?= Admin::e(fmtDateTime($e['created_at'])) ?><?= $e['detail'] ? ' · ' . Admin::e(snip((string)$e['detail'], 60)) : '' ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php
Layout::foot();
