# Site Visit Report — PHP backend (replaces Apps Script)

PHP port of the old `code.js` Apps Script. A Google **service account**
(`service-data-syncer@design-sheet-492811.iam.gserviceaccount.com`) reads/writes
the same Google Sheets `code.js` used. A **MySQL** DB tracks every submission and
each processing step (audit trail). No composer — native `openssl` JWT + `curl`.

## Flow (async — submit returns in ~100 ms)
`api/submit.php` only **captures** the payload (writes a job file + a `queued`
submission row) and returns immediately, then spawns the background worker.
`scripts/worker.php` (`SubmitService::runCore`) does the slow work off the request:
1. photos → **Google Shared Drive**
2. write response row → RESPONSE sheet (VRV / Non-VRV), columns matched by header
3. stamp PMS progress row → General (Order-ID→name) or Developer building (Flat No)
4. build **PDF** (letterhead, FPDF) + upload to Drive, stamp PDF ID / Mail Status

Then, ~`notify_delay_seconds` (default 180s) after submit, the worker runs
`runNotifications()` → **email + WhatsApp**. Every step is recorded in `process_log`;
submission status walks `queued → processing → awaiting_notify → done/partial`.

### Background worker
- Each submit spawns `worker.php` (loops until the queue is drained, incl. the
  delayed notifications) — no scheduler strictly required.
- **Recommended safety net:** register the scheduled task so a missed spawn still
  drains the queue: run (elevated) `scripts/register_worker_task.ps1` — it runs
  `worker.php --once` every minute. Remove with `schtasks /Delete /TN "PMS Worker" /F`.
- Tunables in `config/app.php`: `notify_delay_seconds`, `worker_max_runtime`,
  `worker_poll_seconds`, `php_binary`, `queue_dir`.

## Layout
```
index.php              front-end (reuses Index.html + AppJs.html; google.script.run -> fetch shim)
api/                   project_names.php, submit.php, ui_images.php, _common.php
src/                   GoogleAuth, GoogleClient, Sheets, Drive, Db, Tracker,
                       ResponseSheet, Pms, Pdf, PmsFpdf, SubmitService, Bootstrap
config/app.php         all sheet/folder IDs, DB creds, Shared Drive id  (edit here)
config/google-service-account.json   SA key  (gitignored)
db/schema.sql          MySQL tables
vendor/fpdf/           FPDF 1.9 + core-font metrics (vendored)
assets/                letterhead_header.png, footer_daikin.png, watermark.png
scripts/               verify_auth, check_access, check_drive, check_headers,
                       diag_pms, test_pdf, test_submit  (diagnostics / tests)
storage/               tokens, uploads, reports, logs  (gitignored)
```

## Setup
1. Import DB:  `mysql -u root < db/schema.sql`
2. Open `http://localhost/pms/`
3. Diagnostics:
   - `php scripts/verify_auth.php`   token + sheet read
   - `php scripts/check_access.php`  which sheets the SA can reach
   - `php scripts/check_drive.php`   Shared Drive upload
   - `php scripts/test_submit.php`   full E2E (writes + deletes a test row)

## Status
- ✅ Auth, all 6 sheets readable/writable, response-row write, PMS stamping,
  PDF (matches the old letterhead), Shared-Drive photo/PDF upload, DB tracking,
  HTTP endpoints, front-end.
- ✅ **Phase 2 — Email + WhatsApp** built (ports of `sendReportEmail.js` /
  `sendReportwhatsapp.js`). Fired right after the PDF is ready, inside the submit
  request, recorded in `process_log`. Both default to `MODE=OFF`.

## Going live with Email + WhatsApp
Secrets go in `config/secrets.php` (gitignored — `cp config/secrets.example.php config/secrets.php`);
modes and `test_to` stay in `config/app.php`:
- **Email** (`email` block): set `smtp_pass` in `secrets.php` (app password for `crm@vakhariaairtech.com`),
  then `mode` = `TEST` (all mail → `test_to`) or `LIVE` (real client + CC, stamps Mail Status).
  Verify: `php scripts/test_email.php`.
- **WhatsApp** (`whatsapp` block): set `token` in `secrets.php` (Meta access token) + `test_to` in `app.php`, then
  `mode` = `TEST`/`LIVE`. Verify: `php scripts/test_whatsapp.php`.
- Recipient lookup: email = scrape tab → Orders "Client Email Id" → developer map → fallback;
  WhatsApp = Orders "phone" columns → developer map → fallback. The scrape sheet
  (`1hPvEw…`) and the extra orders sheet (`1HwYDM…`) must be shared with the SA for
  those tiers to work; otherwise it falls back gracefully.

MODE meanings: `OFF` = send nothing (logged as skipped) · `TEST` = only to your test
address/number, no sheet stamp · `LIVE` = real recipients, CC, sheet stamped.

### WhatsApp: link vs actual PDF
`whatsapp.delivery` = `link` (sends the report link, template `daily_site_updates`)
or `document` (attaches the actual PDF so the client needn't open a link). Document
mode uploads the PDF to WhatsApp media then sends it as the header of an APPROVED
document-header template `daily_site_update_doc` (created via `scripts/create_wa_template.php`,
WABA `1568163707846136`). Check approval: `php scripts/check_wa_template.php`. Once
APPROVED, set `whatsapp.delivery = 'document'`. Proactive file sends REQUIRE this
approved template (WhatsApp blocks free-form files outside the 24h window).

## Security note
The SA private key was committed earlier in git history. It's now gitignored,
but consider **rotating the key** in Google Cloud and purging it from history.
