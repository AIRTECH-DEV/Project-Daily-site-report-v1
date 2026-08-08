<?php
/**
 * CLI: drop dead share links (and their events) once the retention window has
 * passed, and clear cached photo thumbnails that no live link can reach.
 *
 *   php scripts/share_purge.php            # uses share.retain_days (default 30)
 *   php scripts/share_purge.php 7
 *
 * Run nightly from cron. The panel also purges opportunistically when someone
 * opens Shared Links, but a link table should not depend on somebody visiting.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/ShareLink.php';

$cfg = require __DIR__ . '/../config/app.php';
date_default_timezone_set((string)($cfg['timezone'] ?? 'Asia/Kolkata'));
$days = (int)($argv[1] ?? ($cfg['share']['retain_days'] ?? 30));

$db = (new Db($cfg['db']))->pdo();
$gone = ShareLink::purge($db, $days);
echo date('Y-m-d H:i:s') . "  purged $gone link(s) older than $days day(s)\n";

// Thumbnail cache: regenerated on demand, so anything untouched for a week goes.
$dir = __DIR__ . '/../storage/share_cache';
$n = 0;
if (is_dir($dir)) {
    foreach (glob($dir . '/*.jpg') ?: [] as $f) {
        if (filemtime($f) < time() - 7 * 86400 && @unlink($f)) {
            $n++;
        }
    }
}
echo date('Y-m-d H:i:s') . "  removed $n cached thumbnail(s)\n";
