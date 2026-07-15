<?php
/**
 * On Hold — every project whose latest report is on Hold, fully expanded: which
 * step(s) are stuck, who they're stuck on (VAPL vs Client), the reason, the PE,
 * and a completed-step history (which step was marked done, and when) built from
 * the project's whole report trail. This is the "why is it stuck" board.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
require __DIR__ . '/inc/helpers.php';

$db = Admin::db();
$rows = $db->query("SELECT id, site_type, client_type, developer, building, floor, flat_no, project, order_id,
                           engineer, current_status, status, hold_reason, hold_reason_detail, tentative_end,
                           work_done_by, payload_json, created_at
                    FROM submissions ORDER BY id DESC")->fetchAll();

// group by project, latest first; collect full trail for step-completion history
$groups = [];
foreach ($rows as $r) {
    $k = projectKey($r);
    if (!isset($groups[$k])) $groups[$k] = ['label' => projectLabel($r), 'rows' => []];
    $groups[$k]['rows'][] = $r;
}

// keep only projects whose latest (rows[0]) is on Hold
$holds = [];
foreach ($groups as $g) {
    $latest = $g['rows'][0];
    if ($latest['status'] !== 'Hold') continue;

    $payload = json_decode((string)$latest['payload_json'], true) ?: [];
    $steps = parseSteps($payload);

    // completed-step history across all reports (earliest done date per step)
    $doneOn = [];
    foreach (array_reverse($g['rows']) as $rr) {          // oldest -> newest
        $pp = json_decode((string)$rr['payload_json'], true) ?: [];
        foreach (parseSteps($pp)['done'] as $st) {
            if (!isset($doneOn[$st])) $doneOn[$st] = $rr['created_at'];
        }
    }

    $holds[] = [
        'label'   => $g['label'],
        'latest'  => $latest,
        'steps'   => $steps,
        'doneOn'  => $doneOn,
        'visits'  => count($g['rows']),
    ];
}

// filter by party (?party=client / vapl)
$partyF = strtolower(trim($_GET['party'] ?? ''));
if ($partyF !== '') {
    $holds = array_filter($holds, function ($h) use ($partyF) {
        foreach ($h['steps']['hold'] as $hs) {
            if (strpos(strtolower($hs['party']), $partyF) !== false) return true;
        }
        return false;
    });
}

$clientN = 0; $vaplN = 0;
foreach ($holds as $h) foreach ($h['steps']['hold'] as $hs) {
    if (stripos($hs['party'], 'client') !== false) $clientN++;
    elseif (stripos($hs['party'], 'vapl') !== false) $vaplN++;
}

require __DIR__ . '/inc/layout.php';
Layout::head('On Hold', 'holds');
?>
<div class="kpi-grid">
  <div class="kpi"><div class="kpi-ico ic-red"><i class="bi bi-pause-circle"></i></div><div class="kpi-label">Projects On Hold</div><div class="kpi-value"><?= count($holds) ?></div><div class="kpi-foot">latest report is Hold</div></div>
  <div class="kpi"><div class="kpi-ico ic-red"><i class="bi bi-person-x"></i></div><div class="kpi-label">Stuck on Client</div><div class="kpi-value"><?= $clientN ?></div><div class="kpi-foot">held step(s) awaiting client</div></div>
  <div class="kpi"><div class="kpi-ico ic-amber"><i class="bi bi-tools"></i></div><div class="kpi-label">Stuck on VAPL</div><div class="kpi-value"><?= $vaplN ?></div><div class="kpi-foot">held step(s) on our side</div></div>
</div>

<div class="card2">
  <div class="card2-head"><i class="bi bi-funnel text-primary"></i><h2>Held By</h2>
    <span class="spacer"></span>
    <a class="btn btn-sm <?= $partyF === '' ? 'btn-primary' : 'btn-ghost' ?>" href="<?= Admin::BASE ?>/holds.php">All</a>
    <a class="btn btn-sm <?= $partyF === 'client' ? 'btn-primary' : 'btn-ghost' ?>" href="?party=client">Client</a>
    <a class="btn btn-sm <?= $partyF === 'vapl' ? 'btn-primary' : 'btn-ghost' ?>" href="?party=vapl">VAPL</a>
  </div>
</div>

<?php if (!$holds): ?>
  <div class="card2"><div class="card2-body"><div class="t-empty"><i class="bi bi-check-circle text-success"></i> No projects currently on hold.</div></div></div>
<?php endif; ?>

<?php foreach ($holds as $h): $s = $h['latest']; ?>
  <div class="card2">
    <div class="card2-head">
      <i class="bi bi-pause-circle text-danger"></i>
      <h2><?= Admin::e($h['label']) ?></h2>
      <span class="pill pill-muted"><?= Admin::e($s['site_type']) ?> · <?= Admin::e($s['client_type']) ?></span>
      <span class="spacer"></span>
      <a class="btn btn-ghost btn-sm" href="<?= Admin::BASE ?>/submission.php?id=<?= (int)$s['id'] ?>"><i class="bi bi-eye"></i> Full report</a>
    </div>
    <div class="card2-body">
      <div class="grid-2" style="gap:24px">
        <div>
          <div class="section-title" style="margin-top:0">Held steps — why &amp; who</div>
          <?php if (!$h['steps']['hold']): ?>
            <div class="tl-msg">Reason: <?= Admin::e(trim($s['hold_reason'] . ' — ' . $s['hold_reason_detail'], ' —')) ?: '—' ?></div>
          <?php endif; ?>
          <?php foreach ($h['steps']['hold'] as $hs): $tone = partyTone($hs['party']); ?>
            <div style="border:1px solid var(--line);border-radius:11px;padding:12px 14px;margin-bottom:10px;background:#fff">
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <strong style="font-size:14px"><?= Admin::e($hs['step']) ?></strong>
                <span class="pill pill-<?= $tone ?>">Stuck by <?= Admin::e($hs['party'] ?: 'Unknown') ?></span>
              </div>
              <?php if ($hs['detail']): ?><div class="tl-msg" style="margin-top:8px"><i class="bi bi-chat-left-quote"></i> <?= Admin::e($hs['detail']) ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>

          <div class="section-title">Details</div>
          <dl class="def-list">
            <dt>Project Executive</dt><dd><?= Admin::e($s['engineer']) ?: '—' ?></dd>
            <dt>Order ID</dt><dd class="mono"><?= Admin::e($s['order_id']) ?: '—' ?></dd>
            <dt>Reported hold on</dt><dd><?= Admin::e(fmtDateTime($s['created_at'])) ?> (<?= Admin::e(ago($s['created_at'])) ?>)</dd>
            <dt>Tentative end</dt><dd><?= Admin::e(fmtDate($s['tentative_end'])) ?></dd>
            <dt>Reports so far</dt><dd><?= (int)$h['visits'] ?> visit<?= $h['visits'] === 1 ? '' : 's' ?></dd>
            <?php if ($s['work_done_by']): ?><dt>Work done by</dt><dd><?= Admin::e($s['work_done_by']) ?></dd><?php endif; ?>
          </dl>
        </div>

        <div>
          <div class="section-title" style="margin-top:0">Completed steps <span style="color:#94a3b8;font-weight:500;text-transform:none">(step · when done)</span></div>
          <?php if (!$h['doneOn']): ?><div class="tl-msg">No steps completed yet.</div><?php endif; ?>
          <?php foreach ($h['doneOn'] as $step => $when): ?>
            <div class="bar-row" style="margin-bottom:8px">
              <span class="pill pill-ok" style="min-width:0"><i class="bi bi-check2"></i></span>
              <div style="flex:1"><?= Admin::e($step) ?></div>
              <div style="color:#8190a5;font-size:12.5px;white-space:nowrap"><?= Admin::e(fmtDate($when)) ?></div>
            </div>
          <?php endforeach; ?>

          <?php if ($h['steps']['pending']): ?>
            <div class="section-title">Pending steps</div>
            <?php foreach ($h['steps']['pending'] as $st): ?>
              <div class="bar-row" style="margin-bottom:8px"><span class="pill pill-warn" style="min-width:0"><i class="bi bi-hourglass-split"></i></span><div style="flex:1"><?= Admin::e($st) ?></div></div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<?php Layout::foot();
