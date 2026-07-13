<?php
/** POST api/submit.php  (JSON body = the AppJs payload) -> submit result JSON */
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
    $result = $service->handle($payload, [
        'email'    => $payload['submitterEmail'] ?? 'unknown',
        'ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
        'base_url' => base_url(),
    ]);
    json_out($result);
} catch (Throwable $e) {
    error_log('submit: ' . $e->getMessage());
    json_out(['success' => false, 'error' => $e->getMessage()], 500);
}
