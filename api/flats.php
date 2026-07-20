<?php
/**
 * GET api/flats.php?developer=Kasturi&building=<tab>
 * -> [{"flat":"D-102","floor":"1st floor"}, ...]
 * Reads the developer building tab's "Flat No" (+ "Floor") column so the form can
 * offer a searchable flat picker instead of manual entry. Empty array on any error.
 */
require __DIR__ . '/_common.php';

try {
    $app = Bootstrap::init();
    $pms = new Pms($app->sheets, $app->cfg);
    json_out($pms->getFlats((string)($_GET['developer'] ?? ''), (string)($_GET['building'] ?? '')));
} catch (Throwable $e) {
    error_log('flats: ' . $e->getMessage());
    json_out([]);
}
