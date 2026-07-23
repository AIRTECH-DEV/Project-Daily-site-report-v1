<?php
/** Shared endpoint prelude: JSON headers, error handling, service wiring. */
require_once __DIR__ . '/../src/GoogleAuth.php';
require_once __DIR__ . '/../src/Bootstrap.php';
Bootstrap::autoload();

header('Content-Type: application/json; charset=utf-8');
// Same-origin app; allow simple CORS so the form can also be embedded if needed.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_fail(string $msg, int $code = 400): void
{
    json_out(['success' => false, 'error' => $msg], $code);
}

/** Best-effort public base URL, e.g. http://localhost/pms */
function base_url(): string
{
    $https = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Endpoints live in /pms/api, so the app root is one directory up.
    $root = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/pms/api/x')), '/');
    return "$scheme://$host$root";
}
