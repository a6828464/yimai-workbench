<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 体测报告（门店智能魔镜 / ruleye）。
 *
 * 报告原文由设备侧生成，工作台只做解析与归档：
 *  - 解析结果拆列存放，方便按指标检索与纵向对比（同一会员多次体测）；
 *  - 同时保留 raw，便于接口字段变化后回溯重算；
 *  - 与会员/客资的关联走 id，与 post_class_reviews 保持同一套约定。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('body_test_reports', function (Blueprint $table) {
            $table->id();
            $table->string('venue', 10)->index();

            // ruleye 侧的报告标识（报告链接里的 body_test_id），同一份报告只存一次
            $table->string('body_test_id', 40)->unique();

            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->unsignedBigInteger('lead_id')->nullable()->index();

            $table->string('member_name', 60)->default('');
            $table->string('phone', 20)->default('')->index();
            $table->string('source_url', 500)->default('');
            $table->dateTime('tested_at')->nullable()->index();

            // 体态评分（报告自带的 score）
            $table->decimal('score', 6, 1)->nullable();

            $table->json('profile')->nullable();      // 年龄/性别/身高/体重/BMI/体脂率/身体类型
            $table->json('composition')->nullable();  // 体成分逐项：值、档位、标准区间、风险与建议
            $table->json('abnormal')->nullable();     // 其中的异常项
            $table->json('posture')->nullable();      // 体态异常项
            $table->json('observations')->nullable(); // 映射出的训练观察项（喂给规则引擎）
            $table->json('directions')->nullable();   // 会员登记的训练方向
            $table->json('health')->nullable();       // 健康问卷
            $table->json('red_flags')->nullable();    // 红线提示
            $table->json('raw')->nullable();          // 接口原始返回

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'tested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('body_test_reports');
    }
};
