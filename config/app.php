<?php
/**
 * Central config for the PHP Site-Visit-Report backend.
 * All sheet / folder IDs are ported verbatim from the old code.js so the
 * service account writes to the exact same destinations Apps Script did.
 *
 * NOTE: keep config/ out of git — it holds the SA private key. See .gitignore.
 */

$cfg = [
    // ---- Google service account + auth ----------------------------------
    'service_account' => __DIR__ . '/google-service-account.json',
    'token_cache_dir' => __DIR__ . '/../storage/tokens',
    'scopes' => [
        'https://www.googleapis.com/auth/spreadsheets',
        'https://www.googleapis.com/auth/drive',
    ],

    // ---- Response spreadsheet (submissions land here) -------------------
    'response_sheet_id' => '1LL9yDxL5uCv_szvnv-7MOQmJgDuDEuXhdS-WvVnLbOI',
    'tab_names' => ['VRV' => 'VRV', 'NONVRV' => 'Non-VRV'],

    // ---- Orders sheets (dropdown + Order ID + phone/email lookups) ------
    'vrv_orders_sheet_id'    => '1SV_WhGa_sEdUkj1X46xRtoCNo5KG3Khi1jRkdl9LSz0',
    'vrv_orders_gid'         => 290389899,
    'nonvrv_orders_sheet_id' => '1hvqgSI3f05d1wSoQVxaBPDzr4maHhTz5MLqhmN4a5Is',
    'nonvrv_orders_gid'      => 290389899,

    // ---- General PMS progress sheet ------------------------------------
    'general_pms_sheet_id' => '1-dkSwABh61SgjPRyEwei6_v0-zQK4jj_FwqHOiGvM0M',
    'general_pms_tabs'     => ['VRV' => 'PMS - VRV', 'NONVRV' => 'PMS - NonVRV'],

    // ---- Developer building progress sheets ----------------------------
    'developer_building_sheets' => [
        'Suyog Navkar' => [
            'spreadsheetId' => '1OJHBUMhIpcG3gGGubd8jeRRC16AX3P6t7aPOQPgdIiM',
            'buildings'     => ['Agam', 'Shruta', 'Kalpa'],
        ],
        'Kasturi' => [
            'spreadsheetId' => '1_Gmi34cOm-NBEcaw99qi3gmk3CT7Da-kFxpdaLqLb-E',
            'buildings'     => [
                'Balmoral River side D-wing',
                'Balmoral River side C-wing',
                'Balmoral TowerD-wing',
                'Balmoral TowerC-wing',
            ],
        ],
    ],

    // ---- Drive (Shared Drive) for photos + PDFs -------------------------
    // Must be a Google Workspace SHARED DRIVE folder shared with the SA,
    // otherwise the SA cannot write (0 storage quota on My Drive).
    // "Daily Site Reports" Shared Drive > "Store Daily site reports" folder,
    // shared with the SA as Content manager.
    'parent_folder_id'  => '1pHLzVUOJKIbmON9niVgsy_JGYS8VuDsu',
    'shared_drive_id'   => '0AE0aKMn9wj0gUk9PVA',

    // ---- Database (process tracker + audit) ----------------------------
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'pms',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // ---- Email (SMTP) — port of sendReportEmail.js ----------------------
    // MODE: OFF = send nothing | TEST = everything to test_to (no CC, no sheet
    // stamp) | LIVE = real client + CC, stamp Mail Status = SENT.
    'email' => [
        'mode'        => 'TEST',       // OFF | TEST | LIVE
        'smtp_host'   => 'smtp.gmail.com',
        'smtp_port'   => 587,
        'smtp_secure' => 'tls',            // tls (STARTTLS) | ssl | none
        'smtp_user'   => 'crm@vakhariaairtech.com',
        'smtp_pass'   => '',                                  // SET IN config/secrets.php (gitignored) — leave blank here
        'from'        => 'crm@vakhariaairtech.com',
        'from_name'   => 'CRM Vakharia Airtech',
        'cc'          => 'crm@vakhariaairtech.com,mis@vakhariaairtech.com,piyush@vakhariaairtech.com',
        'test_to'     => 'devops@vakhariaairtech.com',
        'fallback_to' => 'crm@vakhariaairtech.com',
        'subject_prefix' => 'Site Report: ',
        // General client-email lookup: scrape tab first, then Orders "Client Email Id".
        'scrape_ss_id'    => '1hPvEw0rxaOmg2JDtdp9q8XqjbrV98PZL2jiZVz6XWFI',
        'scrape_tab'      => 'VRV Scraped Data1',
        'scrape_name_col' => 1,            // 0-based col B (Project Name)
        'scrape_email_col'=> 3,            // 0-based col D (Scraped Emails)
        // Must list BOTH Orders sheets — same pair as whatsapp.order_ss_ids. The
        // Non-VRV one was missing, so every Non-VRV General report had no client
        // address to find and silently fell back to fallback_to (crm@).
        'order_ss_ids'    => [
            '1hvqgSI3f05d1wSoQVxaBPDzr4maHhTz5MLqhmN4a5Is',   // Non-VRV Orders
            '1SV_WhGa_sEdUkj1X46xRtoCNo5KG3Khi1jRkdl9LSz0',   // VRV Orders
            // Legacy sheet — the service account gets 403 on it, so it contributes
            // nothing today. Kept only so it starts working if it is ever shared.
            '1HwYDM6ARcDomEqmhqBTxRe3OsAzeezTq_8Wnhsm3_eY',
        ],
        'order_tab'       => 'Orders',
        'developer_emails'=> [
            'Kasturi'      => 'kasturi@vakhariaairtech.com',
            'Suyog Navkar' => '',                            // TODO: Suyog Navkar client email
        ],
    ],

    // ---- WhatsApp (Meta Cloud API) — port of sendReportwhatsapp.js -------
    'whatsapp' => [
        'mode'             => 'TEST',       // OFF | TEST | LIVE
        'token'            => '',          // SET IN config/secrets.php (gitignored) — leave blank here
        'phone_number_id'  => '1002193126304358',
        'template_name'    => 'daily_site_updates',
        'language_code'    => 'en',        // switch to 'en_US' if error 132001
        'graph_version'    => 'v21.0',
        'use_named_params' => true,        // template var named "report_link"
        'make_pdf_viewable'=> true,        // share PDF anyone-with-link so client can open

        // DELIVERY: 'link' = send the report link (original daily_site_updates).
        //           'document' = attach the actual PDF (client need not open a link).
        // 'document' REQUIRES an approved template whose HEADER format is DOCUMENT
        // (name below). Its body vars, if any, come from doc_body_params ({project}
        // is replaced with the project name). Empty body_params = static body text.
        'delivery'         => 'document',
        'doc_template_name'=> 'daily_site_update_doc',
        'doc_body_params'  => [],          // e.g. ['{project}'] if the doc template body has one var
        'test_to'          => '8180942110',          // e.g. '919876543210'
        'fallback_phones'  => '',
        'order_ss_ids'     => [
            '1hvqgSI3f05d1wSoQVxaBPDzr4maHhTz5MLqhmN4a5Is',   // Non-VRV Orders
            '1SV_WhGa_sEdUkj1X46xRtoCNo5KG3Khi1jRkdl9LSz0',   // VRV Orders
        ],
        'order_tab'        => 'Orders',
        'developer_phones' => [
            'Kasturi'      => '',          // TODO: Kasturi client number(s)
            'Suyog Navkar' => '',          // TODO: Suyog Navkar client number(s)
        ],
        'status_col_name'  => 'WhatsApp Status',
    ],

    // ---- PE Plan reminder (WhatsApp image, sent one day before) ---------
    // Sends an image of "tomorrow's site plan" (grouped by engineer) to the
    // internal numbers below, at send_time the evening before. Reuses the
    // whatsapp block's token / phone_number_id / graph_version / language_code.
    // The image is the header of an approved IMAGE-header template (template_name).
    // Runtime-tunable from admin/settings.php -> overrides.json ("pe_plan").
    'pe_plan' => [
        'mode'          => 'OFF',              // OFF | TEST | LIVE
        'template_name' => 'pe_plan_reminder', // approved IMAGE-header template
        'send_time'     => '20:00',            // HH:MM (24h) — fires this time, day before
        'numbers'       => [],                 // LIVE recipient numbers (internal team)
        'test_to'       => '8180942110',       // TEST + "Send test now" target
        'fonts'         => [],                 // optional TTF overrides: regular/semibold/bold
    ],

    // ---- Weekly PE report (email, Saturday evening) ---------------------
    // One email per Project Engineer: every site they worked Mon→Sat, step
    // progress, blockers, next plan, target dates, and what needs chasing.
    // Recipients come from the Team & Alerts "PE / Staff contacts" table
    // (overrides.json -> team_contacts), never from client contacts.
    // Runtime-tunable from admin/settings.php -> overrides.json ("pe_weekly").
    'pe_weekly' => [
        'mode'          => 'OFF',   // OFF | TEST (all to test_to) | LIVE (each PE)
        'send_day'      => 6,       // 1=Mon … 7=Sun — 6 = Saturday
        'send_time'     => '18:30', // HH:MM (24h)
        'test_to'       => '',      // blank = fall back to email.test_to
        'cc_manager'    => 1,       // CC alert_manager_email on every PE mail
        'include_empty' => 0,       // also mail PEs with no activity that week
    ],

    // ---- Client share links (share.php) ---------------------------------
    // A link is a bearer credential: whoever holds the URL sees that ONE project
    // (or building / developer) read-only, until it expires. Keep ttl_hours low.
    // Runtime-tunable from admin/settings.php -> overrides.json ("share").
    'share' => [
        'ttl_hours'   => 24,     // hard expiry from the moment the link is minted
        'max_views'   => 300,    // per-link view cap (forwarded-link blast radius)
        'retain_days' => 30,     // purge dead links + their events after this
        // Public base URL of the app. Blank = derive from the current request
        // (fine on one host); set it on the server so links minted by the CLI
        // worker / cron are absolute and correct.
        'base_url'    => '',     // e.g. https://pms.vakhariaairtech.com/pms
        // 'query' = /share.php?t=<token> (works anywhere, incl. XAMPP)
        // 'path'  = /s/<token>  — prettier, and what the WhatsApp URL-button
        //           template appends its suffix to. NEEDS the nginx location
        //           rule from DEPLOY_GCP.md §12 before you switch it on.
        'link_style'  => 'query',
        // WhatsApp: an APPROVED template with a dynamic URL button whose suffix
        // is the token. Body vars are positional: {name, project, step, percent}.
        'wa_template' => 'project_progress_link',
        'wa_language' => 'en',
        'waba_id'     => '1568163707846136',
        // Email subject prefix for a share (kept apart from the daily report).
        'subject_prefix' => 'Project progress: ',
        // Defaults for what a client may see. The share dialog can tighten these
        // per link, never loosen past this list.
        'defaults'    => [
            'photos'      => 1,
            'pe_names'    => 0,            // engineer names stay internal
            'hold_detail' => 'client_only',// client-side holds spelled out, ours neutral
        ],
    ],

    // ---- Project Engineers (site-report "Assigned Engineer" dropdown) ----
    // Seed roster. The admin panel (admin/users.php) writes the live roster to
    // config/overrides.json ("engineers"), which replaces this list. Entries may
    // be plain names or {"name": "...", "active": 0|1}; inactive names stay on
    // record but drop out of the app dropdown.
    'engineers' => [
        'Dada', 'Nagraj', 'Pratik', 'Ranjeet', 'Paresh', 'Shubham', 'Ganesh',
        'Vrundavan', 'Parikshit', 'Prathamesh Paigude', 'Raj Jthape',
    ],

    // ---- App ------------------------------------------------------------
    'timezone'    => 'Asia/Kolkata',
    'uploads_dir' => __DIR__ . '/../storage/uploads', // local temp before Drive push

    // ---- Async processing (make submit instant) ------------------------
    // Submit only captures the payload + returns; a background worker does
    // photos/sheet/PMS/PDF immediately, then email + WhatsApp after a delay.
    'queue_dir'            => __DIR__ . '/../storage/queue',
    'notify_delay_seconds' => 180,   // wait this long after submit before email/WhatsApp
    'worker_max_runtime'   => 900,   // spawned worker lives at most this long (s)
    'worker_poll_seconds'  => 15,    // gap between worker passes while jobs pending
    // Full path to the PHP CLI binary (PHP_BINARY is unreliable under mod_php).
    'php_binary'           => 'C:\\xampp\\php\\php.exe',

    // ---- HVAC commissioning app backend (separate service) --------------
    // PMS pushes each newly-Commissioned project here; the mobile app reads it.
    // Blank url = push disabled. Set url + api_key per machine in secrets.php.
    'app_backend' => [
        'url'     => '',   // e.g. http://localhost/hvac_backend  (no trailing slash)
        'api_key' => '',   // must equal the backend API_KEY and the app's apiKey
    ],
];

