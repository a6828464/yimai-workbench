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
  # 变量名用 ${} 括起来：后面紧跟的全角「（」是多字节字符，bash 会把首字节并进变量名，
  # 在 set -u 下报 "unbound variable" —— 恰恰是路径配错、最需要看到这条提示的时候，
  # 用户只能看到一句看不懂的 bash 报错。
  echo "错误：站点目录不存在：${APP_ROOT}（请设置 SITE_ROOT 后重试）"
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

# ── 升级前回滚点 ────────────────────────────────────────────────
# 升级失败最难受的形态是「代码已覆盖、migrate 报错」，站点停在半新半旧、又没有任何还原点。
# 在动任何文件之前先留两个回滚点：数据库快照（走应用自身的备份能力）与代码快照（tar）。
# 失败时下面会自动把代码还原，数据库则按提示用「升级前」快照恢复。
BACKUP_STAMP="$(date +%Y%m%d-%H%M%S)"
ROLLBACK_DIR="$SITE_ROOT/.update-backup/$BACKUP_STAMP"

rollback_code() {
  echo "  更新失败：$1"
  if [ -f "$ROLLBACK_DIR/backend-code.tar.gz" ]; then
    echo "  正在回滚代码…"
    rm -rf "$APP_ROOT/public/assets"
    tar xzf "$ROLLBACK_DIR/backend-code.tar.gz" -C "$APP_ROOT"
    (cd "$APP_ROOT" && php artisan optimize:clear >/dev/null 2>&1) || true
    echo "  已还原到更新前的代码。数据库如需回滚：后台 → 数据备份 → 用「升级前」快照恢复。"
    echo "  说明：回滚只还原代码，不回退已经跑过的迁移。新版本独有的文件可能残留，"
    echo "        但旧代码不会引用它们（RESTORE 后重新发版即可覆盖/清理）。"
  else
    echo "  未找到代码快照，无法自动回滚，请人工处理。"
  fi
  echo "  代码快照保留在：$ROLLBACK_DIR"
  exit 1
}

