<?php
/**
 * Creates (or inspects) the WhatsApp template used to deliver a client share link.
 *
 *   php scripts/create_share_template.php --list      # what exists today
 *   php scripts/create_share_template.php --create    # submit for approval
 *
 * The template carries a dynamic URL button whose SUFFIX is the share token —
 * Meta only allows the variable at the end of the URL, which is exactly what we
 * want: the host is fixed by the approved template, so a token can never be made
 * to point anywhere else.
 *
 * Body variables (positional): {1} client name, {2} project, {3} stage, {4} percent.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
$cfg = require __DIR__ . '/../config/app.php';
$wa    = $cfg['whatsapp'] ?? [];
$share = $cfg['share'] ?? [];

$token = (string)($wa['token'] ?? '');
$waba  = (string)($share['waba_id'] ?? '');
$gv    = (string)($wa['graph_version'] ?? 'v21.0');
if ($token === '' || $waba === '') {
    exit("Missing whatsapp.token (config/secrets.php) or share.waba_id (config/app.php)\n");
}

// The public base the button appends the token to. Must be reachable over HTTPS
// and must serve share.php for /s/<token> (see DEPLOY_GCP.md nginx snippet).
//
// Meta bakes this URL into the APPROVED template — it cannot be edited later, so
// it is passed explicitly rather than picked up from whichever machine runs this:
//   php scripts/create_share_template.php --create https://project.vakhariaairtech.com/pms
$linkBase = rtrim((string)($argv[2] ?? ($share['base_url'] ?? '')), '/')
    ?: 'https://project.vakhariaairtech.com/pms';
$buttonUrl = $linkBase . '/s/{{1}}';

$name = (string)($share['wa_template'] ?? 'project_progress_link');
$lang = (string)($share['wa_language'] ?? 'en');
$mode = $argv[1] ?? '--list';

function graph(string $method, string $url, string $token, ?array $body = null): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 45,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $e = curl_error($ch); curl_close($ch);
        throw new RuntimeException('curl: ' . $e);
    }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string)$raw, true) ?: ['raw' => $raw]];
}

if ($mode === '--list') {
    $r = graph('GET', "https://graph.facebook.com/$gv/$waba/message_templates?limit=100", $token);
    if (isset($r['body']['error'])) {
        exit("ERROR " . json_encode($r['body']['error']) . "\n");
    }
    printf("%-32s %-10s %-12s %s\n", 'NAME', 'LANG', 'STATUS', 'CATEGORY');
    foreach ($r['body']['data'] ?? [] as $t) {
        printf("%-32s %-10s %-12s %s\n", $t['name'], $t['language'], $t['status'], $t['category'] ?? '');
    }
    exit(0);
}

if ($mode !== '--create') {
    exit("usage: --list | --create\n");
}

$payload = [
    'name'     => $name,
    'language' => $lang,
    'category' => 'UTILITY',      // an update on an existing order, not marketing
    'components' => [
        ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Project progress update'],
        [
            'type' => 'BODY',
            'text' => "Hello {{1}},\n\nYour project *{{2}}* is now at *{{3}}*, with *{{4}}* of the work stages completed.\n\nTap below to view the live progress board — stage timeline, completion dates, site photos and reports.\n\nFor your security this link stays active for 24 hours and opens only your project.",
            'example' => ['body_text' => [['Mr. Shah', 'Balmoral Tower C-wing - C-1801', 'Pre-Commissioning', '96%']]],
        ],
        ['type' => 'FOOTER', 'text' => 'Vakharia Airtech Pvt. Ltd.'],
        [
            'type' => 'BUTTONS',
            'buttons' => [[
                'type'    => 'URL',
                'text'    => 'View progress',
                'url'     => $buttonUrl,
                'example' => [str_replace('{{1}}', 'Ab3xK9mQ2p7RtWzY4nL8vC1dS5fG7hJ0kP', $buttonUrl)],
            ]],
        ],
    ],
];

echo "Creating template '$name' ($lang) on WABA $waba\n";
echo "Button URL: $buttonUrl\n\n";
$r = graph('POST', "https://graph.facebook.com/$gv/$waba/message_templates", $token, $payload);
echo "HTTP {$r['code']}\n" . json_encode($r['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
