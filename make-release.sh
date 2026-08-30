#!/bin/bash
# 一麦工作台 · 发布包制作（在线升级包 + 宝塔首次安装包）
# 用法: ./make-release.sh（产物在 releases/ 目录）
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
WORK="$(mktemp -d)"
COMMON="$WORK/common/app"
UPDATE="$WORK/update/app"
INSTALLER="$WORK/installer/app"
REL="$ROOT/releases"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$COMMON/backend" "$UPDATE" "$INSTALLER" "$REL"

echo "── 1/4 构建前端..."
cd "$ROOT/admin-web"
pnpm install --frozen-lockfile --silent
pnpm build >/dev/null
FRONTEND_DIST="$ROOT/admin-web/dist"

# 运行时接口配置（部署后可直接编辑此文件，无需重新构建）
echo "── 1.5/4 写入版本与更新日志..."
cd "$ROOT"
# 版本信息（供「版本更新」页读取；服务器非 git 仓库）
BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo main)"
COMMIT="$(git rev-parse HEAD 2>/dev/null || echo '')"
MSG="$(git log -1 --pretty=%s 2>/dev/null || echo '')"
DATE="$(git log -1 --pretty=%ci 2>/dev/null || echo '')"
cat > "$COMMON/backend/version.json" << PVEOF
{"branch":"$BRANCH","commit":"$COMMIT","message":"$MSG","date":"$DATE"}
PVEOF
# 把更新日志放入后端站，供版本更新页展示。
[ -f "$ROOT/CHANGELOG.md" ] && cp "$ROOT/CHANGELOG.md" "$COMMON/backend/CHANGELOG.md"
cp "$ROOT/update.sh" "$COMMON/update.sh"
chmod 755 "$COMMON/update.sh"

echo "── 2/4 后端生产依赖..."
cd "$ROOT/backend"
COMPOSER_VENDOR_DIR="$WORK/vendor" composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --no-scripts --quiet

echo "── 3/4 组装后端代码..."
cd "$ROOT/backend"
rsync -a \
  --exclude '.env' --exclude '.env.*' \
  --exclude 'vendor/' \
  --exclude 'storage/logs/*' --exclude 'storage/app/private/*' \
  --exclude 'storage/framework/cache/data/*' --exclude 'storage/framework/sessions/*' \
  --exclude 'storage/framework/views/*' --exclude 'storage/oauth-*' \
  --exclude 'database/database.sqlite*' \
  --exclude 'bootstrap/cache/*.php' \
  ./ "$COMMON/backend/"

# Vue 产物与 Laravel 共用 public；仅覆盖前端入口和静态资源。
rm -rf "$COMMON/backend/public/assets"
cp -R "$FRONTEND_DIST/assets" "$COMMON/backend/public/assets"
cp "$FRONTEND_DIST/index.html" "$COMMON/backend/public/index.html"
[ -f "$FRONTEND_DIST/favicon.ico" ] && cp "$FRONTEND_DIST/favicon.ico" "$COMMON/backend/public/favicon.ico"
cat > "$COMMON/backend/public/config.js" << 'EOF'
window.__YIMAI_API_BASE__ = '/api'
EOF

# 先复制首次安装包（保留 install.php 并内置 vendor）。
cp -a "$COMMON/." "$INSTALLER/"
cp -a "$WORK/vendor" "$INSTALLER/backend/vendor"

# 在线升级包不包含安装入口和 vendor。
cp -a "$COMMON/." "$UPDATE/"
rm -f "$UPDATE/backend/public/install.php"

for stage in "$UPDATE/backend" "$INSTALLER/backend"; do
  mkdir -p "$stage/storage/logs" \
           "$stage/storage/framework/cache/data" \
           "$stage/storage/framework/sessions" \
           "$stage/storage/framework/views" \
           "$stage/storage/app/private" \
           "$stage/bootstrap/cache"
  touch "$stage/storage/logs/.gitkeep"
done

echo "── 4/4 压缩..."
VERSION="$(grep -oE 'v[0-9]+\.[0-9]+\.[0-9]+' "$ROOT/CHANGELOG.md" | head -1 | tr -d 'v')"
ZIP="$REL/yimai-workbench-v${VERSION}.zip"
INSTALLER_ZIP="$REL/yimai-workbench-installer-v${VERSION}.zip"
cd "$WORK/update"
zip -qr "$ZIP" app -x '*.DS_Store'
cd "$WORK/installer"
zip -qr "$INSTALLER_ZIP" app -x '*.DS_Store'
du -sh "$ZIP" "$INSTALLER_ZIP"
echo "在线升级包: $ZIP"
echo "首次安装包: $INSTALLER_ZIP"
