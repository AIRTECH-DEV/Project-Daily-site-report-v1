<?php
/** Small view helpers shared by the dashboard/list/detail pages. */

/** "12 Jul 2026, 09:39" from a datetime string. */
function fmtDateTime($s): string
{
    $s = trim((string)$s);
    if ($s === '' || $s === '0000-00-00 00:00:00') {
        return '—';
    }
    $ts = strtotime($s);
    return $ts ? date('d M Y, H:i', $ts) : $s;
}

function fmtDate($s): string
{
    $s = trim((string)$s);
    if ($s === '' || $s === '0000-00-00') {
        return '—';
    }
    $ts = strtotime($s);
    return $ts ? date('d M Y', $ts) : $s;
}

/** Relative "3h ago" / "2d ago". */
function ago($s): string
{
    $ts = strtotime((string)$s);
    if (!$ts) {
        return '—';
    }
    $d = time() - $ts;
    if ($d < 60)      return 'just now';
    if ($d < 3600)    return floor($d / 60) . 'm ago';
    if ($d < 86400)   return floor($d / 3600) . 'h ago';
    if ($d < 604800)  return floor($d / 86400) . 'd ago';
    return date('d M Y', $ts);
}

/** Human label for a submission row (project, or developer › building › flat). */
function projectLabel(array $r): string
{
    if (($r['client_type'] ?? '') === 'Developer') {
        $bits = array_filter([$r['developer'] ?? '', $r['building'] ?? '', $r['flat_no'] ?? '']);
        return $bits ? implode(' › ', $bits) : '(developer report)';
    }
    return trim((string)($r['project'] ?? '')) ?: '(no project)';
}

/**
 * A stable grouping key for "one project" across visits.
 *
 * General reports group on ORDER ID, not the project name: the site-report
 * dropdown used to offer a site name AND the client's billing name for the same
 * order, so keying on the picked label listed one job as two projects, each with
 * its own visit count and lifecycle. The name is only a stand-in for rows whose
 * order was never resolved (pre-fix reports — see scripts/merge_project_aliases.php).
 *
 * Every caller must SELECT order_id alongside project/client_type, or its rows
 * will key differently from the projects table and links will dead-end.
 */
function projectKey(array $r): string
{
    if (($r['client_type'] ?? '') === 'Developer') {
        return 'D|' . strtolower(trim(($r['developer'] ?? '') . '|' . ($r['building'] ?? '') . '|' . ($r['flat_no'] ?? '')));
    }
    $orderId = strtolower(trim((string)($r['order_id'] ?? '')));
    if ($orderId !== '') {
        return 'O|' . $orderId;
    }
    return 'G|' . strtolower(trim((string)($r['project'] ?? '')));
}

/** Developer › Building grouping key (one building, all its flats). */
function buildingKey(array $r): string
{
    return 'B|' . strtolower(trim(($r['developer'] ?? '') . '|' . ($r['building'] ?? '')));
}

/* ============================ multi-flat visits ============================
 * A developer visit can cover MANY flats: the PE fills a complete report per
 * flat and submits them together, so ONE submissions row carries flats[] in its
 * payload — each entry a full report of its own (own steps, status, hold, plan,
 * photos, tentative end).
 *
 * The row's own columns hold only flats[0] (the "representative" flat, see
 * AppJs submitForm). Every rollup in the panel tracks a FLAT — projectKey()
 * ends in the flat number — so reading the raw row tracks the first flat and
 * silently loses the rest: 6 flats reported, 1 flat on the board.
 *
 * expandVisits() is the fix: it explodes such a row into one virtual row per
 * flat, each carrying that flat's own identity/steps/status and its own
 * payload_json. Rows without flats[] (General, single-flat developer) pass
 * through untouched, so any caller can simply wrap its query in it.
 * ========================================================================== */

/** The per-flat reports inside a visit payload ([] when it isn't multi-flat). */
function visitFlats(array $payload): array
{
    $flats = $payload['flats'] ?? null;
    return is_array($flats) ? array_values(array_filter($flats, 'is_array')) : [];
}

/** How many flats one submission row covers (1 for General / single-flat). */
function visitFlatCount(array $r): int
{
    $pl = json_decode((string)($r['payload_json'] ?? ''), true) ?: [];
    return max(1, count(visitFlats($pl)));
}

/**
 * One submission row -> one row per flat it reported on.
 * Needs payload_json in the row; without it the row is returned as-is.
 */
