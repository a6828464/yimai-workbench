#!/usr/bin/env bash
# 一麦工作台 · 双平台推送（GitHub 镜像反代 + Gitee 直连）
#
# 背景（沉淀自一麦GOS 排障手册，2026-10-26）：
#   - 本机在国内，推 GitHub 被墙；已用自建 Cloudflare 反代 github.shunan.fun 作镜像入口。
#   - 全局 git 可能存在坏代理（http.proxy 指向死端口）+ insteadOf 重写叠加，报错极具迷惑性。
#   - 本脚本固定走「清掉本会话代理 + 显式镜像 URL」的推送方式，一次性生效、不改全局配置。
#
# 用法：
#   ./sync.sh                                # 交互式输入提交说明
#   ./sync.sh -m "feat: 新增xxx"             # 带说明直接提交并推送
#   ./sync.sh -m "feat: xxx" --no-commit     # 只推送（本地已有提交未推时）
#
# 推送成功标准：Gitee 与 GitHub 镜像两边 ls-remote 的 SHA 一致。

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

MIRROR_PUSH_URL="https://github.shunan.fun/a6828464/yimai-workbench.git"
GITEE_PUSH_URL="https://gitee.com/meng-taoo/yimai-workbench.git"

MSG=""
NO_COMMIT=0
while [ $# -gt 0 ]; do
  case "$1" in
    -m|--message) MSG="${2:-}"; shift 2 ;;
    --no-commit) NO_COMMIT=1; shift ;;
    *) echo "未知参数：$1"; exit 1 ;;
  esac
done

if [ "$NO_COMMIT" -ne 1 ]; then
  if [ -z "$MSG" ]; then
    read -r -p "提交说明（如 feat: 新增会员导出）: " MSG
  fi
  if [ -z "${MSG// /}" ]; then
    echo "✗ 提交说明不能为空"
    exit 1
  fi

  echo "[1/3] 暂存并提交..."
  git add -A
  if [ -z "$(git status --porcelain)" ]; then
    echo "✓ 没有需要提交的变更"
    NO_COMMIT=1   # 无变更时仍继续推送（可能有本地未推提交）
  else
    git commit -m "$MSG"
  fi
fi

echo "[2/3] 推送 Gitee（主仓库 / 权威源）..."
# Gitee 国内直连稳定，正常推即可
if git push "$GITEE_PUSH_URL" main:main; then
  echo "  ✓ Gitee 已更新"
else
  echo "  ✗ Gitee 推送失败（权威源不可跳过）"
  exit 1
fi

echo "[3/3] 推送 GitHub 镜像（github.shunan.fun 反代通道）..."
# ⚠️ 关键：-c http.proxy= 置空本次会话代理，绕开全局坏代理；
#     显式写镜像 URL，避免 insteadOf 二次重写的干扰。
#     反代通道（Cloudflare）会间歇性抖动：连挂 3 次后等 20 秒再试就过
#     （见《GitHub镜像推送与国内服务器在线更新-参考手册.md》1.4 节）。
push_mirror() {
  git -c http.proxy= push "$MIRROR_PUSH_URL" main:main
}

ok=0
for attempt in 1 2 3; do
  if push_mirror; then ok=1; break; fi
  echo "  第 $attempt 次失败，等 20 秒重试（镜像通道间歇抖动属正常）..."
  sleep 20
done

if [ "$ok" -ne 1 ]; then
  # 降级重试：HTTP/1.1（framing layer 错误时）+ 加大发送缓冲（Empty reply 时）
  echo "  三连挂，降级 HTTP/1.1 + 500MB postBuffer 再试一次..."
  if git -c http.proxy= -c http.version=HTTP/1.1 -c http.postBuffer=524288000 \
    push "$MIRROR_PUSH_URL" main:main; then
    ok=1
  fi
fi

if [ "$ok" -ne 1 ]; then
  echo "⚠ GitHub 镜像推送失败：CI（GitHub Actions 构建 release）暂不会触发。"
  echo "  Gitee 已推成功，代码不丢；稍后单独重试："
  echo "    git -c http.proxy= push $MIRROR_PUSH_URL main:main"
  echo "  发布流水线 release 校验会再次补推。"
  exit 2
fi

echo ""
echo "── 验证双端一致（不依赖推送输出）"
GITEE_SHA="$(git ls-remote "$GITEE_PUSH_URL" HEAD | awk '{print $1}')"
MIRROR_SHA="$(git -c http.proxy= ls-remote "$MIRROR_PUSH_URL" HEAD | awk '{print $1}')"
LOCAL_SHA="$(git rev-parse HEAD)"
echo "  本地   : ${LOCAL_SHA:0:12}"
echo "  Gitee  : ${GITEE_SHA:0:12}"
echo "  GitHub : ${MIRROR_SHA:0:12}"
if [ "$GITEE_SHA" = "$MIRROR_SHA" ] && [ "$GITEE_SHA" = "$LOCAL_SHA" ]; then
  echo "✓ 双平台同步完成，SHA 一致。GitHub Actions 将自动构建发布包。"
else
  echo "⚠ 两端 SHA 不一致，请人工核对："
  echo "  git ls-remote $GITEE_PUSH_URL HEAD"
  echo "  git -c http.proxy= ls-remote $MIRROR_PUSH_URL HEAD"
  exit 3
fi
