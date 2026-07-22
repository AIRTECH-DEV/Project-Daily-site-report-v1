<?php
/**
 * CLI: push newly-Commissioned projects to the HVAC commissioning app backend.
 *   php scripts/commission_push.php
 * Schedule alongside the report worker (e.g. every 2-5 min). Idempotent + retry:
 * only projects with lifecycle='Commissioned' AND app_pushed_at IS NULL are sent,
 * and app_pushed_at is stamped only on a successful ack. Read-mostly: it only
 * writes projects.app_pushed_at — it never touches the report pipeline.
 */
require __DIR__ . '/../src/GoogleAuth.php';
require __DIR__ . '/../src/Bootstrap.php';
Bootstrap::autoload();
require_once __DIR__ . '/../src/CommissionPush.php';

try {
    $app  = Bootstrap::init();
    $push = new CommissionPush($app->db()->pdo(), $app->cfg, $app->sheets, $app->drive);
    $r    = $push->run();
    fwrite(STDOUT, date('Y-m-d H:i:s') . ' commission_push: ' . json_encode($r) . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' commission_push ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