function expandVisit(array $r): array
{
    if (!isset($r['payload_json'])) {
        return [flatRow($r, null, 0, 1)];
    }
    $pl = json_decode((string)$r['payload_json'], true) ?: [];
    $flats = visitFlats($pl);
    if (count($flats) < 2) {
        return [flatRow($r, null, 0, max(1, count($flats)))];
    }
    $out = [];
    foreach ($flats as $i => $f) {
        $out[] = flatRow($r, $f, $i, count($flats));
    }
    return $out;
}

/** Same, for a list of rows. Preserves order (flats follow their visit). */
function expandVisits(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        foreach (expandVisit($r) as $x) {
            $out[] = $x;
        }
    }
    return $out;
}

/**
 * Overlays one flat's report onto its visit row. $flat === null keeps the row's
 * own columns (single-flat / General) and only stamps the flat bookkeeping.
 *
 * Only genuinely per-flat fields are overlaid — developer/building/site type/
 * engineer/order id stay the visit's, since the whole visit shares them.
 */
function flatRow(array $r, ?array $flat, int $idx, int $count): array
{
    $r['visit_id']   = (int)($r['id'] ?? 0);
    $r['flat_index'] = $idx;
    $r['flat_count'] = max(1, $count);
    $r['is_flat']    = $flat !== null;
    // Stable identity for a (visit, flat) pair — several rollups key on it.
    $r['visit_key']  = (string)($r['id'] ?? 0) . ($flat !== null ? '#' . $idx : '');
    if ($flat === null) {
        return $r;
    }

    $map = [
        'floor'              => 'floor',
        'flat_no'            => 'flatNo',
        'current_status'     => 'currentStatus',
        'status'             => 'status',
        'hold_reason'        => 'holdReason',
        'hold_reason_detail' => 'holdReasonDetail',
        'tentative_end'      => 'tentativeEndDate',
        'activity'           => 'activity',
        'next_plan'          => 'nextPlan',
        'work_done_by'       => 'workDoneBy',
        'contractor_name'    => 'contractorName',
        'people'             => 'people',
        'amendment'          => 'amendment',
        'amendment_why'      => 'amendmentWhy',
        'drawing_change'     => 'drawingChange',
        'measurement'        => 'measurement',
    ];
    foreach ($map as $col => $key) {
        if (array_key_exists($col, $r) && array_key_exists($key, $flat)) {
            $r[$col] = $flat[$key];
        }
    }
    // Order ID is per flat too (Pms::updateDeveloperFlats resolves one each), but the
    // submissions column can only hold the first flat's. Use the flat's own when the
    // pipeline stamped it; otherwise only flat 0 may keep the column — showing a
    // neighbour's order id on this flat would be worse than showing none.
    if (array_key_exists('order_id', $r)) {
        $own = trim((string)($flat['orderId'] ?? ''));
        $r['order_id'] = $own !== '' ? $own : ($idx === 0 ? $r['order_id'] : '');
    }
    if (array_key_exists('payload_json', $r)) {
        $r['payload_json'] = json_encode($flat, JSON_UNESCAPED_UNICODE);
    }
    return $r;
}

/**
 * Filename-safe flat token — mirrors SubmitService::safeTag(). A multi-flat visit
 * uploads every file as "Flat<TAG>_SitePhoto_1_…", which is the only per-flat
 * marker attachments carry (the table itself is keyed on the visit).
 */
function flatTag(string $flatNo): string
{
    $t = preg_replace('/[^A-Za-z0-9]+/', '', $flatNo);
    return $t !== '' ? strtolower($t) : '';
}

/** The flat an attachment belongs to, or '' when it covers the whole visit. */
function attachmentFlatTag($fileName): string
{
    return preg_match('/^Flat([A-Za-z0-9]+)_/', (string)$fileName, $m) ? strtolower($m[1]) : '';
}

/**
 * Attachments belonging to one flat: its own "Flat<TAG>_" uploads, plus every
 * untagged file (single-flat visits, and the consolidated PDF of a multi-flat one).
 */
function attachmentsForFlat(array $atts, string $flatNo): array
{
    $tag = flatTag($flatNo);
    return array_values(array_filter($atts, function ($a) use ($tag) {
        $t = attachmentFlatTag($a['file_name'] ?? '');
        return $t === '' || $t === $tag;
    }));
}

/** "Flat 1201" / "Flat 1201 · 12th Floor" for a per-flat row. */
function flatLabel(array $r): string
{
    $flat = trim((string)($r['flat_no'] ?? ''));
    $fl   = trim((string)($r['floor'] ?? ''));
    $lbl  = $flat !== '' ? $flat : '(no flat no.)';
    return $fl !== '' ? ($lbl . ' · ' . $fl) : $lbl;
}

