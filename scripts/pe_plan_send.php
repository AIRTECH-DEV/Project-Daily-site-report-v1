<?php
/**
 * Daily PE-plan reminder sender. Schedule this every ~15 min (Task Scheduler).
 * It fires ONCE per day, at/after pe_plan.send_time, sending tomorrow's plan
 * image to the configured numbers — so the team is warned the evening before.
 *
 *   php scripts/pe_plan_send.php            # normal: time-gated, once/day
 *   php scripts/pe_plan_send.php --force    # ignore time gate + "already sent" + empty-plan skip
 *   php scripts/pe_plan_send.php --test     # send to the test number (ignores mode)
 *   php scripts/pe_plan_send.php --date=2026-07-19   # target a specific plan date
 *
 * Idempotency: a stamp file (storage/.pe_plan_sent) holds the last date it sent,
 * so re-runs within the day are no-ops. --force / --test bypass the gate.
 */
require __DIR__ . '/../src/PePlanSender.php';

$cfg = require __DIR__ . '/../config/app.php';
date_default_timezone_set($cfg['timezone'] ?? 'Asia/Kolkata');

$force = in_array('--force', $argv, true);
$test  = in_array('--test', $argv, true);
$date  = null;
foreach ($argv as $a) { if (strpos($a, '--date=') === 0) $date = substr($a, 7); }

$stampFile = __DIR__ . '/../storage/.pe_plan_sent';
$today     = date('Y-m-d');
$sendTime  = $cfg['pe_plan']['send_time'] ?? '20:00';
$nowHM     = date('H:i');

// ---- time gate (skipped by --force / --test) -------------------------------
if (!$force && !$test) {
    if (strtoupper($cfg['pe_plan']['mode'] ?? 'OFF') === 'OFF') {
        fwrite(STDOUT, "pe_plan mode=OFF — nothing to do\n"); exit(0);
    }
    $already = is_file($stampFile) ? trim((string)file_get_contents($stampFile)) : '';
    if ($already === $today) { fwrite(STDOUT, "already sent today ($today) — skip\n"); exit(0); }
    if ($nowHM < $sendTime)  { fwrite(STDOUT, "before send_time ($nowHM < $sendTime) — skip\n"); exit(0); }
}

// ---- DB (read-only over submissions) ---------------------------------------
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

$sender = new PePlanSender($cfg);
$res = $sender->run($db, ['date' => $date, 'test' => $test, 'force' => $force]);

fwrite(STDOUT, date('Y-m-d H:i:s') . " pe_plan: {$res['status']} — {$res['detail']}"
    . " | to: " . (implode(', ', $res['recipients']) ?: '-')
    . " | {$res['groups']} PE / {$res['sites']} sites\n");

// stamp only a real, non-test daily send that reached someone
if (!$test && ($res['status'] === 'SENT' || strpos($res['status'], 'PARTIAL') === 0)) {
    @file_put_contents($stampFile, $today);
}

exit(($res['status'] === 'SENT' || strpos($res['status'], 'PARTIAL') === 0 || $res['status'] === 'SKIPPED') ? 0 : 1);
