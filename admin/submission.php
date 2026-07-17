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

$payload = json_decode((string)$s['payload_json'], true) ?: [];

// friendly report code (PPR-YYYY-MM-#####) from created date + id
$reportCode = 'PPR-' . date('Y-m', strtotime((string)$s['created_at'])) . '-' . str_pad((string)$s['id'], 5, '0', STR_PAD_LEFT);

// build the 3 info columns: each row = [icon, label, value-html]
$e = fn($v) => Admin::e($v);
$dash = fn($v) => ($v !== null && $v !== '') ? $v : '—';

$col1 = [
    ['bi-hash', 'Report ID', '<span class="mono">' . $e($reportCode) . '</span>'],
    ['bi-calendar-check', 'Submitted', $e(fmtDateTime($s['created_at'])) . ' <span class="info-val soft">(' . $e(ago($s['created_at'])) . ')</span>'],
    ['bi-diagram-3', 'Site / Client', $e($dash($s['site_type'])) . ' &middot; ' . $e($dash($s['client_type']))],
];
if ($s['client_type'] === 'Developer') {
    $col1[] = ['bi-building', 'Developer', $e($dash($s['developer']))];
    $col1[] = ['bi-columns-gap', 'Building', $e($dash($s['building']))];
    $col1[] = ['bi-door-open', 'Floor / Flat', $e($dash($s['floor'])) . ' &middot; ' . $e($dash($s['flat_no']))];
} else {
    $col1[] = ['bi-folder2', 'Project', $e($dash($s['project']))];
}
$col1[] = ['bi-receipt', 'Order ID', '<span class="mono">' . $e($dash($s['order_id'])) . '</span>'];
$col1[] = ['bi-list-check', 'Current step', $e($dash($s['current_status']))];

$col2 = [
    ['bi-activity', 'Work status', Layout::statusBadge((string)$s['status'])],
];
if ($s['hold_reason'] || $s['hold_reason_detail']) {
    $col2[] = ['bi-flag', 'Hold reason', nl2br($e(trim($s['hold_reason'] . ' — ' . $s['hold_reason_detail'], ' —')))];
}
$col2[] = ['bi-person', 'Engineer', $e($dash($s['engineer']))];
$col2[] = ['bi-people', 'People on site', $e($dash($s['people']))];
$col2[] = ['bi-hammer', 'Work done by', '<span class="info-val soft">' . nl2br($e($dash($s['work_done_by']))) . '</span>'];
if ($s['contractor_name']) {
    $col2[] = ['bi-person-badge', 'Contractor', $e($s['contractor_name'])];
}

$col3 = [
    ['bi-calendar-event', 'Tentative end', $e($dash(fmtDate($s['tentative_end'])))],
    ['bi-clipboard-check', 'Activity today', '<span class="info-val soft">' . nl2br($e($dash($s['activity']))) . '</span>'],
    ['bi-signpost-2', 'Next plan', '<span class="info-val soft">' . nl2br($e($dash($s['next_plan']))) . '</span>'],
    ['bi-pencil-square', 'Amendment', $e($dash($s['amendment'])) . ($s['amendment_why'] ? ' <span class="info-val soft">— ' . $e($s['amendment_why']) . '</span>' : '')],
    ['bi-vector-pen', 'Drawing change', $e($dash($s['drawing_change']))],
    ['bi-rulers', 'Measurement', $e($dash($s['measurement']))],
    ['bi-table', 'Response sheet', $s['response_tab'] ? $e($s['response_tab'] . ' · row ' . $s['response_row']) : '—'],
    ['bi-person-circle', 'Submitter', $e($dash($s['submitter_email'])) . ' <span class="mono info-val soft">' . $e($s['submitter_ip']) . '</span>'],
];

$renderCol = function (array $rows) {
    echo '<div class="info-col">';
    foreach ($rows as [$icon, $label, $html]) {
        echo '<div class="info-row"><div class="info-key"><i class="bi ' . $icon . '"></i>' . Admin::e($label) . '</div>'
           . '<div class="info-val">' . $html . '</div></div>';
    }
    echo '</div>';
};

