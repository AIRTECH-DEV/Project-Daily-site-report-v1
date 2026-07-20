# PMS — Site Visit Report & Project Tracker

**Owner:** Vakharia Air Tech Pvt. Ltd. (VAPL) — Daikin air-conditioning sales, service & projects, Pune, India
**Repository:** `AIRTECH-DEV/<pms-repo>`
**Status:** Production-ready · primary working branch `v10` → merges to `main`

> **One-line description:** A PHP + MySQL web application that lets project engineers file a **daily site-visit report** from the field; it captures the report instantly, then in the background writes it to the company's Google Sheets, stamps the PMS progress sheets, builds a branded **PDF**, files photos/PDF to a Google **Shared Drive**, and delivers the report by **email + WhatsApp** — while a tracker database records every step for a full audit trail and an **admin dashboard** rolls it all up into a project portfolio.

This is a PHP port of the old `code.js` **Google Apps Script**. The same Google **service account** reads/writes the same Sheets the Apps Script used; the front-end is the original Apps Script UI running unchanged behind a `google.script.run → fetch` shim. No Composer, no framework — native `openssl` (JWT signing) + `curl`.

---

## 1. What is this system? (Plain-English summary)

VAPL runs air-conditioning installation projects across many sites and developer buildings. Every day a **project engineer (PE)** visits a site and needs to record: what step the work reached (copper piping, drain testing, commissioning…), who did the work (VAPL staff or a contractor), whether anything is on hold, photos of the site, and the plan for next time.

Previously this was a Google Apps Script form writing into Google Sheets. It worked, but it was **slow** (the engineer waited while photos uploaded, the PDF built, and email/WhatsApp went out) and it had **no operational visibility** (no dashboard, no audit trail when a step silently failed).

This system keeps the Sheets as the record of truth but makes the submit **instant** (~100 ms) and adds a **tracker database** + **admin dashboard**:

- The engineer submits and the form returns immediately.
- A **background worker** does the slow work off the request: photos → Shared Drive, response row → Sheet, PMS progress stamp, PDF build, PDF → Drive.
- A short delay later the worker sends the **email + WhatsApp** report to the client/developer.
- Every step is written to a **process log**, so any failure is visible in the admin panel.
- The admin panel synthesizes the raw reports into a **project portfolio** (lifecycle, risk, holds, workforce, alerts).

### Who uses it

| Surface | URL path | Who | What they do |
|---|---|---|---|
| **Report form** | `/pms/` | Project engineers (field) | File the daily site-visit report (VRV / Non-VRV, General / Developer) |
| **Admin dashboard** | `/pms/admin/` | Managers / ops | Portfolio KPIs, submissions & pipeline health, projects (Project 360), workforce/contractors, planner/calendar, alerts, settings |
| **First-run setup** | `/pms/admin/setup.php` | First admin | Create the initial admin account (self-disables after) |

Two outbound channels deliver each report:

- **Email** (Gmail SMTP, STARTTLS 587) — the branded PDF report to the client/developer + CC.
- **WhatsApp** (Meta Cloud API) — the report as a link *or* the actual PDF document (approved template).

A separate **PE-plan reminder** sends an image of *tomorrow's* site plan (grouped by engineer) over WhatsApp the evening before.

---

## 2. The big picture (architecture)

