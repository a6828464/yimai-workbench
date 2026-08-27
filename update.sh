#!/usr/bin/env bash
set -Eeuo pipefail

SITE_ROOT="/www/wwwroot/oa.nbyimai.com"
APP_ROOT="$SITE_ROOT/backend"
WORK_ROOT="$SITE_ROOT/.update-work"
REPO_URL="https://github.com/a6828464/yimai-workbench.git"

rm -rf "$WORK_ROOT"
mkdir -p "$WORK_ROOT"
trap 'rm -rf "$WORK_ROOT"' EXIT

git clone --depth 1 --branch main "$REPO_URL" "$WORK_ROOT/repo"

cd "$WORK_ROOT/repo/admin-web"
if command -v pnpm >/dev/null 2>&1; then
  pnpm install --frozen-lockfile --silent
  pnpm build
elif command -v npm >/dev/null 2>&1; then
  npm install --no-audit --no-fund
  npm run build
else
  echo "未找到 pnpm 或 npm，无法构建前端" >&2
  exit 1
fi

cd "$WORK_ROOT/repo"
BRANCH="$(git rev-parse --abbrev-ref HEAD)"
COMMIT="$(git rev-parse HEAD)"
MESSAGE="$(git log -1 --pretty=%s)"
DATE="$(git log -1 --pretty=%ci)"
printf '{"branch":"%s","commit":"%s","message":"%s","date":"%s"}\n' \
  "$BRANCH" "$COMMIT" "$MESSAGE" "$DATE" > "$WORK_ROOT/repo/version.json"

# Preserve production-only files while replacing application code and built assets.
rsync -a --delete \
  --exclude '.env' \
  --exclude 'storage/' \
  --exclude 'bootstrap/cache/' \
  --exclude 'database/database.sqlite*' \
  "$WORK_ROOT/repo/backend/" "$APP_ROOT/"
rsync -a "$WORK_ROOT/repo/admin-web/dist/" "$APP_ROOT/public/"
cp "$WORK_ROOT/repo/version.json" "$APP_ROOT/version.json"
cp "$WORK_ROOT/repo/CHANGELOG.md" "$APP_ROOT/CHANGELOG.md"

cd "$APP_ROOT"
php artisan migrate --force
php artisan optimize:clear
chown -R www:www "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache"

echo "更新完成：$COMMIT"
