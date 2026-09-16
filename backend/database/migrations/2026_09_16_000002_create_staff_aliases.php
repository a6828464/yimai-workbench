<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 人员别名映射（把"业务表里出现的姓名"映射回账号）
 *
 * ## 为什么需要这张表
 *
 * 业务表的归属列（`leads.service_teacher` / `customers.consultant|owner` /
 * `ky_bookings.teacher_name` / `tasks.owner` / `training_plans.created_by` …）存的是
 * **姓名字符串，不是外键**。而姓名是会变的：
 *
 * 1. 账号改过名 —— 历史数据里还是老名字；
 * 2. 随心瑜后台登记的是另一个姓名（或全名/简称不一致）；
 * 3. 账号的 `nickname` 与 `name` 不同 —— 前端曾把 nickname 写进归属列，
 *    而后端按 `name` 过滤，导致这条数据对本人"消失"。
 *
 * 只比 `users.name` 的话，上面三种情况都会判成"不是这个人的数据"。这张表就是
 * 把"一个人可能被写成哪些名字"显式记录下来，判断归属时把他的全部名字都算上
 * （见 helpers.php 的 `staffNames()`）。
 *
 * `alias` 加唯一索引：一个名字只能映射到一个账号，否则归属就是歧义的 ——
 * 宁可在保存时被拦住，也不要在查询时静默算错。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('alias', 60)->unique();
            // 别名来源：ky=随心瑜同步回来的姓名；manual=管理员手工补的（含改名前的旧名）
            $table->string('source', 16)->default('manual');
            $table->string('note', 200)->default('');
            $table->timestamps();

            $table->index(['user_id']);
        });

        // 把现有账号的 nickname 差异登记为别名，避免升级后历史归属断掉。
        // 只登记"与 name 不同"的，name 本身不用登记（staffNames 恒把 name 算在内）。
        foreach (DB::table('users')->orderBy('id')->get(['id', 'name', 'nickname']) as $u) {
            $nickname = trim((string) $u->nickname);
            if ($nickname === '' || $nickname === (string) $u->name) {
                continue;
            }
            $taken = DB::table('staff_aliases')->where('alias', $nickname)->exists();
            if ($taken) {
                continue;
            }
            DB::table('staff_aliases')->insert([
                'user_id' => $u->id,
                'alias' => $nickname,
                'source' => 'manual',
                'note' => '升级时自动登记的历史昵称（曾用于归属写入）',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_aliases');
    }
};
