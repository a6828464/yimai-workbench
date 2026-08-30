#!/usr/bin/env bash
set -Eeuo pipefail

# 一麦工作台 · 受控在线更新脚本（由后台「版本更新」调用）
# 默认以脚本所在目录为站点根；也可通过 SITE_ROOT=/path ./update.sh 覆盖。
SCRIPT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SITE_ROOT="${SITE_ROOT:-$SCRIPT_ROOT}"
APP_ROOT="$SITE_ROOT/backend"
WORK_ROOT="${WORK_ROOT:-$SITE_ROOT/.update-work}"
GITEE_API="https://gitee.com/api/v5/repos/meng-taoo/yimai-workbench/releases/latest"
GITHUB_PACKAGE_URL="https://github.com/a6828464/yimai-workbench/releases/download/auto-latest/yimai-workbench-latest.zip"

if [ ! -d "$APP_ROOT" ]; then
  echo "错误：站点目录不存在：$APP_ROOT（请设置 SITE_ROOT 后重试）"
  exit 1
fi

rm -rf "$WORK_ROOT"
mkdir -p "$WORK_ROOT"
trap 'rm -rf "$WORK_ROOT"' EXIT

PACKAGE_URL=""
if command -v python3 >/dev/null 2>&1; then
  PACKAGE_URL="$(curl --fail --silent --show-error --location --connect-timeout 15 --max-time 30 "$GITEE_API" \
    | python3 -c 'import json,sys; d=json.load(sys.stdin); print(next((a["browser_download_url"] for a in d.get("assets",[]) if a.get("name")=="yimai-workbench-latest.zip"), ""))' || true)"
else
  PACKAGE_URL="$(curl --fail --silent --show-error --location --connect-timeout 15 --max-time 30 "$GITEE_API" \
    | grep -o '"browser_download_url":"[^"]*yimai-workbench-latest\.zip"' | head -n1 | sed 's/.*":"//' || true)"
fi

echo "── 下载发行包来源：${PACKAGE_URL:-GitHub auto-latest}"

download_ok=0
for url in "${PACKAGE_URL:-}" "$GITHUB_PACKAGE_URL"; do
  [ -z "$url" ] && continue
  echo "  尝试: $url"
  if curl --fail --location --retry 3 --retry-delay 3 --connect-timeout 15 --max-time 600 -o "$WORK_ROOT/release.zip" "$url"; then
    download_ok=1
    break
  else
    echo "  下载失败，尝试下一个来源..."
  fi
done

if [ "$download_ok" -ne 1 ]; then
  echo "致命错误：发行包下载失败（Gitee 与 GitHub 均不可达）。请检查服务器外网连通性后重试。"
  exit 1
fi

test -s "$WORK_ROOT/release.zip" || { echo "致命错误：下载的发行包为空文件"; exit 1; }

unzip -q "$WORK_ROOT/release.zip" -d "$WORK_ROOT/unpacked"
RELEASE_ROOT="$WORK_ROOT/unpacked/app"
[ -d "$RELEASE_ROOT/backend" ] || { echo "致命错误：发行包结构异常（缺少 app/backend）"; exit 1; }

# Preserve production-only files while replacing application code and built assets.
# 发布包不携带 vendor，保留服务器现有生产依赖。
rsync -a \
  --exclude '.env' \
  --exclude 'storage/' \
  --exclude 'bootstrap/cache/' \
  --exclude 'database/database.sqlite*' \
  "$RELEASE_ROOT/backend/" "$APP_ROOT/"

# 升级包同时携带最新版更新脚本；在当前进程结束前覆盖自身不影响本次执行。
if [ -f "$RELEASE_ROOT/update.sh" ]; then
  cp "$RELEASE_ROOT/update.sh" "$SITE_ROOT/update.sh"
  chmod 755 "$SITE_ROOT/update.sh"
fi

cd "$APP_ROOT"
php artisan migrate --force
php artisan optimize:clear

# 统一文件属主：无论本次更新由后台按钮(www)还是计划任务(root)执行，
# 更新完成后整体归回 www:www，保证下次任意方式都能覆盖写入，避免 rsync 权限失败。
# 注意：`.user.ini` 被宝塔以 chattr +i 锁定（防篡改），root 也无法改属主，
# 属主整理需对其容错（storage/bootstrap-cache 仍严格归主）。
chown -R www:www "$APP_ROOT" 2>/dev/null || true
chmod -R 755 "$APP_ROOT" 2>/dev/null || true
chown -R www:www "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache"
chmod -R 775 "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache"

echo "更新完成"
