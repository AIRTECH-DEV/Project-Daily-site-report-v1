<?php
require __DIR__ . '/../src/GoogleAuth.php';
require __DIR__ . '/../src/GoogleClient.php';
require __DIR__ . '/../src/Sheets.php';
require __DIR__ . '/../src/Pms.php';

$cfg = require __DIR__ . '/../config/app.php';
$sheets = new Sheets(new GoogleClient(new GoogleAuth($cfg['service_account'], $cfg['scopes'], $cfg['token_cache_dir'])));

// Reflection to reach private header parser + finders for diagnostics.
$pms = new Pms($sheets, $cfg);
$ref = new ReflectionClass($pms);
$call = function (string $m, ...$a) use ($ref, $pms) {
    $x = $ref->getMethod($m); $x->setAccessible(true); return $x->invoke($pms, ...$a);
};

function dumpTab($sheets, $call, $ssId, $wanted, $step, $flatCol = false) {
    $title = $sheets->titleForName($ssId, $wanted);
    echo "\n===== $wanted  (resolved tab: " . ($title ?? 'NOT FOUND') . ") =====\n";
    if ($title === null) return;
    $rows = $sheets->getTab($ssId, $title);
    $info = $call('headerInfo', $rows);
    printf("subRowIndex=%d dataStartRow=%d lastCol=%d totalRows=%d\n",
        $info['subRowIndex'], $info['dataStartRow'], $info['lastCol'], count($rows));
    for ($i = 0; $i < $info['lastCol']; $i++) {
        $g = $info['groupVals'][$i]; $s = $info['subVals'][$i];
        if ($g !== '' || $s !== '') printf("  col %2d | group=\"%s\" | sub=\"%s\"\n", $i + 1, $g, $s);
    }
    printf("  --> '%s' Status col = %d\n", $step, $call('findStepStatusCol', $info, $step));
    printf("  --> '%s' End Date col = %d\n", $step, $call('findStepSubCol', $info, $step, 'End Date'));
    printf("  --> Remarks col = %d\n", $call('findNamedCol', $info, 'Remarks'));
    printf("  --> Work Done BY col = %d\n", $call('findNamedCol', $info, 'Work Done BY'));
    printf("  --> Project Name col = %d\n", $call('findNamedCol', $info, 'Project Name'));
    printf("  --> Order ID col = %d\n", $call('findOrderIdCol', $info));
    if ($flatCol) printf("  --> Flat No col = %d\n", $call('findNamedCol', $info, 'Flat No'));
}

// Developer sheet (Kasturi) — the one code.js diagnosed.
dumpTab($sheets, $call, $cfg['developer_building_sheets']['Kasturi']['spreadsheetId'],
    'Balmoral TowerD-wing', 'Copper Piping', true);

// General PMS - VRV
dumpTab($sheets, $call, $cfg['general_pms_sheet_id'], 'PMS - VRV', 'Copper Piping', false);
