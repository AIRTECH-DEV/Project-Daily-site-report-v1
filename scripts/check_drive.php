<?php
require __DIR__ . '/../src/GoogleAuth.php';
require __DIR__ . '/../src/GoogleClient.php';
require __DIR__ . '/../src/Drive.php';

$cfg = require __DIR__ . '/../config/app.php';
$auth = new GoogleAuth($cfg['service_account'], $cfg['scopes'], $cfg['token_cache_dir']);
$client = new GoogleClient($auth);
$drive = new Drive($client, $cfg);

echo "SA: " . $auth->clientEmail() . "\n";
$parent = $cfg['parent_folder_id'];

// 1) Can the SA see the parent folder, and is it on a Shared Drive?
try {
    $url = Drive::FILES . '/' . rawurlencode($parent) . '?' . http_build_query([
        'fields'            => 'id,name,driveId,mimeType,capabilities(canAddChildren)',
        'supportsAllDrives' => 'true',
    ]);
    $meta = $client->get($url);
    printf("Parent folder: \"%s\"\n  id=%s\n  driveId=%s  %s\n  canAddChildren=%s\n",
        $meta['name'] ?? '?', $meta['id'] ?? '?',
        $meta['driveId'] ?? '(none — this is My Drive, NOT a Shared Drive)',
        isset($meta['driveId']) ? '(Shared Drive ✓)' : '',
        var_export($meta['capabilities']['canAddChildren'] ?? null, true));
} catch (Throwable $e) {
    echo "PARENT FOLDER NOT ACCESSIBLE: " . $e->getMessage() . "\n";
    echo "-> Share the Shared Drive (or folder) with the SA as Content manager/Editor.\n";
    exit(1);
}

// 2) Try a real folder + upload + trash.
try {
    $folder = $drive->getOrCreateProjectFolder('_PHP_UPLOAD_TEST');
    echo "\nProject folder id: $folder\n";
    $up = $drive->uploadBytes($folder, 'php_probe.txt', 'text/plain', "hello from php " . date('c'));
    echo "Uploaded: id={$up['id']} bytes={$up['bytes']}\n  url={$up['url']}\n";
    $drive->trash($up['id']);
    echo "Trashed test file OK.\n\nDrive upload works ✓\n";
} catch (Throwable $e) {
    echo "\nUPLOAD FAILED: " . $e->getMessage() . "\n";
    if (stripos($e->getMessage(), 'quota') !== false) {
        echo "-> This is the My-Drive 0-quota limit. The parent folder MUST be on a Shared Drive.\n";
    }
    exit(1);
}
