<?php
/**
 * One site report in full: every captured field, the per-step pipeline timeline
 * (from process_log), attached photos/PDF, and the raw payload for auditing.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';

$db = Admin::db();
$id = (int)($_GET['id'] ?? 0);
$st = $db->prepare("SELECT * FROM submissions WHERE id = ?");
$st->execute([$id]);
$s = $st->fetch();

require __DIR__ . '/inc/layout.php';

if (!$s) {
    Layout::head('Report not found', 'submissions');
    echo '<div class="alert2 bad"><i class="bi bi-exclamation-octagon"></i> Report #' . $id . ' not found.</div>';
    echo '<a class="btn btn-ghost" href="' . Admin::BASE . '/submissions.php"><i class="bi bi-arrow-left"></i> Back to reports</a>';
    Layout::foot();
    exit;
}

$logs = $db->prepare("SELECT * FROM process_log WHERE submission_id = ? ORDER BY id ASC");
$logs->execute([$id]);
$logs = $logs->fetchAll();

$atts = $db->prepare("SELECT * FROM attachments WHERE submission_id = ? ORDER BY id ASC");
$atts->execute([$id]);
$atts = $atts->fetchAll();

$photos = array_values(array_filter($atts, fn($a) => $a['kind'] === 'site_photo'));
$pdf    = null; $drawing = null; $measure = null;
foreach ($atts as $a) {
    if ($a['kind'] === 'pdf' && $a['url']) $pdf = $a;
    if ($a['kind'] === 'drawing') $drawing = $a;
    if ($a['kind'] === 'measurement') $measure = $a;
}

$dotTone = ['done'=>'ok','failed'=>'bad','skipped'=>'muted','running'=>'info','pending'=>'warn'];

$payload = json_decode((string)$s['payload_json'], true) ?: [];

Layout::head('Report #' . $id, 'submissions');
?>
<div class="breadcrumb2"><a href="<?= Admin::BASE ?>/submissions.php">Site Reports</a> › Report #<?= (int)$id ?></div>

<div class="card2">
  <div class="card2-head">
    <i class="bi bi-file-earmark-text text-primary"></i>
    <h2><?= Admin::e(projectLabel($s)) ?></h2>
    <?= Layout::statusBadge((string)$s['status']) ?>
    <?= Layout::statusBadge((string)$s['overall_status']) ?>
    <span class="spacer"></span>
    <?php if ($pdf): ?><a class="btn btn-primary btn-sm" href="<?= Admin::e($pdf['url']) ?>" target="_blank"><i class="bi bi-file-earmark-pdf"></i> View PDF</a><?php endif; ?>
  </div>
  <div class="card2-body">
    <dl class="def-list">
      <dt>Report ID</dt><dd class="mono">#<?= (int)$s['id'] ?> · <?= Admin::e($s['public_id']) ?></dd>
      <dt>Submitted</dt><dd><?= Admin::e(fmtDateTime($s['created_at'])) ?> (<?= Admin::e(ago($s['created_at'])) ?>)</dd>
      <dt>Site / Client</dt><dd><?= Admin::e($s['site_type']) ?> · <?= Admin::e($s['client_type']) ?></dd>
      <?php if ($s['client_type'] === 'Developer'): ?>
        <dt>Developer</dt><dd><?= Admin::e($s['developer']) ?: '—' ?></dd>
        <dt>Building</dt><dd><?= Admin::e($s['building']) ?: '—' ?></dd>
        <dt>Floor / Flat</dt><dd><?= Admin::e($s['floor']) ?: '—' ?> · <?= Admin::e($s['flat_no']) ?: '—' ?></dd>
      <?php else: ?>
        <dt>Project</dt><dd><?= Admin::e($s['project']) ?: '—' ?></dd>
      <?php endif; ?>
      <dt>Order ID</dt><dd class="mono"><?= Admin::e($s['order_id']) ?: '—' ?></dd>
      <dt>Current step</dt><dd><?= Admin::e($s['current_status']) ?: '—' ?></dd>
      <dt>Work status</dt><dd><?= Layout::statusBadge((string)$s['status']) ?></dd>
      <?php if ($s['hold_reason'] || $s['hold_reason_detail']): ?>
        <dt>Hold reason</dt><dd><?= Admin::e(trim($s['hold_reason'] . ' — ' . $s['hold_reason_detail'], ' —')) ?></dd>
      <?php endif; ?>
      <dt>Engineer</dt><dd><?= Admin::e($s['engineer']) ?: '—' ?></dd>
      <dt>People on site</dt><dd><?= Admin::e($s['people']) ?: '—' ?></dd>
      <dt>Work done by</dt><dd><?= Admin::e($s['work_done_by']) ?: '—' ?></dd>
      <?php if ($s['contractor_name']): ?><dt>Contractor</dt><dd><?= Admin::e($s['contractor_name']) ?></dd><?php endif; ?>
      <dt>Tentative end</dt><dd><?= Admin::e(fmtDate($s['tentative_end'])) ?></dd>
      <dt>Activity today</dt><dd><?= nl2br(Admin::e($s['activity'])) ?: '—' ?></dd>
      <dt>Next plan</dt><dd><?= nl2br(Admin::e($s['next_plan'])) ?: '—' ?></dd>
      <dt>Amendment</dt><dd><?= Admin::e($s['amendment']) ?: '—' ?><?= $s['amendment_why'] ? ' — ' . Admin::e($s['amendment_why']) : '' ?></dd>
      <dt>Drawing change</dt><dd><?= Admin::e($s['drawing_change']) ?: '—' ?></dd>
      <dt>Measurement</dt><dd><?= Admin::e($s['measurement']) ?: '—' ?></dd>
      <dt>Response sheet</dt><dd><?= $s['response_tab'] ? Admin::e($s['response_tab'] . ' · row ' . $s['response_row']) : '—' ?></dd>
      <dt>Submitter</dt><dd><?= Admin::e($s['submitter_email']) ?: '—' ?> · <span class="mono"><?= Admin::e($s['submitter_ip']) ?></span></dd>
    </dl>
  </div>
</div>

<div class="grid-2">
  <div class="card2">
    <div class="card2-head"><i class="bi bi-diagram-2 text-primary"></i><h2>Processing Timeline</h2></div>
    <div class="card2-body">
      <?php if (!$logs): ?><div class="t-empty">No pipeline steps logged.</div><?php endif; ?>
      <ul class="timeline">
        <?php foreach ($logs as $l): ?>
          <li>
            <span class="dot <?= $dotTone[$l['status']] ?? 'muted' ?>"></span>
            <div class="tl-step"><?= Admin::e(str_replace('_', ' ', $l['step'])) ?> <?= Layout::statusBadge((string)$l['status']) ?></div>
            <div class="tl-meta">
              <?php if ($l['started_at']): ?>started <?= Admin::e(fmtDateTime($l['started_at'])) ?><?php endif; ?>
              <?php if ($l['finished_at']): ?> · finished <?= Admin::e(fmtDateTime($l['finished_at'])) ?><?php endif; ?>
              <?php if ($l['target']): ?> · <span class="mono"><?= Admin::e(snip($l['target'], 60)) ?></span><?php endif; ?>
            </div>
            <?php if ($l['message']): ?><div class="tl-msg"><?= Admin::e($l['message']) ?></div><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <div class="card2">
    <div class="card2-head"><i class="bi bi-images text-primary"></i><h2>Attachments</h2>
      <span class="sub"><?= count($photos) ?> photo<?= count($photos) === 1 ? '' : 's' ?></span></div>
    <div class="card2-body">
      <?php if ($photos): ?>
        <div class="photo-grid">
          <?php foreach ($photos as $p): ?>
            <a href="<?= Admin::e($p['url']) ?>" target="_blank" title="<?= Admin::e($p['file_name']) ?>">
              <img loading="lazy" src="<?= Admin::e(driveThumb((string)$p['url'])) ?>" alt="site photo"
                   onerror="this.parentNode.classList.add('broken');this.style.display='none';">
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="t-empty" style="padding:20px">No site photos uploaded.</div>
      <?php endif; ?>

      <div style="margin-top:16px;display:flex;flex-wrap:wrap;gap:10px">
        <?php if ($pdf): ?><a class="btn btn-ghost btn-sm" href="<?= Admin::e($pdf['url']) ?>" target="_blank"><i class="bi bi-file-earmark-pdf text-danger"></i> Report PDF</a><?php endif; ?>
        <?php if ($drawing && $drawing['url']): ?><a class="btn btn-ghost btn-sm" href="<?= Admin::e($drawing['url']) ?>" target="_blank"><i class="bi bi-vector-pen"></i> Drawing change</a><?php endif; ?>
        <?php if ($measure && $measure['url']): ?><a class="btn btn-ghost btn-sm" href="<?= Admin::e($measure['url']) ?>" target="_blank"><i class="bi bi-rulers"></i> Measurement</a><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card2">
  <div class="card2-head"><i class="bi bi-code-square text-primary"></i><h2>Raw Payload</h2>
    <span class="spacer"></span>
    <button class="btn btn-ghost btn-sm" type="button" onclick="var e=document.getElementById('raw');e.style.display=e.style.display==='none'?'block':'none';"><i class="bi bi-eye"></i> Toggle</button>
  </div>
  <div class="card2-body">
    <pre id="raw" class="mono" style="display:none;white-space:pre-wrap;word-break:break-word;background:#0f1b30;color:#c7d5ea;padding:16px;border-radius:10px;max-height:420px;overflow:auto;"><?= Admin::e(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
  </div>
</div>

<a class="btn btn-ghost" href="<?= Admin::BASE ?>/submissions.php"><i class="bi bi-arrow-left"></i> Back to reports</a>

<?php Layout::foot();
