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
        'order_ss_ids'    => [
            '1HwYDM6ARcDomEqmhqBTxRe3OsAzeezTq_8Wnhsm3_eY',
            '1SV_WhGa_sEdUkj1X46xRtoCNo5KG3Khi1jRkdl9LSz0',
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
    }
}

return $cfg;
