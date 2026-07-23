<?php
require __DIR__ . '/../src/Pdf.php';

// --- make a few sample photos with GD (stand-ins for uploaded site photos) ---
function samplePhoto(string $label, array $rgb): array
{
    $im = imagecreatetruecolor(900, 650);
    imagefill($im, 0, 0, imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]));
    $white = imagecolorallocate($im, 255, 255, 255);
    imagestring($im, 5, 40, 40, $label, $white);
    imagestring($im, 5, 40, 70, date('c'), $white);
    ob_start(); imagejpeg($im, null, 88); $bytes = ob_get_clean();
    imagedestroy($im);
    return ['bytes' => $bytes, 'mime' => 'image/jpeg'];
}

$headers = [
    'Timestamp', 'Email Address', 'Site Type', 'Select Project Name', 'Number of people',
    'Project Engineer Name', "Today's Activity", 'Upload Site Photo',
    'What is the next plan for this site tomorrow?',
    "Any amendment/PO/ approval Required?", "Any amendment/PO/ approval Required?\nIf Yes : why?",
    'Any Changes in Drawing as per Project Condition?',
    'Measurement Report Created Today?', 'Mail Status', 'PDF ID',
];
$rowValues = [
    '13-Jul-2026 15:04:22', 'engineer@site.com', 'VRV', 'BALMORAL RIVERSIDE - TOWER D', '6',
    'Rahul Deshmukh',
    'Completed copper piping on floors 3-5. Started cable pulling on floor 3. Crew coordinated with the developer site supervisor; material staging done for tomorrow.',
    'https://drive.google.com/x, https://drive.google.com/y',
    'Continue cable pulling floors 4-6, begin drain line on floor 3.',
    'No', 'N/A', 'Yes', 'No', 'PENDING', '',
];

$pdf = new Pdf(__DIR__ . '/../assets');
$out = __DIR__ . '/../storage/logs/sample_site_report.pdf';
$pdf->build([
    'project_name' => 'BALMORAL RIVERSIDE - TOWER D',
    'timestamp'    => '13-Jul-2026 15:04:22',
    'headers'      => $headers,
    'rowValues'    => $rowValues,
    'photos'       => [
        samplePhoto('SITE PHOTO 1', [40, 90, 150]),
        samplePhoto('SITE PHOTO 2', [150, 70, 60]),
        samplePhoto('SITE PHOTO 3', [60, 120, 80]),
    ],
    'drawing'      => samplePhoto('DRAWING CHANGE', [90, 70, 130]),
    'out_path'     => $out,
]);

printf("PDF written: %s (%d bytes)\n", $out, filesize($out));
