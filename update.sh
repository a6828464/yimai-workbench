#!/usr/bin/env bash
set -Eeuo pipefail

# 一麦工作台 · 受控在线更新脚本（由后台「版本更新」调用）
# 默认以脚本所在目录为站点根；也可通过 SITE_ROOT=/path ./update.sh 覆盖。
SCRIPT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SITE_ROOT="${SITE_ROOT:-$SCRIPT_ROOT}"
APP_ROOT="$SITE_ROOT/backend"
WORK_ROOT="${WORK_ROOT:-$SITE_ROOT/.update-work}"
# 只用固定可信发布 URL，不采用 Gitee release API 动态返回的 browser_download_url，
# 避免 release 仓库被接管时资产 URL 被投毒（curl 侧 SSRF）。
GITEE_PACKAGE_URL="https://gitee.com/meng-taoo/yimai-workbench/releases/download/auto-latest/yimai-workbench-latest.zip"
GITHUB_PACKAGE_URL="https://github.com/a6828464/yimai-workbench/releases/download/auto-latest/yimai-workbench-latest.zip"
# 期望 sha256（发布方在受控配置中提供；为空则跳过强校验并告警，可经 RELEASE_SHA256 注入）
EXPECTED_SHA256="${RELEASE_SHA256:-}"

if [ ! -d "$APP_ROOT" ]; then
  echo "错误：站点目录不存在：$APP_ROOT（请设置 SITE_ROOT 后重试）"
  exit 1
fi

rm -rf "$WORK_ROOT"
mkdir -p "$WORK_ROOT"
trap 'rm -rf "$WORK_ROOT"' EXIT

echo "── 下载发行包：GitHub auto-latest（优先）；Gitee auto-latest（兜底）"

download_ok=0
for url in "$GITHUB_PACKAGE_URL" "$GITEE_PACKAGE_URL"; do
  echo "  尝试: $url"
  # 仅 HTTPS、强制 TLS1.2+、禁止降级到非 https 的内网跳转，杜绝供应链/SSRF 投毒
  if curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --tlsv1.2 \
    --retry 3 --retry-delay 3 --connect-timeout 15 --max-time 600 \
    -o "$WORK_ROOT/release.zip" "$url"; then
    download_ok=1
    break
  else
    echo "  下载失败，尝试下一个来源..."
  fi
done

if [ "$download_ok" -ne 1 ]; then
  echo "致命错误：发行包下载失败（GitHub 与 Gitee 均不可达）。请检查服务器外网连通性后重试。"
  exit 1
fi

test -s "$WORK_ROOT/release.zip" || { echo "致命错误：下载的发行包为空文件"; exit 1; }

if [ -n "$EXPECTED_SHA256" ]; then
  ACTUAL_SHA256="$(sha256sum "$WORK_ROOT/release.zip" | awk '{print $1}')"
  echo "  校验 sha256：期望 ${EXPECTED_SHA256} 实际 ${ACTUAL_SHA256}"
  if [ "$ACTUAL_SHA256" != "$EXPECTED_SHA256" ]; then
    echo "致命错误：发行包 sha256 校验失败，已中止更新（请确认发布包与校验值一致）。"
    exit 1
  fi
else
  echo "  警告：未提供 RELEASE_SHA256，本次跳过强完整性校验。建议发布侧提供预期 sha256 注入。"
fi

unzip -q "$WORK_ROOT/release.zip" -d "$WORK_ROOT/unpacked"
RELEASE_ROOT="$WORK_ROOT/unpacked/app"
[ -d "$RELEASE_ROOT/backend" ] || { echo "致命错误：发行包结构异常（缺少 app/backend）"; exit 1; }

# 校验包内更新脚本存在，防止加载残缺升级通道；不存在则不覆盖（保留现网可用脚本）
if [ -f "$RELEASE_ROOT/update.sh" ] && [ -s "$RELEASE_ROOT/update.sh" ]; then
  PACKED_UPDATE_OK=1
else
  PACKED_UPDATE_OK=0
  echo "  警告：发行包未携带有效 update.sh，跳过更新脚本自举升级（继续以现有脚本完成本次更新）。"
fi

# Preserve production-only files while replacing application code and built assets.
# 发布包不携带 vendor，保留服务器现有生产依赖。
rsync -a \
  --exclude '.env' \
  --exclude 'storage/' \
  --exclude 'bootstrap/cache/' \
  --exclude 'database/database.sqlite*' \
  "$RELEASE_ROOT/backend/" "$APP_ROOT/"

# 升级包同时携带最新版更新脚本；在当前进程结束前覆盖自身不影响本次执行。
if [ "$PACKED_UPDATE_OK" -eq 1 ]; then
  cp "$RELEASE_ROOT/update.sh" "$SITE_ROOT/update.sh"
  chmod 755 "$SITE_ROOT/update.sh"
  echo "  已自举更新安装脚本 update.sh"
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

# 平滑重载 PHP-FPM：长驻进程可能持有旧 OPcache（含旧 autoload），导致新增类短暂 500。
# 按常见命名逐个尝试，找不到服务则跳过（宝塔各版本命名不同）。
for svc in php-fpm-84 php-fpm-83 php-fpm-82 php-fpm php8.4-fpm php8.3-fpm php-fpm; do
  if systemctl list-unit-files 2>/dev/null | grep -q "^${svc}.service"; then
    systemctl reload "$svc" 2>/dev/null && echo "已平滑重载 $svc" && break
  fi
done

echo "更新完成"