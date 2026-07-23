# PMS — Deploy to Google Cloud (GCP) — Nginx + PHP 8.3-FPM + MySQL 8

This guide takes the PMS / Site-Visit-Report PHP app from XAMPP-on-Windows to a
production **Google Compute Engine** VM on a **LEMP** stack — **Ubuntu 24.04 +
Nginx + PHP 8.3-FPM + MySQL 8** — with the background worker and PE-plan reminder
running as **cron** jobs (they run as Windows Scheduled Tasks locally).

> **Why Nginx (not Apache)?** Same cost — both are free; you pay only for the VM.
> Nginx + PHP-FPM uses less RAM on a 2 GB box (FPM `ondemand` frees idle workers;
> Apache+mod_php loads PHP into every worker), and it **matches the VAPL CRM box**,
> so both servers use one identical stack. See README §10.1.

The app is a normal PHP app with a few specifics you must respect:

* It is served under a **`/pms/` URL path** (admin paths are hardcoded as
  `/pms/admin`, assets as `/pms/assets/...`). Keep the app in a folder named `pms`
  under the web root — do **not** rename it.
* It needs a **Google service-account key** and a **Shared Drive** (photos/PDFs
  can't go on the SA's My-Drive — 0 quota).
* Outbound it talks to **Gmail SMTP (587)** and **Meta WhatsApp Cloud API (443)**
  — both allowed on GCP. (GCP blocks outbound 25; we use 587, so email is fine.)
* PDF generation uses FPDF; the PE-plan reminder renders text on an image, so
  **php-gd with FreeType** is required.
