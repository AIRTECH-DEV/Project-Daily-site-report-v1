<?php
/**
 * De-risk check: mint a service-account token and read live data.
 *   php scripts/verify_auth.php
 * Proves the SA credential, JWT signing, token exchange, and sheet sharing all
 * work — before any submit logic is built on top.
 */
require __DIR__ . '/../src/GoogleAuth.php';
require __DIR__ . '/../src/GoogleClient.php';
require __DIR__ . '/../src/Sheets.php';

$cfg = require __DIR__ . '/../config/app.php';

try {
    $auth = new GoogleAuth($cfg['service_account'], $cfg['scopes'], $cfg['token_cache_dir']);
    echo "SA: " . $auth->clientEmail() . "\n";

    $t0 = microtime(true);
    $token = $auth->getAccessToken();
    printf("Access token OK (len %d) in %.2fs\n", strlen($token), microtime(true) - $t0);

    $client = new GoogleClient($auth);
    $sheets = new Sheets($client);

    foreach (['VRV', 'Non-VRV'] as $site) {
        $names = $sheets->getProjectNames($cfg, $site);
        printf("\n%s project names: %d found\n", $site, count($names));
        foreach (array_slice($names, 0, 8) as $n) {
            echo "   - $n\n";
        }
        if (count($names) > 8) {
            echo "   ... (" . (count($names) - 8) . " more)\n";
        }
    }
    echo "\nOK — auth + sheet read working.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
