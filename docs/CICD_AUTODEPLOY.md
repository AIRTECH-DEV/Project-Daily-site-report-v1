# Automatic Deployment (CI/CD) — push to `main`, it goes live

Every push/merge to **`main`** makes the GCP VM **pull the new code and reload PHP** — no manual SSH, no forgetting a step. Two options; **start with Option A (GitHub Actions)** — simplest for a single VM.

| | **Option A — GitHub Actions (recommended)** | **Option B — Google Cloud Build trigger** |
|---|---|---|
| How | GitHub connects to the VM over SSH, runs a deploy script | Cloud Build runs on each push, deploys via IAP/SSH |
| Setup | ⭐ Simple | More involved (IAM roles + IAP tunneling) |
| Logs | GitHub Actions tab | Cloud Build history |

This assumes the app is deployed per **[DEPLOY_GCP.md](../DEPLOY_GCP.md)** at `/var/www/html/pms`, served under `/pms/`, with **Nginx + PHP 8.3-FPM** (see README §10.1 for why Nginx). If you deployed on **Apache + mod_php**, replace every `php8.3-fpm` reload below with `apache2` (`sudo systemctl reload apache2`).

---

## 0. Protect server-side secrets & runtime FIRST (do not skip)

`git reset --hard origin/main` makes the server match `main` **exactly** — so anything tracked in git gets overwritten. Secrets and runtime therefore live **outside** git and must be **gitignored** so a deploy can never clobber (or leak) them:

- `config/secrets.php` — SMTP app password + WhatsApp token **and** server-local infra (DB creds, `php_binary`) — set once per server
- `config/google-service-account.json` — the Google key
- `config/overrides.json` — admin-panel runtime settings
- `storage/**` — tokens, uploads, generated PDFs, logs, job queue

`config/app.php` **stays tracked** — it holds only non-secret config (Sheet IDs, tabs, modes, tunables) and loads `config/secrets.php` at runtime to fill the blank secrets. You version `app.php` and change it via git + push.

On your laptop, in the repo, ensure `.gitignore` has:
```gitignore
# Server-side secrets & runtime — never deploy these
/config/secrets.php
/config/google-service-account.json
/config/overrides.json
/storage/tokens/
/storage/uploads/
/storage/reports/
/storage/logs/
/storage/queue/
```
On the **server**, set `config/secrets.php` **once** and forget it:
```bash
cp config/secrets.example.php config/secrets.php
nano config/secrets.php    # paste the real smtp_pass + whatsapp token
```
Because it's gitignored, `git reset --hard` leaves it untouched forever.

> ⚠️ **Never hand-edit `config/app.php` on the server** — a deploy's `git reset --hard` overwrites it. Change Sheet IDs / modes / tunables in git (commit → push → auto-deploy). Only `config/secrets.php` is server-local.

---

# Option A — GitHub Actions (recommended)

### A1. Create a deploy SSH key (on your laptop)
```bash
ssh-keygen -t ed25519 -C "pms-deploy" -f pms_deploy_key -N ""
```
Makes `pms_deploy_key` (private) + `pms_deploy_key.pub` (public).

### A2. Authorize the key on the VM
SSH in (Compute Engine → SSH button), paste the **public** key:
```bash
mkdir -p ~/.ssh && chmod 700 ~/.ssh
echo "PASTE-CONTENTS-OF-pms_deploy_key.pub-HERE" >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
whoami   # note this SSH username
```

### A3. Allow the deploy user to reload PHP without a password
```bash
echo "$(whoami) ALL=(ALL) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm, /usr/bin/systemctl reload nginx" | sudo tee /etc/sudoers.d/pms-deploy
sudo chmod 440 /etc/sudoers.d/pms-deploy
```

### A4. Make the repo writable by the deploy user (while www-data still reads it)
```bash
sudo chown -R $(whoami):www-data /var/www/html/pms
sudo find /var/www/html/pms -type d -exec chmod 2775 {} \;   # setgid: new files inherit www-data group
sudo find /var/www/html/pms -type f -exec chmod 664 {} \;
sudo chmod -R 2775 /var/www/html/pms/storage
sudo chown -R www-data:www-data /var/www/html/pms/storage
sudo chmod 640 /var/www/html/pms/config/google-service-account.json
git config --global --add safe.directory /var/www/html/pms
```

### A5. Add the deploy script to the repo — `scripts/deploy.sh`
```bash
#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/var/www/html/pms"
cd "$APP_DIR"

echo "→ Fetching latest main…"
git fetch --all
git reset --hard origin/main        # exact match to main (gitignored secrets untouched)

echo "→ Fixing permissions…"
find "$APP_DIR" -type d -exec chmod 2775 {} \;
find "$APP_DIR" -type f -exec chmod 664 {} \;
chmod -R 2775 "$APP_DIR/storage" || true
chmod 640 "$APP_DIR/config/google-service-account.json" || true

echo "→ Reloading PHP-FPM (clears OPcache so new code is live)…"
sudo /usr/bin/systemctl reload php8.3-fpm
# On Apache+mod_php instead:  sudo /usr/bin/systemctl reload apache2

echo "✅ Deploy complete: $(git rev-parse --short HEAD)"
```
```bash
chmod +x scripts/deploy.sh
git add scripts/deploy.sh .gitignore
git commit -m "Add CI/CD deploy script"
```