// ---- Server-local settings (config/secrets.php) -------------------------
// Everything that differs per machine or must never enter git lives OUTSIDE
// this file, in config/secrets.php:  the SMTP app password + WhatsApp token,
// AND the server-local infra (DB creds, PHP CLI path) — dev XAMPP vs the Linux
// VM. This file stays version-controlled (Sheet IDs / tabs / modes / tunables),
// so CI/CD `git reset --hard` refreshes it without wiping/leaking the secrets.
// Set it ONCE per machine: copy config/secrets.example.php -> config/secrets.php.
// No env vars. Any key present there overrides the default above; missing = keep.
$secretsFile = __DIR__ . '/secrets.php';
if (is_file($secretsFile)) {
    $secrets = require $secretsFile;
    if (is_array($secrets)) {
        if (isset($secrets['email']['smtp_pass']) && $secrets['email']['smtp_pass'] !== '') {
            $cfg['email']['smtp_pass'] = (string)$secrets['email']['smtp_pass'];
        }
        if (isset($secrets['whatsapp']['token']) && $secrets['whatsapp']['token'] !== '') {
            $cfg['whatsapp']['token'] = (string)$secrets['whatsapp']['token'];
        }
        // Server-local infra — DB credentials + PHP CLI binary (per-key merge).
        if (isset($secrets['db']) && is_array($secrets['db'])) {
            $cfg['db'] = array_merge($cfg['db'], $secrets['db']);
        }
        if (isset($secrets['php_binary']) && $secrets['php_binary'] !== '') {
            $cfg['php_binary'] = (string)$secrets['php_binary'];
        }
        // HVAC app backend URL + key (per-machine).
        if (isset($secrets['app_backend']) && is_array($secrets['app_backend'])) {
            $cfg['app_backend'] = array_merge($cfg['app_backend'], $secrets['app_backend']);
        }
        // Client share links: the PUBLIC base URL and link style are per-server
        // (dev XAMPP is …/share.php?t=…, prod is https://…/pms/s/<token>), so they
        // live here rather than in the shared, git-tracked defaults above.
        if (isset($secrets['share']) && is_array($secrets['share'])) {
            $cfg['share'] = array_merge($cfg['share'], $secrets['share']);
        }
    }
}

