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
# ⚠️ `--exclude 'version.json'` 与 `--exclude 'CHANGELOG.md'` 都不能删：步骤 1.5 已把
# **本版**的这两份写进 $COMMON/backend/（version.json 取自当前 git HEAD，CHANGELOG.md 取自
# 仓库根），而 backend/ 下同名的那两份是**上一次发布留下的陈旧副本**（都被 .gitignore 忽略，
# 不入库、不随提交更新）。不加排除，rsync 会用陈旧副本把它们盖回去，导致「版本更新」页
# 永远显示上一版的 commit 与更新日志 —— 实测 v3.3.0 打包时该页显示的是 v3.2.0 的 commit
# （8e7b63f）和 v3.2.0 的日志，此前每次发布都如此。
# （CI 侧走 `git archive HEAD`，只含入库文件，因此不存在这个问题；这些排除只影响本地打包。）
#
# 其余排除项同理，都是「本地开发残留、绝不该进生产包」的文件：
#   .phpunit.result.cache —— 测试缓存（实测随包发出 90KB）
#   yimai                 —— 本地 SQLite 开发库（实测随包发出 610KB；本次核对内容为
#                            仅含 migrations 47 行、无 PII，但本质上仍是本地库文件，
#                            一旦哪天本地库有真实数据就会随包外发，必须排除）
#   .DS_Store             —— macOS 目录元数据
rsync -a \
  --exclude '.env' --exclude '.env.*' \
  --exclude 'vendor/' \
  --exclude 'storage/' \
  --exclude 'database/database.sqlite*' \
  --exclude 'bootstrap/cache/*.php' \
  --exclude 'version.json' \
  --exclude 'CHANGELOG.md' \
  --exclude '.phpunit.result.cache' \
  --exclude 'yimai' \
  --exclude '.DS_Store' \
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
# 未安装时根路径先进入安装向导；安装成功后 install.php 将 app.html 恢复为 index.html。
mv "$INSTALLER/backend/public/index.html" "$INSTALLER/backend/public/app.html"
cp "$ROOT/backend/resources/install-index.html" "$INSTALLER/backend/public/index.html"
test -s "$INSTALLER/backend/public/install.php"
test -s "$INSTALLER/backend/public/app.html"
test -s "$INSTALLER/backend/vendor/autoload.php"

# 在线升级包不包含安装入口和 vendor。
cp -a "$COMMON/." "$UPDATE/"
rm -f "$UPDATE/backend/public/install.php"
test ! -e "$UPDATE/backend/public/install.php"

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
# 固定名副本：服务器 update.sh 的回退下载源取的是 yimai-workbench-latest.zip，
# 只出带版本号的包没法直接走那条路径。CI 一直会额外生成这两份，本地此前没有，两边产物不一致。
LATEST_ZIP="$REL/yimai-workbench-latest.zip"
LATEST_INSTALLER_ZIP="$REL/yimai-workbench-installer-latest.zip"
rm -f "$ZIP" "$INSTALLER_ZIP" "$LATEST_ZIP" "$LATEST_INSTALLER_ZIP"
cd "$WORK/update"
zip -qr "$ZIP" app -x '*.DS_Store'
cd "$WORK/installer"
zip -qr "$INSTALLER_ZIP" app -x '*.DS_Store'
cp "$ZIP" "$LATEST_ZIP"
cp "$INSTALLER_ZIP" "$LATEST_INSTALLER_ZIP"
du -sh "$ZIP" "$INSTALLER_ZIP"
# 变量名必须用 ${} 括起来：后面紧跟的全角「（」是多字节字符，bash 会把它的首字节当成
# 变量名的一部分，在 set -u 下报 "unbound variable" 并以 127 退出 —— 包已经打好，
# 脚本却报失败，任何检查退出码的调用方都会误判。
echo "在线升级包: ${ZIP}（固定名副本 ${LATEST_ZIP}）"
echo "首次安装包: ${INSTALLER_ZIP}（固定名副本 ${LATEST_INSTALLER_ZIP}）"
