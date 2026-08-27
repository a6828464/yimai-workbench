#!/usr/bin/env bash
set -Eeuo pipefail

SITE_ROOT="/www/wwwroot/oa.nbyimai.com"
APP_ROOT="$SITE_ROOT/backend"
WORK_ROOT="$SITE_ROOT/.update-work"
PACKAGE_URL="https://github.com/a6828464/yimai-workbench/releases/download/auto-latest/yimai-workbench-latest.zip"

rm -rf "$WORK_ROOT"
mkdir -p "$WORK_ROOT"
trap 'rm -rf "$WORK_ROOT"' EXIT

curl --fail --location --retry 3 --connect-timeout 15 --max-time 300 \
  "$PACKAGE_URL" -o "$WORK_ROOT/release.zip"
unzip -q "$WORK_ROOT/release.zip" -d "$WORK_ROOT/unpacked"
RELEASE_ROOT="$WORK_ROOT/unpacked/app"

# Preserve production-only files while replacing application code and built assets.
rsync -a --delete \
  --exclude '.env' \
  --exclude 'storage/' \
  --exclude 'bootstrap/cache/' \
  --exclude 'database/database.sqlite*' \
  "$RELEASE_ROOT/backend/" "$APP_ROOT/"

cd "$APP_ROOT"
php artisan migrate --force
php artisan optimize:clear
chown -R www:www "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache"

echo "更新完成：$COMMIT"
