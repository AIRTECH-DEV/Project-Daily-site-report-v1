<?php
/**
 * One site report in full: every captured field, the per-step pipeline timeline
 * (from process_log), attached photos/PDF, and the raw payload for auditing.
 *
 * A DEVELOPER visit can cover several flats in one submission — each flat a
 * complete report of its own. This page therefore reads one FLAT at a time
 * (?flat=<flat no>, default the first): every field, the work progress and the
 * attachments below belong to the selected flat, and the "Flats in this visit"
 * card shows where each of the others stands.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';

$db = Admin::db();
$id = (int)($_GET['id'] ?? 0);
$st = $db->prepare("SELECT * FROM submissions WHERE id = ?");
$st->execute([$id]);
$visit = $st->fetch();

require __DIR__ . '/inc/layout.php';

if (!$visit) {
    Layout::head('Report not found', 'submissions');
    echo '<div class="alert2 bad"><i class="bi bi-exclamation-octagon"></i> Report #' . $id . ' not found.</div>';
    echo '<a class="btn btn-ghost" href="' . Admin::BASE . '/submissions.php"><i class="bi bi-arrow-left"></i> Back to reports</a>';
    Layout::foot();
    exit;
}

// One row per flat this visit reported on; $s is the selected one.
$flatRows  = expandVisits([$visit]);
$flatCount = count($flatRows);
$isMulti   = $flatCount > 1;
$wantFlat  = trim((string)($_GET['flat'] ?? ''));
$active    = 0;
if ($wantFlat !== '') {
    foreach ($flatRows as $i => $fr) {
        if (strcasecmp(trim((string)$fr['flat_no']), $wantFlat) === 0) { $active = $i; break; }
    }
}
$s = $flatRows[$active];

$atts = $db->prepare("SELECT * FROM attachments WHERE submission_id = ? ORDER BY id ASC");
$atts->execute([$id]);
$allAtts = $atts->fetchAll();
// Per-flat uploads are named "Flat<TAG>_…" by the pipeline — show only this flat's.
$atts = $isMulti ? attachmentsForFlat($allAtts, (string)$s['flat_no']) : $allAtts;

$photos = array_values(array_filter($atts, fn($a) => $a['kind'] === 'site_photo'));
// drawing / measurement each accept several uploads -> keep every one.
$pdf = null; $drawings = []; $measures = [];
foreach ($atts as $a) {
    if ($a['kind'] === 'pdf' && $a['url']) $pdf = $a;
    if ($a['kind'] === 'drawing' && $a['url']) $drawings[] = $a;
    if ($a['kind'] === 'measurement' && $a['url']) $measures[] = $a;
}

$payload = json_decode((string)$s['payload_json'], true) ?: [];
$flatUrl = fn(array $r) => Admin::BASE . '/submission.php?id=' . (int)$id
    . (trim((string)$r['flat_no']) !== '' ? '&flat=' . urlencode((string)$r['flat_no']) : '');

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
// expandVisits: for a developer unit the project key ends in the flat number, so
// multi-flat visits must be split before matching or this flat's history is lost
// inside (and mixed with) its neighbours'.
$allRows = expandVisits($db->query(
    "SELECT payload_json, current_status, created_at, client_type, developer, building, floor, flat_no, project, order_id
     FROM submissions ORDER BY id ASC"
)->fetchAll());
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
// "Not Required" is work explicitly ruled out for this unit — it is neither done
// nor outstanding, so it must not sit in "Currently on" (it read as in-progress
// work that would never move) nor count against the completion total.
$wpDone = 0; $wpHold = 0; $wpNr = 0; $wpCur = [];
foreach ($stepOrder as $nm) {
    $stt = strtolower($stepStat[$nm] ?? '');
    if ($stt === 'done') $wpDone++;
    elseif ($stt === 'not required') $wpNr++;
    elseif ($stt === 'hold') { $wpHold++; $wpCur[] = $nm; }
    else $wpCur[] = $nm;   // pending / in-progress
}
$wpTotal = count($stepOrder) - $wpNr;
$wpPct = $wpTotal > 0 ? round($wpDone * 100 / $wpTotal) : 0;

// Per-flat rollup for the overview card: how far each flat of this visit has got
// across ALL its visits (projects is rebuilt from the same reports by Sync).
$flatMaster = [];
if ($isMulti) {
    $keys = array_map('projectKey', $flatRows);
    $ph = implode(',', array_fill(0, count($keys), '?'));
    $mq = $db->prepare("SELECT project_key, steps_done, steps_total, lifecycle, current_step, hold_owner FROM projects WHERE project_key IN ($ph)");
    $mq->execute($keys);
    foreach ($mq as $m) $flatMaster[$m['project_key']] = $m;
}

Layout::head('Report #' . $id, 'submissions', 'submission');
?>
<div class="breadcrumb2">
  <a href="<?= Admin::BASE ?>/index.php"><i class="bi bi-house-door"></i></a> ›
  <a href="<?= Admin::BASE ?>/submissions.php">Site Reports</a> › Report #<?= (int)$id ?>
  <?php if ($isMulti): ?> › <?= Admin::e(trim((string)$s['flat_no']) ?: 'flat ' . ($active + 1)) ?><?php endif; ?>
</div>

<div class="card2">
  <div class="detail-head">
    <div class="dh-ic"><i class="bi bi-file-earmark-text"></i></div>
    <div class="dh-titles">
      <h2><?= Admin::e(projectLabel($s)) ?>
        <?= Layout::statusBadge((string)$s['status']) ?>
        <?= Layout::pipelinePill((string)$s['overall_status']) ?>
        <?php if ($isMulti): ?><span class="pill pill-type"><i class="bi bi-door-open"></i> <?= $flatCount ?> flats in this visit</span><?php endif; ?>
      </h2>
      <div class="dh-sub"><span class="mono"><?= Admin::e($reportCode) ?></span> · submitted <?= Admin::e(ago($s['created_at'])) ?><?= $isMulti ? ' · showing flat ' . ($active + 1) . ' of ' . $flatCount : '' ?></div>
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
  <?php if ($isMulti): ?>
  <div class="card2-body" style="border-top:1px solid var(--line-soft);padding-top:14px">
    <div class="wp-cur" style="margin:0">
      <span class="lbl">Showing flat:</span>
      <div class="sib-strip">
        <?php foreach ($flatRows as $i => $fr): $stt = strtolower((string)$fr['status']);
          $tone = $stt === 'done' ? 'ok' : ($stt === 'hold' ? 'bad' : 'warn'); ?>
          <a class="sib<?= $i === $active ? ' on' : '' ?>" href="<?= Admin::e($flatUrl($fr)) ?>">
            <span class="dot <?= $tone ?>"></span><?= Admin::e(trim((string)$fr['flat_no']) ?: 'Flat ' . ($i + 1)) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <div class="info-grid">
    <?php $renderCol($col1); $renderCol($col2); $renderCol($col3); ?>
  </div>
</div>

<?php
// Multi-flat developer visit: one submission, one PDF, one notification — but
// several INDEPENDENT flat reports. This card is the per-flat answer to "which
// flat is finished and which one is stuck", and links each to its own tracking.
if ($isMulti):
?>
<div class="card2">
  <div class="card2-head"><i class="bi bi-building text-primary"></i><h2>Flats in this visit</h2>
    <span class="sub"><?= $flatCount ?> flats · each tracked as its own unit</span></div>
  <div class="card2-body">
    <div class="flat-grid">
      <?php foreach ($flatRows as $fi => $fr):
        $fpl = json_decode((string)$fr['payload_json'], true) ?: [];
        $fSteps = parseSteps($fpl);
        // parseSteps only buckets done/pending/hold — collect the ruled-out steps too,
        // so a flat that reported only "Not Required" doesn't look like it reported nothing.
        $fNr = [];
        foreach (($fpl['stepStatuses'] ?? []) as $eSt) {
            if (!is_array($eSt)) continue;
            if (strcasecmp(trim((string)($eSt['status'] ?? '')), 'Not Required') !== 0) continue;
            $nm = trim((string)($eSt['step'] ?? ''));
            if ($nm !== '') $fNr[] = $nm;
        }
        $fno = trim((string)$fr['flat_no']);
        $ffl = trim((string)$fr['floor']);
        $stt = (string)$fr['status'];
        $cls = strcasecmp($stt, 'Hold') === 0 ? ' hold' : (strcasecmp($stt, 'Done') === 0 ? ' done' : '');
        if ($fi === $active) $cls .= ' active';
        $m = $flatMaster[projectKey($fr)] ?? null;
        $mt = (int)($m['steps_total'] ?? 0); $md = (int)($m['steps_done'] ?? 0);
        $pct = $mt > 0 ? (int)round($md * 100 / $mt) : 0;
        $tent = trim((string)$fr['tentative_end']);
      ?>
      <div class="flat-card<?= $cls ?>">
        <div class="flat-head">
          <span class="flat-no"><?= Admin::e($fno !== '' ? $fno : 'Flat ' . ($fi + 1)) ?></span>
          <?php if ($ffl !== ''): ?><span class="flat-floor"><?= Admin::e($ffl) ?></span><?php endif; ?>
          <span class="spacer" style="margin-left:auto"></span>
          <?= Layout::statusBadge($stt) ?>
        </div>

        <?php if ($m): ?>
        <div class="flat-prog">
          <div class="bar-track"><div class="bar-fill" style="width:<?= max(2, $pct) ?>%"></div></div>
          <span class="n"><?= $md ?>/<?= $mt ?> steps</span>
        </div>
        <div class="flat-note"><i class="bi bi-flag"></i><?= Admin::e((string)$m['lifecycle']) ?>
          <?php if (trim((string)$m['current_step']) !== ''): ?> · now on <b><?= Admin::e($m['current_step']) ?></b><?php endif; ?>
          <?php if (!empty($m['hold_owner'])): ?> · <span class="pill pill-<?= partyTone((string)$m['hold_owner']) ?>">stuck on <?= Admin::e($m['hold_owner']) ?></span><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="flat-steps">
          <?php foreach ($fSteps['done'] as $stp): ?><span class="pill pill-ok"><i class="bi bi-check-lg"></i> <?= Admin::e($stp) ?></span><?php endforeach; ?>
          <?php foreach ($fSteps['pending'] as $stp): ?><span class="pill pill-warn"><i class="bi bi-hourglass-split"></i> <?= Admin::e($stp) ?></span><?php endforeach; ?>
          <?php foreach ($fSteps['hold'] as $hs): ?><span class="pill pill-bad"><i class="bi bi-pause"></i> <?= Admin::e($hs['step']) ?><?= $hs['party'] ? ' — ' . Admin::e($hs['party']) : '' ?></span><?php endforeach; ?>
          <?php foreach ($fNr as $stp): ?><span class="pill pill-muted"><i class="bi bi-slash-circle"></i> <?= Admin::e($stp) ?></span><?php endforeach; ?>
          <?php if (!$fSteps['done'] && !$fSteps['pending'] && !$fSteps['hold'] && !$fNr): ?><span class="info-val soft">No step updates in this visit.</span><?php endif; ?>
        </div>

        <?php foreach ($fSteps['hold'] as $hs): if ($hs['detail'] === '') continue; ?>
          <div class="flat-note"><i class="bi bi-chat-left-quote"></i><?= Admin::e($hs['step']) ?>: <?= Admin::e($hs['detail']) ?></div>
        <?php endforeach; ?>
        <?php if (trim((string)$fr['activity']) !== ''): ?><div class="flat-note"><i class="bi bi-clipboard-check"></i><?= Admin::e(snip((string)$fr['activity'], 150)) ?></div><?php endif; ?>
        <?php if (trim((string)$fr['next_plan']) !== ''): ?><div class="flat-note"><i class="bi bi-signpost-2"></i><?= Admin::e(snip((string)$fr['next_plan'], 150)) ?></div><?php endif; ?>
        <?php if (trim((string)$fr['work_done_by']) !== ''): ?><div class="flat-note"><i class="bi bi-hammer"></i><?= Admin::e(snip((string)$fr['work_done_by'], 120)) ?></div><?php endif; ?>
        <?php if ($tent !== ''): ?><div class="flat-note"><i class="bi bi-calendar-event"></i>Tentative end <?= Admin::e(fmtDate($tent) ?: $tent) ?></div><?php endif; ?>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
          <a class="btn btn-ghost btn-sm" href="<?= Admin::e($flatUrl($fr)) ?>"><i class="bi bi-eye"></i> This flat in this report</a>
          <a class="btn btn-ghost btn-sm" href="<?= Admin::BASE ?>/project.php?key=<?= urlencode(projectKey($fr)) ?>"><i class="bi bi-bar-chart-steps"></i> Full flat tracking</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($wpTotal > 0): ?>
<div class="card2">
  <div class="card2-head"><i class="bi bi-bar-chart-steps text-primary"></i><h2>Work Progress</h2>
    <span class="sub">site steps for <?= $isMulti ? 'flat ' . Admin::e(trim((string)$s['flat_no']) ?: (string)($active + 1)) : 'this project' ?>, across every visit</span></div>
  <div class="card2-body">
    <div class="wp-summary">
      <div class="wp-count"><b><?= $wpDone ?></b> of <?= $wpTotal ?> steps done</div>
      <div class="wp-stats">
        <span class="pill pill-ok"><span class="dot"></span><?= $wpDone ?> Done</span>
        <?php if (($wpTotal - $wpDone - $wpHold) > 0): ?><span class="pill pill-warn"><span class="dot"></span><?= $wpTotal - $wpDone - $wpHold ?> In progress</span><?php endif; ?>
        <?php if ($wpHold > 0): ?><span class="pill pill-bad"><span class="dot"></span><?= $wpHold ?> On hold</span><?php endif; ?>
        <?php if ($wpNr > 0): ?><span class="pill pill-muted"><span class="dot"></span><?= $wpNr ?> Not required</span><?php endif; ?>
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
        $nr   = $stt === 'not required';
        $tone = $stt === 'done' ? 'ok' : ($stt === 'hold' ? 'bad' : ($nr ? 'muted' : 'warn'));
        $ico  = $stt === 'done' ? 'bi-check-lg' : ($stt === 'hold' ? 'bi-pause' : ($nr ? 'bi-slash-circle' : 'bi-hourglass-split'));
        $cls  = ($stt === 'done' || $nr) ? '' : ($stt === 'hold' ? ' hold' : ' current'); ?>
        <div class="step-item<?= $cls ?>">
          <span class="si-dot <?= $tone ?>"><i class="bi <?= $ico ?>"></i></span>
          <div class="si-body">
            <div class="si-name"><?= Admin::e($nm) ?></div>
            <div class="si-meta">
              <?php if ($stt === 'done'): ?>Done · <?= Admin::e(fmtDate($stepDone[$nm] ?? '')) ?>
              <?php elseif ($stt === 'hold'): ?>On hold<?= isset($stepHold[$nm]) && $stepHold[$nm] !== '' ? ' ' . Admin::e($stepHold[$nm]) : '' ?>
              <?php elseif ($nr): ?>Not required for this unit
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
      <span class="sub">(<?= count($photos) ?> photo<?= count($photos) === 1 ? '' : 's' ?>)<?= $isMulti ? ' · this flat only — the PDF covers all ' . $flatCount . ' flats' : '' ?></span></div>
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
        <?php foreach ($drawings as $di => $drawing): ?><a class="att-file" href="<?= Admin::e($drawing['url']) ?>" target="_blank"><span class="pdf-ic" style="background:var(--info-bg);color:var(--info)"><i class="bi bi-vector-pen"></i></span> Drawing change<?= count($drawings) > 1 ? ' ' . ($di + 1) : '' ?></a><?php endforeach; ?>
        <?php foreach ($measures as $mi => $measure): ?><a class="att-file" href="<?= Admin::e($measure['url']) ?>" target="_blank"><span class="pdf-ic" style="background:var(--warn-bg);color:var(--warn)"><i class="bi bi-rulers"></i></span> Measurement<?= count($measures) > 1 ? ' ' . ($mi + 1) : '' ?></a><?php endforeach; ?>
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
