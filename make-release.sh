#!/bin/bash
# 一麦工作台 · 发布包制作（宝塔上传解压即装）
# 用法: ./make-release.sh   （产物在 releases/ 目录）
set -e
ROOT="$(cd "$(dirname "$0")" && pwd)"
STAGE_ROOT="$(mktemp -d)/yimai-workbench"
STAGE="$STAGE_ROOT/app"
REL="$ROOT/releases"
mkdir -p "$STAGE" "$REL"

echo "── 1/4 构建前端..."
cd "$ROOT/admin-web"
pnpm install --frozen-lockfile --silent
pnpm build >/dev/null
FRONTEND_DIST="$ROOT/admin-web/dist"

# 运行时接口配置（部署后可直接编辑此文件，无需重新构建）
echo "── 1.5/4 写入版本与更新日志..."
mkdir -p "$STAGE"
cd "$ROOT"
# 版本信息（供「版本更新」页读取；服务器非 git 仓库）
BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo main)"
COMMIT="$(git rev-parse HEAD 2>/dev/null || echo '')"
MSG="$(git log -1 --pretty=%s 2>/dev/null || echo '')"
DATE="$(git log -1 --pretty=%ci 2>/dev/null || echo '')"
cat > "$STAGE/version.json" << PVEOF
{"branch":"$BRANCH","commit":"$COMMIT","message":"$MSG","date":"$DATE"}
PVEOF
# 把更新日志一并复制进后端站（版本更新页展示），并放入 backend 内随升级同步
[ -f "$ROOT/CHANGELOG.md" ] && cp "$ROOT/CHANGELOG.md" "$STAGE/CHANGELOG.md"
mkdir -p "$STAGE/backend"
[ -f "$ROOT/CHANGELOG.md" ] && cp "$ROOT/CHANGELOG.md" "$STAGE/backend/CHANGELOG.md"

echo "── 2/4 后端生产依赖..."
cd "$ROOT/backend"
composer install --no-dev --prefer-dist --no-interaction --no-progress --quiet

echo "── 3/4 组装后端代码..."
cd "$ROOT/backend"
mkdir -p "$STAGE"
rsync -a \
  --exclude '.env' --exclude '.env.*' \
  --exclude 'storage/logs/*' --exclude 'storage/app/private/*' \
  --exclude 'storage/framework/cache/data/*' --exclude 'storage/framework/sessions/*' \
  --exclude 'storage/framework/views/*' --exclude 'storage/oauth-*' \
  --exclude 'database/database.sqlite*' \
  --exclude 'bootstrap/cache/*.php' \
  ./ "$STAGE/"

# Vue 产物与 Laravel 共用 public；仅覆盖前端入口和静态资源。
rm -rf "$STAGE/public/assets"
cp -R "$FRONTEND_DIST/assets" "$STAGE/public/assets"
cp "$FRONTEND_DIST/index.html" "$STAGE/public/index.html"
[ -f "$FRONTEND_DIST/favicon.ico" ] && cp "$FRONTEND_DIST/favicon.ico" "$STAGE/public/favicon.ico"
cat > "$STAGE/public/config.js" << 'EOF'
window.__YIMAI_API_BASE__ = '/api'
EOF
# 安装器不进入生产发布包；首次安装用 CLI 或受控安装流程。
rm -f "$STAGE/public/install.php"
# 保留空目录结构
mkdir -p "$STAGE/storage/logs" \
         "$STAGE/storage/framework/cache/data" \
         "$STAGE/storage/framework/sessions" \
         "$STAGE/storage/framework/views" \
         "$STAGE/storage/app/private" \
         "$STAGE/bootstrap/cache"
touch "$STAGE/storage/logs/.gitkeep"

echo "── 4/4 压缩..."
cd "$(dirname "$STAGE_ROOT")"
ZIP="$REL/yimai-workbench-$(date +%Y%m%d-%H%M).zip"
zip -rq "$ZIP" "$(basename "$STAGE_ROOT")" -x '*.DS_Store'
du -sh "$ZIP" | awk '{print "完成: "$1}'
echo "安装包: $ZIP"
