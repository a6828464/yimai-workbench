#!/usr/bin/env bash
set -Eeuo pipefail

SITE_ROOT="/www/wwwroot/oa.nbyimai.com"
APP_ROOT="$SITE_ROOT/backend"
WORK_ROOT="$SITE_ROOT/.update-work"
GITEE_API="https://gitee.com/api/v5/repos/meng-taoo/yimai-workbench/releases/latest"
GITHUB_PACKAGE_URL="https://github.com/a6828464/yimai-workbench/releases/download/auto-latest/yimai-workbench-latest.zip"

rm -rf "$WORK_ROOT"
mkdir -p "$WORK_ROOT"
trap 'rm -rf "$WORK_ROOT"' EXIT

PACKAGE_URL="$(curl --fail --silent --show-error --location --connect-timeout 15 --max-time 30 "$GITEE_API" \
  | python3 -c 'import json,sys; d=json.load(sys.stdin); print(next((a["browser_download_url"] for a in d.get("assets",[]) if a.get("name")=="yimai-workbench-latest.zip"), ""))' || true)"
if [ -z "$PACKAGE_URL" ]; then PACKAGE_URL="$GITHUB_PACKAGE_URL"; fi
curl --fail --location --retry 3 --connect-timeout 15 --max-time 300 \
  "$PACKAGE_URL" -o "$WORK_ROOT/release.zip"
unzip -q "$WORK_ROOT/release.zip" -d "$WORK_ROOT/unpacked"
RELEASE_ROOT="$WORK_ROOT/unpacked/app"

# Preserve production-only files while replacing application code and built assets.
# 发布包不携带 vendor，保留服务器现有生产依赖。
rsync -a \
  --exclude '.env' \
  --exclude 'storage/' \
  --exclude 'bootstrap/cache/' \
  --exclude 'database/database.sqlite*' \
  "$RELEASE_ROOT/backend/" "$APP_ROOT/"

cd "$APP_ROOT"
php artisan migrate --force
php artisan optimize:clear
chown -R www:www "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache"

echo "更新完成"
