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

/** A stable grouping key for "one project" across visits. */
function projectKey(array $r): string
{
    if (($r['client_type'] ?? '') === 'Developer') {
        return 'D|' . strtolower(trim(($r['developer'] ?? '') . '|' . ($r['building'] ?? '') . '|' . ($r['flat_no'] ?? '')));
    }
    return 'G|' . strtolower(trim((string)($r['project'] ?? '')));
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

/** Short one-line preview of a longer text value. */
function snip($s, int $len = 60): string
{
    $s = trim((string)$s);
    if ($s === '') {
        return '';
    }
    return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1) . '…' : $s;
}
