<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 训练计划补上门店。
 *
 * 原来只有 created_by（按人隔离），导致店长/超管在训练计划页看不到别人建的计划
 * ——老师转出来的计划只有那个老师自己能看到。补上门店后按门店范围可见，
 * 与其他模块（会员、留资、任务）的口径一致。
 *
 * 回填优先级：来源课后分析的门店 → 创建人所在门店。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_plans', function (Blueprint $table) {
            $table->string('venue', 10)->default('')->index()->after('member_name');
        });

        // 从关联的课后分析取门店
        DB::table('training_plans')
            ->whereNotNull('source_review_id')
            ->orderBy('id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    $venue = DB::table('post_class_reviews')->where('id', $row->source_review_id)->value('venue');
                    if ($venue) {
                        DB::table('training_plans')->where('id', $row->id)->update(['venue' => $venue]);
                    }
                }
            });

        // 其余按创建人所在门店回填
        DB::table('training_plans')->where('venue', '')->orderBy('id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    $venue = DB::table('users')->where('name', $row->created_by)->value('venue');
                    if ($venue) {
                        DB::table('training_plans')->where('id', $row->id)->update(['venue' => $venue]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('training_plans', function (Blueprint $table) {
            $table->dropColumn('venue');
        });
    }
};
