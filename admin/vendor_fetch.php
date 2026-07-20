<?php
/**
 * One-time localizer: downloads Bootstrap, Bootstrap-Icons (+ its font) and
 * Chart.js from the CDN into admin/assets/vendor/ so the panel serves them
 * same-origin. Fixes icons showing as boxes when a network serves JS/CSS but
 * blocks cross-origin .woff2 fonts — and makes the panel work fully offline.
 * Runs on the XAMPP PHP server (which reaches the internet, like the main app).
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();
Admin::requireEditor();

$files = [
    'bootstrap.min.css'             => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css',
    'bootstrap.bundle.min.js'       => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js',
    'bootstrap-icons.css'           => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css',
    'fonts/bootstrap-icons.woff2'   => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2',
    'fonts/bootstrap-icons.woff'    => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff',
    'chart.umd.min.js'              => 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
];

/** Download one URL; returns [ok, bytes|errorText]. Tries cURL then streams. */
function grab(string $url): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,  // XAMPP often lacks a CA bundle; assets are public + static
            CURLOPT_USERAGENT      => 'PMS-Admin-Vendor-Fetch',
        ]);
        $data = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($data !== false && $code >= 200 && $code < 400) return [true, $data];
        if ($data !== false && $code === 0 && $err === '')  return [true, $data];
        return [false, $err ?: ('HTTP ' . $code)];
    }
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false], 'http' => ['timeout' => 30]]);
    $data = @file_get_contents($url, false, $ctx);
    return $data === false ? [false, 'download failed'] : [true, $data];
}

$results = [];
$allOk = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Admin::checkCsrf()) {
    @mkdir(Admin::vendorDir() . 'fonts', 0775, true);
    foreach ($files as $rel => $url) {
        [$ok, $payload] = grab($url);
        if ($ok) {
            $dest = Admin::vendorDir() . $rel;
            if (@file_put_contents($dest, $payload) !== false) {
                $results[$rel] = ['ok' => true, 'msg' => number_format(strlen($payload)) . ' bytes'];
            } else {
                $results[$rel] = ['ok' => false, 'msg' => 'cannot write ' . $rel . ' (folder permissions)'];
                $allOk = false;
            }
        } else {
            $results[$rel] = ['ok' => false, 'msg' => $payload];
            $allOk = false;
        }
    }
    if ($allOk) Admin::audit('vendor_localized', 'assets');
}

require __DIR__ . '/inc/layout.php';
Layout::head('Localize Assets', '');
?>
<div class="card2" style="max-width:760px">
  <div class="card2-head"><i class="bi bi-cloud-arrow-down text-primary"></i><h2>Localize icons &amp; styles</h2></div>
  <div class="card2-body">
    <?php if (!$results): ?>
      <p style="color:#5b6b82;font-size:14px;margin:0 0 16px">
        This downloads Bootstrap, Bootstrap&nbsp;Icons (with its font) and Chart.js from the CDN onto this
        server (into <span class="mono">admin/assets/vendor/</span>). After that the panel serves them from your
        own server — icons render reliably and the panel works even with no internet. Takes a few seconds.
      </p>
      <form method="POST">
        <?= Admin::csrfField() ?>
        <button class="btn btn-primary" type="submit"><i class="bi bi-download"></i> Download now</button>
        <a class="btn btn-ghost" href="<?= Admin::BASE ?>/index.php">Cancel</a>
      </form>
    <?php else: ?>
      <div class="alert2 <?= $allOk ? 'ok' : 'bad' ?>" style="margin-bottom:16px">
        <i class="bi bi-<?= $allOk ? 'check-circle' : 'exclamation-octagon' ?>"></i>
        <?= $allOk ? 'All assets localized. Reload any page — icons now load from this server.' : 'Some files failed — see below. The panel still works via CDN.' ?>
      </div>
      <table class="tbl">
        <thead><tr><th>File</th><th>Result</th></tr></thead>
        <tbody>
        <?php foreach ($results as $file => $r): ?>
          <tr>
            <td class="mono"><?= Admin::e($file) ?></td>
            <td><?php if ($r['ok']): ?><span class="pill pill-ok"><i class="bi bi-check2"></i> <?= Admin::e($r['msg']) ?></span>
                <?php else: ?><span class="pill pill-bad"><?= Admin::e($r['msg']) ?></span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div style="margin-top:16px">
        <a class="btn btn-primary" href="<?= Admin::BASE ?>/index.php"><i class="bi bi-arrow-left"></i> Back to dashboard</a>
        <?php if (!$allOk): ?><a class="btn btn-ghost" href="<?= Admin::BASE ?>/vendor_fetch.php">Try again</a><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php Layout::foot();
