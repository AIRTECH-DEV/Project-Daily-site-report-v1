<?php
/**
 * Local end-to-end test for the pre-commissioning machine prefill.
 *
 *   php scripts/test_precommissioning_machines.php [workbook.xls|.xlsx] [--push]
 *
 * Without --push it stays offline: parse a workbook (or the built-in form-JSON
 * sample) -> extract model/serial/location -> store in PMS -> read back the
 * exact `machines[]` array CommissionPush would send.
 *
 * With --push it also POSTs that payload to the configured app backend
 * (config/app.php -> app_backend.url) and GETs the queue back, so you can see
 * the technician's app receiving the prefilled machine list. Override with
 * --url=http://localhost/vapl/api/commissioning_api.php and --key=...
 *
 * Safe to re-run: it writes one project key, PMS_TEST|precommissioning, and
 * touches nothing else. Needs no Google credentials.
 */
require __DIR__ . '/../src/Workbook.php';
require __DIR__ . '/../src/PreCommissioningMachines.php';

$args     = array_slice($argv, 1);
$flags    = array_values(array_filter($args, fn($a) => strpos($a, '--') === 0));
$workbook = array_values(array_filter($args, fn($a) => strpos($a, '--') !== 0))[0] ?? null;
$opt      = static function (string $name) use ($flags): ?string {
    foreach ($flags as $f) {
        if (strpos($f, "--$name=") === 0) { return substr($f, strlen($name) + 3); }
    }
    return null;
};
$push = in_array('--push', $flags, true);

$cfg = require __DIR__ . '/../config/app.php';

// --project="Agam Wing - Flat 1202" makes the queue row read like a real job
// instead of "PMS local test", which is what you want when someone is going to
// open it in the app. Still namespaced PMS_TEST| so it is obvious what it is and
// trivial to delete afterwards.
$projectName = $opt('project') ?? 'PMS local test - pre-commissioning prefill';
$projectKey  = 'PMS_TEST|' . strtolower(trim($opt('project') ?? 'precommissioning'));

/* ---------- 1. extract ---------- */

if ($workbook !== null) {
    echo "SOURCE  uploaded workbook: $workbook\n";
    $machines = PreCommissioningMachines::fromWorkbook($workbook);
} else {
    echo "SOURCE  in-app form JSON (built-in sample; pass a .xls/.xlsx to test the upload path)\n";
    $machines = PreCommissioningMachines::fromForm(sampleForm());
}

if (!$machines) {
    fwrite(STDERR, "FAIL    no machines extracted — check the sheet has a Model No. / Sr. No. header row\n");
    exit(1);
}
printf("\n%-4s %-4s %-5s %-15s %-9s %-16s %-7s %-12s %s\n",
    'M#', 'U#', 'ROLE', 'MODEL', 'SERIAL', 'LOCATION', 'SYSTEM', 'INVOICE', 'DATE');
foreach ($machines as $m) {
    printf("%-4s %-4s %-5s %-15s %-9s %-16s %-7s %-12s %s\n",
        $m['machineNo'], $m['unitNo'], $m['role'], $m['model'], $m['serial'],
        $m['location'], $m['system'], $m['invoiceNo'], $m['invoiceDate'] ?? '');
}
echo "\nEXTRACT " . count($machines) . " unit(s)\n";

/* ---------- 2. store + read back ---------- */

