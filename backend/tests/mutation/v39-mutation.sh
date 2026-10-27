#!/usr/bin/env bash
#
# v3.9「待续费」独立验收 · 变异/反例探测（t6）
#
# 用法：cd backend && bash tests/mutation/v39-mutation.sh
#
# 原则：每个变异**只改一条规则**，跑 RenewalV39AcceptanceTest，然后逐字节还原
# （cmp 校验 + sha256 打印）。脚本本身不改任何测试，只临时改实现再从备份还原。
#
set -u
cd "$(dirname "$0")/../.." || exit 1

CL="app/Support/CardLifecycle.php"
HL="app/Support/helpers.php"
SY="app/Services/KyMemberSyncService.php"
ORIG=$(mktemp -d /tmp/v39mut.XXXXXX)
cp "$CL" "$ORIG/CardLifecycle.php"
cp "$HL" "$ORIG/helpers.php"
cp "$SY" "$ORIG/KyMemberSyncService.php"

restore() {
  cp "$ORIG/CardLifecycle.php" "$CL"
  cp "$ORIG/helpers.php" "$HL"
  cp "$ORIG/KyMemberSyncService.php" "$SY"
  if ! cmp -s "$ORIG/CardLifecycle.php" "$CL" \
    || ! cmp -s "$ORIG/helpers.php" "$HL" \
    || ! cmp -s "$ORIG/KyMemberSyncService.php" "$SY"; then
    echo "!! 还原失败，请立即检查 git status"
    exit 9
  fi
}

run_case() {
  local name="$1" out code
  out=$(php artisan test --filter='RenewalV39AcceptanceTest' 2>&1)
  code=$?
  echo "=== ${name}（exit=${code}）"
  echo "$out" | grep -E '^  Tests: ' | tail -1
  echo "$out" | grep -E 'FAILED  Tests' | sed 's/^ *//;s/ *$//' | head -8
  restore
}

patch() { # patch <file> <old> <new>
  python3 - "$1" "$2" "$3" <<'PY'
import sys
path, old, new = sys.argv[1], sys.argv[2], sys.argv[3]
s = open(path, encoding='utf-8').read()
if old not in s:
    print('!! 变异锚点未命中：' + old[:70].replace('\n', '\\n'))
    sys.exit(3)
open(path, 'w', encoding='utf-8').write(s.replace(old, new, 1))
print('patched ' + path)
PY
}

echo "sha256（基线，脚本结束时必须与之一致）"
shasum -a 256 "$CL" "$HL" "$SY"

# ── M0 对照：不改任何东西
run_case "M0 对照（无变异）"

# ── M1：deadline 不再决定实例历史（I1 的核心：状态只信上游 status）
patch "$CL" "        if (\$daysUntil !== null && \$daysUntil < 0) {
            return 'history';
        }
" "" >/dev/null || exit 3
run_case "M1 stateOf 删掉 deadline<today⇒history"

# ── M2：期限卡余量回退读 residue_amount（F1 的旧 bug：residue_amount 恒 0）
patch "$CL" '                $remaining = (float) max(0, (int) $daysUntil);' \
             '                $remaining = (float) max(0, (int) ($residue ?? 0));' >/dev/null || exit 3
run_case "M2 期限卡 remaining 改读 residue_amount"

# ── M3：expire_date 让历史实例参与取最早（I6 回归：旧卡把到期日拉早）
patch "$CL" "            if (\$i['state'] === 'current' && \$i['deadline'] !== null) {
                \$deadlines[] = \$i['deadline'];
            }" \
            "            if (\$i['deadline'] !== null) {
                \$deadlines[] = \$i['deadline'];
            }" >/dev/null || exit 3
run_case "M3 expireDateFrom 让历史卡参与 min()"

# ── M4：储值卡单位落成「节」（D05/I5 回归）
patch "$CL" "[self::TYPE_COUNT => '节', self::TYPE_TIME => '天', self::TYPE_STORED => '元']" \
            "[self::TYPE_COUNT => '节', self::TYPE_TIME => '天', self::TYPE_STORED => '节']" >/dev/null || exit 3
run_case "M4 储值卡 unit 落成「节」"

# ── M5：isUsable 去掉 state=current 守卫（§4.3 第二道防线；与 M6 组合才可观测）
patch "$CL" '        return $i['"'"'state'"'"'] === '"'"'current'"'"' && $i['"'"'remaining'"'"'] !== null && $i['"'"'remaining'"'"'] > 0;' \
            '        return $i['"'"'remaining'"'"'] !== null && $i['"'"'remaining'"'"'] > 0;' >/dev/null || exit 3
run_case "M5 isUsable 去掉 state=current 守卫"

# ── M6：命中域改成遍历 $all（§4.3 结构性排除；单独存在时被 state 守卫遮住＝等价变异）
patch "$HL" "            // ── 逐实例求值（只作用于 CURRENT；HISTORY/UNACTIVATED 不在命中域）──
            foreach (\$current as \$instance) {" \
            "            // ── 逐实例求值（只作用于 CURRENT；HISTORY/UNACTIVATED 不在命中域）──
            foreach (\$all as \$instance) {" >/dev/null || exit 3
run_case "M6 HIT_DOMAIN 遍历 \$all（含历史；单独为等价变异）"

# ── M7：M5+M6 同时施加＝「历史卡带余额进命中域」这个真实回归形态
patch "$CL" '        return $i['"'"'state'"'"'] === '"'"'current'"'"' && $i['"'"'remaining'"'"'] !== null && $i['"'"'remaining'"'"'] > 0;' \
            '        return $i['"'"'remaining'"'"'] !== null && $i['"'"'remaining'"'"'] > 0;' >/dev/null || exit 3
patch "$HL" "            // ── 逐实例求值（只作用于 CURRENT；HISTORY/UNACTIVATED 不在命中域）──
            foreach (\$current as \$instance) {" \
            "            // ── 逐实例求值（只作用于 CURRENT；HISTORY/UNACTIVATED 不在命中域）──
            foreach (\$all as \$instance) {" >/dev/null || exit 3
run_case "M7 M5+M6：历史卡可命中（用户原始 bug 形态）"

# ── M8：兼容区 expiredCards 收回 29/39（S11 回归）
patch "$SY" "            return \$card['type'] === CardLifecycle::TYPE_COUNT
                && ! in_array(\$card['statusCode'], ['29', '39'], true)
                && ! \$card['isTaste']" \
            "            return \$card['type'] === CardLifecycle::TYPE_COUNT
                && ! \$card['isTaste']" >/dev/null || exit 3
run_case "M8 expiredCards 收回 29/39（炸弹成员判定回归）"

# ── M9：体验卡关键词退回旧硬编码（D10-④ 回归：「新人礼包2次」进 P0）
patch "$CL" "public const DEFAULT_TASTE_PATTERN = '体验|员工|测试|礼包|赠送';" \
            "public const DEFAULT_TASTE_PATTERN = '体验|员工|测试';" >/dev/null || exit 3
run_case "M9 体验卡默认正则退回（去掉 礼包|赠送）"

echo "=== 还原校验"
cmp -s "$ORIG/CardLifecycle.php" "$CL" && echo "CardLifecycle.php OK" || echo "CardLifecycle.php DIRTY"
cmp -s "$ORIG/helpers.php" "$HL" && echo "helpers.php OK" || echo "helpers.php DIRTY"
cmp -s "$ORIG/KyMemberSyncService.php" "$SY" && echo "KyMemberSyncService.php OK" || echo "KyMemberSyncService.php DIRTY"
shasum -a 256 "$CL" "$HL" "$SY"
rm -rf "$ORIG"
