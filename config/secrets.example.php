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
];
