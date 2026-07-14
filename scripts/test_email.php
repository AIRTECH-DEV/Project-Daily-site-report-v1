<?php
/**
 * Sends ONE test email to email.test_to (TEST mode) to verify SMTP creds.
 * Fill config/app.php > email.smtp_pass first. Sends to nobody else, no CC,
 * touches no sheet.
 *   php scripts/test_email.php
 */
require __DIR__ . '/../src/GoogleAuth.php';
require __DIR__ . '/../src/Bootstrap.php';
Bootstrap::autoload();

$cfg = require __DIR__ . '/../config/app.php';
$app = Bootstrap::init();

$emailCfg = $cfg['email'];
$emailCfg['mode'] = 'TEST';                      // force TEST for this script
if (empty($emailCfg['smtp_pass'])) {
    fwrite(STDERR, "email.smtp_pass is empty — set the crm@ app password in config/app.php first.\n");
    exit(1);
}

// Minimal real PDF to attach.
require __DIR__ . '/../vendor/fpdf/fpdf.php';
$fp = new FPDF();
$fp->AddPage(); $fp->SetFont('Arial', 'B', 16);
$fp->Cell(0, 10, 'PMS SMTP test — ' . date('c'));
$pdfBytes = $fp->Output('S');

$mailer = new Mailer($app->sheets, $emailCfg);
echo "Sending test email to {$emailCfg['test_to']} via {$emailCfg['smtp_host']}:{$emailCfg['smtp_port']} ...\n";
$r = $mailer->sendReport('General', '', 'SMTP TEST PROJECT', $pdfBytes, 'smtp_test.pdf');
echo $r['sent']
    ? "SENT ✓ to {$r['to']} — check that inbox.\n"
    : "FAILED: {$r['error']}\n";
exit($r['sent'] ? 0 : 1);