```
                         PROJECT ENGINEER (field, phone/browser)
                                        │
                                        ▼
                        ┌───────────────────────────────┐
                        │  Report form  /pms/            │
                        │  (Index.html + AppJs.html,     │
                        │   google.script.run→fetch shim)│
                        └───────────────┬───────────────┘
                                        │  POST /pms/api/submit.php
                                        ▼
        ┌───────────────────────────────────────────────────────────┐
        │  FAST PATH (HTTP, ~100 ms)   SubmitService::enqueue()       │
        │   • write `submissions` row (status = queued)               │
        │   • write job file to storage/queue                         │
        │   • spawn the background worker (Spawn → exec/popen)         │
        │   • return to the engineer immediately                      │
        └───────────────┬───────────────────────────────────────────┘
                        │  (also drained by the every-minute cron worker)
                        ▼
        ┌───────────────────────────────────────────────────────────┐
        │  WORKER PHASE 1   SubmitService::runCore()                  │
        │   photos → Shared Drive · response row → RESPONSE sheet      │
        │   (VRV/Non-VRV, header-matched) · PMS progress stamp         │
        │   (General or Developer building) · build PDF (FPDF) →       │
        │   Shared Drive · stamp PDF id / status                       │
        └───────────────┬───────────────────────────────────────────┘
                        │  wait ~notify_delay_seconds (default 180 s)
                        ▼
        ┌───────────────────────────────────────────────────────────┐
        │  WORKER PHASE 2   SubmitService::runNotifications()         │
        │   Email (SMTP)  +  WhatsApp (Meta Cloud API)                │
        └───────────────┬───────────────────────────────────────────┘
                        │  every step recorded in `process_log`
                        ▼
        ┌───────────────────────────────────────────────────────────┐
        │  MySQL tracker DB (`pms`)   submissions · process_log ·     │
        │  attachments   +   synced master tables (projects, alerts,  │
        │  workers, contractors, visit_workers)                       │
        └───────────────┬───────────────────────────────────────────┘
                        ▼
        ┌───────────────────────────────────────────────────────────┐
        │  ADMIN DASHBOARD  /pms/admin/  — portfolio, pipeline health │
        │  Project 360, workforce, planner, alerts, settings          │
        └───────────────────────────────────────────────────────────┘

  External: Google Sheets API v4 · Google Drive (Shared Drive) · Gmail SMTP · Meta WhatsApp Cloud API
```

**Technology stack**

| Layer | Technology | Notes |
|---|---|---|
| Language | **PHP 8.1+** | Pure PHP — `match`, arrow functions, typed params. **No Composer / framework.** |
| Database | **MySQL 8 / MariaDB 10.4+** | PDO, prepared statements. Holds the **process tracker + audit + admin master tables** — the report *data* lives in Google Sheets. |
| Web server | Apache + mod_php (XAMPP locally) / **Nginx + PHP-FPM (production, recommended)** | See §10 for the Apache-vs-Nginx decision. |
| Front-end | Ported Apps Script UI (`Index.html` + `AppJs.html`) via a `google.script.run → fetch` shim in `index.php` | No build step, no npm. |
| PDF | **FPDF 1.9** (vendored in `vendor/fpdf/`) | Branded letterhead report. |
| Image | **php-gd with FreeType** | Renders the PE-plan reminder image + PDF glyphs. |
| External APIs | Google Sheets v4, Google Drive, Gmail SMTP (587), Meta WhatsApp Cloud API | Called via `curl`; the service-account JWT is signed with `openssl`. |
| Async | File-based **job queue** (`storage/queue`) + a spawned CLI **worker**, with a per-minute cron safety net | Makes submit instant; survives missed spawns. |
| PHP extensions | `pdo_mysql`, `curl`, `openssl`, `mbstring`, `gd` (FreeType), `json` | |

---

## 3. The core idea — instant submit, everything else async

This is the reason the app exists in its current shape.

### Why
On Apps Script the engineer waited 10–30 s while photos uploaded, the PDF rendered, and email/WhatsApp fired — on a phone, on-site, on mobile data. And when a downstream step failed there was no record.

### How (`src/SubmitService.php`)
The pipeline is split into three phases so the HTTP request returns instantly:

| Phase | Method | Runs where | Work |
|---|---|---|---|
| **Fast path** | `enqueue()` | The web submit (HTTP) | Create the `submissions` row (`queued`), write the job file, spawn the worker, return. |
| **Core** | `runCore()` | Background worker | Photos → Shared Drive · response row → RESPONSE sheet · PMS progress stamp · PDF build → Drive. |
| **Notify** | `runNotifications()` | Background worker | Email + WhatsApp, ~`notify_delay_seconds` after submit. |

`handle()` runs all three inline (used only by CLI tests). The web submit calls **only** `enqueue()`.

### The background worker (`src/Worker.php`, `scripts/worker.php`)
- Each submit **spawns** `worker.php` (`src/Spawn.php` via `exec()`/`popen()`), which loops until the queue is drained (including the delayed notifications). No scheduler is strictly required.
- **Safety net (required in production):** a cron entry runs `worker.php --once` **every minute**, so a missed spawn still drains the queue and the delayed notifications still fire. Keep this even when `exec()` is enabled.
- Tunables (`config/app.php`): `notify_delay_seconds`, `worker_max_runtime`, `worker_poll_seconds`, `php_binary`, `queue_dir`.

