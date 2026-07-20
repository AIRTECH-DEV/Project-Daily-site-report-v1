# PMS — Deploy to Google Cloud (GCP) — Full Step-by-Step Guide

This guide takes the PMS / Site-Visit-Report PHP app from XAMPP-on-Windows to a
production **Google Compute Engine** Linux VM (Apache + PHP + MariaDB), with the
background worker and PE-plan reminder running as **cron** jobs (they run as
Windows Scheduled Tasks locally).

The app is a normal LAMP app with a few specifics you must respect:

* It is served under a **`/pms/` URL path** (admin paths are hardcoded as
  `/pms/admin` and `/pms/assets/assets`). Keep the app in a folder named `pms`
  under the web root — do **not** rename it.
* It needs a **Google service-account key** and a **Shared Drive** (photos/PDFs
  can't go on the SA's My-Drive — 0 quota).
* Outbound it talks to **Gmail SMTP (port 587)** and **Meta WhatsApp Cloud API
  (HTTPS 443)** — both allowed on GCP by default. (GCP blocks outbound port 25;
  we use 587, so email is fine.)
* PDF generation uses FPDF; the PE-plan reminder renders text on an image, so
  **php-gd with FreeType** is required.

---

## 0. Which GCP instance should you buy?

This is a **low-traffic internal tool** (a handful of engineers submitting site
reports). Photos and PDFs live in Google Drive, not on the VM, so local disk and
RAM needs are modest. The only real work on the box is PHP + MariaDB + occasional
PDF/image generation.

### Recommendation

| Instance | vCPU / RAM | Approx cost* | Verdict |
|---|---|---|---|
| `e2-micro` | 2 vCPU (shared burst) / **1 GB** | Free-tier eligible** | Works only with 2 GB swap; tight for MariaDB + Apache. Fine to trial, not ideal for prod. |
| **`e2-small`** ⭐ | 2 vCPU (shared burst) / **2 GB** | **~$13–15 / month** | **Buy this.** Comfortable for LAMP + PDF + worker at this load. |
| `e2-medium` | 2 vCPU / **4 GB** | ~$25–27 / month | Only if you add many more users or heavier reporting. |

