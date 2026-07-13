<?php
require __DIR__ . '/../src/GoogleAuth.php';
require __DIR__ . '/../src/GoogleClient.php';
require __DIR__ . '/../src/Sheets.php';

$cfg = require __DIR__ . '/../config/app.php';
$s = new Sheets(new GoogleClient(new GoogleAuth($cfg['service_account'], $cfg['scopes'], $cfg['token_cache_dir'])));

$checks = [
    'timestamp' => null, 'email address' => null, 'site type' => null, 'client type' => null,
    'developer' => null, 'building' => null, 'floor' => null, 'flat no' => null,
    'select project name' => null, 'current status' => null, 'work done by' => null,
    "today's activity" => null, 'upload site photo' => null, 'what is the next plan' => null,
    'number of people' => null, 'project engineer name' => null,
    'tentative project end date' => null, 'mail status' => null, 'pdf id' => null,
    'hold reason' => 'detail', 'hold reason detail' => null,
    'approval required?' => 'why', 'why' => null,
    'changes in drawing' => 'upload photo here', 'upload photo here' => null,
    'measurement report created today' => 'upload the measurement',
    'upload the measurement report here' => null,
];

foreach (['VRV', 'Non-VRV'] as $tab) {
    $rows = $s->getTab($cfg['response_sheet_id'], $tab);
    $h = $rows[0] ?? [];
    echo "\n=== $tab (" . count($h) . " cols) ===\n";
    $missing = 0;
    foreach ($checks as $inc => $exc) {
        $i = Sheets::findColIndex($h, $inc, $exc);
        if ($i > -1) {
            printf("  ok  %-34s -> col %d  \"%s\"\n", $inc, $i + 1, $h[$i]);
        } else {
            printf("  --  %-34s -> NOT FOUND\n", $inc);
            $missing++;
        }
    }
    echo "  ($missing not found)\n";
}
