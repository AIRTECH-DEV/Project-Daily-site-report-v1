<?php
/**
 * Weekly PE report sender — one email per Project Engineer, Saturday evening.
 * Schedule every ~30 min (Task Scheduler / cron); it fires ONCE per week, on
 * pe_weekly.send_day at/after pe_weekly.send_time.
 *
 *   php scripts/pe_weekly_send.php                     # normal: day+time gated
 *   php scripts/pe_weekly_send.php --force             # ignore day/time + already-sent
 *   php scripts/pe_weekly_send.php --test              # one sample to the test inbox
 *   php scripts/pe_weekly_send.php --dry               # build only, send nothing
 *   php scripts/pe_weekly_send.php --preview=out.html  # write the mails to a file
 *   php scripts/pe_weekly_send.php --date=2026-08-01   # week ENDING this date
 *   php scripts/pe_weekly_send.php --pe=Ganesh         # only this engineer
 *
 * Idempotency: storage/.pe_weekly_sent holds the last week-end date it sent, so
 * re-runs inside the same week are no-ops. --force / --test / --dry bypass it.
 */
require __DIR__ . '/../admin/inc/PeWeekly.php';

$cfg = require __DIR__ . '/../config/app.php';
date_default_timezone_set($cfg['timezone'] ?? 'Asia/Kolkata');

$force = in_array('--force', $argv, true);
$test  = in_array('--test',  $argv, true);
$dry   = in_array('--dry',   $argv, true);
$date  = null; $pe = ''; $preview = '';
foreach ($argv as $a) {
    if (strpos($a, '--date=')    === 0) $date    = substr($a, 7);
    if (strpos($a, '--pe=')      === 0) $pe      = substr($a, 5);
    if (strpos($a, '--preview=') === 0) $preview = substr($a, 10);
}
if ($preview !== '') $dry = true;

$w        = $cfg['pe_weekly'] ?? [];
$sendDay  = (int)($w['send_day'] ?? 6);              // 1=Mon … 7=Sun (6 = Saturday)
$sendTime = (string)($w['send_time'] ?? '18:30');
$stampF   = __DIR__ . '/../storage/.pe_weekly_sent';
$weekEnd  = $date ?: date('Y-m-d');

// ---- gate (skipped by --force / --test / --dry) ----------------------------
if (!$force && !$test && !$dry) {
    if (strtoupper((string)($w['mode'] ?? 'OFF')) === 'OFF') {
        fwrite(STDOUT, "pe_weekly mode=OFF — nothing to do\n"); exit(0);
    }
    if ((int)date('N') !== $sendDay) {
        fwrite(STDOUT, "not the send day (today " . date('D') . ", want day $sendDay) — skip\n"); exit(0);
    }
    if (date('H:i') < $sendTime) {
        fwrite(STDOUT, "before send_time (" . date('H:i') . " < $sendTime) — skip\n"); exit(0);
    }
    $already = is_file($stampF) ? trim((string)file_get_contents($stampF)) : '';
    if ($already === $weekEnd) { fwrite(STDOUT, "already sent for week ending $weekEnd — skip\n"); exit(0); }
}

// ---- DB (read-only) --------------------------------------------------------
$d = $cfg['db'];
try {
    $db = new PDO(
        "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset={$d['charset']}",
        $d['user'], $d['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+05:30'"]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "DB error: " . $e->getMessage() . "\n"); exit(1);
}

$res = PeWeekly::run($db, $cfg, [
    'date' => $weekEnd, 'pe' => $pe, 'test' => $test, 'force' => $force, 'dry' => $dry,
]);

fwrite(STDOUT, date('Y-m-d H:i:s') . " pe_weekly: {$res['status']} — {$res['detail']}"
    . " | sent {$res['sent']}, skipped {$res['skipped']}\n");
foreach ($res['reports'] as $name => $r) {
    fwrite(STDOUT, "  · $name -> " . ($r['to'] ?: '(no email)') . " | {$r['subject']}\n");
}

// ---- optional HTML preview file (all PEs stacked) --------------------------
if ($preview !== '') {
    $html = '<meta charset="utf-8"><title>PE weekly preview</title><body style="margin:0;background:#e9eef6">';
    foreach ($res['reports'] as $name => $r) {
        $html .= '<div style="font:12px Arial;color:#64748b;padding:14px 20px 0">TO: '
               . htmlspecialchars($r['to'] ?: '(no email set)') . ' &nbsp;·&nbsp; SUBJECT: '
               . htmlspecialchars($r['subject']) . '</div>' . $r['html'];
    }
    file_put_contents($preview, $html . '</body>');
    fwrite(STDOUT, "  preview written: $preview\n");
}

// stamp only a real weekly send that reached someone
if (!$test && !$dry && ($res['status'] === 'SENT' || strpos($res['status'], 'PARTIAL') === 0)) {
    @file_put_contents($stampF, $weekEnd);
}

exit(in_array($res['status'], ['SENT', 'SKIPPED', 'BUILT'], true) || strpos($res['status'], 'PARTIAL') === 0 ? 0 : 1);
