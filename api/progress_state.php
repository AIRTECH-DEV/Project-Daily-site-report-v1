<?php
/**
 * GET api/progress_state.php
 *   ?clientType=General&siteType=Non-VRV&project=<name>
 *   ?clientType=Developer&developer=Kasturi&building=<tab>&flatNo=D-102&floor=<x>&siteType=Non-VRV
 * -> { "found": bool, "doneSteps": ["Copper Piping", ...], "orderId": "BRSDW-D-102" }
 *
 * Read-only: never writes. Drives the form's pre-ticked / locked status steps and
 * shows the developer Order ID. Degrades to {found:false,doneSteps:[],orderId:''} on any error.
 */
require __DIR__ . '/_common.php';

try {
    $app = Bootstrap::init();
    $pms = new Pms($app->sheets, $app->cfg);
    $res = $pms->getProgressState([
        'clientType' => $_GET['clientType'] ?? 'General',
        'siteType'   => $_GET['siteType'] ?? '',
        'project'    => $_GET['project'] ?? '',
        'developer'  => $_GET['developer'] ?? '',
        'building'   => $_GET['building'] ?? '',
        'flatNo'     => $_GET['flatNo'] ?? '',
        'floor'      => $_GET['floor'] ?? '',
    ]);
    json_out($res);
} catch (Throwable $e) {
    error_log('progress_state: ' . $e->getMessage());
    json_out(['found' => false, 'doneSteps' => [], 'orderId' => '']);
}
