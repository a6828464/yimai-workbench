<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 课型独立成列。
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