/** Google-Drive file-view URL -> inline thumbnail URL (best effort). */
function driveThumb(string $url, int $w = 400): string
{
    if (preg_match('#/d/([A-Za-z0-9_-]+)#', $url, $m) || preg_match('#[?&]id=([A-Za-z0-9_-]+)#', $url, $m)) {
        return 'https://drive.google.com/thumbnail?id=' . $m[1] . '&sz=w' . $w;
    }
    return $url;
}

/** The party a step is stuck on, pulled from a "Stuck BY VAPL/Client" reason. */
function holdParty(string $reason): string
{
    if (preg_match('/by\s+(.+)$/i', trim($reason), $m)) {
        return ucfirst(strtolower(trim($m[1])));
    }
    return trim($reason);
}

/** Colour tone for a hold party (client = red, VAPL/us = amber, other = muted). */
function partyTone(string $party): string
{
    $p = strtolower($party);
    if (strpos($p, 'client') !== false) return 'bad';
    if (strpos($p, 'vapl') !== false)   return 'warn';
    return 'muted';
}

/**
 * Splits a submission's stepStatuses into done / pending / hold buckets.
 * hold entries carry {step, party, detail}. Falls back gracefully on old rows.
 */
function parseSteps(array $payload): array
{
    $out = ['done' => [], 'pending' => [], 'hold' => []];
    foreach (($payload['stepStatuses'] ?? []) as $e) {
        if (!is_array($e)) continue;
        $step = trim((string)($e['step'] ?? ''));
        if ($step === '') continue;
        $st = strtolower(trim((string)($e['status'] ?? '')));
        if ($st === 'done') {
            $out['done'][] = $step;
        } elseif ($st === 'hold') {
            $out['hold'][] = [
                'step'   => $step,
                'party'  => holdParty((string)($e['holdReason'] ?? '')),
                'detail' => trim((string)($e['holdReasonDetail'] ?? '')),
            ];
        } elseif ($st === 'pending') {
            $out['pending'][] = $step;
        }
    }
    return $out;
}

/** Canonical ordered site steps (mirrors AppJs STATUS_STEPS) for a site type. */
function canonicalSteps(string $siteType): array
{
    $vrv = ['LS Material Delivery','Marking','Civil Opening','Support','Copper Piping','Cable','Drain','LS Pressure Testing','IDU/ODU Pressure Testing','Main Ducting','Collar','Fresh Air - PVC PIPE / Duct','1st RA Measurement Submitted by PE','Underdake Insulation','HS Material Delivery IDU','HS Material Delivery ODU','Indoor Installation','odu unit installation','Final Nitrogen Testing','Grill Installation','Fan Installation','Disk Valve','FINAL RA Measurement Received','Pre-Commissining','Commissining'];
    $nonvrv = ['LS Material Delivery','Marking','Civil Opening','Support','Copper Piping','Cable','Drain','LS Pressure Testing','IDU/ODU Pressure Testing','Main Ducting','Collar','PVC PIPE','Underdake Insulation','HS Material Delivery IDU','HS Material Delivery ODU','Indoor Installation','odu unit installation','Final Nitrogen Testing','Grill Installation','Fan Installation','Disk Valve','Pre-Commissining','Commissining'];
    return strcasecmp(trim($siteType), 'VRV') === 0 ? $vrv : $nonvrv;
}

/** Compact match key for a step name (case/space/punct-insensitive). */
function stepKey(string $s): string
{
    return preg_replace('/[^a-z0-9]/', '', strtolower(trim($s)));
}

/**
 * True when a step name is (or contains) the FINAL commissioning step.
 * "Pre-Commissining" is a separate, earlier step — it must NOT flip a project to
 * Commissioned, so anything prefixed "pre" is excluded.
 */
function isCommissioning(string $s): bool
{
    $k = stepKey($s);
    if (strncmp($k, 'pre', 3) === 0) {
        return false;
    }
    return strpos($k, 'commiss') !== false;
}

/**
 * True for the "Pre-Commissining" step (the step BEFORE final commissioning).
 * This is the hand-off point to the HVAC commissioning app — see CommissionPush.
 */
function isPreCommissioning(string $s): bool
{
    $k = stepKey($s);
    return strncmp($k, 'pre', 3) === 0 && strpos($k, 'commiss') !== false;
}

/** Short one-line preview of a longer text value. */
function snip($s, int $len = 60): string
{
    $s = trim((string)$s);
    if ($s === '') {
        return '';
    }
    return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1) . '…' : $s;
}
