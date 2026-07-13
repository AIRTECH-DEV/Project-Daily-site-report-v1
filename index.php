<?php
/**
 * Front-end entry. Serves the existing Index.html shell + AppJs.html UNCHANGED,
 * but replaces the Apps Script loader with a google.script.run shim that routes
 * getProjectNames / submitSiteReport / getUiImages to the PHP api/ endpoints.
 * The rest of AppJs runs byte-for-byte as it did inside Apps Script.
 */
$root = __DIR__;
$html = (string)file_get_contents($root . '/Index.html');

// Keep everything up to the final <script> loader; drop the loader itself.
$pos = strrpos($html, '<script>');
$markup = $pos !== false ? substr($html, 0, $pos) : $html;

// AppJs, with the old "Submit another report" URL pointed back at this app.
$appJs = (string)file_get_contents($root . '/AppJs.html');
$selfUrl = htmlspecialchars(strtok($_SERVER['REQUEST_URI'] ?? '/pms/', '?'), ENT_QUOTES);
$appJs = preg_replace(
    '#https://script\.google\.com/[^\'"]*exec#',
    $selfUrl,
    $appJs
);

$shim = <<<JS
<script>
// --- google.script.run -> PHP api shim (keeps AppJs unmodified) -----------
(function () {
  function Runner(s, f) { this._s = s || function(){}; this._f = f || function(){}; }
  Runner.prototype.withSuccessHandler = function (fn) { return new Runner(fn, this._f); };
  Runner.prototype.withFailureHandler = function (fn) { return new Runner(this._s, fn); };
  var self = this;
  function call(url, opts, run) {
    fetch(url, opts)
      .then(function (r) { return r.text().then(function (t) {
        if (!r.ok) throw new Error(t || ('HTTP ' + r.status));
        try { return JSON.parse(t); } catch (e) { throw new Error('Bad JSON from server: ' + t.slice(0, 300)); }
      }); })
      .then(function (d) { run._s(d); })
      .catch(function (e) { run._f({ message: (e && e.message) ? e.message : String(e) }); });
  }
  Runner.prototype.getProjectNames = function (siteType) {
    call('api/project_names.php?siteType=' + encodeURIComponent(siteType || ''), undefined, this);
  };
  Runner.prototype.getUiImages = function () {
    call('api/ui_images.php', undefined, this);
  };
  Runner.prototype.getProgressState = function (params) {
    call('api/progress_state.php?' + new URLSearchParams(params || {}).toString(), undefined, this);
  };
  Runner.prototype.submitSiteReport = function (payload) {
    call('api/submit.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }, this);
  };
  window.google = window.google || {};
  window.google.script = window.google.script || {};
  window.google.script.run = new Runner();
})();
</script>
JS;

header('Content-Type: text/html; charset=utf-8');
echo $markup;
echo $shim;
echo "\n<script>\n" . $appJs . "\n</script>\n</body>\n</html>";
