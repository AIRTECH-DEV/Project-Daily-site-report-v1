<?php
/**
 * CLI: mint a client share link without the panel (testing / support).
 *
 *   php scripts/share_mint.php "D|kasturi|balmoral towerc-wing|c-1801" [hours]
 *   php scripts/share_mint.php --building "Kasturi" "Balmoral TowerC-wing"
 *   php scripts/share_mint.php --developer "Kasturi"
 *
 * Prints the URL once — it is not stored anywhere and cannot be recovered.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/ShareLink.php';

$cfg   = require __DIR__ . '/../config/app.php';
date_default_timezone_set((string)($cfg['timezone'] ?? 'Asia/Kolkata'));
$share = $cfg['share'] ?? [];
$db    = (new Db($cfg['db']))->pdo();

$args = array_slice($argv, 1);
if (!$args) {
    exit("usage: share_mint.php <project_key> [hours] | --building <dev> <building> | --developer <dev>\n");
}

$mode = $args[0];
if ($mode === '--building') {
    $scope    = 'building';
    $scopeKey = ShareLink::buildingScopeKey($args[1] ?? '', $args[2] ?? '');
    $label    = trim(($args[1] ?? '') . ' › ' . ($args[2] ?? ''));
    $projId   = null;
    $hours    = (int)($args[3] ?? $share['ttl_hours'] ?? 24);
} elseif ($mode === '--developer') {
    $scope    = 'developer';
    $scopeKey = ShareLink::developerScopeKey($args[1] ?? '');
    $label    = trim($args[1] ?? '');
    $projId   = null;
    $hours    = (int)($args[2] ?? $share['ttl_hours'] ?? 24);
} else {
    $st = $db->prepare("SELECT * FROM projects WHERE project_key = ? LIMIT 1");
    $st->execute([$mode]);
    $pr = $st->fetch(PDO::FETCH_ASSOC);
    if (!$pr) {
        exit("project_key not found: $mode\n");
    }
    $scope    = 'project';
    $scopeKey = $mode;
    $label    = (string)$pr['label'];
    $projId   = (int)$pr['id'];
    $hours    = (int)($args[1] ?? $share['ttl_hours'] ?? 24);
}

$mint = ShareLink::mint(
    $db, $scope, $scopeKey, $projId, $label,
    (array)($share['defaults'] ?? []), 'cli', $hours
);
$base = (string)($share['base_url'] ?? '') ?: 'http://localhost/pms';

echo "scope   : $scope\n";
echo "label   : $label\n";
echo "expires : {$mint['expires_at']} (in {$hours} h)\n";
echo "url     : " . ShareLink::url($mint['token'], $share, $base) . "\n";
