<?php
/** Offline unit checks for phase-2 senders (no network, no sheets). */
require __DIR__ . '/../src/GoogleAuth.php';
require __DIR__ . '/../src/Bootstrap.php';
Bootstrap::autoload();

$cfg = require __DIR__ . '/../config/app.php';
$app = Bootstrap::init();
$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    $ok = $got === $want;
    printf("  [%s] %-42s got=%s\n", $ok ? 'PASS' : 'FAIL', $label, is_array($got) ? json_encode($got) : var_export($got, true));
    $ok ? $pass++ : $fail++;
}

echo "== Whatsapp phone normalization ==\n";
$wa = new Whatsapp($app->sheets, $app->drive, $cfg['whatsapp']);
$ref = new ReflectionClass($wa);
$fmt = $ref->getMethod('formatPhone'); $fmt->setAccessible(true);
$split = $ref->getMethod('splitPhones'); $split->setAccessible(true);
check('10-digit -> 91', $fmt->invoke($wa, '9876543210'), '919876543210');
check('leading 0 stripped', $fmt->invoke($wa, '09876543210'), '919876543210');
check('already 91', $fmt->invoke($wa, '919876543210'), '919876543210');
check('spaces/paren', $fmt->invoke($wa, '(98765) 43210'), '919876543210');
check('too short -> empty', $fmt->invoke($wa, '12345'), '');
check('split multi "a / b"', $split->invoke($wa, '9876543210 / 09123456789'), ['919876543210', '919123456789']);
check('drops NA/junk', $split->invoke($wa, 'NA, 9876543210, No number'), ['919876543210']);

echo "\n== Mailer recipient resolution (developer map, no sheet reads) ==\n";
$mailer = new Mailer($app->sheets, $cfg['email']);
// Kasturi is a developer with an email in config.
check('developer Kasturi', $mailer->resolveRecipient('Developer', 'Kasturi', 'Kasturi'), 'devops@vakhariaairtech.com');
// Developer by project name, Suyog has blank email -> fallback.
check('developer Suyog -> fallback', $mailer->resolveRecipient('Developer', 'Suyog Navkar', 'Suyog Navkar'), $cfg['email']['fallback_to']);

echo "\n== Mailer clean recipients ==\n";
$rc = (new ReflectionClass($mailer))->getMethod('cleanRecipients'); $rc->setAccessible(true);
check('keep real, drop junk', $rc->invoke($mailer, 'a@x.com, No emails found, b@y.co.in'), 'a@x.com,b@y.co.in');

echo "\n== Mailer HTML body ==\n";
$html = $mailer->buildReportHtml('BALMORAL <TEST>');
check('escapes project name', strpos($html, 'BALMORAL &lt;TEST&gt;') !== false, true);

echo "\n== SMTP MIME builder (multipart + attachment) ==\n";
$smtp = new Smtp($cfg['email']);
$bm = (new ReflectionClass($smtp))->getMethod('buildMime'); $bm->setAccessible(true);
$mime = $bm->invoke($smtp, 'from@x.com', 'to@y.com', 'cc@z.com', 'Site Report: X', '<b>hi</b>',
    ['name' => 'r.pdf', 'mime' => 'application/pdf', 'bytes' => 'PDFDATA']);
check('has multipart/mixed', strpos($mime, 'multipart/mixed') !== false, true);
check('has Cc header', strpos($mime, 'Cc: cc@z.com') !== false, true);
check('has attachment disp', strpos($mime, 'attachment; filename="r.pdf"') !== false, true);
check('attachment base64', strpos($mime, base64_encode('PDFDATA')) !== false, true);
check('subject present', strpos($mime, 'Subject: Site Report: X') !== false, true);

echo "\n== WhatsApp payload shape (named param) ==\n";
$st = $ref->getMethod('sendTemplate'); // don't call (network); just confirm config wiring
check('template name cfg', $cfg['whatsapp']['template_name'], 'daily_site_updates');
check('named params on', $cfg['whatsapp']['use_named_params'], true);

echo "\n== modes default OFF ==\n";
check('email mode', $mailer->mode(), 'OFF');
check('whatsapp mode', $wa->mode(), 'OFF');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
