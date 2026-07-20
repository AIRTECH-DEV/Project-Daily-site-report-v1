<?php
/**
 * CLI sync for the admin master tables (workforce, projects, alerts).
 *   php scripts/admin_sync.php
 * Safe to schedule (Task Scheduler) alongside the report worker — it only READS
 * submissions/payload_json and writes the additive master tables. Later this is
 * also where alert digests / internal sends are triggered.
 */
require __DIR__ . '/../admin/inc/helpers.php';
require __DIR__ . '/../admin/inc/Sync.php';
require __DIR__ . '/../admin/inc/NotifyAlerts.php';

$cfg = require __DIR__ . '/../config/app.php';
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

$overrides = [];
$ovFile = __DIR__ . '/../config/overrides.json';
if (is_file($ovFile)) { $overrides = json_decode((string)file_get_contents($ovFile), true) ?: []; }

$stats = Sync::run($db, $overrides);
fwrite(STDOUT, date('Y-m-d H:i:s') . ' sync: ' . json_encode($stats) . "\n");

// internal alert email for new criticals (gated OFF by default in overrides.json)
$sent = NotifyAlerts::notifyCritical($db, $cfg);
if ($sent) fwrite(STDOUT, "  alert emails sent: $sent\n");

// digests: schedule three tasks -> php admin_sync.php --digest=morning|evening|weekly
foreach ($argv as $arg) {
    if (strpos($arg, '--digest=') === 0) {
        $type = substr($arg, 9);
        $ok = NotifyAlerts::digest($db, $cfg, in_array($type, ['morning','evening','weekly'], true) ? $type : 'morning');
        fwrite(STDOUT, "  digest $type: " . ($ok ? 'sent' : 'skipped (mode OFF / no recipient)') . "\n");
    }
}
