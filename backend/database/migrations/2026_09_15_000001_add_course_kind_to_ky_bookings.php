<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 课型独立成列。
 *
 * ⚠️ 本迁移里的 `course_type` 映射方向是**正确的**（2=私教 / 3=小班）；中间一度
 * 被 t35 误判为反向，现已由用户第一手确认翻回本方向。切勿再翻转！
 *
 * 「当前正确口径」只在一处：`app/Support/helpers.php` 的 **`courseKindFrom()`**
 * （1=团课 / **2=私教课→私教** / **3=精品课→小班**），完整证据链与 t35 误判复盘
 * 见该函数注释。
 *
 * 本文件保留的是**当年实际执行过的代码**（生产已执行，改动它对已建库无效），
 * 作为历史记录；之所以不直接改内联映射，是为了不造成「改了这里就能修数据」的误解 ——
 * 数据修正由后续迁移
 * `2026_09_21_000004_recompute_course_kind_after_mapping_correction` 重算回补，
 * 而不是改这个文件。
 *
 * （`2026_09_21_000003` 曾按误判方向重算过一次，该迁移已被 `000004` 纠正；
 *   两者都保留原样以维持「已执行迁移不改写」的纪律。）
 *
 * 下面「原来只有 booking_type…」那段背景描述仍然成立（列确实是为区分小班而建）。
 *
 * ---
 * （以下为原始注释）
 *
 * 原来只有 booking_type（来自随心瑜接口路径，只有「团课/私教」两值），
 * 而「小班（精品课）」是从 queryreversionprivate 取的，booking_type 也是「私教」，
 * 无法区分私教与小班。授课老师的「我的学员」只认私教，必须按 course_type 判定，
 * 之前每次都要解析 raw JSON，无法建索引。这里固化成一列并回填历史数据。
 *
 * course_type：2=私教，3=小班（精品课），1=团课（精品团课）。
 *
 * 兜底顺序（与 KyMemberSyncService::bookingFact 保持一致）：
 * 1. 行内 course_type 明确时以它为准；
 * 2. 缺失时按 booking_type 兜底 —— 团课接口(queryreversionleague)的行算团课，
 *    私教接口(queryreversionprivate)的行算私教。私教接口同时服务私教与小班，
 *    小班行会带 course_type=3，因此该接口上无 course_type 的行按私教处理，
 *    避免授课老师的「我的学员」静默为空。
 *    → 现行兜底已收口为：缺失一律 `group`（失败关闭，不放大可见范围），
 *      见 `courseKindFrom()` 与 t30 的收口。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ky_bookings', function (Blueprint $table) {
            $table->string('course_kind', 10)->default('')->after('booking_type');
            $table->index(['teacher_name', 'course_kind'], 'ky_bookings_teacher_kind_index');
        });

        DB::table('ky_bookings')->select('id', 'raw', 'booking_type')->orderBy('id')
            ->chunk(500, function ($rows) {
                $buckets = ['private' => [], 'small' => [], 'group' => []];
                foreach ($rows as $row) {
                    $raw = json_decode((string) $row->raw, true) ?: [];
                    $explicit = (string) ($raw['course_type'] ?? '');
                    // 方向正确（2=私教 / 3=小班），与现行 courseKindFrom() 一致。
                    // 保留原样是因为本迁移已在生产执行过 —— 改它不会重跑，也不会修数据。
                    $kind = match ($explicit) {
                        '2' => 'private',
                        '3' => 'small',
                        '1' => 'group',
                        default => ((string) $row->booking_type === '团课') ? 'group' : 'private',
                    };
                    $buckets[$kind][] = $row->id;
                }
                foreach ($buckets as $kind => $ids) {
                    if ($ids !== []) {
                        DB::table('ky_bookings')->whereIn('id', $ids)->update(['course_kind' => $kind]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('ky_bookings', function (Blueprint $table) {
            $table->dropIndex('ky_bookings_teacher_kind_index');
            $table->dropColumn('course_kind');
        });
    }
};
