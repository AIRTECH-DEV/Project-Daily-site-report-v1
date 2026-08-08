<?php
/**
 * Share endpoint for the panel — mints a client link and delivers it.
 *
 *   GET  ?contacts=1&key=<project_key>   client contacts on file (JSON, async)
 *   POST action=create                   mint + optionally send (JSON)
 *   POST action=revoke                   kill a live link
 *
 * Every path is gated by Admin::requireSharer() (its own permission, not the
 * role), CSRF-checked, and written to audit_logs + share_events. Nothing here
 * echoes a project's data — it only ever returns link state and send results.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
Admin::requireSharer();
require __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/../src/ShareLink.php';
require_once __DIR__ . '/../src/ShareData.php';
require_once __DIR__ . '/../src/ShareSender.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$cfg   = Admin::cfg();
$share = $cfg['share'] ?? [];
$db    = Admin::db();

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Public base URL of this install, used when config/share.base_url is blank. */
function share_base(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // …/pms/admin/share_action.php -> …/pms
    $dir = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/pms/admin/x.php'))), '/');
    return ($https ? 'https://' : 'http://') . $host . $dir;
}

/* ---------------- contacts (async, GET) ---------------- */

if (isset($_GET['contacts'])) {
    $st = $db->prepare("SELECT * FROM projects WHERE project_key = ? LIMIT 1");
    $st->execute([(string)($_GET['key'] ?? '')]);
    $pr = $st->fetch();
    if (!$pr) {
        out(['ok' => false, 'error' => 'project not found'], 404);
    }
    $c = (new ShareSender($cfg))->contactsFor($pr);
    out(['ok' => true] + $c);
}

/* ---------------- writes (POST) ---------------- */

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Admin::checkCsrf()) {
    out(['ok' => false, 'error' => 'bad request'], 400);
}

$action = (string)($_POST['action'] ?? '');

if ($action === 'revoke') {
    $id = (int)($_POST['id'] ?? 0);
    ShareLink::revoke($db, $id, Admin::user()['user']);
    Admin::audit('share_revoke', 'share_links', $id);
    out(['ok' => true]);
}

if ($action !== 'create') {
    out(['ok' => false, 'error' => 'unknown action'], 400);
}

/* ---- resolve the scope from the project the panel is on ---- */

$key = (string)($_POST['key'] ?? '');
$st = $db->prepare("SELECT * FROM projects WHERE project_key = ? LIMIT 1");
$st->execute([$key]);
$pr = $st->fetch();
if (!$pr) {
    out(['ok' => false, 'error' => 'project not found'], 404);
}

$scope = (string)($_POST['scope'] ?? 'project');
$isDev = (string)$pr['client_type'] === 'Developer';
if (!$isDev) {
    $scope = 'project';                     // a General site has nothing to widen to
}
switch ($scope) {
    case 'building':
        $scopeKey = ShareLink::buildingScopeKey((string)$pr['developer'], (string)$pr['building']);
        $label    = trim((string)$pr['developer'] . ' › ' . (string)$pr['building']);
        $projId   = null;
        break;
    case 'developer':
        $scopeKey = ShareLink::developerScopeKey((string)$pr['developer']);
        $label    = trim((string)$pr['developer']);
        $projId   = null;
        break;
    default:
        $scope    = 'project';
        $scopeKey = $key;
        $label    = (string)$pr['label'];
        $projId   = (int)$pr['id'];
}

$opts = [
    'photos'      => empty($_POST['no_photos']) ? 1 : 0,
    'pe_names'    => !empty($_POST['pe_names']) ? 1 : 0,
    // Business rule: a hold sitting with the CLIENT is spelled out (they must
    // act); a hold sitting with us stays a neutral line.
    'hold_detail' => 'client_only',
];

$ttl = (int)($share['ttl_hours'] ?? 24);
$mint = ShareLink::mint($db, $scope, $scopeKey, $projId, $label, $opts, Admin::user()['user'], $ttl);
$url  = ShareLink::url($mint['token'], $share, share_base());
Admin::audit('share_create', 'share_links', $mint['id'], '', $scope . ' · ' . $label);

/* ---- deliver ---- */

$total = (int)$pr['steps_total'];
$ctx = [
    'name'  => trim((string)($_POST['client_name'] ?? '')) ?: 'Sir/Madam',
    'label' => $label,
    'stage' => trim((string)$pr['current_step']) ?: 'in progress',
    'pct'   => ($total > 0 ? min(100, (int)round((int)$pr['steps_done'] * 100 / $total)) : 0) . '%',
    'ttl'   => $ttl . ' hours',
];

$sender  = new ShareSender($cfg);
$results = [];
$sentOk  = ['email' => 0, 'whatsapp' => 0];

$emails = array_values(array_unique(array_filter(array_map('trim', (array)($_POST['emails'] ?? [])))));
$phones = array_values(array_unique(array_filter(array_map('trim', (array)($_POST['phones'] ?? [])))));

// 'to' in the result is where the message ACTUALLY went — in TEST mode that is
// the test inbox/number, not the client, and the operator must see that.
foreach ($emails as $to) {
    $r = $sender->email($to, $ctx, $url);
    $results[] = ['channel' => 'email', 'to' => $r['to'], 'ok' => $r['ok'], 'error' => $r['error']];
    if ($r['ok']) { $sentOk['email']++; }
    ShareLink::log($db, $mint['id'], $r['ok'] ? 'sent' : 'denied', 'email', $r['to'], $r['ok'] ? null : substr($r['error'], 0, 200));
}
foreach ($phones as $to) {
    $r = $sender->whatsapp($to, $ctx, $mint['token']);
    $results[] = ['channel' => 'whatsapp', 'to' => $r['to'], 'ok' => $r['ok'], 'error' => $r['error']];
    if ($r['ok']) { $sentOk['whatsapp']++; }
    ShareLink::log($db, $mint['id'], $r['ok'] ? 'sent' : 'denied', 'whatsapp', $r['to'], $r['ok'] ? null : substr($r['error'], 0, 200));
}

if ($results) {
    $summary = $sentOk['whatsapp'] . ' WhatsApp · ' . $sentOk['email'] . ' email';
    $db->prepare("UPDATE share_links SET sent_summary = ? WHERE id = ?")->execute([$summary, $mint['id']]);
}

out([
    'ok'        => true,
    'id'        => $mint['id'],
    'url'       => $url,
    'expires'   => $mint['expires_at'],
    'ttl_hours' => $ttl,
    'scope'     => $scope,
    'label'     => $label,
    'results'   => $results,
    'modes'     => ['email' => $sender->emailMode(), 'whatsapp' => $sender->waMode()],
]);
