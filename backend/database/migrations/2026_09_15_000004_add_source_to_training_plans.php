<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 训练计划追加上游来源。
 *
 * 课后分析（含体测解读）确认后可直接流转成训练计划，
 * 记录来源便于：会员档案里回溯「这份计划是哪次课/哪次体测得出的」。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('source_review_id')->nullable()->index()->after('confirmed_at');
            $table->unsignedBigInteger('source_body_test_id')->nullable()->after('source_review_id');
        });
    }

    public function down(): void
    {
        Schema::table('training_plans', function (Blueprint $table) {
            $table->dropColumn(['source_review_id', 'source_body_test_id']);
        });
    }
};
