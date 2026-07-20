<?php
/** Prints approval status of the WhatsApp templates. php scripts/check_wa_template.php */
require __DIR__ . '/../src/GoogleAuth.php';
require __DIR__ . '/../src/Bootstrap.php';
Bootstrap::autoload();

$cfg  = require __DIR__ . '/../config/app.php';
$t    = $cfg['whatsapp']['token'];
$ver  = $cfg['whatsapp']['graph_version'];
$waba = '1568163707846136';

$u = "https://graph.facebook.com/$ver/$waba/message_templates?fields=name,status,category&limit=50&access_token=" . urlencode($t);
$j = json_decode((string)file_get_contents($u), true);
foreach (($j['data'] ?? []) as $d) {
    printf("  %-26s %-10s %s\n", $d['name'], $d['status'], $d['category']);
}
if (empty($j['data'])) {
    echo "no templates / " . json_encode($j) . "\n";
}
$doc = 'daily_site_update_doc';
$found = array_filter($j['data'] ?? [], fn($d) => $d['name'] === $doc);
$row = reset($found);
if ($row && $row['status'] === 'APPROVED') {
    echo "\n$doc is APPROVED — set config whatsapp.delivery = 'document' to attach the PDF.\n";
} elseif ($row) {
    echo "\n$doc is {$row['status']} — wait for APPROVED.\n";
}