### Submission status walk
`queued → processing → awaiting_notify → done | partial | failed`, mirrored on `submissions.overall_status`. Each step (`sheet_write`, `photo_save`, `pms_update`, `pdf`, `email`, `whatsapp`) gets its own `process_log` row (`pending → running → done | failed | skipped`), so the admin **Pipeline** page shows exactly where anything broke.

---

## 4. Module-by-module reference

### 4.1 Front-end (`index.php` + vendored HTML/JS)

`index.php` serves the original Apps Script UI **unchanged**: it emits `Index.html` (markup) + `AppJs.html` (app logic), then injects a small shim that reimplements `google.script.run` on top of `fetch`, routing the old calls (`getProjectNames`, `getUiImages`, `getProgressState`, `getFlats`, `submitSiteReport`) to the PHP `api/` endpoints. `HeroJs.html` / `LogoJs.html` are large vendored UI asset blobs. **No build step.**

### 4.2 API layer (`api/`)

| Endpoint | Method | Purpose |
|---|---|---|
| `api/submit.php` | POST | Capture the payload → `enqueue()` → spawn worker → return the submission id. **Never blocks on Drive/PDF/notify.** |
| `api/project_names.php` | GET | Project dropdown for a `siteType` (VRV / Non-VRV), read from the Orders sheets. |
| `api/ui_images.php` | GET | Letterhead/logo images for the form UI. |
| `api/flats.php` | GET | Flats for a chosen developer building. |
| `api/progress_state.php` | GET | Current progress/step state for a project (drives the form's step picker). |
| `api/_common.php` | — | Shared bootstrap/helpers for the endpoints. |

### 4.3 Business logic (`src/`)

| Class | Responsibility |
|---|---|
| `Bootstrap.php` | Wires config + services (Sheets, Drive, Db) and is passed to the pipeline. |
| `GoogleAuth.php` | Signs the service-account **JWT** with `openssl`, exchanges it for an access token, caches it in `storage/tokens`. |
| `GoogleClient.php` | Thin `curl` HTTP client for the Google APIs. |
| `Sheets.php` | Read/write Google Sheets; **matches columns by header name** (not position). |
| `Drive.php` | Uploads photos + the generated PDF to the **Shared Drive** (My-Drive uploads fail — 0 quota on a service account). |
| `Db.php` | PDO wrapper (prepared statements). |
| `Tracker.php` | Writes `submissions` + `process_log` (the audit trail). |
| `ResponseSheet.php` | Writes the submission's response row into the VRV / Non-VRV RESPONSE tab. |
| `Pms.php` | Stamps the **PMS progress** sheet — General (Order-ID → project) or a Developer building (Flat No). |
| `Pdf.php` / `PmsFpdf.php` | Build the branded letterhead report PDF (FPDF 1.9). |
| `JobQueue.php` | File-based queue in `storage/queue` (one job file per submission). |
| `Spawn.php` | Launches the background worker via `exec()`/`popen()`. |
| `Worker.php` | Drains the queue: runs `runCore()`, then `runNotifications()` after the delay. |
| `SubmitService.php` | The pipeline orchestrator (`enqueue` / `runCore` / `runNotifications`). |
| `NotificationService.php` | Orchestrates email + WhatsApp; resolves recipients; records results. |
| `Mailer.php` / `Smtp.php` | SMTP send over STARTTLS (587). |
| `Whatsapp.php` | Meta Cloud API send — `link` (template `daily_site_updates`) or `document` (attaches the real PDF via approved template `daily_site_update_doc`). |
| `PePlan.php` / `PePlanSender.php` | Build + send the PE-plan reminder image (tomorrow's plan, grouped by engineer). |

### 4.4 Admin panel (`admin/`)

A Bootstrap-based dashboard, gated by `Admin::requireAuth()`, that reads the tracker DB and a set of **synced master tables** it rebuilds from the raw submissions. **The admin panel is read-only over the report pipeline** — its sync only *reads* `submissions`/`payload_json`; it never touches the submit → sheet → PMS → PDF → notify flow.

| Page(s) | Purpose |
|---|---|
| `index.php` | **Executive dashboard** — portfolio lifecycle KPIs, operational strip (holds, overdue, no-update-24/48h, unassigned), delivery-coverage %, risk. |
| `login.php` / `logout.php` / `setup.php` | Auth + first-run admin creation (`setup.php` works only while `admin_users` is empty, then self-disables). |
| `users.php` | Manage admin/viewer accounts. |
| `settings.php` | Runtime settings → writes `config/overrides.json` (developer client contacts, email/WhatsApp modes, notify delay, PE-plan mode/time/numbers). Applies to **both** web and worker. |
| `submissions.php` / `submission.php` | List + full detail of every report (with its `process_log` timeline). |
| `pipeline.php` | Pipeline health — failed/partial steps across the tracker. |
| `projects.php` / `project.php` | Project master + **Project 360** (per-project rollup + lifecycle). |
| `developers.php` | Developer buildings view. |
| `contractor.php` / `workforce.php` | Contractor companies + individual workers, visit counts. |
| `planner.php` / `calendar.php` | Next-plan planner + site calendar. |
| `holds.php` | On-hold projects (Client vs VAPL owner). |
| `notifications.php` | Alerts inbox (open / ack / snoozed / resolved). |
| `sync.php` | Force an immediate master-data rebuild (pages also auto-sync, throttled). |
| `pe_plan_test.php` | Send a test PE-plan reminder. |
| `vendor_fetch.php` / `worker.php` | Admin helpers. |

### 4.5 Scripts (`scripts/`)

| Script | Purpose |
|---|---|
| `worker.php` | The queue drainer. `--once` = one pass (used by cron). |
| `pe_plan_send.php` | PE-plan reminder; self-gates to send **once/day at `send_time`** (run it every 15 min from cron). |
| `register_worker_task.ps1` / `register_pe_plan_task.ps1` | **Windows** Scheduled Tasks for local dev (Linux uses cron — see the deploy guide). |
| `verify_auth.php` | SA token + a sheet read. |
| `check_access.php` | Which sheets the SA can reach. |
| `check_drive.php` | Shared-Drive upload works. |
| `check_headers.php` / `diag_pms.php` | Header / PMS-stamp diagnostics. |
| `test_submit.php` | Full end-to-end (writes + deletes a test row). |
| `test_pdf.php` / `test_pdf_hold.php` | PDF render tests. |
| `test_email.php` / `test_whatsapp.php` | Notification tests in isolation. |
| `test_async.php` / `test_notify_units.php` / `profile_submit.php` | Async + unit + profiling tests. |
| `create_wa_template.php` / `create_pe_plan_template.php` / `check_wa_template.php` | WhatsApp template management + approval check. |
| `admin_sync.php` | CLI master-data sync (same rebuild the admin panel runs). |

---

## 5. End-to-end process flows

### 5.1 Daily report lifecycle (the main flow)

```
Engineer submits /pms/ form
        │
        ▼
api/submit.php → SubmitService::enqueue()
        • create submissions row (queued) · write job file · spawn worker · RETURN (~100 ms)
        ▼
Worker::runCore()
        • photos → Shared Drive
        • response row → RESPONSE sheet (VRV / Non-VRV, header-matched)
        • PMS progress stamp → General (Order-ID→project) OR Developer building (Flat No)
        • build PDF (FPDF letterhead) → Shared Drive · stamp PDF id
        ▼   (wait ~notify_delay_seconds, default 180 s)
Worker::runNotifications()
        • Email (SMTP)     — client/developer + CC, stamps Mail Status
        • WhatsApp (Meta)  — link or actual PDF document, stamps WhatsApp Status
        ▼
process_log fully written · submissions.overall_status = done | partial | failed
        ▼
Admin sync folds the report into projects / workers / contractors / alerts
```

### 5.2 Recipient resolution (email + WhatsApp)
- **Email:** scrape tab → Orders "Client Email Id" → developer map → fallback address.
- **WhatsApp:** Orders "phone" columns → developer map → fallback numbers.
- The scrape sheet and the extra Orders sheet must be **shared with the SA** for those tiers to work; otherwise it falls back gracefully. **MODE** per channel: `OFF` (send nothing, logged skipped) · `TEST` (only to your test address/number, no sheet stamp) · `LIVE` (real recipients + CC, sheet stamped).

### 5.3 PE-plan reminder (WhatsApp image, evening before)
`scripts/pe_plan_send.php` runs every 15 min from cron and self-gates: at `pe_plan.send_time` it renders an image of *tomorrow's* site plan grouped by engineer and sends it (image-header template `pe_plan_reminder`) to the internal team numbers. Mode/time/numbers are tunable from **Admin → Settings** (`overrides.json`).

### 5.4 Admin master-data sync
Admin pages auto-sync (throttled) and `admin/sync.php` forces it: the sync **reads** `submissions` + `payload_json` and rebuilds `contractors`, `workers`, `visit_workers`, `projects`, `alerts`. It is **additive and read-only** over the pipeline — it can never affect a live submit.

---

## 6. Database (`pms`)

The report *data* lives in Google Sheets; this database is the **process tracker, audit trail, and admin rollup**. Three idempotent schema files (`CREATE TABLE IF NOT EXISTS`) — import all three.

| Group | Tables | Schema file |
|---|---|---|
| **Report pipeline** | `submissions` (one row per report), `process_log` (one row per step), `attachments` (photos/drawing/measurement/PDF) | `db/schema.sql` |
| **Auth & ops** | `admin_users`, `rate_limits` (login throttle), `audit_logs` | `db/admin_schema.sql` |
| **Admin master (synced, additive)** | `contractors`, `workers`, `visit_workers`, `projects` (lifecycle rollup), `alerts`, `alert_events` | `db/admin_ext_schema.sql` |

`submissions.overall_status ∈ {received, processing, done, failed, partial}`; `process_log.step ∈ {sheet_write, photo_save, pms_update, pdf, email, whatsapp}`. `projects.lifecycle` walks `Not Started → Active → At Risk → On Hold → Commissioning Pending → Commissioned → Closed` (Commissioned/Closed can be manually locked so sync won't override).

---

## 7. Configuration reference (`config/`)

| File | What it holds | In git? |
|---|---|---|
| `config/app.php` | All Sheet/folder IDs, Shared-Drive IDs, DB creds, the `email` / `whatsapp` / `pe_plan` blocks (modes, hosts, templates), async tunables (`notify_delay_seconds`, `worker_*`), `php_binary`, timezone. **The two secrets are blank here** — filled from `secrets.php` at runtime. **First file to edit on a new server.** | ✅ Yes (no secrets in it) |
| `config/secrets.php` | **Server-local values:** the secrets (`email.smtp_pass`, `whatsapp.token`) **and** the infra that differs per box (`db` creds, `php_binary`). `app.php` loads it and overrides its defaults. Set **once per server** (copy the example). | **No (gitignored)** |
| `config/secrets.example.php` | Blank template for `secrets.php` — `cp` it and fill in. No real values. | Yes (safe template) |
| `config/google-service-account.json` | The Google **service-account key**. Must be **Content manager** on the "Daily Site Reports" Shared Drive and shared on every Sheet the app reads/writes. | No (gitignored) |
| `config/overrides.json` | Admin-panel runtime overrides (developer client contacts, email/WhatsApp modes, notify delay, PE-plan mode/time/numbers). Merged over `app.php` for both web + worker. | No (gitignored) |

> The Shared-Drive requirement is non-negotiable: a service account has **0 storage quota on My Drive**, so photos/PDFs must go to a Workspace **Shared Drive** shared with the SA. `parent_folder_id` + `shared_drive_id` in `app.php` must match it.

---

## 8. Security model & secrets ⚠️

- **Admin auth** — `admin_users` with `password_hash`; roles `admin` / `viewer`; first admin via `setup.php` (self-disables once one exists).
- **Rate limiting** — `rate_limits` throttles admin login brute-force.
- **Audit** — `audit_logs` records who changed what (settings, users, sync, lifecycle).
- **CSRF** on admin state-changing POSTs.
- **Secrets blocked from the web** — the deploy blocks HTTP access to `config/`, `storage/`, `db/` (see the deploy guide). Verify `curl http://HOST/pms/config/app.php` returns **403/404**, not source.

### 🔴 Rotate the exposed secrets before go-live
The secrets now live in the gitignored **`config/secrets.php`** (only `email.smtp_pass` + `whatsapp.token`); `config/app.php` stays tracked and holds no secrets, and loads `secrets.php` at runtime to fill them. **But git history still holds the old values** — they were committed before the split (as was the SA key). Before production:

1. **Rotate** all three — generate a **new** Gmail app password for `crm@`, a **new** Meta access token, and a **new** SA key (disable the old ones). Put the new SMTP/WhatsApp values in the server's `config/secrets.php`; drop the new SA key at `config/google-service-account.json`.
2. Optionally **purge** the old values from git history (`git filter-repo`) if the repo is shared.

| Where | Contents | Git |
|---|---|---|
| `config/secrets.php` | real `smtp_pass` + `token`, plus server-local `db` creds + `php_binary` | gitignored — set once per server |
| `config/app.php` | everything else (Sheet IDs, modes, tunables) | tracked — edit via git + push |

A deploy (`git reset --hard`) refreshes `app.php` but **never touches** `secrets.php`.

---

## 9. Run it locally (XAMPP, Windows)

1. Install XAMPP (PHP 8.1+); start **Apache** + **MySQL**. Ensure the `gd` extension is on (needed for the PE-plan image + PDF glyphs).
2. Put this repo at `c:\xampp\htdocs\pms` (folder **must** be named `pms` — the app hardcodes `/pms/` paths).
3. Import the DB (all three files):
   ```bash
   mysql -u root pms < db/schema.sql
   mysql -u root pms < db/admin_schema.sql
   mysql -u root pms < db/admin_ext_schema.sql
   ```
   > **Windows gotcha:** if MySQL 8 (port 33060) shadows XAMPP's MariaDB on 3306 you'll get root access-denied — check `netstat` before blaming the code.
4. `cp config/secrets.example.php config/secrets.php` and fill it in — the two secrets plus DB creds + `php_binary` = `C:\xampp\php\php.exe` (the committed defaults already suit XAMPP root/no-password). Sheet IDs / modes stay in `config/app.php`.
5. Drop the SA key at `config/google-service-account.json` (shared on the Shared Drive + Sheets).
6. Open `http://localhost/pms/` (report form) and `http://localhost/pms/admin/setup.php` (create first admin).
7. Diagnostics: `php scripts/verify_auth.php`, `check_access.php`, `check_drive.php`, `test_submit.php`.
8. Register the worker safety-net task (elevated): `scripts/register_worker_task.ps1` (worker every minute). PE-plan: `scripts/register_pe_plan_task.ps1`.

---

## 10. Deploy to production (GCP)

This project runs on a single **Google Compute Engine `e2-small`** VM (2 vCPU / 2 GB, `asia-south1` Mumbai) — **Ubuntu 24.04 + Nginx + PHP 8.3-FPM + MySQL 8** — served under the **`/pms/`** URL path. The complete, click-by-click walkthrough is in **[DEPLOY_GCP.md](DEPLOY_GCP.md)** (VM, LEMP install, 2 GB tuning, DB, code, `secrets.php`, SA key + Shared Drive, `storage/` perms, the three schema imports, Nginx server block, worker + PE-plan cron, diagnostics, go-live for email/WhatsApp, HTTPS, hardening, backups).

### 10.1 Which web server — Apache+PHP 8.2 or Nginx? (you asked)

**Cost is identical.** Both Apache and Nginx are free, open-source, and run on the *same* `e2-small` VM. The bill is the **VM + disk + static IP** (~$13–15/mo in Mumbai), not the web server. Choosing one over the other saves **$0**.

The real difference is **RAM and operational consistency**:

| | **Apache + mod_php 8.2** | **Nginx + PHP 8.3-FPM** ⭐ |
|---|---|---|
| Cost | $0 (VM only) | $0 (VM only) |
| RAM model | PHP is loaded **into every Apache worker** (prefork) → ~30–60 MB per idle connection | Nginx (event-driven, tiny) + a **separate FPM pool** (`ondemand` frees idle workers) → less idle RAM |
| On a 2 GB box | Works; tighter headroom alongside MySQL | **More headroom** — recommended at 2 GB |
| `exec()`/`popen()` (worker spawn) | ✅ works | ✅ works |
| Config style | `.htaccess` + vhost, familiar from XAMPP | server block + `location` rules |
| Your other project (VAPL CRM) | — | **Already runs Nginx + PHP 8.3-FPM + MySQL 8** |

**Decision: Nginx + PHP 8.3-FPM** (this is what [DEPLOY_GCP.md](DEPLOY_GCP.md) now walks through). Same price, lower memory footprint on a 2 GB VM, and — most importantly — it **matches your existing VAPL CRM box**, so you operate one stack, one set of tuning knobs, one CI/CD pattern (the deploy reloads `php8.3-fpm` to clear OPcache). Apache+PHP 8.2 would run the app identically (it just needs `exec()` enabled and `php-gd`), but there's no reason to keep two different stacks.

> **Note on ports/coexistence:** you decided to put PMS on its **own project + instance**, so there's nothing to share with VAPL. (Two apps *can* share one VM — different URL prefixes `/pms/` vs `/vapl/`, different DBs — but a dedicated box keeps blast-radius and tuning clean.)

### 10.2 Production essentials (recap of DEPLOY_GCP.md)
- Keep the app in a folder named **`pms`** under the web root (hardcoded `/pms/` paths).
- In `config/secrets.php`: `php_binary = /usr/bin/php` + DB creds for a dedicated `pms_user` (not root), bound to `127.0.0.1`.
- Install **`php-gd`**; confirm `exec`/`popen` are **not** in `disable_functions`.
- Block HTTP to `config/`, `storage/`, `db/`.
- **Cron (as `www-data`):** `worker.php --once` every minute + `pe_plan_send.php` every 15 min.
- SA key `chmod 640`, owned by the web user; `storage/*` writable.
- Go live: fill secrets, test email/WhatsApp in `TEST`, then flip to `LIVE` from Admin → Settings.

---

## 11. CI/CD — push to `main`, it deploys itself

Full step-by-step in **[docs/CICD_AUTODEPLOY.md](docs/CICD_AUTODEPLOY.md)**. Summary of the recommended flow (**GitHub Actions → SSH → deploy script**):

```
1. Work on branch  v10   (locally / XAMPP)
2. git commit → git push origin v10
3. Open a PR:  v10 → main   (review, merge)
4. Merge updates  main
5. GitHub Actions fires → SSH to the GCP VM → scripts/deploy.sh
        • git reset --hard origin/main        (secrets are gitignored, untouched)
        • fix file permissions
        • reload php8.3-fpm                    (clears OPcache → new code live)
6. https://pms.vakhariaairtech.com/pms/  now runs the new code ✅
```

The worker + PE-plan **cron jobs are unaffected by a deploy** (they run the freshly-pulled `scripts/*.php` each tick). Schema changes: re-import the (idempotent) `db/*.sql` once over SSH. **Rollback:** `git reset --hard <good-commit> && sudo systemctl reload php8.3-fpm`, or revert the merge on GitHub.

> Secrets already sit in the gitignored `config/secrets.php`, so `git reset --hard origin/main` refreshes `config/app.php` (Sheet IDs, modes, tunables) without ever touching the server's SMTP/WhatsApp secrets. Don't hand-edit `app.php` on the server — change it in git and push.

---

## 12. Glossary

| Term | Meaning |
|---|---|
| **PE** | Project Engineer — files the daily site-visit report. |
| **VRV / Non-VRV** | The two AC-system project types; each has its own Orders + RESPONSE sheet. |
| **PMS sheet** | The progress sheet the report stamps — General (by Order-ID) or a Developer building (by Flat No). |
| **Shared Drive** | A Google Workspace shared drive (has real storage quota, unlike a service account's My-Drive). |
| **Worker** | The background PHP process that does the slow work (Drive/PDF/notify) off the request. |
| **Job queue** | The `storage/queue` files the worker drains. |
| **process_log** | The per-step audit trail row for each submission. |
| **PE-plan reminder** | The WhatsApp image of tomorrow's site plan, sent the evening before. |
| **overrides.json** | Admin-panel runtime settings, merged over `config/app.php`. |
| **LEMP** | Linux + **E**ngine-x (Nginx) + MySQL + PHP — the recommended production stack. |

---

## 13. Recent changes

- **Phase 1** — full submit pipeline: SA auth, all six Sheets read/write, response-row write, PMS stamping, FPDF letterhead, Shared-Drive photo/PDF upload, DB tracking, HTTP endpoints, ported front-end.
- **Phase 2** — Email + WhatsApp delivery (ports of `sendReportEmail.js` / `sendReportwhatsapp.js`), fired after the PDF is ready, recorded in `process_log`; WhatsApp `document` delivery (real PDF via approved template) + PE-plan reminder image.
- **Admin panel** — executive dashboard, submissions & pipeline health, Project 360, workforce/contractors, planner/calendar, alerts, and runtime settings (`overrides.json`).
- **Async rework** — instant submit (`enqueue`) + background worker (`runCore` / `runNotifications`) + per-minute cron safety net.

---

*The code is the source of truth. Start at `src/SubmitService.php` (pipeline) and `config/app.php` (all IDs + toggles).*
