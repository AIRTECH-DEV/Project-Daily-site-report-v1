#!/usr/bin/env bash
# PMS deploy — pull main, fix perms, reload PHP-FPM. Invoked by GitHub Actions
# over SSH (.github/workflows/deploy.yml), or run by hand on the VM.
set -euo pipefail

APP_DIR="/var/www/html/pms"
cd "$APP_DIR"

echo "-> Fetching latest main..."
git fetch --all --prune
git reset --hard origin/main        # exact match to main; gitignored secrets untouched

echo "-> Fixing permissions..."
find "$APP_DIR" -type d -exec chmod 2775 {} \;
find "$APP_DIR" -type f -exec chmod 664 {} \;
chmod -R 2775 "$APP_DIR/storage" || true
chmod 640 "$APP_DIR/config/google-service-account.json" "$APP_DIR/config/secrets.php" 2>/dev/null || true

echo "-> Reloading PHP-FPM (clears OPcache so new code is live)..."
sudo /usr/bin/systemctl reload php8.3-fpm

echo "OK deploy complete: $(git rev-parse --short HEAD)"
