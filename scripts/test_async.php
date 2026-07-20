<?php
/**
 * Verifies the async plumbing without sending anything:
 *  - enqueue() is fast and creates a queued job + submission
 *  - Worker runs core (photos/sheet/PMS/PDF) then notifications (forced OFF)
 *  - job file is consumed; DB status advances
 * Cleans up the live sheet row, Drive folder, DB rows and job file afterwards.
 */
require __DIR__ . '/../src/GoogleAuth.php';
require __DIR__ . '/../src/Bootstrap.php';
Bootstrap::autoload();
require __DIR__ . '/../src/Worker.php';

function jpeg($l) { $im=imagecreatetruecolor(600,400); imagefill($im,0,0,imagecolorallocate($im,50,100,150));
    imagestring($im,5,20,20,$l,imagecolorallocate($im,255,255,255)); ob_start(); imagejpeg($im,null,80);
    $b=ob_get_clean(); imagedestroy($im); return base64_encode($b); }

$app = Bootstrap::init();
// Neutralize real sends + delay for this test.
$app->cfg['email']['mode'] = 'OFF';
$app->cfg['whatsapp']['mode'] = 'OFF';
$app->cfg['notify_delay_seconds'] = 0;

$payload = [
    'siteType' => 'VRV', 'clientType' => 'General',
    'project' => 'ZZZ ASYNC TEST — DELETE ME',
    'people' => '3', 'engineer' => 'Async Tester',
    'currentStatus' => 'Copper Piping', 'status' => 'Pending', 'workDoneBy' => 'VAPL',
    'activity' => 'Async pipeline test.', 'nextPlan' => 'delete', 'amendment' => 'No',
    'drawingChange' => 'No', 'measurement' => 'No',
    'photos' => [['base64'=>jpeg('A1'),'mimeType'=>'image/jpeg','name'=>'a1.jpg']],
];

$svc = new SubmitService($app);

$t = microtime(true);
$r = $svc->enqueue($payload, ['email'=>'async@test.com','ip'=>'127.0.0.1','base_url'=>'http://localhost/pms']);
printf("enqueue: %d ms  public_id=%s\n", round((microtime(true)-$t)*1000), $r['public_id']);

$queue = new JobQueue($app->cfg['queue_dir']);
echo "job file exists after enqueue: " . (is_file($queue->path($r['public_id'])) ? 'yes' : 'NO') . "\n";
$st = $app->db()->query("SELECT overall_status FROM submissions WHERE public_id=?", [$r['public_id']]);
echo "submission status: " . ($st[0]['overall_status'] ?? '?') . " (expect queued)\n\n";

echo "running worker (once)...\n";
(new Worker($app))->run(true);

echo "\njob file exists after worker: " . (is_file($queue->path($r['public_id'])) ? 'yes' : 'NO (consumed)') . "\n";
$sub = $app->db()->query("SELECT id,response_tab,response_row,overall_status,pdf_url FROM submissions WHERE public_id=?", [$r['public_id']]);
echo "submission: " . json_encode($sub[0] ?? null) . "\n";
$logs = $app->db()->query("SELECT step,status FROM process_log WHERE submission_id=? ORDER BY id", [$sub[0]['id'] ?? 0]);
foreach ($logs as $l) { printf("  %-12s %s\n", $l['step'], $l['status']); }

// ---- cleanup ----
echo "\ncleanup...\n";
if (!empty($sub[0]['response_row'])) {
    try { $app->sheets->deleteRow($app->cfg['response_sheet_id'], $sub[0]['response_tab'], (int)$sub[0]['response_row']); echo "  sheet row deleted\n"; }
    catch (Throwable $e) { echo "  sheet delete warn: ".$e->getMessage()."\n"; }
}
try {
    $q=rawurlencode("mimeType='application/vnd.google-apps.folder' and trashed=false and '".$app->cfg['parent_folder_id']."' in parents");
    $url="https://www.googleapis.com/drive/v3/files?q=$q&corpora=drive&driveId=".$app->cfg['shared_drive_id']."&includeItemsFromAllDrives=true&supportsAllDrives=true&fields=".rawurlencode("files(id,name)");
    foreach ($app->client->get($url)['files'] as $f) { if (strpos($f['name'],'ZZZ')!==false){ $app->drive->trash($f['id']); echo "  drive folder trashed: {$f['name']}\n"; } }
} catch (Throwable $e) { echo "  drive clean warn: ".$e->getMessage()."\n"; }
$app->db()->pdo()->exec("DELETE FROM submissions WHERE project LIKE 'ZZZ%'");
$queue->delete($r['public_id']);
foreach (glob(__DIR__.'/../storage/reports/*ZZZ*') ?: [] as $f) { @unlink($f); }
echo "done.\n";
