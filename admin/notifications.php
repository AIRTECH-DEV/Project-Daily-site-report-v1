<?php
/**
 * Notification center — the in-app alert inbox. Alerts are generated + auto-
 * resolved by Sync (rules over the projects/process_log data). Here you triage
 * them: acknowledge, snooze, resolve, reopen — with a full event history. Internal
 * email/WhatsApp sending for critical alerts is handled by the alert notifier
 * (gated in Settings → Team & Alerts); this page is the inbox regardless.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';
Admin::autoSync();

$db = Admin::db();

// actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Admin::requireEditor();
    if (Admin::checkCsrf()) {
        $id  = (int)($_POST['id'] ?? 0);
        $act = $_POST['act'] ?? '';
        $me  = Admin::user()['user'];
        $ev  = $db->prepare("INSERT INTO alert_events (alert_id, event, actor, note) VALUES (?,?,?,?)");
        if ($id && in_array($act, ['ack','resolve','reopen','snooze'], true)) {
            if ($act === 'ack') {
                $db->prepare("UPDATE alerts SET status='ack', acked_at=NOW(), acked_by=? WHERE id=?")->execute([$me, $id]);
                $ev->execute([$id, 'ack', $me, null]);
            } elseif ($act === 'resolve') {
                $db->prepare("UPDATE alerts SET status='resolved', resolved_at=NOW(), resolved_by=? WHERE id=?")->execute([$me, $id]);
                $ev->execute([$id, 'resolved', $me, trim($_POST['note'] ?? '') ?: null]);
            } elseif ($act === 'reopen') {
                $db->prepare("UPDATE alerts SET status='open', resolved_at=NULL, resolved_by=NULL WHERE id=?")->execute([$id]);
                $ev->execute([$id, 'reopened', $me, null]);
            } elseif ($act === 'snooze') {
                $days = max(1, min(30, (int)($_POST['days'] ?? 1)));
                $db->prepare("UPDATE alerts SET status='snoozed', snooze_until=DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id=?")->execute([$days, $id]);
                $ev->execute([$id, 'snoozed', $me, $days . 'd']);
            }
        }
        header('Location: ' . Admin::BASE . '/notifications.php' . (($_GET['status'] ?? '') ? '?status=' . urlencode($_GET['status']) : ''));
        exit;
    }
}

// wake snoozed alerts whose time has passed
$db->exec("UPDATE alerts SET status='open' WHERE status='snoozed' AND snooze_until IS NOT NULL AND snooze_until <= NOW()");

$statusF = $_GET['status'] ?? 'open';
$sevF    = $_GET['sev'] ?? '';
$where = []; $args = [];
if ($statusF === 'open')     { $where[] = "status IN ('open','ack','snoozed')"; }
elseif ($statusF === 'resolved') { $where[] = "status='resolved'"; }
elseif ($statusF !== 'all')  { $where[] = "status=?"; $args[] = $statusF; }
if ($sevF !== '') { $where[] = "severity=?"; $args[] = $sevF; }
$wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$st = $db->prepare("SELECT * FROM alerts $wsql ORDER BY FIELD(severity,'critical','warning','info'), COALESCE(created_at,updated_at) DESC LIMIT 200");
$st->execute($args);
$alerts = $st->fetchAll();

$openCrit = (int)$db->query("SELECT COUNT(*) FROM alerts WHERE status IN ('open','ack') AND severity='critical'")->fetchColumn();
$openWarn = (int)$db->query("SELECT COUNT(*) FROM alerts WHERE status IN ('open','ack') AND severity='warning'")->fetchColumn();
$snoozed  = (int)$db->query("SELECT COUNT(*) FROM alerts WHERE status='snoozed'")->fetchColumn();
$resolved = (int)$db->query("SELECT COUNT(*) FROM alerts WHERE status='resolved' AND resolved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

$sevTone = ['critical'=>'bad','warning'=>'warn','info'=>'info'];
$csrf = Admin::csrf();
$editor = !Admin::isViewer();

require __DIR__ . '/inc/layout.php';
Layout::head('Notifications', 'notifications');
?>
<div class="kpi-grid">
  <div class="kpi"><div class="kpi-ico ic-red"><i class="bi bi-exclamation-octagon"></i></div><div class="kpi-label">Critical Open</div><div class="kpi-value"><?= $openCrit ?></div><div class="kpi-foot">need action now</div></div>
  <div class="kpi"><div class="kpi-ico ic-amber"><i class="bi bi-exclamation-triangle"></i></div><div class="kpi-label">Warnings Open</div><div class="kpi-value"><?= $openWarn ?></div><div class="kpi-foot">watch</div></div>
  <div class="kpi"><div class="kpi-ico ic-slate"><i class="bi bi-alarm"></i></div><div class="kpi-label">Snoozed</div><div class="kpi-value"><?= $snoozed ?></div><div class="kpi-foot">will re-open</div></div>
  <div class="kpi"><div class="kpi-ico ic-green"><i class="bi bi-check2-circle"></i></div><div class="kpi-label">Resolved 7d</div><div class="kpi-value"><?= $resolved ?></div><div class="kpi-foot">cleared</div></div>
</div>

<div class="card2">
  <div class="card2-head"><i class="bi bi-bell text-primary"></i><h2>Alert Inbox</h2>
    <span class="spacer"></span>
    <div class="cal-toolbar" style="gap:6px">
      <?php foreach (['open'=>'Open','resolved'=>'Resolved','all'=>'All'] as $v=>$t): ?>
        <a class="btn btn-sm <?= $statusF===$v?'btn-primary':'btn-ghost' ?>" href="?status=<?= $v ?><?= $sevF?'&sev='.$sevF:'' ?>"><?= $t ?></a>
      <?php endforeach; ?>
      <select class="card-select" onchange="location.href='?status=<?= Admin::e($statusF) ?>'+(this.value?'&sev='+this.value:'')">
        <option value="">All severities</option>
        <?php foreach (['critical','warning','info'] as $sv): ?><option value="<?= $sv ?>" <?= $sevF===$sv?'selected':'' ?>><?= ucfirst($sv) ?></option><?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="card2-body">
    <?php if (!$alerts): ?><div class="t-empty"><i class="bi bi-check-circle text-success"></i> Nothing here. All clear.</div><?php endif; ?>
    <?php foreach ($alerts as $a): $tone = $sevTone[$a['severity']] ?? 'muted'; ?>
      <div style="display:flex;gap:14px;padding:14px 0;border-bottom:1px solid var(--line-soft)">
        <div style="width:88px;flex-shrink:0">
          <span class="pill pill-<?= $tone ?>"><span class="dot"></span><?= Admin::e(ucfirst($a['severity'])) ?></span>
          <div style="font-size:11px;color:#9aa8bd;margin-top:6px"><?= Admin::e(ago($a['created_at'])) ?></div>
        </div>
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:14px;color:#0f1b30"><?= Admin::e($a['title']) ?>
            <?php if ($a['status']!=='open'): ?><span class="pill pill-muted" style="margin-left:6px"><?= Admin::e($a['status']) ?></span><?php endif; ?>
          </div>
          <div class="info-val soft" style="font-size:12.5px;margin-top:3px"><?= Admin::e($a['detail']) ?></div>
          <div style="font-size:12px;color:#8190a5;margin-top:5px">
            <?php if ($a['project_label']): ?><a class="row-link" href="<?= Admin::BASE ?>/project.php?key=<?= urlencode($a['project_key']) ?>"><?= Admin::e($a['project_label']) ?></a> · <?php endif; ?>
            <?php if ($a['owner']): ?>owner <?= Admin::e($a['owner']) ?> · <?php endif; ?>
            <?php if ($a['submission_id']): ?><a class="row-link" href="<?= Admin::BASE ?>/submission.php?id=<?= (int)$a['submission_id'] ?>">report #<?= (int)$a['submission_id'] ?></a> · <?php endif; ?>
            <?= $a['notified_at'] ? '<span title="internal alert sent">sent ' . Admin::e(ago($a['notified_at'])) . '</span>' : '' ?>
            <?= $a['snooze_until'] && $a['status']==='snoozed' ? 'wakes ' . Admin::e(fmtDate($a['snooze_until'])) : '' ?>
          </div>
        </div>
        <?php if ($editor): ?>
        <div style="flex-shrink:0;display:flex;gap:6px;align-items:flex-start;flex-wrap:wrap;max-width:230px;justify-content:flex-end">
          <?php if ($a['status']==='resolved'): ?>
            <form method="POST" action="?status=<?= Admin::e($statusF) ?>"><input type="hidden" name="csrf" value="<?= Admin::e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="act" value="reopen"><button class="btn btn-ghost btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Reopen</button></form>
          <?php else: ?>
            <?php if ($a['status']!=='ack'): ?><form method="POST" action="?status=<?= Admin::e($statusF) ?>"><input type="hidden" name="csrf" value="<?= Admin::e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="act" value="ack"><button class="btn btn-ghost btn-sm" title="Acknowledge"><i class="bi bi-eye"></i></button></form><?php endif; ?>
            <form method="POST" action="?status=<?= Admin::e($statusF) ?>"><input type="hidden" name="csrf" value="<?= Admin::e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="act" value="snooze"><input type="hidden" name="days" value="1"><button class="btn btn-ghost btn-sm" title="Snooze 1 day"><i class="bi bi-alarm"></i></button></form>
            <form method="POST" action="?status=<?= Admin::e($statusF) ?>"><input type="hidden" name="csrf" value="<?= Admin::e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="act" value="resolve"><button class="btn btn-primary btn-sm" title="Resolve"><i class="bi bi-check-lg"></i></button></form>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php Layout::foot();
