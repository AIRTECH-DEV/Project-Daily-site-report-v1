<?php
/**
 * POST api/submit.php (JSON body = AppJs payload).
 * Fast path: capture the submission, kick the background worker, return at once.
 * Photos/sheet/PMS/PDF run in the worker immediately; email + WhatsApp ~3 min later.
 */
require __DIR__ . '/_common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail('POST required', 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    json_fail('Invalid JSON payload');
}

try {
    $app = Bootstrap::init();
    $service = new SubmitService($app);
    $r = $service->enqueue($payload, [
        'email'    => $payload['submitterEmail'] ?? 'unknown',
        'ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
        'base_url' => base_url(),
    ]);

    // Launch the worker without blocking the response.
    Spawn::worker($app->cfg);

    json_out([
        'success'  => true,
        'queued'   => true,
        'publicId' => $r['public_id'],
        'message'  => 'Report received. The PDF and notifications are being processed.',
    ]);
} catch (Throwable $e) {
    error_log('submit: ' . $e->getMessage());
    json_out(['success' => false, 'error' => $e->getMessage()], 500);
}