$d   = $cfg['db'];
$pdo = new PDO(
    "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset={$d['charset']}",
    $d['user'], $d['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$stored = PreCommissioningMachines::store($pdo, $projectKey, null, $machines);
echo "STORE   $stored row(s) into precommissioning_machines under $projectKey\n";

$payloadMachines = PreCommissioningMachines::forProject($pdo, $projectKey);
echo "READ    " . count($payloadMachines) . " row(s) back — this is payload.machines[]\n";
if (count($payloadMachines) !== $stored) {
    fwrite(STDERR, "FAIL    stored $stored but read back " . count($payloadMachines) . "\n");
    exit(1);
}

/* ---------- 3. the push payload ---------- */

$payload = [
    'projectKey'        => $projectKey,
    'projectName'       => $projectName,
    'clientName'        => $opt('client') ?? 'Test Client',
    'clientPhone'       => $opt('phone'),
    'clientEmail'       => null,
    'address'           => $opt('address') ?? 'Local test address',
    'siteType'          => 'VRV',
    'clientType'        => 'General',
    'developer'         => null,
    'building'          => null,
    'flatNo'            => null,
    'orderId'           => null,
    'stage'             => 'pre_commissioned',
    'preCommissionedAt' => gmdate('c'),
    'commissionedAt'    => gmdate('c'),
    'machines'          => $payloadMachines,
];
echo "\nPAYLOAD POST <backend>/ingest/commissioned\n";
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

if (!$push) {
    echo "\nOK      offline check passed. Re-run with --push to send it to the app backend.\n";
    exit(0);
}

/* ---------- 4. push + read the queue as the app sees it ---------- */

$url = rtrim($opt('url') ?? (string)($cfg['app_backend']['url'] ?? ''), '/');
$key = $opt('key') ?? (string)($cfg['app_backend']['api_key'] ?? '');
if ($url === '') {
    fwrite(STDERR, "FAIL    no backend url — set app_backend.url or pass --url=\n");
    exit(1);
}
echo "\nPUSH    $url/ingest/commissioned\n";
[$code, $body] = http($url . '/ingest/commissioned', $key, $payload);
echo "        HTTP $code  $body\n";
if ($code < 200 || $code >= 300) {
    fwrite(STDERR, "FAIL    backend rejected the push\n");
    exit(1);
}

echo "\nPENDING $url/pending-commissioning   (what the Android app fetches)\n";
[$code, $body] = http($url . '/pending-commissioning', $key, null);
$res = json_decode($body, true);
$item = null;
foreach ((array)($res['items'] ?? []) as $row) {
    if (($row['projectKey'] ?? '') === $projectKey) { $item = $row; break; }
}
if ($item === null) {
    fwrite(STDERR, "FAIL    HTTP $code — test project not in the pending queue\n$body\n");
    exit(1);
}
echo json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

$idus = count((array)($item['prefill']['machines'] ?? []));
$odus = count((array)($item['prefill']['oduUnits'] ?? []));
if ($idus + $odus === 0) {
    fwrite(STDERR, "\nFAIL    queue row carries no prefill — apply vapl/migrations/028_commissioning_prefill_machines.sql\n");
    exit(1);
}
echo "\nOK      app receives $idus prefilled indoor unit(s) + $odus outdoor unit(s).\n";
// The test project now sits in the technician's pending queue like a real job.
echo "CLEANUP DELETE FROM commissioning_queue WHERE project_key = '$projectKey';\n";
echo "        DELETE FROM commissioning_queue_machines WHERE project_key = '$projectKey';\n";

/* ---------- helpers ---------- */

function http(string $url, string $key, ?array $body): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Api-Key: ' . $key, 'X-VAPL-APP-KEY: ' . $key],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $out  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($out === false) { $out = 'curl error: ' . curl_error($ch); }
    curl_close($ch);
    return [$code, (string)$out];
}

/** Mirrors what precommissioning-report.php saves — one 10 HP ODU, six indoor units. */
function sampleForm(): array
{
    return [
        'date' => date('Y-m-d'), 'customer' => 'Dr. Suresh Chandanmal Jain',
        'address' => 'Flat No. 902, Shruta Wing, Suyog Navkaar Building, Gultekadi, Pune',
        'machineReports' => [[
            'system' => '10 HP', 'oduModel' => 'RXMQ10BRY16', 'oduSerial' => '972',
            'units' => [
                ['model' => 'RXMQ10BRY16', 'serial' => '972',   'systemName' => '10 HP ODU',   'invoice' => '2527045074', 'invoiceDate' => '2025-11-29'],
                ['model' => 'FXKQ40ARV16', 'serial' => '3749',  'systemName' => 'LIVING ROOM'],
                ['model' => 'FXKQ40ARV16', 'serial' => '4339',  'systemName' => 'LIVING ROOM'],
                ['model' => 'FXKQ63ARV16', 'serial' => '24510', 'systemName' => 'M BEDROOM'],
                ['model' => 'FXKQ40ARV16', 'serial' => '3746',  'systemName' => 'FAMILY ROOM'],
                ['model' => 'FXKQ63ARV16', 'serial' => '24489', 'systemName' => 'BEDROOM 1'],
                ['model' => 'FXKQ63ARV16', 'serial' => '24495', 'systemName' => 'BEDROOM 2'],
            ],
        ]],
    ];
}