\* On-demand pricing in **asia-south1 (Mumbai)**, VM only, before any committed-use
discount. Add ~$3–4/mo for the 30 GB balanced disk. Check the
[GCP pricing calculator](https://cloud.google.com/products/calculator) for exact,
current numbers.

\*\* GCP's Always-Free `e2-micro` is only free in **us-west1 / us-central1 /
us-east1** (US). Your users and phone numbers are in India (`Asia/Kolkata`), so a
US VM adds ~250 ms latency. For production, prefer a **paid `e2-small` in Mumbai**
over a free US micro.

### Final spec to order

* **Machine type:** `e2-small` (2 GB RAM)
* **Region / zone:** `asia-south1` / `asia-south1-a` (Mumbai — closest to your users)
* **Boot disk:** **Debian 12 (bookworm)**, **30 GB**, `pd-balanced`
* **Networking:** allow HTTP + HTTPS, reserve a **static external IP**

> Prefer Ubuntu? Ubuntu 22.04/24.04 LTS works the same; package names are nearly
> identical. This guide uses **Debian 12** commands.

---

## 1. Create the GCP project & VM

### 1a. Project + billing (one-time)

1. Go to <https://console.cloud.google.com>.
2. Create a project (e.g. `pms-prod`) and **link a billing account**.
3. Enable the **Compute Engine API** (Console → APIs & Services → Enable, or it
   prompts you the first time you open Compute Engine).

### 1b. Reserve a static IP (so the address never changes)

Console → **VPC network → IP addresses → Reserve external static address**
(Region: `asia-south1`). Or with the CLI (see 1d).

### 1c. Create the VM (Console)

Console → **Compute Engine → VM instances → Create instance**:

* **Name:** `pms-vm`
* **Region / Zone:** `asia-south1` / `asia-south1-a`
* **Machine type:** `e2-small`
* **Boot disk:** Change → **Debian 12**, **Balanced**, **30 GB** → Select
* **Firewall:** tick **Allow HTTP traffic** and **Allow HTTPS traffic**
* **Networking → Network interfaces → External IPv4:** pick the static IP from 1b
* Create.

### 1d. Or create everything with the gcloud CLI (faster)

Run in Cloud Shell (top-right terminal icon in the console) or a local
[gcloud install](https://cloud.google.com/sdk/docs/install):

```bash
gcloud config set project pms-prod
gcloud config set compute/region asia-south1
gcloud config set compute/zone asia-south1-a

# Reserve a static IP
gcloud compute addresses create pms-ip --region asia-south1

# Create the VM (attaches the static IP, opens 80/443 via tags)
gcloud compute instances create pms-vm \
  --machine-type=e2-small \
  --image-family=debian-12 --image-project=debian-cloud \
  --boot-disk-size=30GB --boot-disk-type=pd-balanced \
  --address=$(gcloud compute addresses describe pms-ip --region asia-south1 --format='value(address)') \
  --tags=http-server,https-server

# Ensure firewall rules for the tags exist (usually auto-created)
gcloud compute firewall-rules create allow-http  --allow tcp:80  --target-tags=http-server  2>/dev/null || true
gcloud compute firewall-rules create allow-https --allow tcp:443 --target-tags=https-server 2>/dev/null || true
```

### 1e. SSH into the VM

Console: click **SSH** next to the instance. Or CLI:

```bash
gcloud compute ssh pms-vm --zone asia-south1-a
```

All remaining commands run **inside the VM** unless noted.

---

## 2. Install the LAMP stack

```bash
sudo apt update && sudo apt -y upgrade

# Apache + PHP 8.2 (Debian 12 default) + required extensions + MariaDB + tools
sudo apt -y install \
  apache2 \
  php php-cli php-curl php-mysql php-mbstring php-gd php-xml php-zip php-bcmath \
  libapache2-mod-php \
  mariadb-server \
  git unzip certbot python3-certbot-apache
```

Extension check — all of these must show up:

```bash
php -m | grep -Ei 'curl|openssl|pdo_mysql|mbstring|^gd$|json'
```

* `curl` → Google API + WhatsApp calls
* `openssl` → signs the service-account JWT (built into PHP core)
* `pdo_mysql` → DB tracker/audit
* `mbstring` → text handling
* `gd` (with FreeType) → PE-plan reminder image + PDF glyphs

Enable Apache rewrite (harmless, and future-proof) and restart:

```bash
sudo a2enmod rewrite
sudo systemctl enable --now apache2
```

---

## 3. Secure MariaDB & create the database

```bash
sudo mysql_secure_installation
```
Answer: set a root password (or keep unix_socket auth), remove anonymous users,
disallow remote root, remove test DB, reload privileges.

Create the app database and a dedicated user (do **not** reuse root for the app):

```bash
sudo mysql <<'SQL'
CREATE DATABASE IF NOT EXISTS pms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'pms_user'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON pms.* TO 'pms_user'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
```

> Keep MariaDB on the default **port 3306**. (On the Windows dev box, a stray
> MySQL 8 on 33060 could shadow it — that's a Windows-only gotcha; a clean Debian
> box won't have it.)

---

## 4. Deploy the application code

Target directory: **`/var/www/html/pms`** (so the app answers at
`http://SERVER/pms/`, matching the hardcoded `/pms/` paths).

### Option A — git (recommended)

```bash
cd /var/www/html
sudo git clone <YOUR_REPO_URL> pms
cd pms
sudo git checkout <branch>   # e.g. main, or your release branch
```

### Option B — copy from your Windows machine

From your **local machine** (PowerShell/Terminal), upload everything **except**
`storage/`, `.git/`, and `vendor/` cache noise. `vendor/fpdf` **is** needed and is
committed, so include it. Then on the VM move it into place:

```bash
# local → VM home dir, then move into web root on the VM
gcloud compute scp --recurse ./pms pms-vm:~/pms --zone asia-south1-a
# on the VM:
sudo rm -rf /var/www/html/pms && sudo mv ~/pms /var/www/html/pms
```

### Files that are NOT in git — you must add them by hand

These are `.gitignored` (secrets/runtime). Create them on the server:

* `config/google-service-account.json` — the SA private key (see §6).
* `config/overrides.json` — optional; the admin panel writes it. Skip for now.
* `storage/…` runtime dirs — created in §7.

---

## 5. Point Apache at the app & tune PHP

### 5a. Make `/pms/` load `index.php` and keep the doc root at `/var/www/html`

The default Debian vhost already serves `/var/www/html`, so
`http://SERVER/pms/` works out of the box once the code is in
`/var/www/html/pms`. Just make sure `DirectoryIndex` includes `index.php`
(it does by default) and `.htaccess`/overrides are allowed:

```bash
sudo tee /etc/apache2/conf-available/pms.conf >/dev/null <<'CONF'
<Directory /var/www/html/pms>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
    DirectoryIndex index.php
</Directory>

# Block direct web access to secrets and CLI-only areas
<DirectoryMatch "^/var/www/html/pms/(config|storage|db)/">
    Require all denied
</DirectoryMatch>
CONF

sudo a2enconf pms
sudo systemctl reload apache2
```

> The `DirectoryMatch` block stops the outside world from fetching
> `config/google-service-account.json`, the DB schema, or queued jobs over HTTP.
> The diagnostic scripts in `scripts/` are meant to be run from the CLI, not the
> browser; leave them, but they don't expose secrets on their own.

### 5b. PHP settings (uploads + timezone + execution time)

Site-visit submits carry photos, so raise the upload limits and set the timezone:

```bash
PHPINI=$(php -r 'echo php_ini_loaded_file();' | sed 's#/cli/#/apache2/#')  # the Apache php.ini
sudo sed -i \
  -e 's/^;*date.timezone.*/date.timezone = Asia\/Kolkata/' \
  -e 's/^upload_max_filesize.*/upload_max_filesize = 64M/' \
  -e 's/^post_max_size.*/post_max_size = 80M/' \
  -e 's/^max_execution_time.*/max_execution_time = 120/' \
  -e 's/^;*max_file_uploads.*/max_file_uploads = 50/' \
  "$PHPINI"
```

Also set the CLI php.ini timezone (used by the worker/cron):

```bash
CLIINI=$(php -r 'echo php_ini_loaded_file();')
sudo sed -i 's/^;*date.timezone.*/date.timezone = Asia\/Kolkata/' "$CLIINI"
```

**Important — the background worker needs `exec()`/`popen()`.** After a submit,
the web request spawns the worker (`src/Spawn.php`). Confirm neither `exec` nor
`popen` is in `disable_functions`:

```bash
php -r 'echo ini_get("disable_functions") ?: "(none)"; echo "\n";'
```
If they're disabled, the cron safety net in §8 still drains the queue — but leave
them enabled for instant processing.

Restart Apache to load the ini changes:

```bash
sudo systemctl restart apache2
```

---

## 6. Configure the app (`config/app.php`)

Edit `/var/www/html/pms/config/app.php`. Change these from the dev values:

```php
// Point PHP CLI at the Linux binary (was C:\xampp\php\php.exe on Windows)
'php_binary' => '/usr/bin/php',

'db' => [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'pms',
    'user' => 'pms_user',                 // the app user from §3
    'pass' => 'CHANGE_ME_STRONG_PASSWORD',// the password you set
    'charset' => 'utf8mb4',
],

'timezone' => 'Asia/Kolkata',   // already correct
```

**Secrets** — set these when you're ready to go live (keep TEST/OFF until then):

* `email.smtp_pass` → Gmail **app password** for `crm@vakhariaairtech.com`.
* `whatsapp.token` → Meta access token.
* Notification **modes** (`email.mode`, `whatsapp.mode`, `pe_plan.mode`) are best
  flipped from the **admin panel** later (§10), which writes `config/overrides.json`.

### 6a. Install the Google service-account key

Copy your SA JSON to the server as `config/google-service-account.json`. From your
local machine:

```bash
gcloud compute scp ./config/google-service-account.json \
  pms-vm:~/google-service-account.json --zone asia-south1-a
# on the VM:
sudo mv ~/google-service-account.json /var/www/html/pms/config/google-service-account.json
```

**Shared Drive requirement:** the SA cannot upload to its own My-Drive (0 quota).
Confirm the SA (`service-data-syncer@…gserviceaccount.com`) is added as
**Content manager** on the "Daily Site Reports" **Shared Drive** and that
`parent_folder_id` / `shared_drive_id` in `config/app.php` match it. Also share
the Sheets the app reads/writes with the SA email.

---

## 7. File ownership & runtime storage

Apache (and the worker it spawns) runs as **`www-data`**. It must own the code and
be able to write `storage/` and `config/overrides.json`.

```bash
cd /var/www/html/pms

# Create the runtime dirs the app writes to
sudo mkdir -p storage/tokens storage/uploads storage/reports storage/logs storage/queue

# Ownership: www-data owns everything
sudo chown -R www-data:www-data /var/www/html/pms

# Sensible perms: dirs 755, files 644, storage writable
sudo find . -type d -exec chmod 755 {} \;
sudo find . -type f -exec chmod 644 {} \;
sudo chmod -R 775 storage
# The SA key: readable by www-data only
sudo chmod 640 config/google-service-account.json
```

If cron runs the worker as `www-data` (recommended, §8), ownership above is all
you need. If you ever run a script as your login user for testing, use `sudo -u
www-data php scripts/…` so it writes files `www-data` can later read.

---

## 8. Import the database schema

The repo ships three SQL files — import all three:

```bash
cd /var/www/html/pms
sudo mysql pms < db/schema.sql            # core: submissions + process_log
sudo mysql pms < db/admin_schema.sql      # admin panel (users, audit, …)
sudo mysql pms < db/admin_ext_schema.sql  # admin extensions
```

Verify tables exist:

```bash
sudo mysql pms -e 'SHOW TABLES;'
```

---

## 9. Schedule the worker & PE-plan reminder (cron replaces the Windows tasks)

On Windows these are Scheduled Tasks (`register_worker_task.ps1`,
`register_pe_plan_task.ps1`). On Linux, use **cron running as `www-data`** so the
files it writes match web ownership:

```bash
sudo crontab -u www-data -e
```

Add:

```cron
# Drain the submission queue every minute (core work + delayed email/WhatsApp).
* * * * * /usr/bin/php /var/www/html/pms/scripts/worker.php --once >> /var/www/html/pms/storage/logs/worker_cron.log 2>&1

# PE-plan reminder — runs every 15 min but self-gates to send once/day at send_time.
*/15 * * * * /usr/bin/php /var/www/html/pms/scripts/pe_plan_send.php >> /var/www/html/pms/storage/logs/pe_plan_cron.log 2>&1
```

Check it registered:

```bash
sudo crontab -u www-data -l
```

> The per-submit spawn (§5b) handles instant processing; this cron is the safety
> net that guarantees the queue drains and the delayed notifications fire even if
> a spawn is missed. Keep the every-minute worker even if `exec()` is enabled.

---

## 10. First run, admin setup & diagnostics

### 10a. Smoke test the site

Open `http://YOUR_STATIC_IP/pms/` — the report form should load.

### 10b. Create the first admin account

Open `http://YOUR_STATIC_IP/pms/admin/setup.php`. This page works **only while
`admin_users` is empty** — create your admin (username + 8+ char password). After
that it self-disables; add more admins from **Admin → Users**.

### 10c. Run the diagnostics (from the VM, as www-data)

```bash
cd /var/www/html/pms
sudo -u www-data php scripts/verify_auth.php   # SA token + a sheet read
sudo -u www-data php scripts/check_access.php  # which sheets the SA can reach
sudo -u www-data php scripts/check_drive.php   # Shared-Drive upload works
sudo -u www-data php scripts/test_submit.php   # full end-to-end (writes+deletes a test row)
```

All four should pass before you trust production submits. If `check_drive` fails,
re-check the Shared Drive sharing/IDs (§6a).

---

## 11. Go live with notifications

Do this **after** the diagnostics pass. Prefer the admin panel so changes land in
`config/overrides.json` and apply to both web and worker:

1. Fill secrets in `config/app.php`: `email.smtp_pass`, `whatsapp.token`.
2. Test in isolation first:
   ```bash
   sudo -u www-data php scripts/test_email.php      # email to test_to
   sudo -u www-data php scripts/test_whatsapp.php   # WhatsApp to test_to
   ```
3. In **Admin → Settings**: set **Email mode** and **WhatsApp mode** to `TEST`
   (everything to your test address/number) and confirm, then switch to `LIVE`.
4. Confirm/adjust `notify_delay_seconds` (default 180 s — the gap between the PDF
   being ready and the email/WhatsApp going out).
5. **PE-plan reminder:** in Admin → Settings set `pe_plan` mode `TEST` → `LIVE`,
   the `send_time`, and the recipient `numbers`. The §9 cron delivers it.

**WhatsApp document delivery:** to attach the real PDF (not just a link),
`whatsapp.delivery` must be `document` **and** the `daily_site_update_doc`
document-header template must be **APPROVED**. Check with
`sudo -u www-data php scripts/check_wa_template.php`.

---

## 12. Add a domain + HTTPS (recommended)

Running on a bare IP works, but a domain with TLS is safer (the admin login sends
a password).

1. In your DNS provider, add an **A record** → your static IP
   (e.g. `pms.vakhariaairtech.com`).
2. Set the Apache ServerName and issue a Let's Encrypt cert:

```bash
sudo sed -i 's/#ServerName.*/ServerName pms.vakhariaairtech.com/' /etc/apache2/sites-available/000-default.conf
sudo systemctl reload apache2
sudo certbot --apache -d pms.vakhariaairtech.com   # auto-configures HTTPS + renewal
```

Now the app is at `https://pms.vakhariaairtech.com/pms/`. Certbot installs a renew
timer automatically.

---

## 13. Security hardening (do this)

* **Rotate the service-account key.** The old key was committed to git history
  earlier. Create a **new key** in GCP → IAM → Service Accounts, replace
  `config/google-service-account.json`, and disable the old key. Purge the key
  from git history if the repo is shared.
* **Firewall:** only 80/443 (public) and 22 (SSH) should be open. Prefer restricting
  SSH to your IP, or use **IAP TCP forwarding** instead of a public port 22.
* **DB:** the app user (`pms_user`) is not root and is bound to `127.0.0.1`; MariaDB
  isn't exposed publicly. Keep it that way (no `0.0.0.0` bind, no external 3306
  firewall rule).
* **Secrets in `config/`** are blocked from HTTP by the §5a `DirectoryMatch`. Verify:
  `curl -s http://YOUR_IP/pms/config/app.php` should return **403**, not PHP source.
* **Admin panel:** strong password; consider IP-allowlisting `/pms/admin` in Apache
  if only office IPs need it.
* Optional: `sudo apt install fail2ban` to throttle SSH brute force.

---

## 14. Backups & maintenance

**Nightly DB dump** (add to root's crontab, `sudo crontab -e`):

```cron
0 2 * * * mysqldump pms | gzip > /var/backups/pms-$(date +\%F).sql.gz && find /var/backups -name 'pms-*.sql.gz' -mtime +14 -delete
```
(`sudo mkdir -p /var/backups` first.)

**Disk snapshots:** Console → Compute Engine → **Snapshots → Create snapshot
schedule**, attach it to the VM's boot disk (e.g. daily, keep 7).

**Updates:** `sudo apt update && sudo apt upgrade` periodically; certbot renews TLS
automatically.

**Logs to watch:**
* App/worker: `storage/logs/`, `storage/queue/spawn.log`, `storage/logs/worker_cron.log`
* Apache: `/var/log/apache2/error.log`
* PHP errors surface in the Apache error log.

**Updating the app later:**
```bash
cd /var/www/html/pms
sudo -u www-data git pull
sudo mysql pms < db/schema.sql   # only if schema changed (files are idempotent-ish; review first)
sudo systemctl reload apache2
```

---

## 15. Quick deployment checklist

- [ ] `e2-small`, Debian 12, 30 GB, `asia-south1`, static IP, HTTP+HTTPS allowed
- [ ] LAMP installed; `php -m` shows curl, openssl, pdo_mysql, mbstring, gd
- [ ] MariaDB secured; `pms` DB + `pms_user` created
- [ ] Code in `/var/www/html/pms` (folder named `pms`)
- [ ] `config/app.php`: `php_binary=/usr/bin/php`, DB creds, timezone
- [ ] `config/google-service-account.json` in place; SA on Shared Drive + Sheets
- [ ] `storage/*` dirs created; owned by `www-data`; SA key `chmod 640`
- [ ] Apache `pms.conf` enabled; secrets blocked (403 test passes)
- [ ] PHP upload limits + timezone set; `exec` not disabled
- [ ] All 3 SQL schemas imported
- [ ] Cron (as `www-data`): worker every min + pe_plan every 15 min
- [ ] `http://IP/pms/` loads; `admin/setup.php` first admin created
- [ ] `verify_auth` / `check_access` / `check_drive` / `test_submit` all pass
- [ ] Email + WhatsApp tested, then flipped to LIVE in admin
- [ ] Domain + Let's Encrypt HTTPS
- [ ] SA key rotated; SSH restricted; nightly DB dump + disk snapshots
```