// ---- Work progress: site steps (Copper Piping, Collar, …) aggregated across
// every report for THIS project, so we can show how many are done and which are
// currently in progress / on hold. Prefers payload stepStatuses; falls back to
// parsing the "Step (Status)" current_status text on older rows.
$flatSteps = function (array $payload, string $currentStatus): array {
    $out = [];
    $ss = $payload['stepStatuses'] ?? [];
    if (is_array($ss) && $ss) {
        foreach ($ss as $eSt) {
            if (!is_array($eSt)) continue;
            $step = trim((string)($eSt['step'] ?? ''));
            if ($step === '') continue;
            $out[] = ['step' => $step, 'status' => ucfirst(strtolower(trim((string)($eSt['status'] ?? '')))),
                      'party' => holdParty((string)($eSt['holdReason'] ?? '')), 'detail' => trim((string)($eSt['holdReasonDetail'] ?? ''))];
        }
        return $out;
    }
    if (preg_match_all('/([^,()]+)\(([^)]+)\)/', $currentStatus, $m, PREG_SET_ORDER)) {
        foreach ($m as $mm) $out[] = ['step' => trim($mm[1]), 'status' => ucfirst(strtolower(trim($mm[2]))), 'party' => '', 'detail' => ''];
    }
    return $out;
};

$stepOrder = []; $stepStat = []; $stepDone = []; $stepHold = [];
$allRows = $db->query("SELECT payload_json, current_status, created_at, client_type, developer, building, flat_no, project FROM submissions ORDER BY id ASC");
foreach ($allRows as $r) {
    if (projectKey($r) !== projectKey($s)) continue;
    $pl = json_decode((string)$r['payload_json'], true) ?: [];
    foreach ($flatSteps($pl, (string)$r['current_status']) as $stp) {
        $nm = $stp['step'];
        if (!isset($stepStat[$nm])) $stepOrder[] = $nm;
        $stepStat[$nm] = $stp['status'] ?: ($stepStat[$nm] ?? '');
        if (strcasecmp($stp['status'], 'Done') === 0 && !isset($stepDone[$nm])) $stepDone[$nm] = $r['created_at'];
        if (strcasecmp($stp['status'], 'Hold') === 0) $stepHold[$nm] = trim(($stp['party'] ? 'by ' . $stp['party'] : '') . ($stp['detail'] ? ' — ' . $stp['detail'] : ''), ' —');
        else unset($stepHold[$nm]);
    }
}
$wpDone = 0; $wpHold = 0; $wpCur = [];
foreach ($stepOrder as $nm) {
    $stt = strtolower($stepStat[$nm] ?? '');
    if ($stt === 'done') $wpDone++;
    elseif ($stt === 'hold') { $wpHold++; $wpCur[] = $nm; }
    else $wpCur[] = $nm;   // pending / in-progress
}
$wpTotal = count($stepOrder);
$wpPct = $wpTotal > 0 ? round($wpDone * 100 / $wpTotal) : 0;

Layout::head('Report #' . $id, 'submissions', 'submission');
?>
<div class="breadcrumb2">
  <a href="<?= Admin::BASE ?>/index.php"><i class="bi bi-house-door"></i></a> ›
  <a href="<?= Admin::BASE ?>/submissions.php">Site Reports</a> › Report #<?= (int)$id ?>
</div>

<div class="card2">
  <div class="detail-head">
    <div class="dh-ic"><i class="bi bi-file-earmark-text"></i></div>
    <div class="dh-titles">
      <h2><?= Admin::e(projectLabel($s)) ?>
        <?= Layout::statusBadge((string)$s['status']) ?>
        <?= Layout::pipelinePill((string)$s['overall_status']) ?>
      </h2>
      <div class="dh-sub"><span class="mono"><?= Admin::e($reportCode) ?></span> · submitted <?= Admin::e(ago($s['created_at'])) ?></div>
    </div>
    <div class="dh-actions">
      <?php if ($pdf): ?><a class="btn btn-primary btn-sm" href="<?= Admin::e($pdf['url']) ?>" target="_blank"><i class="bi bi-file-earmark-pdf"></i> View PDF</a><?php endif; ?>
      <div class="kebab-wrap">
        <button class="kebab-btn" id="kebabBtn" aria-label="More"><i class="bi bi-three-dots-vertical"></i></button>
        <div class="kebab-menu" id="kebabMenu">
          <?php if ($pdf): ?><a href="<?= Admin::e($pdf['url']) ?>" target="_blank"><i class="bi bi-file-earmark-pdf"></i> Open PDF</a><?php endif; ?>
          <?php foreach ($photos as $p): ?><a href="<?= Admin::e($p['url']) ?>" target="_blank"><i class="bi bi-image"></i> Photo</a><?php break; endforeach; ?>
          <a href="<?= Admin::BASE ?>/pipeline.php?report=<?= (int)$id ?>"><i class="bi bi-list-ol"></i> Processing log</a>
          <a href="#" onclick="navigator.clipboard&&navigator.clipboard.writeText(location.href);this.innerHTML='<i class=\'bi bi-check2\'></i> Link copied';return false;"><i class="bi bi-link-45deg"></i> Copy report link</a>
          <a href="<?= Admin::BASE ?>/holds.php"><i class="bi bi-pause-circle"></i> On-hold board</a>
        </div>
      </div>
    </div>
  </div>
  <div class="info-grid">
    <?php $renderCol($col1); $renderCol($col2); $renderCol($col3); ?>
  </div>
