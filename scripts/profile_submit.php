<?php
/** Times the expensive steps of a submit so we know what to defer. */
require __DIR__ . '/../src/GoogleAuth.php';
require __DIR__ . '/../src/Bootstrap.php';
Bootstrap::autoload();

$cfg = require __DIR__ . '/../config/app.php';
function ms($t) { return round((microtime(true) - $t) * 1000); }
function jpeg($label) {
    $im = imagecreatetruecolor(1200, 900);
    imagefill($im, 0, 0, imagecolorallocate($im, 40, 90, 150));
    imagestring($im, 5, 30, 30, $label, imagecolorallocate($im, 255, 255, 255));
    ob_start(); imagejpeg($im, null, 85); $b = ob_get_clean(); imagedestroy($im);
    return $b;
}

$t = microtime(true);
$app = Bootstrap::init();
$app->auth->getAccessToken();
printf("%-34s %5d ms\n", 'Bootstrap + token', ms($t));

$t = microtime(true);
$app->sheets->getTab($cfg['response_sheet_id'], 'VRV');
printf("%-34s %5d ms\n", 'read response tab (VRV)', ms($t));

$t = microtime(true);
$app->sheets->getTab($cfg['general_pms_sheet_id'], 'PMS - VRV');
printf("%-34s %5d ms\n", 'read PMS-VRV (big grouped sheet)', ms($t));

$t = microtime(true);
$app->sheets->getTab($cfg['vrv_orders_sheet_id'], 'Orders');
printf("%-34s %5d ms\n", 'read VRV Orders (for Order ID)', ms($t));

// PDF build with 3 photos
$photos = [['bytes' => jpeg('P1'), 'mime' => 'image/jpeg'], ['bytes' => jpeg('P2'), 'mime' => 'image/jpeg'], ['bytes' => jpeg('P3'), 'mime' => 'image/jpeg']];
$t = microtime(true);
$out = sys_get_temp_dir() . '/prof_' . bin2hex(random_bytes(4)) . '.pdf';
(new Pdf(__DIR__ . '/../assets'))->build([
    'project_name' => 'PROFILE TEST', 'timestamp' => date('d-M-Y H:i:s'),
    'headers' => ['Timestamp', "Today's Activity", 'Select Project Name'],
    'rowValues' => [date('c'), 'profiling', 'PROFILE TEST'],
    'photos' => $photos, 'drawing' => null, 'out_path' => $out,
]);
printf("%-34s %5d ms  (3 photos)\n", 'build PDF', ms($t));

// Drive upload one file
$t = microtime(true);
$folder = $app->drive->getOrCreateProjectFolder('_PROFILE_TEST');
$up = $app->drive->uploadBytes($folder, 'prof.pdf', 'application/pdf', file_get_contents($out));
printf("%-34s %5d ms\n", 'Drive: folder + upload 1 file', ms($t));
$app->drive->trash($up['id']);
@unlink($out);

// WhatsApp media upload (network)
$t = microtime(true);
try {
    $wa = new Whatsapp($app->sheets, $app->drive, $cfg['whatsapp']);
    $wa->uploadMedia(jpeg('X'), 'x.pdf'); // reuse as bytes just to time upload
    printf("%-34s %5d ms\n", 'WhatsApp media upload', ms($t));
} catch (Throwable $e) {
    printf("%-34s  (skip: %s)\n", 'WhatsApp media upload', $e->getMessage());
}

echo "\nNote: email SMTP send + WhatsApp send add ~2-4s each on top.\n";
