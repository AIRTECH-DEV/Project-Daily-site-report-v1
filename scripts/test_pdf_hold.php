<?php
require __DIR__ . '/../src/Pdf.php';

function samplePhoto(string $label, array $rgb): array
{
    $im = imagecreatetruecolor(900, 650);
    imagefill($im, 0, 0, imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]));
    $white = imagecolorallocate($im, 255, 255, 255);
    imagestring($im, 5, 40, 40, $label, $white);
    ob_start(); imagejpeg($im, null, 88); $bytes = ob_get_clean();
    imagedestroy($im);
    return ['bytes' => $bytes, 'mime' => 'image/jpeg'];
}

// Mirror of SubmitService::clientHoldText() so the test drives the real path:
// hold info comes from the PAYLOAD (the response sheet has NO hold columns).
function clientHoldText(array $p): string
{
    $parts = [];
    foreach (($p['stepStatuses'] ?? []) as $e) {
        if (!is_array($e) || trim((string)($e['status'] ?? '')) !== 'Hold') { continue; }
        if (stripos((string)($e['holdReason'] ?? ''), 'client') === false) { continue; }
        $detail = trim((string)($e['holdReasonDetail'] ?? ''));
        $parts[] = $detail !== '' ? $detail : trim((string)$e['holdReason']);
    }
    if (!$parts && stripos((string)($p['holdReason'] ?? ''), 'client') !== false) {
        $d = trim((string)($p['holdReasonDetail'] ?? ''));
        $parts[] = $d !== '' ? $d : trim((string)$p['holdReason']);
    }
    return implode("\n", array_values(array_unique(array_filter($parts))));
}

// Real sheet header set (19 cols) -- NOTE: no Hold Reason columns.
$headers = [
    'Timestamp', 'Email Address', 'Site Type', 'Select Project Name', 'Number of people',
    'Project Engineer Name', "Today's Activity", 'Upload Site Photo',
    'What is the next plan for this site tomorrow?',
    "Any amendment/PO/ approval Required?", "Any amendment/PO/ approval Required?\nIf Yes : why?",
    'Any Changes in Drawing as per Project Condition?',
    'Measurement Report Created Today?', 'Mail Status', 'PDF ID',
];
$rowValues = [
    '14-Jul-2026 12:04:22', 'engineer@site.com', 'VRV', 'KUREMU CONSULTANTS PVT. LTD. VRV', '1',
    'Shubham', 'Copper Piping is on hold - stuck by Client (client not home).',
    'https://drive.google.com/x',
    'Planned for tomorrow: Copper Piping, Cable.',
    'No', 'N/A', 'No', 'No', 'PENDING', '',
];

$cases = [
    'client' => [['step' => 'Copper Piping', 'status' => 'Hold', 'holdReason' => 'Stuck BY Client', 'holdReasonDetail' => 'client not home']],
    'vapl'   => [['step' => 'Copper Piping', 'status' => 'Hold', 'holdReason' => 'Stuck BY VAPL',   'holdReasonDetail' => 'material not dispatched']],
];

$pdf = new Pdf(__DIR__ . '/../assets');
foreach ($cases as $tag => $stepStatuses) {
    $p = ['stepStatuses' => $stepStatuses];
    $out = __DIR__ . '/../storage/logs/sample_hold_' . $tag . '.pdf';
    $pdf->build([
        'project_name' => 'KUREMU CONSULTANTS PVT. LTD. VRV',
        'timestamp'    => '14-Jul-2026 12:04:22',
        'headers'      => $headers,
        'rowValues'    => $rowValues,
        'client_hold'  => clientHoldText($p),
        'photos'       => [samplePhoto('SITE PHOTO 1', [40, 90, 150])],
        'drawing'      => null,
        'out_path'     => $out,
    ]);
    printf("%-6s client_hold=%-20s -> %s\n", $tag, '"' . clientHoldText($p) . '"', basename($out));
}
