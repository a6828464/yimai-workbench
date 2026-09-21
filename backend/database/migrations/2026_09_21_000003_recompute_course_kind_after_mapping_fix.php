<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 按修正后的 course_type 映射**重算回补** `ky_bookings.course_kind`。
 *
 * ## 为什么必须回补
 *
 * `ky_bookings.course_kind` 是同步时按**当时（反向）的映射**落盘的列：
 * `2026_09_15_000001` 建列并回填过历史行，`KyMemberSyncService::bookingFact()`
 * 之后也一直按同一（错误）方向写入。现在 `courseKindFrom()`
 * （`app/Support/helpers.php`）修正为 2=小班 / 3=私教后：
 *  - **新**数据会正确（同步走该函数）；
 *  - **历史**行仍是旧值 —— 只改函数不回补，会出现「同一列里新老两种口径并存」，
 *    而读取侧（`privateStudentKeys()`、三分统计、课后分析）一律读**列值**。
 *    其中 `privateStudentKeys()` 是授课老师按人隔离的**授权判据**，列值反了 =
 *    老师越权可见（详见下方「授权影响」）。
 *
 * 所以本迁移把存量行**按每行 `raw.course_type` 重算**，与修正后的口径对齐。
 *
 * ## 重算口径（与 `courseKindFrom()` 逐字一致）
 *
 * 1. `raw.course_type` 明确时以它为准：`1`=团课 / `2`=小班 / `3`=私教；
 * 2. 无 `raw`、`raw` 非 JSON、或 `raw` 缺 `course_type` 时 → **`group`**。
 *
 * 第 2 条即 t30 收口后的既定兜底（「不放大可见范围」的保守侧）：`course_kind`
 * 是授权判据，缺失时按**非私教**处理 —— 宁可让老师少看到一行（可被发现），
 * 也不把「课型未知」的行算成其私教学员（越权读到他人会员不可撤）。
 *
 * **与旧实现的一致性说明（重要差异）**：旧口径在缺失时按 `booking_type`
 * （接口来源）兜底 —— 团课接口算团课、私教接口算私教。本迁移**不沿用**该规则，
 * 理由有二：
 *  - 与当前代码口径统一：t30 已把四处兜底收口为 `group`，列值应与代码一致；
 *  - 方向安全：沿用旧规则会把这批「课型未知」行重新写成 `private`，从而**放大**
 *    授课老师可见范围 —— 正是本任务要消除的缺陷方向。
 * 因此本迁移会把「无 course_type 且旧值为 private」的行改为 `group`；这是
 * **有意收窄**（失败关闭），已在本注释中显式记录，回滚见 `down()`。
 *
 * ## 授权影响（本任务的核心安全面）
 *
 * 修正前：`course_type=2`（实为**精品课/对客「私教小班」**）被写成
 * `course_kind='private'`，而 `privateStudentKeys()` 只取 `course_kind='private'`
 * 的学员作为授课老师的「我的学员」授权键（再经 `EnsureUserIsEnabled`、
 * `privateTeaches()` 生效）⇒ **老师看到的是小班学员**（越权可见），
 * 同时**看不到**自己的真实私教学员（`course_type=3`）。
 * 修正 + 回补后：`private` 只对应真实私教课（`course_type=3`）。
 *
 * ## 回补行数与 down()
 *
 * 见任务报告（本地演示数据实测）：up 会重算**全部**行（该表无「真实/演示」标记，
 * 也无法只针对部分行——按 raw 重算对每行都是幂等确定的）。
 *
 * `down()` 复刻 **`2026_09_15_000001` 当年的完整旧口径**（含它的 `booking_type`
 * 兜底），因此是 up 在「同步落盘行」上的忠实逆操作：
 *  - 显式 `course_type` → 旧反向映射（2=private / 3=small / 1=group）；
 *  - 缺失 → 旧兜底（`booking_type='团课'` → group，否则 private）。
 *
 * ⚠️ 诚实说明一处**不可逆边界**：本机 810 行演示数据的列值是**造出来的**
 * （`raw` 恒为 `{"demo":true}`、不含 `course_type`），旧兜底只能推出 540 group /
 * 270 private，**推不出**迁移前那 270 行 `small`。即 down() 恢复的是
 * 「按旧口径从 raw 推导的值」，而非「up 之前的字面值」——**对演示数据无法逐字复原**
 * （任何只依据 raw 的规则都不能，因为这些行的列值本就不来自 raw）。
 * 对**真实同步行**（都有 `course_type`）则完全可逆。若需保留演示分布，
 * 请在 up 前自行备份该表。
 */
return new class extends Migration
{
    /** 新映射：1=团课（对客「精品团课」）/ 2=精品课（对客「私教小班」）/ 3=私教课（对客「定制私教」） */
    private const MAPPING = ['1' => 'group', '2' => 'small', '3' => 'private'];

    /** 旧（反向）映射，仅 down() 回滚使用：2=private / 3=small */
    private const MAPPING_LEGACY = ['1' => 'group', '2' => 'private', '3' => 'small'];

    public function up(): void
    {
        $this->recompute(self::MAPPING);
    }

    public function down(): void
    {
        // 复刻 2026_09_15_000001 的旧兜底：缺失 course_type 时按接口来源（booking_type）
        // 判定 —— 团课接口的行算团课，其余（私教接口）算私教。这是当年的原状。
        $this->recompute(self::MAPPING_LEGACY, legacyBookingTypeFallback: true);
    }

    /**
     * 按给定映射重算全部行。
     *
     * 逐行解析 raw（`course_type` 可能缺失/raw 可能是脏 JSON），按「先分组再批量
     * update」减少写次数；`chunkById` 避免一次性载入整表。
     *
     * @param  array<string,string>  $mapping
     * @param  bool  $legacyBookingTypeFallback  缺失 course_type 时是否复刻旧口径
     *                                           （按 booking_type 兜底；仅 down() 用）。
     *                                           默认 false = 用 t30 收口后的 group 兜底。
     */
    private function recompute(array $mapping, bool $legacyBookingTypeFallback = false): void
    {
        $columns = ['id', 'raw'];
        if ($legacyBookingTypeFallback) {
            $columns[] = 'booking_type';
        }

        DB::table('ky_bookings')->select($columns)->orderBy('id')
            ->chunkById(500, function ($rows) use ($mapping, $legacyBookingTypeFallback) {
                $buckets = ['private' => [], 'small' => [], 'group' => []];
                foreach ($rows as $row) {
                    $raw = json_decode((string) $row->raw, true);
                    $explicit = is_array($raw) ? (string) ($raw['course_type'] ?? '') : '';
                    if (isset($mapping[$explicit])) {
                        $kind = $mapping[$explicit];
                    } elseif ($legacyBookingTypeFallback) {
                        $kind = ((string) ($row->booking_type ?? '')) === '团课' ? 'group' : 'private';
                    } else {
                        // 缺失/未知 → group（t30 收口的既定兜底，即「不放大可见范围」侧）
                        $kind = 'group';
                    }
                    $buckets[$kind][] = $row->id;
                }
                foreach ($buckets as $kind => $ids) {
                    if ($ids !== []) {
                        DB::table('ky_bookings')->whereIn('id', $ids)->update(['course_kind' => $kind]);
                    }
                }
            });
    }
};
