<?php
/** GET api/project_names.php?siteType=VRV|Non-VRV -> ["Project A", ...] */
require __DIR__ . '/_common.php';

try {
    $siteType = $_GET['siteType'] ?? 'Non-VRV';
    $app = Bootstrap::init();
    $names = $app->sheets->getProjectNames($app->cfg, $siteType);
    json_out($names);
} catch (Throwable $e) {
    // Match the old client contract: it just expects an array; log the error.
    error_log('project_names: ' . $e->getMessage());
    json_out([]);
}
