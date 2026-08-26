#!/bin/bash
# 一麦工作台 · 发布包制作（宝塔上传解压即装）
# 用法: ./make-release.sh   （产物在 releases/ 目录）
set -e
ROOT="$(cd "$(dirname "$0")" && pwd)"
STAGE="$(mktemp -d)/yimai-workbench"
REL="$ROOT/releases"
mkdir -p "$STAGE" "$REL"

echo "── 1/4 构建前端..."
cd "$ROOT/admin-web"
pnpm install --silent
pnpm build >/dev/null
cp -R dist "$STAGE/frontend"

# 运行时接口配置（部署后可直接编辑此文件，无需重新构建）
cat > "$STAGE/frontend/config.js" << 'EOF'
// 部署配置：修改接口地址后刷新浏览器即可，无需重新构建前端
// 单域名反代：'/api'；双域名示例：'https://oaapi.yourdomain.com/api'
window.__YIMAI_API_BASE__ = '/api'
EOF

echo "── 2/4 后端生产依赖..."
cd "$ROOT/backend"
composer install --no-dev --prefer-dist --no-interaction --quiet || composer install --no-interaction --quiet

echo "── 3/4 组装后端代码..."
cd "$ROOT/backend"
mkdir -p "$STAGE/backend"
rsync -a \
  --exclude '.env' --exclude '.env.*' \
  --exclude 'storage/logs/*' --exclude 'storage/app/private/*' \
  --exclude 'storage/framework/cache/data/*' --exclude 'storage/framework/sessions/*' \
  --exclude 'storage/framework/views/*' --exclude 'storage/oauth-*' \
  --exclude 'database/database.sqlite*' \
  --exclude 'bootstrap/cache/*.php' \
  ./ "$STAGE/backend/"
# 保留空目录结构
mkdir -p "$STAGE/backend/storage/logs" \
         "$STAGE/backend/storage/framework/cache/data" \
         "$STAGE/backend/storage/framework/sessions" \
         "$STAGE/backend/storage/framework/views" \
         "$STAGE/backend/storage/app/private" \
         "$STAGE/backend/bootstrap/cache"
touch "$STAGE/backend/storage/logs/.gitkeep"

echo "── 4/4 压缩..."
cd "$(dirname "$STAGE")"
ZIP="$REL/yimai-workbench-$(date +%Y%m%d-%H%M).zip"
zip -rq "$ZIP" yimai-workbench -x '*.DS_Store'
du -sh "$ZIP" | awk '{print "完成: "$1}'
echo "安装包: $ZIP"
