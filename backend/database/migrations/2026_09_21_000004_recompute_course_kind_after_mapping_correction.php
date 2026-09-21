<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 按**已纠正**的 `course_type` 映射重算 `ky_bookings.course_kind`。
 *
 * ## 为什么需要第二个重算迁移
 *
 * `ky_bookings.course_kind` 是同步时按**当时**的映射落盘的列，被重算过两次：
 *
 *  1. `2026_09_15_000001` 建列并回填（原始方向 `2=private` / `3=small`）；
 *  2. `2026_09_21_000003` 按 t35 **误判的反向**（`2=small` / `3=private`）重算
 *     —— 该迁移已随 v3.2.0 / v3.2.1 发布，**生产可能已执行**，真实数据被写反；
 *  3. 本迁移按纠正后的方向**再重算一次**，把列值拉回正确口径。
 *
 * 方向裁决与完整证据链见 `app/Support/helpers.php` 的 `courseKindFrom()` 注释
 * （用户第一手确认 + 同文档实测例子 + 三处独立来源交叉验证）。
 *
 * ## 为什么不能改 `000003`
 *
 * 它已在生产执行过，改它对已建库无效（Laravel 只记「跑没跑过」，不会重跑）；
 * 反而会造成「改了文件就以为数据修好了」的误解。故新增本迁移做数据修正。
 *
 * ## 重算口径（与 `courseKindFrom()` 逐字一致）
 *
 * 1. `raw.course_type` 明确时以它为准：`1`=团课 / `2`=私教 / `3`=小班；
 * 2. 无 `raw`、`raw` 非 JSON、或 `raw` 缺 `course_type` 时 → **`group`**
 *    （t30 收口的失败关闭侧：`course_kind='private'` 是授权判据，缺失时按非私教
 *    处理，宁可让老师少看到一行，也不把「课型未知」的行算成其私教学员）。
 *
 * 幂等：只依据 `raw.course_type` 计算，重复执行结果相同。
 *
 * ## 授权影响
 *
 * `course_kind='private'` 是 `privateStudentKeys()` 的取数条件，进而经
 * `EnsureUserIsEnabled` / `privateTeaches()` 决定授课老师能看到哪些会员与客资。
 * `000003` 执行后（若生产已跑）：
 *  - 真实**私教课**（`course_type=2`）被写成 `small` ⇒ 老师的**私教学员从
 *    「我的学员」消失**；
 *  - 真实**精品课**（`course_type=3`）被写成 `private` ⇒ 老师**把小班学员
 *    当作私教学员看到**（越权可见）。
 * 本迁移把两个方向都纠正回来。
 *
 * ## down()
 *
 * 复刻 `000003` 的错误方向（`2=small` / `3=private`），使 up/down 严格互逆。
 * 注意：`down()` 恢复的是 `000003` 之后的（错误）状态，而非更早的历史状态。
 */
return new class extends Migration
{
    /** 纠正后的映射：1=团课 / 2=私教课（对客「定制私教」）/ 3=精品课（对客「私教小班」） */
    private const MAPPING = ['1' => 'group', '2' => 'private', '3' => 'small'];

    /** `000003` 的错误方向，仅 down() 使用 */
    private const MAPPING_MISJUDGED = ['1' => 'group', '2' => 'small', '3' => 'private'];

    public function up(): void
    {
        $this->recompute(self::MAPPING);
    }

    public function down(): void
    {
        $this->recompute(self::MAPPING_MISJUDGED);
    }

    /**
     * 按给定映射重算全部行。
     *
     * 逐行解析 raw（`course_type` 可能缺失 / raw 可能是脏 JSON），按「先分组再批量
     * update」减少写次数；`chunkById` 避免一次性载入整表。
     *
     * @param  array<string,string>  $mapping
     */
    private function recompute(array $mapping): void
    {
        DB::table('ky_bookings')->select(['id', 'raw'])->orderBy('id')
            ->chunkById(500, function ($rows) use ($mapping) {
                $buckets = ['private' => [], 'small' => [], 'group' => []];
                foreach ($rows as $row) {
                    $raw = json_decode((string) $row->raw, true);
                    $explicit = is_array($raw) ? (string) ($raw['course_type'] ?? '') : '';
                    // 缺失/未知 → group（t30 收口的既定兜底，即「不放大可见范围」侧）
                    $kind = $mapping[$explicit] ?? 'group';
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
