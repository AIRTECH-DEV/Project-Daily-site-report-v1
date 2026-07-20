<?php
/**
 * Sends ONE test WhatsApp (TEST mode -> whatsapp.test_to) for the most recently
 * submitted report. Honors whatsapp.delivery: 'link' sends the report link,
 * 'document' attaches the actual PDF (needs the approved doc template).
 *   php scripts/test_whatsapp.php
 */
require __DIR__ . '/../src/GoogleAuth.php';
require __DIR__ . '/../src/Bootstrap.php';
Bootstrap::autoload();

$cfg = require __DIR__ . '/../config/app.php';
$app = Bootstrap::init();

$waCfg = $cfg['whatsapp'];
$waCfg['mode'] = 'TEST';
if (empty($waCfg['token']))   { fwrite(STDERR, "whatsapp.token empty.\n"); exit(1); }
if (empty($waCfg['test_to'])) { fwrite(STDERR, "whatsapp.test_to empty (e.g. 919876543210).\n"); exit(1); }

// Newest report + its PDF.
$rows = $app->db()->query(
    "SELECT s.project, s.client_type, s.developer, a.drive_file_id, a.file_name
     FROM submissions s JOIN attachments a ON a.submission_id = s.id AND a.kind='pdf'
     WHERE a.drive_file_id IS NOT NULL
     ORDER BY s.id DESC LIMIT 1"
);
if (!$rows) { fwrite(STDERR, "No submitted report with a PDF found.\n"); exit(1); }
$r = $rows[0];

$localPath = __DIR__ . '/../storage/reports/' . $r['file_name'];
$pdf = [
    'drive_id' => $r['drive_file_id'],
    'path'     => is_file($localPath) ? $localPath : '',
    'name'     => $r['file_name'] ?: 'Site Report.pdf',
];

echo "Report:   {$r['project']}\n";
echo "Delivery: {$waCfg['delivery']}   ->   {$waCfg['test_to']}\n";

// Document mode needs a local PDF. If the report's local copy is gone (e.g. after
// a test_submit), build a minimal one so we still verify the real document-send
// path (media upload + doc-header template), not just the link fallback.
if (strtolower($waCfg['delivery']) === 'document' && $pdf['path'] === '') {
    require_once __DIR__ . '/../vendor/fpdf/fpdf.php';   // already loaded by Bootstrap
    $fp = new FPDF();
    $fp->AddPage(); $fp->SetFont('Arial', 'B', 14);
    $fp->Cell(0, 10, 'PMS WhatsApp document test - ' . date('c'));
    $tmp = sys_get_temp_dir() . '/pms_wa_test.pdf';
    $fp->Output('F', $tmp);
    $pdf['path'] = $tmp;
    $pdf['name'] = 'PMS WhatsApp Test.pdf';
    echo "(no local report PDF — built a minimal test PDF to verify document delivery)\n";
}

$wa = new Whatsapp($app->sheets, $app->drive, $waCfg);
$res = $wa->sendReport($r['client_type'] ?? 'General', $r['developer'] ?? '', $r['project'] ?? '', $pdf);
echo "STATUS: {$res['status']} — {$res['detail']}\n";
exit((strpos($res['status'], 'SENT') === 0 || strpos($res['status'], 'PARTIAL') === 0) ? 0 : 1);
