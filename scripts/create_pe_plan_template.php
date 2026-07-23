<?php
/**
 * Creates the "pe_plan_reminder" WhatsApp template (IMAGE header + static body)
 * so the daily PE-plan card can be sent as an inline image, one day before the
 * planned work. One-off admin action — submits to Meta for approval.
 *   php scripts/create_pe_plan_template.php
 * Wait until scripts/check_wa_template.php shows it APPROVED, then set
 * pe_plan.mode = TEST/LIVE in the admin panel.
 */
require __DIR__ . '/../src/GoogleAuth.php';
require __DIR__ . '/../src/Bootstrap.php';
Bootstrap::autoload();
require __DIR__ . '/../src/PePlan.php';

$cfg   = require __DIR__ . '/../config/app.php';
$token = $cfg['whatsapp']['token'];
$ver   = $cfg['whatsapp']['graph_version'];
$appId = '1415548890216481';
$waba  = '1568163707846136';
$tplName = $cfg['pe_plan']['template_name'] ?: 'pe_plan_reminder';
$lang    = $cfg['whatsapp']['language_code'] ?: 'en';

if (empty($token)) { fwrite(STDERR, "whatsapp.token empty\n"); exit(1); }

function http(string $method, string $url, $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 60,
    ]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $resp === false ? '' : (string)$resp, 'err' => $err,
            'json' => json_decode((string)$resp, true)];
}

// --- sample plan image used as the header example (Meta requires one) --------
$pp = new PePlan($cfg['pe_plan'] ?? []);
$sample = [
    ['pe' => 'Pratik', 'sites' => [
        ['label' => 'Kasturi › Balmoral River side D-wing › D-102', 'steps' => ['Copper Piping']],
        ['label' => 'Suyog Navkar › Agam › 102', 'steps' => ['Support']],
    ]],
    ['pe' => 'Shubham', 'sites' => [
        ['label' => 'Suyog Navkar › Kalpa › 502', 'steps' => ['Support']],
    ]],
];
$png = $pp->renderPng(date('Y-m-d', strtotime('+1 day')), $sample);
$len = strlen($png);
echo "sample image: $len bytes\n";

// --- step 1: start resumable upload session ---------------------------------
$startUrl = "https://graph.facebook.com/$ver/$appId/uploads?"
    . http_build_query([
        'file_name'   => 'pe_plan_sample.png',
        'file_length' => $len,
        'file_type'   => 'image/png',
        'access_token'=> $token,
    ]);
$r1 = http('POST', $startUrl);
$session = $r1['json']['id'] ?? '';
if ($session === '') { echo "START FAILED: {$r1['code']} {$r1['body']}\n"; exit(1); }
echo "upload session: $session\n";

// --- step 2: upload the bytes -> file handle --------------------------------
$r2 = http('POST', "https://graph.facebook.com/$ver/$session", $png, [
    'Authorization: OAuth ' . $token,
    'file_offset: 0',
]);
$handle = $r2['json']['h'] ?? '';
if ($handle === '') { echo "UPLOAD FAILED: {$r2['code']} {$r2['body']}\n"; exit(1); }
echo "file handle: " . substr($handle, 0, 40) . "...\n";

// --- step 3: create the template --------------------------------------------
$payload = [
    'name'      => $tplName,
    'language'  => $lang,
    'category'  => 'UTILITY',
    'components' => [
        [
            'type'    => 'HEADER',
            'format'  => 'IMAGE',
            'example' => ['header_handle' => [$handle]],
        ],
        [
            'type' => 'BODY',
            'text' => "Tomorrow's site visit plan is attached above (grouped by engineer).\n"
                    . "Please keep material and manpower ready.\n\n— Vakharia Airtech PMS",
        ],
    ],
];
$r3 = http('POST', "https://graph.facebook.com/$ver/$waba/message_templates",
    json_encode($payload),
    ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);

echo "\n== create template result ==\nHTTP {$r3['code']}\n{$r3['body']}\n";
if (!empty($r3['json']['id'])) {
    echo "\nTEMPLATE CREATED ✓  id={$r3['json']['id']}  status=" . ($r3['json']['status'] ?? '?')
        . "  category=" . ($r3['json']['category'] ?? '?') . "\n";
    echo "Wait for APPROVED (php scripts/check_wa_template.php), then set pe_plan.mode in admin.\n";
    exit(0);
}
exit(1);
