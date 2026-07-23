<?php
/**
 * Creates the "daily_site_update_doc" WhatsApp template (DOCUMENT header + body)
 * via the Message Templates API, so the PDF can be sent as an attachment.
 * One-off admin action — submits the template to Meta for approval.
 *   php scripts/create_wa_template.php
 */
require __DIR__ . '/../src/GoogleAuth.php';
require __DIR__ . '/../src/Bootstrap.php';
Bootstrap::autoload();

$cfg   = require __DIR__ . '/../config/app.php';
$token = $cfg['whatsapp']['token'];
$ver   = $cfg['whatsapp']['graph_version'];
$appId = '1415548890216481';
$waba  = '1568163707846136';
$tplName = $cfg['whatsapp']['doc_template_name'] ?: 'daily_site_update_doc';

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

// --- sample PDF for the header example (Meta requires an example media) ------
$fp = new FPDF('P', 'pt', 'A4');
$fp->AddPage();
$fp->SetFont('Arial', 'B', 20);
$fp->Cell(0, 30, 'Daily Site Update Report');
$fp->SetFont('Arial', '', 12);
$fp->Ln(40);
$fp->MultiCell(0, 16, "Sample document used only as the template header example.\nVakharia Airtech.");
$pdf = $fp->Output('S');
$len = strlen($pdf);
echo "sample pdf: $len bytes\n";

// --- step 1: start resumable upload session ---------------------------------
$startUrl = "https://graph.facebook.com/$ver/$appId/uploads?"
    . http_build_query([
        'file_name'   => 'daily_site_update_sample.pdf',
        'file_length' => $len,
        'file_type'   => 'application/pdf',
        'access_token'=> $token,
    ]);
$r1 = http('POST', $startUrl);
$session = $r1['json']['id'] ?? '';
if ($session === '') { echo "START FAILED: {$r1['code']} {$r1['body']}\n"; exit(1); }
echo "upload session: $session\n";

// --- step 2: upload the bytes -> file handle --------------------------------
$r2 = http('POST', "https://graph.facebook.com/$ver/$session", $pdf, [
    'Authorization: OAuth ' . $token,
    'file_offset: 0',
]);
$handle = $r2['json']['h'] ?? '';
if ($handle === '') { echo "UPLOAD FAILED: {$r2['code']} {$r2['body']}\n"; exit(1); }
echo "file handle: " . substr($handle, 0, 40) . "...\n";

// --- step 3: create the template --------------------------------------------
$payload = [
    'name'       => $tplName,
    'language'   => $cfg['whatsapp']['language_code'],
    'category'   => 'UTILITY',
    'components'  => [
        [
            'type'    => 'HEADER',
            'format'  => 'DOCUMENT',
            'example' => ['header_handle' => [$handle]],
        ],
        [
            'type' => 'BODY',
            'text' => "Hello,\n\nPlease find your Daily Site Update Report attached above.\n"
                    . "Open the attached PDF to view the detailed site progress.\n\nRegards,\nVakharia Airtech",
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
    echo "Wait for status APPROVED, then set config whatsapp.delivery = 'document'.\n";
    exit(0);
}
exit(1);
