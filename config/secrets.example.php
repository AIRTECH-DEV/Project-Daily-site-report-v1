<?php
/**
 * TEMPLATE for config/secrets.php.  Committed to git — keep it BLANK (no real
 * values). Per machine/server, once:
 *     cp config/secrets.example.php config/secrets.php   # then edit secrets.php
 *
 * config/app.php loads config/secrets.php (gitignored) and overrides its
 * defaults with whatever you set here: the secrets, plus the server-local infra
 * (DB creds, PHP CLI path) that differ between the XAMPP dev box and the Linux
 * VM. No environment variables; CI/CD `git reset --hard` never touches it.
 */
return [
    'email' => [
        'smtp_pass' => '',   // Gmail app password for crm@vakhariaairtech.com
    ],
    'whatsapp' => [
        'token' => '',       // Meta (WhatsApp) Cloud API access token
    ],

    // Server-local infra. On the GCP VM use the Linux values:
    //   db.user/pass = the dedicated `pms_user` you create (NOT root in prod)
    //   php_binary   = /usr/bin/php
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'pms',
        'user' => '',        // e.g. pms_user
        'pass' => '',        // the pms_user password
    ],
    'php_binary' => '',      // Linux: /usr/bin/php   ·   Windows/XAMPP: C:\xampp\php\php.exe

    // HVAC commissioning intake (scripts/commission_push.php). Blank url = push OFF
    // — silently, so a missing block here is why nothing ever reaches the app.
    // CommissionPush appends "/ingest/commissioned" to the url; the VAPL endpoint
    // routes on its query string instead, hence the trailing "&x=" on that URL.
    //   prod: https://service.vakhariaairtech.com/vapl/api/commissioning_api.php?action=ingest_commissioned&x=
    //   dev : http://localhost/vapl/api/commissioning_api.php
    // api_key MUST equal VAPL_APP_API_KEY in vapl/config/config.php (and the app's apiKey).
    'app_backend' => [
        'url'     => '',
        'api_key' => '',
    ],
];
