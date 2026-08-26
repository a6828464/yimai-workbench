<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 阶段1收尾：同步任务批次 + 对外发布快照（H5 分享跨设备可用） */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_jobs', function (Blueprint $t) {
            $t->id();
            $t->string('batch_no', 32)->unique();
            $t->string('data_type', 30);
            $t->string('venue', 10)->default('双店');
            $t->unsignedInteger('total_count')->default(0);
            $t->unsignedInteger('success_count')->default(0);
            $t->unsignedInteger('fail_count')->default(0);
            $t->string('status', 10)->default('成功');
            $t->string('operator', 30)->default('');
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });

        Schema::create('published_shares', function (Blueprint $t) {
            $t->id();
            $t->string('type', 16);                 // sales | training | ...
            $t->string('token', 40)->unique();      // 分享码
            $t->json('payload');                    // 页面所需完整数据
            $t->string('created_by', 30)->default('');
            $t->timestamps();
        });

        // 训练计划：前端为扁平结构，整包存 payload；member_name/status/share 冗余出便于查询
        Schema::table('training_plans', function (Blueprint $t) {
            $t->json('payload')->nullable()->after('member_name');
        });
    }

    public function down(): void
    {
        Schema::table('training_plans', function (Blueprint $t) {
            $t->dropColumn('payload');
        });
        Schema::dropIfExists('published_shares');
        Schema::dropIfExists('sync_jobs');
    }
};
