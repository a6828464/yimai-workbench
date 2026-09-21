<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 课型独立成列。
 *
 * ⚠️⚠️ 本迁移里的 `course_type` 映射方向**已被确认为反向**，切勿据此理解口径！⚠️⚠️
 *
 * 「当前正确口径」只在一处：`app/Support/helpers.php` 的 **`courseKindFrom()`**
 * （1=团课 / **2=精品课→小班** / **3=私教课→私教**）。
 *
 * 本文件保留的是**当年实际执行过的代码**（生产已执行，改动它对已建库无效），
 * 作为历史记录；之所以不直接改内联映射，是为了不造成「改了这里就能修数据」的误解 ——
 * 数据修正由后续迁移 `2026_09_21_000003_recompute_course_kind_after_mapping_fix`
 * 重算回补，而不是改这个文件。
 *
 * 判定依据（用户实地勘察的第一手笔记）：`随心瑜后台解读/notes.md:425-426`
 * 「团课→显示『精品团课』；精品课→显示『私教小班』；私教课→显示『定制私教』」
 * ⇒ `course_type=1/2/3` 分别对应 团课/精品课/私教课；
 * 另见《随心瑜后台完整解读》`:262-268`（`course_type_name` 字段级佐证）。
 *
 * 下面「原来只有 booking_type…」那段背景描述仍然成立（列确实是为区分小班而建），
 * 但**其后的映射与兜底描述是反向的**，已逐处标注【已废弃】。
 *
 * ---
 * （以下为原始注释，映射部分已废弃）
 *
 * 原来只有 booking_type（来自随心瑜接口路径，只有「团课/私教」两值），
 * 而「小班（精品课）」是从 queryreversionprivate 取的，booking_type 也是「私教」，
 * 无法区分私教与小班。授课老师的「我的学员」只认私教，必须按 course_type 判定，
 * 之前每次都要解析 raw JSON，无法建索引。这里固化成一列并回填历史数据。
 *
 * course_type：【已废弃】2=私教，3=小班（精品课），1=团课（精品团课）。
 *   → 正确为：2=精品课（小班），3=私教课（私教）。见文件头与 `courseKindFrom()`。
 *
 * 兜底顺序（与 KyMemberSyncService::bookingFact 保持一致）：
 * 1. 行内 course_type 明确时以它为准；
 * 2. 【已废弃】缺失时按 booking_type 兜底 —— 团课接口(queryreversionleague)的行算团课，
 *    私教接口(queryreversionprivate)的行算私教。私教接口同时服务私教与小班，
 *    小班行会带 course_type=3，因此该接口上无 course_type 的行按私教处理，
 *    避免授课老师的「我的学员」静默为空。
 *    → 现行兜底：缺失一律 `group`（失败关闭，不放大可见范围），
 *      见 `courseKindFrom()` 与本任务 t30 的收口。
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
                    // 【已废弃·勿照抄】下面这个 match 的 2/3 方向是反的（当年写错）。
                    // 现行口径见 courseKindFrom()：'2' => 'small'、'3' => 'private'。
                    // 保留原样是因为本迁移已在生产执行过 —— 改它不会重跑，也不会修数据；
                    // 数据修正是 2026_09_21_000003 那个迁移的职责。
                    $kind = match ($explicit) {
                        '2' => 'private',  // 【错】应为 small（2=精品课/对客「私教小班」）
                        '3' => 'small',    // 【错】应为 private（3=私教课/对客「定制私教」）
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
