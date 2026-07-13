# Site Visit Report — PHP backend (replaces Apps Script)

PHP port of the old `code.js` Apps Script. A Google **service account**
(`service-data-syncer@design-sheet-492811.iam.gserviceaccount.com`) reads/writes
the same Google Sheets `code.js` used. A **MySQL** DB tracks every submission and
each processing step (audit trail). No composer — native `openssl` JWT + `curl`.

## Flow (per submit)
form → `api/submit.php` → `SubmitService`:
1. photos → **Google Shared Drive** (skipped until a Shared Drive is configured)
2. write response row → RESPONSE sheet (VRV / Non-VRV), columns matched by header
3. stamp PMS progress row → General (Order-ID→name) or Developer building (Flat No)
4. build **PDF** (letterhead, from FPDF) from the submitted photos
5. upload PDF to Drive (or serve locally), stamp PDF ID / Mail Status
6. every step recorded in `process_log`

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
  PDF (matches the old letterhead), DB tracking, HTTP endpoints, front-end.
- ⚠️ **Photo/PDF upload needs a Shared Drive.** Service accounts have no My-Drive
  quota, so `config/app.php > parent_folder_id` must be a folder on a Google
  Workspace **Shared Drive** shared with the SA (Content manager). Until then,
  photos are skipped and the PDF is served from `storage/reports/`. Everything
  else runs and is logged.

## Not yet built (phase 2, per "core first")
- Email (Gmail/SMTP) and WhatsApp (Meta) senders — the `code.js`
  `sendReportEmail.js` / `sendReportwhatsapp.js` equivalents.

## Security note
The SA private key was committed earlier in git history. It's now gitignored,
but consider **rotating the key** in Google Cloud and purging it from history.