* The web submit **spawns** the worker via `exec()`/`popen()`, so those must not
  be disabled (Ubuntu's default leaves them on).
* **Secrets + server-local infra live in `config/secrets.php`** (gitignored), not
  `config/app.php`. `app.php` stays in git (Sheet IDs, modes, tunables) and is
  overwritten by every deploy — never hand-edit it on the server. See §6.

---

## 0. Which GCP instance?

Low-traffic internal tool (a handful of engineers submitting site reports); photos
and PDFs live in Google Drive, not on the VM, so disk/RAM needs are modest.

| Instance | vCPU / RAM | Approx cost* | Verdict |
|---|---|---|---|
| `e2-micro` | 2 vCPU (burst) / **1 GB** | Free-tier (US only) | Too tight for MySQL 8 + Nginx + worker; trial only. |
| **`e2-small`** ⭐ | 2 vCPU (burst) / **2 GB** | **~$13–15 / mo** | **Buy this.** Comfortable with the §3 tuning + 2 GB swap. |
| `e2-medium` | 2 vCPU / **4 GB** | ~$25–27 / mo | Only if you add many users / heavier reporting. |

\* On-demand in **asia-south1 (Mumbai)**, VM only. Add ~$3–4/mo for a 30 GB balanced
disk. GCP's Always-Free `e2-micro` is US-region only; your users/numbers are in
India, so prefer a **paid `e2-small` in Mumbai**.

**Final spec:** `e2-small` · **Ubuntu 24.04 LTS** · 30 GB `pd-balanced` ·
`asia-south1` / `asia-south1-c` · static external IP · allow HTTP + HTTPS.

---

## 1. Create the project & VM

### 1a. Project + billing
1. <https://console.cloud.google.com> → create a project (e.g. `pms-prod`) → **link billing**.
2. Enable the **Compute Engine API** (prompts on first open).

### 1b. Create the VM (Console)
Compute Engine → **Create instance**:
* **Name:** `pms-vm` · **Region/Zone:** `asia-south1` / `asia-south1-c`
* **Machine type:** `e2-small`
* **Boot disk:** Change → **Ubuntu 24.04 LTS (x86/64)**, **Balanced**, **30 GB**
* **Firewall:** tick **Allow HTTP** + **Allow HTTPS**
* Create.

### 1c. Or with the gcloud CLI
```bash
gcloud config set project pms-prod
gcloud config set compute/region asia-south1
gcloud config set compute/zone asia-south1-c

gcloud compute addresses create pms-ip --region asia-south1
gcloud compute instances create pms-vm \
  --machine-type=e2-small \
  --image-family=ubuntu-2404-lts-amd64 --image-project=ubuntu-os-cloud \
  --boot-disk-size=30GB --boot-disk-type=pd-balanced \
  --address=$(gcloud compute addresses describe pms-ip --region asia-south1 --format='value(address)') \
  --tags=http-server,https-server
```

### 1d. Reserve the IP as static
VPC network → **IP addresses** → the VM's external IP → **Ephemeral → Static** → Reserve.
Note it as `SERVER_IP`.

### 1e. SSH in
Console: **SSH** button next to the instance. Or `gcloud compute ssh pms-vm --zone asia-south1-c`.
All remaining commands run **inside the VM** unless noted.

---

## 2. Install the LEMP stack

```bash
sudo apt update && sudo apt -y upgrade

# Nginx + PHP 8.3-FPM + the extensions PMS needs + MySQL 8 + tools
sudo apt -y install \
  nginx \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-curl php8.3-mbstring \
  php8.3-gd php8.3-xml php8.3-bcmath php8.3-zip \
  mysql-server \
  git unzip certbot python3-certbot-nginx
```

Verify the extensions PMS relies on (all must appear):
```bash
php -m | grep -Ei 'curl|openssl|pdo_mysql|mbstring|^gd$|json'
```
* `curl` → Google API + WhatsApp calls · `openssl` → signs the SA JWT (PHP core)
* `pdo_mysql` → DB tracker/audit · `mbstring` → text · `gd` (FreeType) → PE-plan image + PDF glyphs

Confirm `exec`/`popen` are **not** disabled (needed for the per-submit worker spawn):
```bash
php -r 'echo "CLI disable_functions: [" , (ini_get("disable_functions") ?: "none") , "]\n";'
```
Ubuntu leaves them enabled by default. (Even if a spawn is ever missed, the §10 cron drains the queue.)

```bash
sudo systemctl enable --now nginx php8.3-fpm mysql
```

---

## 3. Tune for a 2 GB VM

### 3a. 2 GB swap (safety net for memory spikes)
```bash
sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
sudo sysctl vm.swappiness=10 && echo 'vm.swappiness=10' | sudo tee -a /etc/sysctl.conf
```

### 3b. MySQL (small DB, low traffic)
```bash
sudo tee /etc/mysql/mysql.conf.d/zz-pms.cnf >/dev/null <<'EOF'
[mysqld]
innodb_buffer_pool_size        = 256M
innodb_log_file_size           = 64M
innodb_flush_log_at_trx_commit = 2
innodb_flush_method            = O_DIRECT
max_connections                = 50
performance_schema             = OFF
EOF
sudo systemctl restart mysql
```

### 3c. PHP-FPM `ondemand` (frees idle workers)
```bash
sudo sed -i \
 -e 's/^pm = .*/pm = ondemand/' \
 -e 's/^pm.max_children = .*/pm.max_children = 10/' \
 -e 's/^;*pm.process_idle_timeout = .*/pm.process_idle_timeout = 15s/' \
 -e 's/^pm.max_requests = .*/pm.max_requests = 500/' \
 /etc/php/8.3/fpm/pool.d/www.conf
```

### 3d. PHP limits + timezone (photos need big uploads; worker needs the TZ)
PMS site-visit submits carry photos, so raise upload limits; set the timezone for
**both** the FPM (web) and CLI (worker/cron) SAPIs:
```bash
sudo tee /etc/php/8.3/fpm/conf.d/99-pms.ini >/dev/null <<'EOF'
date.timezone       = Asia/Kolkata
upload_max_filesize = 64M
post_max_size       = 80M
max_execution_time  = 120
max_file_uploads    = 50
memory_limit        = 256M
opcache.enable      = 1
opcache.memory_consumption      = 128
opcache.max_accelerated_files   = 10000
opcache.validate_timestamps     = 1
EOF
# CLI (worker/cron) — timezone matters here
sudo cp /etc/php/8.3/fpm/conf.d/99-pms.ini /etc/php/8.3/cli/conf.d/99-pms.ini
sudo systemctl restart php8.3-fpm
```

> **Do NOT change the server clock.** PMS cron is interval-based (`* * * * *`,
> `*/15`) so the OS timezone is irrelevant; the app reads `Asia/Kolkata` from
> config. Leave the VM on UTC.

---

## 4. Create the database + app user

```bash
sudo mysql_secure_installation   # set a root password, remove anon users, test DB, reload
```

Create the app DB and a dedicated user (never run the app as root). The app connects
over **TCP to 127.0.0.1**, so the grant is for `'pms_user'@'127.0.0.1'`:
```bash
sudo mysql <<'SQL'
CREATE DATABASE IF NOT EXISTS pms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'pms_user'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON pms.* TO 'pms_user'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
```
Keep MySQL bound to localhost (default) — never expose 3306 publicly.

---

## 5. Deploy the application code

Target: **`/var/www/html/pms`** (so the app answers at `http://SERVER/pms/`).

```bash
sudo mkdir -p /var/www/html
cd /var/www/html
sudo git clone https://github.com/AIRTECH-DEV/<pms-repo>.git pms
cd pms
sudo git checkout main            # or your release branch
```

### Files NOT in git — create them by hand (§6)
These are gitignored (secrets/runtime): `config/secrets.php`,
`config/google-service-account.json`, `config/overrides.json` (optional; the admin
panel writes it), and the `storage/…` runtime dirs (§7).

---

## 6. Configure — `config/secrets.php` (NOT `app.php`)

`config/app.php` is **tracked in git** and holds only non-secret config (Sheet/folder
IDs, notification modes, tunables). It is overwritten by every deploy — **do not edit
it on the server.** All server-local values — DB creds, the PHP binary path, and the
two secrets — go in **`config/secrets.php`** (gitignored), which `app.php` loads and
merges. Deploys never touch it.

```bash
cd /var/www/html/pms
sudo cp config/secrets.example.php config/secrets.php
sudo nano config/secrets.php
```
Set the Linux values:
```php
return [
    'email'    => ['smtp_pass' => 'GMAIL_APP_PASSWORD_FOR_crm@'],   // fill to go live
    'whatsapp' => ['token'     => 'META_ACCESS_TOKEN'],             // fill to go live
    'db' => [
        'host' => '127.0.0.1', 'port' => 3306, 'name' => 'pms',
        'user' => 'pms_user',                    // from §4
        'pass' => 'CHANGE_ME_STRONG_PASSWORD',   // from §4
    ],
    'php_binary' => '/usr/bin/php',              // was C:\xampp\php\php.exe on Windows
];
```

> **Modes** (`email.mode`, `whatsapp.mode`, `pe_plan.mode`) stay in `app.php` and are
> best flipped from the **admin panel** later (§12) — it writes `config/overrides.json`.
> Keep them TEST/OFF until diagnostics pass.

### 6a. Install the service-account key
Copy your SA JSON to the server as `config/google-service-account.json`. From your laptop:
```bash
gcloud compute scp ./config/google-service-account.json pms-vm:~/gsa.json --zone asia-south1-c
# on the VM:
sudo mv ~/gsa.json /var/www/html/pms/config/google-service-account.json
```
**Shared Drive requirement:** the SA cannot upload to its own My-Drive (0 quota).
Confirm the SA is **Content manager** on the "Daily Site Reports" **Shared Drive** and
that `parent_folder_id` / `shared_drive_id` in `config/app.php` match it. Share every
Sheet the app reads/writes with the SA email too.

---

## 7. File ownership & runtime storage

Nginx/PHP-FPM (and the worker it spawns) run as **`www-data`**.
```bash
cd /var/www/html/pms
sudo mkdir -p storage/tokens storage/uploads storage/reports storage/logs storage/queue
sudo chown -R www-data:www-data /var/www/html/pms
sudo find . -type d -exec chmod 755 {} \;
sudo find . -type f -exec chmod 644 {} \;
sudo chmod -R 775 storage
sudo chmod 640 config/google-service-account.json config/secrets.php   # secrets: www-data-only
```
(If you set up CI/CD, §A4 of the CI/CD guide changes ownership to the deploy user +
`www-data` group with setgid — follow that instead.)

---

## 8. Import the database schema

Three idempotent files (`CREATE TABLE IF NOT EXISTS`) — import all three:
```bash
cd /var/www/html/pms
sudo mysql pms < db/schema.sql            # core: submissions, process_log, attachments
sudo mysql pms < db/admin_schema.sql      # admin auth (admin_users, rate_limits, audit_logs)
sudo mysql pms < db/admin_ext_schema.sql  # admin master (projects, alerts, workers, …)
sudo mysql pms -e 'SHOW TABLES;'          # verify
```

---

## 9. Nginx — serve `/pms/` + block secrets

PMS uses real `.php` paths (no front-controller rewrite needed). Point the doc root at
`/var/www/html` so `/pms/` maps to the code:
```bash
sudo tee /etc/nginx/sites-available/pms >/dev/null <<'EOF'
server {
    listen 80;
    server_name _;                 # change to your domain in §13

    root /var/www/html;
    index index.php index.html;

    client_max_body_size 80M;      # site-photo uploads

    location / {
        try_files $uri $uri/ =404;
    }

    # Convenience redirects: bare /admin -> /pms/admin/
    location = /admin  { return 301 /pms/admin/; }
    location = /admin/ { return 301 /pms/admin/; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 120;
    }

    # Block web access to secrets, runtime storage and the SQL schema
    location ~ ^/pms/(config|storage|db)/ { deny all; return 404; }
    location ~ /\.(?!well-known) { deny all; }
}
EOF

sudo ln -s /etc/nginx/sites-available/pms /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

**Verify the secret block:** `curl -s http://localhost/pms/config/secrets.php` and
`.../config/app.php` must return **403/404**, not PHP source.

---

## 10. Schedule the worker & PE-plan reminder (cron)

On Windows these are Scheduled Tasks; on Linux use **cron as `www-data`** so files it
writes match web ownership:
```bash
sudo crontab -u www-data -e
```
Add:
```cron
# Drain the submission queue every minute (core work + delayed email/WhatsApp).
* * * * * /usr/bin/php /var/www/html/pms/scripts/worker.php --once >> /var/www/html/pms/storage/logs/worker_cron.log 2>&1

# PE-plan reminder — runs every 15 min, self-gates to send once/day at send_time.
*/15 * * * * /usr/bin/php /var/www/html/pms/scripts/pe_plan_send.php >> /var/www/html/pms/storage/logs/pe_plan_cron.log 2>&1
```
Check: `sudo crontab -u www-data -l`.

> The per-submit spawn handles instant processing; this cron is the safety net that
> guarantees the queue drains and delayed notifications fire even if a spawn is missed.
> Keep the every-minute worker even with `exec()` enabled.

---

## 11. First run + diagnostics

1. **Smoke test:** open `http://SERVER_IP/pms/` — the report form loads.
2. **First admin:** open `http://SERVER_IP/pms/admin/setup.php` — create the admin
   (username + 8+ char password). Works only while `admin_users` is empty, then
   self-disables; add more from **Admin → Users**.
3. **Diagnostics** (as `www-data`, so files it writes stay web-owned):
   ```bash
   cd /var/www/html/pms
   sudo -u www-data php scripts/verify_auth.php    # SA token + a sheet read
   sudo -u www-data php scripts/check_access.php   # which sheets the SA can reach
   sudo -u www-data php scripts/check_drive.php    # Shared-Drive upload works
   sudo -u www-data php scripts/test_submit.php    # full E2E (writes + deletes a test row)
   ```
   All four should pass before trusting production submits. If `check_drive` fails,
   re-check the Shared-Drive sharing/IDs (§6a).

---

## 12. Go live with notifications

After diagnostics pass. Prefer the admin panel so changes land in
`config/overrides.json` and apply to both web + worker:

1. Fill `email.smtp_pass` + `whatsapp.token` in **`config/secrets.php`** (§6).
2. Test in isolation:
   ```bash
   sudo -u www-data php scripts/test_email.php      # email to test_to
   sudo -u www-data php scripts/test_whatsapp.php   # WhatsApp to test_to
   ```
3. In **Admin → Settings**: set **Email** + **WhatsApp** mode to `TEST` (everything to
   your test address/number), confirm, then switch to `LIVE`.
4. Confirm `notify_delay_seconds` (default 180 s — gap between PDF ready and email/WA).
5. **PE-plan reminder:** Admin → Settings → `pe_plan` mode `TEST` → `LIVE`, set
   `send_time` + recipient `numbers`. The §10 cron delivers it.

**WhatsApp document delivery:** to attach the real PDF (not a link), `whatsapp.delivery`
must be `document` **and** the `daily_site_update_doc` template must be **APPROVED**:
`sudo -u www-data php scripts/check_wa_template.php`.

---

## 13. Domain + HTTPS

The admin login sends a password, so use TLS.
1. DNS: add an **A record** `pms.vakhariaairtech.com` → `SERVER_IP`.
2. Set the server name + issue a Let's Encrypt cert:
```bash
sudo sed -i 's/server_name _;/server_name pms.vakhariaairtech.com;/' /etc/nginx/sites-available/pms
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d pms.vakhariaairtech.com   # auto-configures HTTPS + renewal
```
App is now at `https://pms.vakhariaairtech.com/pms/`. Certbot installs a renew timer.

---

## 14. Security hardening

* **Rotate the service-account key** — it was committed to git history earlier. Create a
  **new** key (GCP → IAM → Service Accounts), replace the JSON, disable the old key.
* **Rotate the SMTP app password + WhatsApp token** — also in git history. Regenerate
  both, put the new values in `config/secrets.php`.
* **Firewall / OS:** `sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw --force enable`.
  Prefer restricting SSH to your IP or use IAP TCP forwarding. `sudo apt install fail2ban`.
* **DB:** `pms_user` is not root, bound to `127.0.0.1`; keep MySQL off the public net.
* **Secrets blocked from HTTP** by §9 — verify the 403/404 curl test.
* **Admin panel:** strong password; consider IP-allowlisting `/pms/admin` in Nginx.

---

## 15. Backups & maintenance

**Nightly DB dump** (root crontab, `sudo crontab -e`; `sudo mkdir -p /var/backups` first):
```cron
0 2 * * * mysqldump pms | gzip > /var/backups/pms-$(date +\%F).sql.gz && find /var/backups -name 'pms-*.sql.gz' -mtime +14 -delete
```
**Disk snapshots:** Compute Engine → **Snapshots → Create snapshot schedule**, attach to the boot disk (daily, keep 7).

**Updating the app:** if not using CI/CD (see [docs/CICD_AUTODEPLOY.md](docs/CICD_AUTODEPLOY.md)):
```bash
cd /var/www/html/pms
sudo -u www-data git pull
sudo mysql pms < db/schema.sql   # only if schema changed (files are idempotent; review first)
sudo systemctl reload php8.3-fpm # clears OPcache so new code is live
```

**Logs:** `storage/logs/`, `storage/queue/spawn.log`, `storage/logs/worker_cron.log`;
Nginx `/var/log/nginx/error.log`; PHP `/var/log/php8.3-fpm.log`.
**Handy:** `free -h` · `swapon --show` · `sudo systemctl restart nginx php8.3-fpm mysql`.

---

## 16. Deployment checklist

- [ ] `e2-small`, **Ubuntu 24.04**, 30 GB, `asia-south1`, static IP, HTTP+HTTPS allowed
- [ ] LEMP installed; `php -m` shows curl, openssl, pdo_mysql, mbstring, **gd**, json; `exec` not disabled
- [ ] 2 GB tuning: swap on, `zz-pms.cnf`, FPM `ondemand`, `99-pms.ini` (fpm **and** cli)
- [ ] MySQL secured; `pms` DB + `'pms_user'@'127.0.0.1'` created
- [ ] Code in `/var/www/html/pms` (folder named `pms`), branch checked out
- [ ] **`config/secrets.php`** set: db creds + `php_binary=/usr/bin/php` (+ secrets when going live)
- [ ] `config/google-service-account.json` in place; SA is Content-manager on Shared Drive + shares the Sheets
- [ ] `storage/*` dirs created, `www-data`-owned; `secrets.php` + SA key `chmod 640`
- [ ] All 3 SQL schemas imported (`SHOW TABLES` looks right)
- [ ] Nginx `pms` site enabled, default removed; **403/404** on `config/`, `storage/`, `db/`
- [ ] Cron (as `www-data`): worker every min + pe_plan every 15 min
- [ ] `http://IP/pms/` loads; `admin/setup.php` first admin created
- [ ] `verify_auth` / `check_access` / `check_drive` / `test_submit` all pass
- [ ] Email + WhatsApp tested, then flipped to LIVE in admin
- [ ] Domain + Let's Encrypt HTTPS (`certbot --nginx`)
- [ ] SA key + SMTP pass + WA token rotated; SSH restricted; nightly DB dump + disk snapshots
```
