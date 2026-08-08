<?php
/**
 * Client share view — the ONLY page a client ever sees.
 *
 * Reached with a 24-hour bearer token (?t=<token>, or /s/<token> when the nginx
 * rule is on). It renders the same Project 360 look the panel uses, but every
 * value on it comes from ShareData's whitelist — this file must never query the
 * admin tables directly, and never widen past the token's own scope.
 *
 * Hard rules kept here:
 *   • no session, no admin bootstrap, no login state of any kind;
 *   • no-store / noindex / no-referrer headers, so the token never leaks into a
 *     cache, a search index or a third-party Referer;
 *   • photos and PDFs are STREAMED through this file with the service account's
 *     own credentials — Drive files stay private, so a copied file URL dies with
 *     the link instead of living forever as "anyone with the link";
 *   • unknown tokens are rate-limited per IP and answered with the same generic
 *     page as expired ones (no oracle telling a prober which is which).
 */
declare(strict_types=1);

require_once __DIR__ . '/src/Db.php';
require_once __DIR__ . '/src/ShareLink.php';
require_once __DIR__ . '/src/ShareData.php';
require_once __DIR__ . '/admin/inc/helpers.php';

$cfg = require __DIR__ . '/config/app.php';
date_default_timezone_set((string)($cfg['timezone'] ?? 'Asia/Kolkata'));
$shareCfg = $cfg['share'] ?? [];

const SHARE_ASSETS = '/pms/assets/assets';
const SHARE_ADMIN_ASSETS = '/pms/admin/assets';

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/** Same headers on every response, including the error pages. */
function share_headers(bool $html = true): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    if ($html) {
        header('Content-Type: text/html; charset=UTF-8');
    }
}

/**
 * Throttles token probing. 60 bad tokens an hour from one IP and it stops
 * answering — brute force against 256 bits is hopeless anyway, this just keeps
 * the noise (and the log) down.
 */
function share_probe_ok(PDO $db, string $ip): bool
{
    try {
        $key = hash('sha256', $ip . ':share_probe');
        $db->prepare("DELETE FROM rate_limits WHERE action='share_probe' AND window_start < DATE_SUB(NOW(), INTERVAL 1 HOUR)")->execute();
        $st = $db->prepare("SELECT requests FROM rate_limits WHERE key_hash=? AND action='share_probe'");
        $st->execute([$key]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['requests'] >= 60) {
            return false;
        }
        $db->prepare(
            "INSERT INTO rate_limits (key_hash, action, requests, window_start) VALUES (?, 'share_probe', 1, NOW())
             ON DUPLICATE KEY UPDATE requests = requests + 1"
        )->execute([$key]);
    } catch (Throwable $e) { /* never lock a real client out on a rate-table error */ }
    return true;
}

/* ---------------- shell ---------------- */