> The worker + PE-plan **cron jobs need no restart** — they run the freshly-pulled `scripts/*.php` on their next tick. The deploy does **not** run DB migrations (see A9).

### A6. Store secrets in GitHub
Repo → **Settings → Secrets and variables → Actions → New repository secret**:

| Secret | Value |
|---|---|
| `SSH_HOST` | the VM's static IP |
| `SSH_USER` | the SSH username from A2 (`whoami`) |
| `SSH_PRIVATE_KEY` | the **entire** contents of `pms_deploy_key` (private) |

### A7. Add the workflow — `.github/workflows/deploy.yml`
```yaml
name: Deploy PMS to GCP

on:
  push:
    branches: [ main ]
  workflow_dispatch: {}

concurrency:
  group: production-deploy
  cancel-in-progress: false

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - name: Deploy over SSH
        uses: appleboy/ssh-action@v1.2.0
        with:
          host: ${{ secrets.SSH_HOST }}
          username: ${{ secrets.SSH_USER }}
          key: ${{ secrets.SSH_PRIVATE_KEY }}
          script: |
            bash /var/www/html/pms/scripts/deploy.sh
```
```bash
git add .github/workflows/deploy.yml
git commit -m "Add GitHub Actions auto-deploy"
git push origin main
```

### A8. Watch it work
Repo **Actions** tab → "Deploy PMS to GCP" goes green. From now on every merge to `main` deploys automatically.

### A9. When a change includes a DB schema/migration
The deploy syncs **code only**. The `db/*.sql` files use `CREATE TABLE IF NOT EXISTS` (idempotent), so after a deploy that changed them, apply once over SSH:
```bash
sudo mysql pms < /var/www/html/pms/db/schema.sql
sudo mysql pms < /var/www/html/pms/db/admin_schema.sql
sudo mysql pms < /var/www/html/pms/db/admin_ext_schema.sql
```
(Adding a **column** to an existing table is not idempotent — run any such `ALTER` exactly once.)

> **Firewall note:** GitHub runners use changing IPs, so SSH (22) must be reachable. GCP's `default-allow-ssh` covers this; it's protected by key-only auth + `fail2ban`. To harden, restrict 22 to [GitHub's IP ranges](https://api.github.com/meta) (optional at this scale).

---

# Option B — Google Cloud Build trigger

Keeps everything inside Google Cloud: Cloud Build watches the repo and, on push to `main`, SSHes to the VM through IAP and runs the same `scripts/deploy.sh`.

### B1. Enable APIs
```bash
gcloud services enable cloudbuild.googleapis.com iap.googleapis.com compute.googleapis.com
```

### B2. Connect the GitHub repo
Console → **Cloud Build → Triggers → Connect repository** → GitHub (Cloud Build GitHub App) → authorize → pick the PMS repo → Connect.

### B3. Give Cloud Build access to the VM (IAP SSH)
```bash
PROJECT_ID=$(gcloud config get-value project)
PROJECT_NUMBER=$(gcloud projects describe "$PROJECT_ID" --format='value(projectNumber)')
CB_SA="$PROJECT_NUMBER@cloudbuild.gserviceaccount.com"

for ROLE in roles/compute.instanceAdmin.v1 roles/iap.tunnelResourceAccessor roles/iam.serviceAccountUser roles/compute.viewer roles/compute.osLogin; do
  gcloud projects add-iam-policy-binding "$PROJECT_ID" --member="serviceAccount:$CB_SA" --role="$ROLE"
done

gcloud compute firewall-rules create allow-iap-ssh \
  --direction=INGRESS --action=ALLOW --rules=tcp:22 --source-ranges=35.235.240.0/20
```

### B4. Add `cloudbuild.yaml` (repo root)
```yaml
steps:
  - name: 'gcr.io/cloud-builders/gcloud'
    id: 'Deploy to VM'
    entrypoint: 'bash'
    args:
      - '-c'
      - |
        gcloud compute ssh pms-vm \
          --zone=asia-south1-a \
          --tunnel-through-iap \
          --command="bash /var/www/html/pms/scripts/deploy.sh"
options:
  logging: CLOUD_LOGGING_ONLY
```

### B5. Create the trigger
Cloud Build → **Triggers → Create trigger** → Event: *Push to a branch* → Branch: `^main$` → Configuration: `cloudbuild.yaml` → Create. Push to `main` → Cloud Build **History** shows the run.

> For a single VM, **Option A is materially simpler** — prefer it unless your org mandates Cloud Build.

---

## The full loop & rollback

```
work on v10 → PR v10→main → merge → GitHub Actions → SSH → scripts/deploy.sh
   • git reset --hard origin/main   • fix perms   • reload php8.3-fpm (clear OPcache)
→ https://pms.vakhariaairtech.com/pms/  runs the new code ✅
```

**Rollback a bad deploy** (on the VM):
```bash
cd /var/www/html/pms && git reset --hard <previous-good-commit> && sudo systemctl reload php8.3-fpm
```
…or just revert the merge on GitHub — the next auto-deploy restores the previous good state.