// ---- Admin-panel overrides (config/overrides.json) ----------------------
// The admin panel (admin/settings.php) writes runtime-tunable values here so
// developer client contacts / notification modes can be changed without editing
// this file. Both the web app and the CLI worker load this, so a change applies
// everywhere. Anything not present in the JSON keeps the defaults above.
$overridesFile = __DIR__ . '/overrides.json';
if (is_file($overridesFile)) {
    $ov = json_decode((string)file_get_contents($overridesFile), true);
    if (is_array($ov)) {
        if (!empty($ov['developer_emails']) && is_array($ov['developer_emails'])) {
            $cfg['email']['developer_emails'] =
                array_merge($cfg['email']['developer_emails'] ?? [], $ov['developer_emails']);
        }
        if (!empty($ov['developer_phones']) && is_array($ov['developer_phones'])) {
            $cfg['whatsapp']['developer_phones'] =
                array_merge($cfg['whatsapp']['developer_phones'] ?? [], $ov['developer_phones']);
        }
        if (!empty($ov['email_mode']) && in_array($ov['email_mode'], ['OFF', 'TEST', 'LIVE'], true)) {
            $cfg['email']['mode'] = $ov['email_mode'];
        }
        if (!empty($ov['whatsapp_mode']) && in_array($ov['whatsapp_mode'], ['OFF', 'TEST', 'LIVE'], true)) {
            $cfg['whatsapp']['mode'] = $ov['whatsapp_mode'];
        }
        if (isset($ov['notify_delay_seconds']) && is_numeric($ov['notify_delay_seconds'])) {
            $cfg['notify_delay_seconds'] = (int)$ov['notify_delay_seconds'];
        }
        // PE Plan reminder runtime settings (mode / send time / recipient numbers).
        if (isset($ov['pe_plan']) && is_array($ov['pe_plan'])) {
            $p = $ov['pe_plan'];
            if (!empty($p['mode']) && in_array($p['mode'], ['OFF', 'TEST', 'LIVE'], true)) {
                $cfg['pe_plan']['mode'] = $p['mode'];
            }
            if (!empty($p['send_time']) && preg_match('/^\d{1,2}:\d{2}$/', (string)$p['send_time'])) {
                $cfg['pe_plan']['send_time'] = sprintf('%02d:%02d', ...array_map('intval', explode(':', $p['send_time'])));
            }
            if (isset($p['numbers']) && is_array($p['numbers'])) {
                $cfg['pe_plan']['numbers'] = array_values(array_filter(array_map('strval', $p['numbers']), fn($v) => trim($v) !== ''));
            }
            if (!empty($p['test_to'])) {
                $cfg['pe_plan']['test_to'] = (string)$p['test_to'];
            }
        }
        // Weekly PE report runtime settings (mode / day / time / test inbox).
        if (isset($ov['pe_weekly']) && is_array($ov['pe_weekly'])) {
            $p = $ov['pe_weekly'];
            if (!empty($p['mode']) && in_array($p['mode'], ['OFF', 'TEST', 'LIVE'], true)) {
                $cfg['pe_weekly']['mode'] = $p['mode'];
            }
            if (isset($p['send_day']) && (int)$p['send_day'] >= 1 && (int)$p['send_day'] <= 7) {
                $cfg['pe_weekly']['send_day'] = (int)$p['send_day'];
            }
            if (!empty($p['send_time']) && preg_match('/^\d{1,2}:\d{2}$/', (string)$p['send_time'])) {
                $cfg['pe_weekly']['send_time'] = sprintf('%02d:%02d', ...array_map('intval', explode(':', $p['send_time'])));
            }
            if (isset($p['test_to']))       { $cfg['pe_weekly']['test_to']       = (string)$p['test_to']; }
            if (isset($p['cc_manager']))    { $cfg['pe_weekly']['cc_manager']    = (int)!empty($p['cc_manager']); }
            if (isset($p['include_empty'])) { $cfg['pe_weekly']['include_empty'] = (int)!empty($p['include_empty']); }
        }
        // Client share links (TTL / view cap / public base URL / link style).
        if (isset($ov['share']) && is_array($ov['share'])) {
            $s = $ov['share'];
            if (isset($s['ttl_hours']) && (int)$s['ttl_hours'] >= 1 && (int)$s['ttl_hours'] <= 168) {
                $cfg['share']['ttl_hours'] = (int)$s['ttl_hours'];
            }
            if (isset($s['max_views']) && (int)$s['max_views'] >= 0) {
                $cfg['share']['max_views'] = (int)$s['max_views'];
            }
            if (isset($s['base_url']))   { $cfg['share']['base_url']   = rtrim((string)$s['base_url'], '/'); }
            if (!empty($s['link_style']) && in_array($s['link_style'], ['query', 'path'], true)) {
                $cfg['share']['link_style'] = $s['link_style'];
            }
            if (!empty($s['wa_template'])) { $cfg['share']['wa_template'] = (string)$s['wa_template']; }
            if (!empty($s['wa_language'])) { $cfg['share']['wa_language'] = (string)$s['wa_language']; }
            if (!empty($s['waba_id']))     { $cfg['share']['waba_id']     = (string)$s['waba_id']; }
            if (isset($s['defaults']) && is_array($s['defaults'])) {
                $cfg['share']['defaults'] = array_merge($cfg['share']['defaults'], $s['defaults']);
            }
        }
        // Project Engineer roster — admin panel owns it once it writes the key.
        if (isset($ov['engineers']) && is_array($ov['engineers']) && $ov['engineers']) {
            $cfg['engineers'] = $ov['engineers'];
        }
    }
}

// ---- Normalize the engineer roster --------------------------------------
// Accepts plain names (seed list) or {"name":..,"active":..} rows (admin panel)
// and always returns rows: [['name' => 'Dada', 'active' => 1], ...].
$engineers = [];
$seen = [];
foreach ((array)($cfg['engineers'] ?? []) as $e) {
    $name = is_array($e) ? trim((string)($e['name'] ?? '')) : trim((string)$e);
    if ($name === '') continue;
    $key = mb_strtolower($name);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $engineers[] = [
        'name'   => $name,
        'active' => (is_array($e) && array_key_exists('active', $e)) ? (int)!empty($e['active']) : 1,
    ];
}
$cfg['engineers'] = $engineers;

return $cfg;