</div>

<?php if ($wpTotal > 0): ?>
<div class="card2">
  <div class="card2-head"><i class="bi bi-bar-chart-steps text-primary"></i><h2>Work Progress</h2>
    <span class="sub">site steps for this project</span></div>
  <div class="card2-body">
    <div class="wp-summary">
      <div class="wp-count"><b><?= $wpDone ?></b> of <?= $wpTotal ?> steps done</div>
      <div class="wp-stats">
        <span class="pill pill-ok"><span class="dot"></span><?= $wpDone ?> Done</span>
        <?php if (($wpTotal - $wpDone - $wpHold) > 0): ?><span class="pill pill-warn"><span class="dot"></span><?= $wpTotal - $wpDone - $wpHold ?> In progress</span><?php endif; ?>
        <?php if ($wpHold > 0): ?><span class="pill pill-bad"><span class="dot"></span><?= $wpHold ?> On hold</span><?php endif; ?>
      </div>
    </div>

    <div class="bar-track" style="height:12px"><div class="bar-fill" style="width:<?= max(2, $wpPct) ?>%"></div></div>

    <div class="wp-cur">
      <span class="lbl">Currently on:</span>
      <?php if ($wpCur): foreach ($wpCur as $nm): $isHold = isset($stepHold[$nm]); ?>
        <span class="pill pill-<?= $isHold ? 'bad' : 'warn' ?>"><?= Admin::e($nm) ?><?= $isHold ? ' (hold)' : '' ?></span>
      <?php endforeach; else: ?>
        <span class="info-val soft">All reported steps completed ✓</span>
      <?php endif; ?>
    </div>

    <div class="steps-grid">
      <?php foreach ($stepOrder as $nm): $stt = strtolower($stepStat[$nm] ?? '');
        $tone = $stt === 'done' ? 'ok' : ($stt === 'hold' ? 'bad' : 'warn');
        $ico  = $stt === 'done' ? 'bi-check-lg' : ($stt === 'hold' ? 'bi-pause' : 'bi-hourglass-split');
        $cls  = $stt === 'done' ? '' : ($stt === 'hold' ? ' hold' : ' current'); ?>
        <div class="step-item<?= $cls ?>">
          <span class="si-dot <?= $tone ?>"><i class="bi <?= $ico ?>"></i></span>
          <div class="si-body">
            <div class="si-name"><?= Admin::e($nm) ?></div>
            <div class="si-meta">
              <?php if ($stt === 'done'): ?>Done · <?= Admin::e(fmtDate($stepDone[$nm] ?? '')) ?>
              <?php elseif ($stt === 'hold'): ?>On hold<?= isset($stepHold[$nm]) && $stepHold[$nm] !== '' ? ' ' . Admin::e($stepHold[$nm]) : '' ?>
              <?php else: ?>In progress<?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card2">
    <div class="card2-head"><i class="bi bi-paperclip text-primary"></i><h2>Attachments</h2>
      <span class="sub">(<?= count($photos) ?> photo<?= count($photos) === 1 ? '' : 's' ?>)</span></div>
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
        <?php if ($pdf): ?><a class="att-file" href="<?= Admin::e($pdf['url']) ?>" target="_blank"><span class="pdf-ic"><i class="bi bi-file-earmark-pdf"></i></span> Report PDF</a><?php endif; ?>
        <?php if ($drawing && $drawing['url']): ?><a class="att-file" href="<?= Admin::e($drawing['url']) ?>" target="_blank"><span class="pdf-ic" style="background:var(--info-bg);color:var(--info)"><i class="bi bi-vector-pen"></i></span> Drawing change</a><?php endif; ?>
        <?php if ($measure && $measure['url']): ?><a class="att-file" href="<?= Admin::e($measure['url']) ?>" target="_blank"><span class="pdf-ic" style="background:var(--warn-bg);color:var(--warn)"><i class="bi bi-rulers"></i></span> Measurement</a><?php endif; ?>
      </div>
    </div>
  </div>

<a class="btn btn-ghost" href="<?= Admin::BASE ?>/submissions.php"><i class="bi bi-arrow-left"></i> Back to reports</a>

<?php
$js = '<script>(function(){'
    . 'var kb=document.getElementById("kebabBtn"),km=document.getElementById("kebabMenu");'
    . 'if(kb&&km){kb.addEventListener("click",function(e){e.stopPropagation();km.classList.toggle("open");});document.addEventListener("click",function(){km.classList.remove("open");});km.addEventListener("click",function(e){e.stopPropagation();});}'
    . '})();</script>';
Layout::foot($js);
