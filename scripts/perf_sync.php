<?php
/**
 * CLI refresh of the PMS-sheet dates used by the Performance & Incentive tab.
 *   php scripts/perf_sync.php
 *
 * Reads every PMS progress sheet once and stores, per project:
 *   start_date       — "Marking" → Start Date  (the project's real start)
 *   actual_end_date  — final "Commissining" → End Date
 *   sheet_target_end — "Tentitive Project End date"
 *   plus the per-step Start/End grid in project_step_dates.
 *
 * READ-ONLY over the sheets and over the report pipeline. Safe to schedule —
 * once or twice a day is plenty (nightly is the usual choice), since a Marking
 * start date is typed in by hand. The admin page's button runs the same code.
 */
require __DIR__ . '/../admin/inc/helpers.php';
require __DIR__ . '/../admin/inc/Perf.php';

$cfg = require __DIR__ . '/../config/app.php';
date_default_timezone_set($cfg['timezone'] ?? 'Asia/Kolkata');
$d = $cfg['db'];
try {
    $db = new PDO(
        "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset={$d['charset']}",
        $d['user'], $d['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+05:30'"]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "DB error: " . $e->getMessage() . "\n");
    exit(1);
}

$stats = Perf::syncSheets($db);
fwrite(STDOUT, date('Y-m-d H:i:s') . ' perf sheet sync: '
    . (int)$stats['rows'] . ' sheet rows, ' . (int)$stats['matched'] . ' matched, '
    . (int)$stats['with_start'] . ' with start, ' . (int)$stats['with_end'] . ' finished, '
    . (int)$stats['steps'] . " step rows\n");

foreach ($stats['warnings'] as $w) {
    fwrite(STDERR, "  warning: $w\n");
}
exit(empty($stats['matched']) && !empty($stats['warnings']) ? 1 : 0);
