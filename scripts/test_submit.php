<?php
/**
 * End-to-end submit test through SubmitService (no HTTP). Writes a clearly
 * marked test row to the VRV response tab, runs the whole pipeline, prints the
 * DB tracking, then DELETES the test row so the sheet is left clean.
 *   php scripts/test_submit.php
 */
require __DIR__ . '/../src/GoogleAuth.php';
require __DIR__ . '/../src/Bootstrap.php';
Bootstrap::autoload();

function jpeg(string $label): string
{
    $im = imagecreatetruecolor(800, 560);
    imagefill($im, 0, 0, imagecolorallocate($im, 45, 95, 145));
    imagestring($im, 5, 30, 30, $label, imagecolorallocate($im, 255, 255, 255));
    ob_start(); imagejpeg($im, null, 85); $b = ob_get_clean(); imagedestroy($im);
    return base64_encode($b);
}

$app = Bootstrap::init();

$payload = [
    'siteType' => 'VRV', 'clientType' => 'General',
    'project' => 'ZZZ PHP TEST — DELETE ME',
    'people' => '4', 'engineer' => 'PHP Tester',
    'currentStatus' => 'Copper Piping', 'status' => 'Pending',
    'workDoneBy' => 'VAPL',
    'activity' => 'Automated PHP end-to-end test submission. Verifies sheet write, PMS lookup, PDF build, and DB tracking.',
    'nextPlan' => 'Delete this test row.',
    'amendment' => 'No', 'drawingChange' => 'No', 'measurement' => 'No',
    'photos' => [
        ['base64' => jpeg('E2E PHOTO 1'), 'mimeType' => 'image/jpeg', 'name' => 'p1.jpg'],
        ['base64' => jpeg('E2E PHOTO 2'), 'mimeType' => 'image/jpeg', 'name' => 'p2.jpg'],
    ],
    'submitterEmail' => 'e2e@vakhariaairtech.com',
];

echo "Submitting...\n";
$service = new SubmitService($app);
$result = $service->handle($payload, ['email' => 'e2e@vakhariaairtech.com', 'ip' => '127.0.0.1', 'base_url' => 'http://localhost/pms']);
echo "RESULT: " . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

// Show DB tracking for this submission.
$db = $app->db();
$sub = $db->query('SELECT id,public_id,project,response_tab,response_row,overall_status,pdf_url FROM submissions WHERE public_id=?', [$result['publicId']]);
echo "SUBMISSION ROW:\n" . json_encode($sub[0] ?? null, JSON_PRETTY_PRINT) . "\n\n";
$logs = $db->query('SELECT step,status,message,target FROM process_log WHERE submission_id=? ORDER BY id', [$sub[0]['id'] ?? 0]);
echo "PROCESS LOG:\n";
foreach ($logs as $l) {
    printf("  %-12s %-8s %s\n", $l['step'], $l['status'], $l['target'] ? '[' . $l['target'] . '] ' : '', $l['message'] ?? '');
    if ($l['message']) { echo "                        -> " . $l['message'] . "\n"; }
}

// Clean up the test row from the live sheet.
if (!empty($result['row']) && !empty($sub[0]['response_tab'])) {
    echo "\nDeleting test row {$sub[0]['response_tab']}!{$result['row']} ...\n";
    try {
        $app->sheets->deleteRow($app->cfg['response_sheet_id'], $sub[0]['response_tab'], (int)$result['row']);
        echo "Test row deleted. Sheet clean.\n";
    } catch (Throwable $e) {
        echo "WARN: could not delete test row: " . $e->getMessage() . "\n";
    }
}
echo "\nGenerated PDF(s):\n";
foreach (glob(__DIR__ . '/../storage/reports/*ZZZ*') ?: [] as $f) {
    echo "  $f (" . filesize($f) . " bytes)\n";
}
