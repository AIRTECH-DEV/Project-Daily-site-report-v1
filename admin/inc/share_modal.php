<?php
/**
 * "Share with client" dialog — included by admin/project.php.
 *
 * Expects $pr (a projects row) in scope. Renders nothing for accounts without
 * the share permission, so the button and the markup both disappear together.
 *
 * The dialog never posts project data: it posts a scope + recipients, and
 * admin/share_action.php mints the token server-side. Contacts load
 * asynchronously because the General-site lookup reads the Orders sheet.
 */
if (!Admin::canShare()) {
    return;
}
$shareCfg  = Admin::cfg()['share'] ?? [];
$emailMode = strtoupper(Admin::cfg()['email']['mode'] ?? 'OFF');
$waMode    = strtoupper(Admin::cfg()['whatsapp']['mode'] ?? 'OFF');
$shareIsDev = (string)$pr['client_type'] === 'Developer';
$ttlHours  = (int)($shareCfg['ttl_hours'] ?? 24);
?>
<style>
  .sh-ov{position:fixed;inset:0;background:rgba(15,27,48,.55);z-index:1000;display:none;
         align-items:flex-start;justify-content:center;padding:36px 16px;overflow:auto}
  .sh-ov.open{display:flex}
  .sh-dlg{background:#fff;border-radius:18px;width:100%;max-width:640px;box-shadow:0 24px 60px rgba(12,20,36,.28);overflow:hidden}
  .sh-hd{display:flex;align-items:center;gap:12px;padding:18px 22px;border-bottom:1px solid var(--line)}
  .sh-hd h3{margin:0;font-size:17px;font-weight:800;color:#0f1b30;flex:1}
  .sh-bd{padding:18px 22px;max-height:66vh;overflow:auto}
  .sh-ft{display:flex;gap:10px;align-items:center;padding:14px 22px;border-top:1px solid var(--line);background:#fafbfe;flex-wrap:wrap}
  .sh-sec{margin-bottom:18px}
  .sh-lbl{font-size:11.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:var(--muted);margin-bottom:8px}
  .sh-scope{display:flex;gap:8px;flex-wrap:wrap}
  .sh-scope label{border:1px solid var(--line);border-radius:10px;padding:9px 13px;font-size:13px;font-weight:600;
                  cursor:pointer;display:flex;gap:7px;align-items:center;background:#fff}
  .sh-scope input{margin:0}
  .sh-scope label:has(input:checked){border-color:#2f6dff;background:#f2f6ff;color:#2f6dff}
  .sh-chk{display:flex;gap:9px;align-items:center;padding:8px 10px;border:1px solid var(--line);border-radius:10px;
          font-size:13px;margin-bottom:7px;background:#fff}
  .sh-chk .who{flex:1;font-weight:600;color:#22314e;word-break:break-all}
  .sh-chk .tag{font-size:11px;color:var(--muted)}
  .sh-inp{width:100%;border:1px solid var(--line);border-radius:10px;padding:9px 12px;font:inherit;font-size:13px}
  .sh-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .sh-warn{display:flex;gap:9px;align-items:flex-start;background:#fff5df;border:1px solid #f2ddb0;color:#8a5b00;
           border-radius:10px;padding:10px 12px;font-size:12.5px;margin-bottom:14px}
  .sh-link{display:flex;gap:8px;align-items:center;background:#f2f6ff;border:1px solid #cfe0ff;border-radius:10px;padding:10px 12px;margin-top:12px}
  .sh-link code{flex:1;font-size:12px;word-break:break-all;color:#22314e}
  .sh-res{margin-top:12px;font-size:12.5px}
  .sh-res div{display:flex;gap:8px;align-items:center;padding:6px 0;border-bottom:1px dashed var(--line)}
  .sh-muted{font-size:12px;color:var(--muted)}
  @media(max-width:640px){.sh-grid{grid-template-columns:1fr}}
</style>

<div class="sh-ov" id="shOv" role="dialog" aria-modal="true" aria-labelledby="shTitle">
  <div class="sh-dlg">
    <div class="sh-hd">
      <span class="dh-ic" style="width:38px;height:38px;font-size:18px"><i class="bi bi-send"></i></span>
      <h3 id="shTitle">Share progress with client</h3>
      <button class="btn btn-ghost btn-sm" type="button" id="shClose"><i class="bi bi-x-lg"></i></button>
    </div>

    <div class="sh-bd">
      <?php if ($emailMode !== 'LIVE' || $waMode !== 'LIVE'): ?>
        <div class="sh-warn"><i class="bi bi-exclamation-triangle"></i>
          <span>Delivery is not fully live — email is <b><?= Admin::e($emailMode) ?></b>, WhatsApp is <b><?= Admin::e($waMode) ?></b>.
          In TEST every message goes to the test inbox/number, not the client. Change it in Settings › Notifications.</span></div>
      <?php endif; ?>

      <?php if ($shareIsDev): ?>
      <div class="sh-sec">
        <div class="sh-lbl">What the client may open</div>
        <div class="sh-scope">
          <label><input type="radio" name="shScope" value="project" checked> This flat only</label>
          <label><input type="radio" name="shScope" value="building"> Whole building</label>
          <label><input type="radio" name="shScope" value="developer"> All buildings of <?= Admin::e($pr['developer']) ?></label>
        </div>
        <div class="sh-muted" style="margin-top:7px">The link can never open anything outside this choice.</div>
      </div>
      <?php endif; ?>

      <div class="sh-sec">
        <div class="sh-lbl">Client contacts on file</div>
        <div id="shContacts"><div class="sh-muted"><i class="bi bi-hourglass-split"></i> Loading contacts…</div></div>
      </div>

      <div class="sh-sec">
        <div class="sh-lbl">Send to someone else</div>
        <div class="sh-grid">
          <input class="sh-inp" type="text" id="shExtraMail" placeholder="extra@email.com">
          <input class="sh-inp" type="text" id="shExtraPhone" placeholder="WhatsApp number (e.g. 9876543210)">
        </div>
        <div class="sh-muted" style="margin-top:7px">Only send to people the client has asked us to update. Every send is logged.</div>
      </div>

      <div class="sh-sec">
        <div class="sh-lbl">Options</div>
        <input class="sh-inp" type="text" id="shName" placeholder="Client name for the greeting (optional)" style="margin-bottom:9px">
        <label class="sh-chk"><input type="checkbox" id="shPhotos" checked>
          <span class="who">Include site photos</span></label>
        <label class="sh-chk"><input type="checkbox" id="shPe">
          <span class="who">Show our engineer names <span class="tag">off by default</span></span></label>
        <div class="sh-muted">Holds waiting on the client are always explained. Holds on our side show only as “with our team”.</div>
      </div>

      <div id="shResult"></div>
    </div>

    <div class="sh-ft">
      <span class="sh-muted"><i class="bi bi-shield-lock"></i> Link expires in <?= $ttlHours ?> hours · read-only · revocable</span>
      <span style="margin-left:auto"></span>
      <button class="btn btn-ghost btn-sm" type="button" id="shLinkOnly"><i class="bi bi-link-45deg"></i> Create link only</button>
      <button class="btn btn-primary btn-sm" type="button" id="shSend"><i class="bi bi-send"></i> Create &amp; send</button>
    </div>
  </div>
</div>

<script>
(function () {
  var ov = document.getElementById('shOv'), open = document.getElementById('shBtn');
  if (!ov || !open) return;
  var key = <?= json_encode((string)$pr['project_key']) ?>;
  var csrf = <?= json_encode(Admin::csrf()) ?>;
  var loaded = false;

  function show(v) { ov.classList.toggle('open', v); if (v && !loaded) { loaded = true; loadContacts(); } }
  open.addEventListener('click', function () { show(true); });
  document.getElementById('shClose').addEventListener('click', function () { show(false); });
  ov.addEventListener('click', function (e) { if (e.target === ov) show(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') show(false); });

  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) {
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

  function loadContacts() {
    var box = document.getElementById('shContacts');
    fetch('share_action.php?contacts=1&key=' + encodeURIComponent(key), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { box.innerHTML = '<div class="sh-muted">Could not load contacts.</div>'; return; }
        var html = '';
        (d.phones || []).forEach(function (p) {
          html += '<label class="sh-chk"><input type="checkbox" class="shPhone" value="' + esc(p) + '" checked>'
                + '<span class="who"><i class="bi bi-whatsapp" style="color:#25d366"></i> +' + esc(p) + '</span>'
                + '<span class="tag">WhatsApp</span></label>';
        });
        (d.emails || []).forEach(function (m) {
          html += '<label class="sh-chk"><input type="checkbox" class="shMail" value="' + esc(m) + '" checked>'
                + '<span class="who"><i class="bi bi-envelope" style="color:#2f6dff"></i> ' + esc(m) + '</span>'
                + '<span class="tag">Email</span></label>';
        });
        if (!html) { html = '<div class="sh-muted">No contact saved for this client.</div>'; }
        if (d.note) { html += '<div class="sh-muted" style="margin-top:6px">' + esc(d.note) + '</div>'; }
        box.innerHTML = html;
      })
      .catch(function () { box.innerHTML = '<div class="sh-muted">Could not load contacts.</div>'; });
  }

  function collect(sel, extraId) {
    var out = [];
    document.querySelectorAll(sel).forEach(function (c) { if (c.checked) out.push(c.value); });
    var extra = (document.getElementById(extraId).value || '').split(/[,;\s]+/);
    extra.forEach(function (v) { if (v.trim()) out.push(v.trim()); });
    return out;
  }

  function submit(send) {
    var btn = send ? document.getElementById('shSend') : document.getElementById('shLinkOnly');
    var old = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Working…';

    var scopeEl = document.querySelector('input[name="shScope"]:checked');
    var fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('action', 'create');
    fd.append('key', key);
    fd.append('scope', scopeEl ? scopeEl.value : 'project');
    fd.append('client_name', document.getElementById('shName').value || '');
    if (!document.getElementById('shPhotos').checked) fd.append('no_photos', '1');
    if (document.getElementById('shPe').checked) fd.append('pe_names', '1');
    if (send) {
      collect('.shMail', 'shExtraMail').forEach(function (v) { fd.append('emails[]', v); });
      collect('.shPhone', 'shExtraPhone').forEach(function (v) { fd.append('phones[]', v); });
    }

    fetch('share_action.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false; btn.innerHTML = old;
        var box = document.getElementById('shResult');
        if (!d.ok) { box.innerHTML = '<div class="alert2 bad" style="margin:0">' + esc(d.error || 'Failed') + '</div>'; return; }
        var html = '<div class="sh-link"><code id="shUrl">' + esc(d.url) + '</code>'
                 + '<button class="btn btn-ghost btn-sm" type="button" id="shCopy"><i class="bi bi-clipboard"></i> Copy</button></div>'
                 + '<div class="sh-muted" style="margin-top:6px">Valid for ' + d.ttl_hours + ' hours · scope: ' + esc(d.scope) + ' · ' + esc(d.label) + '</div>';
        if (d.results && d.results.length) {
          html += '<div class="sh-res">';
          d.results.forEach(function (r) {
            html += '<div><i class="bi bi-' + (r.ok ? 'check-circle-fill" style="color:#15a34a' : 'x-circle-fill" style="color:#d64550') + '"></i>'
                  + '<span>' + esc(r.channel) + ' → ' + esc(r.to) + '</span>'
                  + (r.error ? '<span class="sh-muted" style="margin-left:auto">' + esc(r.error) + '</span>' : '') + '</div>';
          });
          html += '</div>';
        }
        box.innerHTML = html;
        var cp = document.getElementById('shCopy');
        if (cp) cp.addEventListener('click', function () {
          var t = document.getElementById('shUrl').textContent;
          if (navigator.clipboard) { navigator.clipboard.writeText(t); }
          cp.innerHTML = '<i class="bi bi-check2"></i> Copied';
        });
      })
      .catch(function () {
        btn.disabled = false; btn.innerHTML = old;
        document.getElementById('shResult').innerHTML = '<div class="alert2 bad" style="margin:0">Network error.</div>';
      });
  }

  document.getElementById('shSend').addEventListener('click', function () { submit(true); });
  document.getElementById('shLinkOnly').addEventListener('click', function () { submit(false); });
})();
</script>
