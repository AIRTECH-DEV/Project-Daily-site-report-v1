<?php
/** Probes SA read-access to every spreadsheet code.js touches. */
require __DIR__ . '/../src/GoogleAuth.php';
require __DIR__ . '/../src/GoogleClient.php';
require __DIR__ . '/../src/Sheets.php';

$cfg = require __DIR__ . '/../config/app.php';
$auth = new GoogleAuth($cfg['service_account'], $cfg['scopes'], $cfg['token_cache_dir']);
$sheets = new Sheets(new GoogleClient($auth));

echo "Share ALL of these with: " . $auth->clientEmail() . " (Editor)\n\n";

$targets = [
    'Response sheet'    => $cfg['response_sheet_id'],
    'VRV Orders'        => $cfg['vrv_orders_sheet_id'],
    'Non-VRV Orders'    => $cfg['nonvrv_orders_sheet_id'],
    'General PMS'       => $cfg['general_pms_sheet_id'],
    'Suyog Navkar bldg' => $cfg['developer_building_sheets']['Suyog Navkar']['spreadsheetId'],
    'Kasturi bldg'      => $cfg['developer_building_sheets']['Kasturi']['spreadsheetId'],
];

foreach ($targets as $label => $id) {
    try {
        $tabs = $sheets->listTabs($id);
        $titles = array_map(fn($t) => $t['title'], $tabs);
        printf("  OK    %-18s %s  [%s]\n", $label, $id, implode(', ', array_slice($titles, 0, 6)));
    } catch (Throwable $e) {
        $code = strpos($e->getMessage(), '403') !== false ? 'NO ACCESS' : 'ERROR';
        printf("  %-6s %-18s %s  -> %s\n", $code, $label, $id, $e->getMessage());
    }
}
