<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 体验课课后分析 ＋ 训练方向。
 *
 * 与 training_plans 的区别（有意避开它的三个坑）：
 * 1. 关联一律走 id（lead_id / customer_id / booking_id / teacher_user_id），
 *    不再用 member_name 纯文本；姓名只做冗余展示。
 * 2. 不用 created_by 姓名做隔离键，权限统一由 scope 函数按角色计算。
 * 3. 不用「整表删除重建」的保存方式，逐条 upsert。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_class_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('venue', 10)->index();

            // 场景：trial=体验课，private_first=私教首课，private=私教常规课
            $table->string('scene', 20)->default('trial')->index();

            // 关联（一律 id）
            $table->unsignedBigInteger('lead_id')->nullable()->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->unsignedBigInteger('booking_id')->nullable()->index();
            $table->unsignedBigInteger('teacher_user_id')->nullable()->index();

            // 冗余展示字段
            $table->string('teacher_name', 30)->default('')->index();
            $table->string('student_name', 60)->default('');
            $table->string('student_phone', 20)->default('')->index();
            $table->dateTime('class_at')->nullable()->index();
            $table->string('course_name', 120)->default('');

            // 学员类型：产后恢复 / 塑形线条 / 体态调整 / 康复 / 减脂体能
            $table->string('student_type', 20)->default('')->index();

            // 红线命中：命中则不产出对客训练方案，只给「建议进一步专业评估」沟通卡
            $table->boolean('red_flag')->default(false)->index();

            $table->string('status', 20)->default('草稿');  // 草稿 / 已确认
            $table->string('source', 10)->default('rules'); // rules=规则引擎生成 / manual=老师手写
            $table->json('share')->nullable();              // {enabled, code, views}
            $table->json('payload')->nullable();            // 完整表单与生成结果
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['venue', 'teacher_name']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_class_reviews');
    }
};
