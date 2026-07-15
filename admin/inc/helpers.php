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

/** Short one-line preview of a longer text value. */
function snip($s, int $len = 60): string
{
    $s = trim((string)$s);
    if ($s === '') {
        return '';
    }
    return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1) . '…' : $s;
}