function share_head(string $title, string $sub = '', string $expiry = ''): void
{
    $icons = is_file(__DIR__ . '/admin/assets/vendor/bootstrap-icons.css')
        ? SHARE_ADMIN_ASSETS . '/vendor/bootstrap-icons.css'
        : 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css';
    $cdn = strpos($icons, 'http') === 0 ? ' https://cdn.jsdelivr.net' : '';
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; "
        . "style-src 'self' 'unsafe-inline'$cdn; font-src 'self'$cdn; script-src 'self' 'unsafe-inline'; "
        . "object-src 'self'; frame-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
    ?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer"><meta name="robots" content="noindex, nofollow, noarchive">
<title><?= h($title) ?> · Vakharia Airtech</title>
<link rel="icon" type="image/png" href="<?= SHARE_ASSETS ?>/favicon.png">
<link rel="stylesheet" href="<?= h($icons) ?>">
<link rel="stylesheet" href="<?= SHARE_ADMIN_ASSETS ?>/admin.css?v=18">
<style>
  body{background:var(--bg,#f2f5fb)}
  .sh-top{background:#fff;border-bottom:1px solid var(--line);padding:12px 24px;position:sticky;top:0;z-index:20;
          display:flex;align-items:center;gap:14px;flex-wrap:wrap}
  .sh-top img{height:34px}
  .sh-brand{font-size:16px;font-weight:800;color:#0f1b30;line-height:1.2}
  .sh-brand small{display:block;font-size:11.5px;font-weight:600;color:var(--muted)}
  .sh-exp{margin-left:auto;display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:700;
          color:#8a5b00;background:#fff5df;border:1px solid #f2ddb0;border-radius:999px;padding:7px 14px}
  .sh-wrap{padding:18px 24px 46px;max-width:1460px;margin:0 auto;width:100%}
  .sh-foot{border-top:1px solid var(--line);margin-top:26px;padding:18px 24px 40px;color:var(--muted);font-size:12.5px;text-align:center}
  .sh-note{font-size:12.5px;color:var(--muted);margin:-6px 0 16px}
  .unit-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}
  .unit{display:block;border:1px solid var(--line);border-radius:13px;padding:14px 16px;text-decoration:none;background:#fff}
  .unit:hover{border-color:#bcd0f5;box-shadow:0 6px 18px rgba(60,90,160,.08)}
  .unit-t{font-weight:800;font-size:14.5px;color:#0f1b30}
  .unit-m{font-size:12px;color:var(--muted);margin-top:4px}
  .unit-bar{height:7px;border-radius:99px;background:#eef1f7;margin-top:10px;overflow:hidden}
  .unit-bar i{display:block;height:100%;background:linear-gradient(90deg,#20a5d8,#2ec2e6)}
  .err-card{max-width:560px;margin:9vh auto;background:#fff;border:1px solid var(--line);border-radius:18px;
            padding:34px 30px;text-align:center;box-shadow:0 12px 34px rgba(22,31,45,.07)}
  .err-ic{width:64px;height:64px;border-radius:50%;display:grid;place-items:center;margin:0 auto 16px;font-size:30px}
  @media(max-width:720px){.sh-top{padding:10px 14px}.sh-wrap{padding:14px 12px 40px}}
</style>
</head><body>
<header class="sh-top">
  <img src="<?= SHARE_ASSETS ?>/logo.png" alt="Vakharia Airtech" onerror="this.style.display='none'">
  <div class="sh-brand">Vakharia Airtech Pvt. Ltd.<small><?= h($sub !== '' ? $sub : 'Project progress') ?></small></div>
  <?php if ($expiry !== ''): ?>
    <div class="sh-exp"><i class="bi bi-shield-lock"></i> Private link · expires in <?= h($expiry) ?></div>
  <?php endif; ?>
</header>
<div class="sh-wrap">
<?php
}

function share_foot(): void
{
    ?>
</div>
<div class="sh-foot">
  This page was shared with you by Vakharia Airtech Pvt. Ltd. and shows only your project.<br>
  Please do not forward the link — it stops working when it expires.
  · <a href="https://www.vakhariaairtech.com/" rel="noreferrer noopener">www.vakhariaairtech.com</a>
</div>
</body></html>
<?php
}

/** One generic dead-end for expired / revoked / unknown / used-up links. */
function share_dead(string $state): void
{
    $map = [
        'expired'   => ['This link has expired', 'For your security a progress link stays active for a limited time only. Ask your Vakharia Airtech contact to send a fresh one — it takes a moment.', 410],
        'revoked'   => ['This link is no longer active', 'The link was withdrawn. Ask your Vakharia Airtech contact for a new one.', 410],
        'exhausted' => ['This link has been opened too many times', 'For safety the link locks after heavy use. Ask your Vakharia Airtech contact for a new one.', 429],
        'blocked'   => ['Too many attempts', 'Please try again later.', 429],
    ];
    [$title, $msg, $code] = $map[$state] ?? ['Link not found', 'This progress link is not valid. Please check the message you received, or ask your Vakharia Airtech contact for a new link.', 404];

    http_response_code($code);
    share_headers();
    share_head($title);
    ?>
    <div class="err-card">
      <div class="err-ic" style="background:#fff1f1;color:#d64550"><i class="bi bi-clock-history"></i></div>
      <h2 style="margin:0 0 10px;font-size:21px;color:#0f1b30"><?= h($title) ?></h2>
      <p style="margin:0;color:var(--muted);font-size:14px;line-height:1.6"><?= h($msg) ?></p>
    </div>
    <?php
    share_foot();
    exit;
}

/* ---------------- token ---------------- */

$token = (string)($_GET['t'] ?? '');
if ($token === '') {
    // /s/<token> style — nginx passes the tail as PATH_INFO
    $path = (string)($_SERVER['PATH_INFO'] ?? '');
    if ($path !== '') {
        $token = trim(basename($path));
    }
}

try {
    $db = (new Db($cfg['db']))->pdo();
} catch (Throwable $e) {
    http_response_code(503);
    share_headers();
    share_head('Temporarily unavailable');
    echo '<div class="err-card"><div class="err-ic" style="background:#fff5df;color:#b56c00"><i class="bi bi-tools"></i></div>'
       . '<h2 style="margin:0 0 10px;font-size:21px">Temporarily unavailable</h2>'
       . '<p style="margin:0;color:var(--muted)">Please try again in a few minutes.</p></div>';
    share_foot();
    exit;
}

$res  = ShareLink::resolve($db, $token, (int)($shareCfg['max_views'] ?? 300));
if ($res['state'] !== 'ok') {
    if ($res['state'] === 'unknown' && !share_probe_ok($db, client_ip())) {
        share_dead('blocked');
    }
    if ($res['link']) {
        ShareLink::log($db, (int)$res['link']['id'], 'denied', null, null, $res['state'],
            client_ip(), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''), (string)$res['link']['token_hash']);
    }
    share_dead($res['state']);
}

$link = $res['link'];
$opts = json_decode((string)$link['opts_json'], true);
if (!is_array($opts)) {
    $opts = (array)($shareCfg['defaults'] ?? []);
}
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$expiryLeft = ShareLink::timeLeft((string)$link['expires_at']);
$selfBase = 'share.php?t=' . rawurlencode($token);

/* ---------------- file proxy ---------------- */

if (isset($_GET['f'])) {
    $att = ShareData::attachmentInScope($db, $link, (int)$_GET['f'], $opts);
    if (!$att) {
        ShareLink::log($db, (int)$link['id'], 'denied', 'file', null, 'file out of scope: ' . (int)$_GET['f'],
            client_ip(), $ua, (string)$link['token_hash']);
        http_response_code(404);
        share_headers();
        share_head('File not available');
        echo '<div class="err-card"><div class="err-ic" style="background:#fff1f1;color:#d64550"><i class="bi bi-file-earmark-x"></i></div>'
           . '<h2 style="margin:0 0 10px;font-size:21px">File not available</h2>'
           . '<p style="margin:0;color:var(--muted)">This file is not part of the project shared with you.</p></div>';
        share_foot();
        exit;
    }

    require_once __DIR__ . '/src/GoogleAuth.php';
    require_once __DIR__ . '/src/GoogleClient.php';
    require_once __DIR__ . '/src/Drive.php';

    $wantThumb = !empty($_GET['th']);
    $download  = !empty($_GET['dl']);
    $name      = ShareData::fileName($att, []);
    $isImage   = stripos((string)$att['mime_type'], 'image/') === 0;

    try {
        $auth  = new GoogleAuth($cfg['service_account'], $cfg['scopes'], $cfg['token_cache_dir']);
        $drive = new Drive(new GoogleClient($auth), $cfg);

        // Thumbnails are cached on disk (storage/ is denied by nginx, so the cache
        // is only ever reachable through this proxy). A photo grid otherwise pulls
        // 12 full-size phone photos from Drive on every open.
        if ($wantThumb && $isImage && function_exists('imagecreatefromstring')) {
            $cacheDir = __DIR__ . '/storage/share_cache';
            if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0770, true); }
            $cacheFile = $cacheDir . '/' . sha1((string)$att['drive_file_id']) . '_w480.jpg';
            if (!is_file($cacheFile)) {
                $buf = '';
                $drive->download((string)$att['drive_file_id'], function ($chunk) use (&$buf) { $buf .= $chunk; });
                $img = @imagecreatefromstring($buf);
                unset($buf);
                if ($img !== false) {
                    $w = imagesx($img); $hgt = imagesy($img);
                    $scale = min(1, 480 / max(1, $w));
                    $tw = max(1, (int)round($w * $scale)); $th = max(1, (int)round($hgt * $scale));
                    $dst = imagecreatetruecolor($tw, $th);
                    imagecopyresampled($dst, $img, 0, 0, 0, 0, $tw, $th, $w, $hgt);
                    imagejpeg($dst, $cacheFile, 78);
                    imagedestroy($dst); imagedestroy($img);
                }
            }
            if (is_file($cacheFile)) {
                share_headers(false);
                header('Content-Type: image/jpeg');
                header('Content-Length: ' . (string)filesize($cacheFile));
                header('Content-Disposition: inline; filename="preview.jpg"');
                readfile($cacheFile);
                exit;
            }
        }

        ShareLink::log($db, (int)$link['id'], 'download', null, null,
            ($download ? 'download ' : 'open ') . $att['kind'], client_ip(), $ua, (string)$link['token_hash']);

        share_headers(false);
        header('Content-Type: ' . ($att['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline')
            . '; filename="' . str_replace(['"', "\r", "\n"], '', $name) . '"');
        while (ob_get_level() > 0) { ob_end_flush(); }
        $drive->download((string)$att['drive_file_id'], function ($chunk) { echo $chunk; flush(); });
    } catch (Throwable $e) {
        // Drive can 404 a file that was trashed, or refuse it if the service
        // account lost access — never leak the API message to the client.
        ShareLink::log($db, (int)$link['id'], 'denied', 'file', null, 'drive: ' . substr($e->getMessage(), 0, 120),
            client_ip(), $ua, (string)$link['token_hash']);
        if (!headers_sent()) {
            http_response_code(502);
            share_headers();
            share_head('File unavailable');
            echo '<div class="err-card"><div class="err-ic" style="background:#fff5df;color:#b56c00"><i class="bi bi-cloud-slash"></i></div>'
               . '<h2 style="margin:0 0 10px;font-size:21px">File unavailable right now</h2>'
               . '<p style="margin:0;color:var(--muted)">Please try again later, or ask your Vakharia Airtech contact to resend it.</p></div>';
            share_foot();
        }
    }
    exit;
}

/* ---------------- page ---------------- */

ShareLink::touch($db, $link, $ua, client_ip());

$units = ShareData::scopeProjects($db, $link);
if (!$units) {
    share_dead('unknown');
}

$wantKey = (string)($_GET['u'] ?? '');
$isIndex = ((string)$link['scope'] !== 'project') && $wantKey === '';

share_headers();

if ($isIndex) {
    // ---- building / developer index: units and where each one stands ----
    $devName = trim((string)$units[0]['developer']);
    share_head($link['label'], $devName !== '' ? $devName : 'Project progress', $expiryLeft);
    $byBuilding = [];
    foreach ($units as $u) {
        $byBuilding[trim((string)$u['building']) ?: '—'][] = $u;
    }
    $doneUnits = count(array_filter($units, fn($u) => in_array((string)$u['lifecycle'], ['Commissioned', 'Closed'], true)));
    ?>
    <div class="card2">
      <div class="detail-head" style="border-bottom:0">
        <div class="dh-ic"><i class="bi bi-buildings"></i></div>
        <div class="dh-titles">
          <h2><?= h($link['label']) ?></h2>
          <div class="dh-sub"><?= count($units) ?> unit(s) · <?= $doneUnits ?> commissioned · updated <?= h(fmtDate(max(array_map(fn($u) => (string)$u['last_report_at'], $units)))) ?></div>
        </div>
      </div>
    </div>
    <?php foreach ($byBuilding as $bName => $rows): ?>
      <div class="card2">
        <div class="card2-head"><i class="bi bi-building text-primary"></i><h2><?= h($bName) ?></h2>
          <span class="sub"><?= count($rows) ?> unit(s)</span></div>
        <div class="card2-body">
          <div class="unit-grid">
            <?php foreach ($rows as $u):
              $tot = (int)$u['steps_total']; $dn = (int)$u['steps_done'];
              $p = $tot > 0 ? min(100, (int)round($dn * 100 / $tot)) : 0;
            ?>
              <a class="unit" href="<?= h($selfBase . '&u=' . rawurlencode((string)$u['project_key'])) ?>">
                <div class="unit-t"><?= h(trim((string)$u['flat_no']) ?: $u['label']) ?></div>
                <div class="unit-m"><?= h((string)$u['lifecycle']) ?> · <?= h(snip((string)$u['current_step'], 30)) ?: '—' ?></div>
                <div class="unit-bar"><i style="width:<?= $p ?>%"></i></div>
                <div class="unit-m"><?= $dn ?>/<?= $tot ?> steps · <?= $p ?>%</div>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endforeach;
    share_foot();
    exit;
}

// ---- one unit ----
$pr = ShareData::pickProject($db, $link, $wantKey);
if (!$pr) {
    ShareLink::log($db, (int)$link['id'], 'denied', null, null, 'unit out of scope',
        client_ip(), $ua, (string)$link['token_hash']);
    share_dead('unknown');
}

$view    = ShareData::projectView($db, $pr, $opts);
$isDev   = (string)$pr['client_type'] === 'Developer';
$dotTone = ['Done' => 'ok', 'Hold' => 'bad', 'Pending' => 'warn', 'Inprogress' => 'warn'];
$lcTone  = ['Not Started' => 'muted', 'Active' => 'info', 'At Risk' => 'warn', 'On Hold' => 'bad',
            'Commissioning Pending' => 'info', 'Commissioned' => 'ok', 'Closed' => 'muted'];
$title   = (string)$pr['label'];

share_head($title, $isDev ? trim((string)$pr['developer']) : 'Project progress', $expiryLeft);
?>

<?php if (!$isIndex && (string)$link['scope'] !== 'project'): ?>
  <div class="breadcrumb2"><a href="<?= h($selfBase) ?>">← All units</a></div>
<?php endif; ?>

<div class="card2">
  <div class="detail-head" style="border-bottom:0">
    <div class="dh-ic"><i class="bi bi-buildings"></i></div>
    <div class="dh-titles">
      <h2><?= h($title) ?>
        <span class="pill pill-<?= h($lcTone[(string)$pr['lifecycle']] ?? 'muted') ?>"><?= h((string)$pr['lifecycle'] ?: '—') ?></span>
      </h2>
      <div class="dh-sub"><?= h((string)$pr['site_type']) ?> system · <?= $view['visits'] ?> site visit(s) · last update <?= h(fmtDate($view['last_at'])) ?></div>
    </div>
  </div>
</div>

<div class="proj-hero" style="grid-template-columns:repeat(4,1fr)">
  <div class="phero g-blue"><div class="ph-v"><?= h((string)$pr['current_step']) ?: '—' ?></div><div class="ph-l">Current stage</div></div>
  <div class="phero g-cyan ph-prog">
    <div><div class="ph-v"><?= (int)$pr['steps_done'] ?>/<?= (int)$pr['steps_total'] ?> <small>steps</small></div><div class="ph-l">Progress</div></div>
    <div class="ph-ring" style="--p:<?= $view['pct'] ?>"><span><?= $view['pct'] ?>%</span></div>
  </div>
  <div class="phero g-orange ph-flip"><div class="ph-l">Target completion</div><div class="ph-v"><?= $pr['target_end'] ? h(fmtDate((string)$pr['target_end'])) : 'To be confirmed' ?></div></div>
  <div class="phero g-green ph-flip"><div class="ph-l">Next activity</div><div class="ph-v"><?= $pr['next_plan_date'] ? h(fmtDate((string)$pr['next_plan_date'])) : (h(snip((string)$pr['next_plan_steps'], 40)) ?: 'Being scheduled') ?></div></div>
</div>

<div class="proj-cols">
  <div class="pcol">
    <div class="card2">
      <div class="card2-head"><i class="bi bi-bar-chart-steps text-primary"></i><h2>Work stages</h2>
        <span class="sub"><?= (int)$pr['steps_done'] ?> of <?= (int)$pr['steps_total'] ?> complete</span></div>
      <div class="card2-body">
        <div class="table-wrap">
          <table class="tbl">
            <thead><tr><th style="width:34px"></th><th>Stage</th><th>Status</th><th>Planned</th><th>Started</th><th>Completed</th><?php if ($view['show_pe']): ?><th>Engineer</th><?php endif; ?><th>Note</th></tr></thead>
            <tbody>
              <?php foreach ($view['steps'] as $st):
                $stt  = $st['status'] ?: '—';
                $tone = $dotTone[$stt] ?? 'muted';
                $ico  = $stt === 'Done' ? 'bi-check-lg' : ($stt === 'Hold' ? 'bi-pause' : ($stt === 'Pending' ? 'bi-hourglass-split' : 'bi-circle'));
              ?>
                <tr>
                  <td><span class="si-dot <?= h($tone) ?>" style="width:22px;height:22px;font-size:10px"><i class="bi <?= h($ico) ?>"></i></span></td>
                  <td style="font-weight:600"><?= h($st['name']) ?></td>
                  <td><?php if ($st['status']): ?><span class="pill pill-<?= h($tone) ?>"><?= h($stt) ?></span><?php else: ?><span class="info-val soft">not started</span><?php endif; ?></td>
                  <td><?= h($st['planned'] ? fmtDate($st['planned']) : '—') ?></td>
                  <td><?= h($st['actualStart'] ? fmtDate($st['actualStart']) : '—') ?></td>
                  <td><?= h($st['doneOn'] ? fmtDate($st['doneOn']) : '—') ?></td>
                  <?php if ($view['show_pe']): ?><td class="info-val soft" style="font-size:12px"><?= h($st['pe'] ?: '—') ?></td><?php endif; ?>
                  <td class="info-val soft" style="font-size:12px;max-width:240px"><?= h($st['note'] ?: '—') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="pcol">
    <div class="card2">
      <div class="card2-head"><i class="bi bi-clock-history text-primary"></i><h2>Progress updates</h2><span class="sub">(<?= count($view['changes']) ?>)</span></div>
      <div class="card2-body" style="max-height:520px;overflow:auto">
        <?php if (!$view['changes']): ?><div class="t-empty">No stage updates recorded yet.</div><?php endif; ?>
        <ul class="tl2">
          <?php foreach ($view['changes'] as $ch): $tone = $dotTone[$ch['to']] ?? 'muted'; ?>
            <li>
              <span class="tl2-dot <?= h($tone) ?>"><i class="bi <?= $ch['to'] === 'Done' ? 'bi-check-lg' : ($ch['to'] === 'Hold' ? 'bi-pause' : 'bi-arrow-right') ?>"></i></span>
              <div class="tl2-step"><?= h($ch['step']) ?> <span class="pill pill-<?= h($tone) ?>"><?= h($ch['to']) ?></span></div>
              <?php if ($ch['note'] !== ''): ?><div class="tl2-reason"><i class="bi bi-info-circle"></i> <?= h($ch['note']) ?></div><?php endif; ?>
              <div class="tl2-meta"><?= h(fmtDate($ch['date'])) ?><?= $view['show_pe'] && $ch['pe'] !== '' ? ' · ' . h($ch['pe']) : '' ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

<div class="grid-2">
  <div class="card2">
    <div class="card2-head"><i class="bi bi-file-earmark-text text-primary"></i><h2>Documents &amp; reports</h2></div>
    <div class="card2-body">
      <?php if (!$view['flags'] && !$view['docs']): ?><div class="t-empty">No documents shared yet.</div><?php endif; ?>
      <?php foreach ($view['flags'] as $f): ?>
        <div class="up-item">
          <div style="width:130px;flex-shrink:0"><span class="pill pill-info"><?= h($f['label']) ?></span></div>
          <div class="up-body"><div class="info-val soft" style="font-size:12.5px">Recorded on <?= h(fmtDate($f['date'])) ?></div></div>
        </div>
      <?php endforeach; ?>
      <?php if ($view['docs']): ?>
        <div style="margin-top:12px;display:flex;flex-direction:column;gap:10px">
          <?php foreach ($view['docs'] as $d): ?>
            <div class="up-item" style="align-items:center">
              <span class="pdf-ic" style="flex-shrink:0"><i class="bi bi-file-earmark-pdf"></i></span>
              <div class="up-body">
                <div class="info-val" style="font-size:13px;font-weight:600"><?= h(ucfirst(str_replace('_', ' ', (string)$d['kind']))) ?></div>
                <div class="info-val soft" style="font-size:11.5px"><?= h(fmtDate((string)$d['created_at'])) ?></div>
              </div>
              <a class="btn btn-ghost btn-sm" target="_blank" rel="noreferrer noopener"
                 href="<?= h($selfBase . '&f=' . (int)$d['id']) ?>"><i class="bi bi-eye"></i> Preview</a>
              <a class="btn btn-primary btn-sm"
                 href="<?= h($selfBase . '&f=' . (int)$d['id'] . '&dl=1') ?>"><i class="bi bi-download"></i> Download</a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card2">
    <div class="card2-head"><i class="bi bi-images text-primary"></i><h2>Site photos</h2><span class="sub">(<?= count($view['photos']) ?>)</span></div>
    <div class="card2-body">
      <?php if (!$view['photos']): ?><div class="t-empty" style="padding:20px">No photos shared.</div><?php else: ?>
        <div class="photo-grid">
          <?php foreach (array_slice($view['photos'], 0, 24) as $p): ?>
            <a href="<?= h($selfBase . '&f=' . (int)$p['id']) ?>" target="_blank" rel="noreferrer noopener">
              <img loading="lazy" src="<?= h($selfBase . '&f=' . (int)$p['id'] . '&th=1') ?>" alt="Site photo">
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="sh-note">
  <i class="bi bi-info-circle"></i> Dates are shown in IST. Stage dates are recorded by our site engineers at the time of each visit.
</div>
<?php
share_foot();
