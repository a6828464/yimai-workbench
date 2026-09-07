<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 幂等：兼容生产半成功状态可重跑
        if (! Schema::hasColumn('users', 'phone')) {
            Schema::table('users', function (Blueprint $t) {
                $t->string('phone', 20)->nullable()->after('email');
            });
        }
        if (! Schema::hasColumn('users', 'avatar')) {
            Schema::table('users', function (Blueprint $t) {
                $t->text('avatar')->nullable()->after('phone');
            });
        }
        if (! Schema::hasColumn('users', 'profile')) {
            Schema::table('users', function (Blueprint $t) {
                // 个人中心 + 营销人设：{gender,age,years,specialties[],persona{},xhs{}}
                $t->json('profile')->nullable()->after('avatar');
            });
        }

        if (! Schema::hasTable('marketing_posts')) {
            Schema::create('marketing_posts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('platform', 10); // 朋友圈 / 小红书
                $t->string('title', 120)->default('');
                $t->text('content');
                $t->text('reply')->nullable(); // 首评建议
                $t->string('source', 10)->default('llm');
                $t->timestamps();
                $t->index(['user_id', 'platform', 'id'], 'mp_user_platform_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_posts');
        if (Schema::hasColumn('users', 'profile')) {
            DB::statement('ALTER TABLE users DROP COLUMN profile');
        }
        if (Schema::hasColumn('users', 'avatar')) {
            DB::statement('ALTER TABLE users DROP COLUMN avatar');
        }
        if (Schema::hasColumn('users', 'phone')) {
            DB::statement('ALTER TABLE users DROP COLUMN phone');
        }
    }
};