# 只保留最近 3 份历史快照，避免系统盘被自己的回滚点吃掉
keep=0
for d in $(ls -1dt "$SITE_ROOT"/.update-backup/*/ 2>/dev/null); do
  keep=$((keep + 1))
  [ "$keep" -gt 3 ] && rm -rf "$d"
done

echo "── 生成升级前数据库快照"
if ! php artisan backup:snapshot --label="升级前(${BACKUP_STAMP})"; then
  echo "致命错误：升级前数据库快照失败，已中止更新（没有回滚点的升级风险过高）。"
  echo "  可先在后台「数据备份」页确认备份可用，或修复磁盘/权限后重试。"
  exit 1
fi

echo "── 生成升级前代码快照（回滚用）"
mkdir -p "$ROLLBACK_DIR"
tar czf "$ROLLBACK_DIR/backend-code.tar.gz" -C "$APP_ROOT" \
  --exclude='./vendor' --exclude='./storage' --exclude='./.env' --exclude='./node_modules' . \
  || { echo "致命错误：代码快照生成失败，已中止更新。"; exit 1; }

# ── 迁移必须随包到达（rsync 之前的硬门禁） ──────────────────────────
# 失败形态：新代码引用了新列，而包里**没有**对应迁移，rsync 覆盖代码后
# `php artisan migrate` 无迁移可跑、新代码却已经在跑 —— 公开接口直接 500，
# 且因为迁移没跑，回滚代码也修不好「列不存在」之外的问题。
# 所以在覆盖任何文件**之前**就确认：包内携带的迁移集合里，必须有新增的迁移。
# 做法：把包内迁移与线上迁移对比，若线上缺少包内的任一 .php 文件即为「包不完整」。
if [ -d "$RELEASE_ROOT/backend/database/migrations" ]; then
  missing_migrations=""
  for f in "$RELEASE_ROOT/backend/database/migrations"/*.php; do
    [ -e "$f" ] || continue
    name="$(basename "$f")"
    if [ ! -f "$APP_ROOT/database/migrations/$name" ]; then
      missing_migrations="$missing_migrations $name"
    fi
  done
  # 线上已有、包内没有的迁移不算错（可能是人工补丁），这里只拦「包内必须有却缺失」。
  # 但包内迁移目录必须非空 —— 空目录说明打包含数据库迁移那一步被跳过了。
  if [ -z "$(ls -1 "$RELEASE_ROOT/backend/database/migrations"/*.php 2>/dev/null)" ]; then
    echo "致命错误：升级包内 database/migrations 为空，包不完整，已中止更新（未覆盖任何文件）。"
    exit 1
  fi
  if [ -n "$missing_migrations" ]; then
    echo "  包内迁移（线上尚未应用）：$missing_migrations"
  fi
else
  echo "致命错误：升级包内缺少 backend/database/migrations 目录，包不完整，已中止更新（未覆盖任何文件）。"
  exit 1
fi

# 包内代码引用了 published_shares 的相关列时，包里必须真的带着建那些列的迁移。
# 这一条直接对标「迁移没进版本控制 / 打包漏了」这个具体事故：
# 代码在线上、建列的迁移不在包里，migrate 什么也不做，公开接口直接 500。
if grep -rqs "published_shares" "$RELEASE_ROOT/backend/app" 2>/dev/null; then
  for required in add_enabled_to_published_shares add_owner_and_source_to_published_shares; do
    if ! ls "$RELEASE_ROOT/backend/database/migrations"/*"$required"*.php >/dev/null 2>&1; then
      echo "致命错误：包内代码引用 published_shares，但包里缺少必需迁移 *${required}*.php。"
      echo "  已中止更新，未覆盖任何文件。请重新打包后再发布。"
      exit 1
    fi
  done
  echo "  迁移完整：published_shares 所需迁移均在包内"
fi

# Preserve production-only files while replacing application code and built assets.
# 发布包不携带 vendor，保留服务器现有生产依赖。
#
# 这里刻意不用 `rsync --delete`：目标是整棵目录树，而服务器上有一批「包内没有、删了会出事」
# 的东西（vendor、宝塔 chattr +i 锁定的 .user.ini、storage 里的私有文件）。--delete 一旦
# 碰上不可删文件会以非 0 退出，脚本在 set -e 下直接中断，正好制造我们要避免的半新半旧状态。
# 真正的目录膨胀来自 public/assets（每次发版换一批内容 hash 文件名，只增不减），单独清掉即可。
rm -rf "$APP_ROOT/public/assets"
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
# 迁移失败是最需要自动回滚的一步：代码已经换成新版，而库表还停在旧结构。
if ! php artisan migrate --force; then
  rollback_code "migrate 执行失败"
fi
php artisan optimize:clear
# composer.json 的 autoload.files 变更（如 helpers.php）需要重建 autoload 列表；
# 服务器无 composer 时由 bootstrap/app.php 的 require_once 兜底加载助手函数，
# 此处仅作最佳努力：存在 composer 则重建，失败不阻断更新。
if command -v composer >/dev/null 2>&1; then
  composer dump-autoload --no-scripts --quiet || echo "  警告：composer dump-autoload 失败（不影响本次更新，助手函数由 bootstrap 兜底加载）"
else
  echo "  提示：服务器未安装 composer，跳过 autoload 重建（助手函数由 bootstrap 兜底加载）"
fi
php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (! Illuminate\Support\Facades\Schema::hasColumns("customers", ["enrolled_at", "visit_at"])) { fwrite(STDERR, "customers 同步字段迁移未生效\n"); exit(1); }' \
  || rollback_code "迁移后结构健康检查未通过（customers 同步字段缺失）"

# 在线升级后清理首次安装专用入口；普通 SPA 首页存在时才执行，避免残包导致站点无入口。
if [ -s "$APP_ROOT/public/index.html" ]; then
  rm -f "$APP_ROOT/public/install.php" "$APP_ROOT/public/app.html"
fi

# 统一文件属主：无论本次更新由后台按钮(www)还是计划任务(root)执行，
# root 执行时只整理发布代码目录，跳过未变化的 vendor，降低系统盘元数据扫描峰值。
# 注意：`.user.ini` 被宝塔以 chattr +i 锁定（防篡改），root 也无法改属主，
# 属主整理需对其容错（storage/bootstrap-cache 仍严格归主）。
if [ "$(id -u)" -eq 0 ]; then
  for path in app bootstrap config database public resources routes tests artisan composer.json composer.lock phpunit.xml; do
    [ -e "$APP_ROOT/$path" ] && chown -R www:www "$APP_ROOT/$path" 2>/dev/null || true
  done
fi
chown -R www:www "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache"
chmod -R 775 "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache"

# 平滑重载 PHP-FPM：长驻进程可能持有旧 OPcache（含旧 autoload），导致新增类短暂 500。
# 按常见命名逐个尝试，找不到服务则跳过（宝塔各版本命名不同）。
for svc in php-fpm-85 php-fpm-84 php-fpm-83 php-fpm-82 php-fpm php8.5-fpm php8.4-fpm php8.3-fpm php-fpm; do
  if systemctl list-unit-files 2>/dev/null | grep -q "^${svc}.service"; then
    systemctl reload "$svc" 2>/dev/null && echo "已平滑重载 $svc" && break
  fi
done

echo "更新完成"
echo "  回滚点：数据库快照（后台「数据备份」页可见，标签「升级前」）+ 代码快照 $ROLLBACK_DIR"
