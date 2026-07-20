<?php
/**
 * Background worker entry point.
 *   php scripts/worker.php          loop until idle (spawned by submit)
 *   php scripts/worker.php --once   one pass then exit (Task Scheduler safety net)
 */
require __DIR__ . '/../src/GoogleAuth.php';
require __DIR__ . '/../src/Bootstrap.php';
Bootstrap::autoload();
require __DIR__ . '/../src/Worker.php';

$once = in_array('--once', $argv, true);
(new Worker(Bootstrap::init()))->run($once);
