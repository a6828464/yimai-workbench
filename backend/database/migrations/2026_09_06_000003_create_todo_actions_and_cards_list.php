<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 幂等：生产环境曾出现「cards_list 已加列、todo_actions 建表失败」的半完成状态
        // （MySQL DDL 不带事务），重跑时必须跳过已完成的部分。

        // 会员有效卡项明细（同步写入）：供会员管理「剩余课时」列展示每张卡的剩余情况
        if (! Schema::hasColumn('customers', 'cards_list')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->json('cards_list')->nullable()->after('card_paid_amount');
            });
        }

        // 今日待办操作留痕：标记后当天从待办列表消隐，并按类型流转业务状态
        if (! Schema::hasTable('todo_actions')) {
            Schema::create('todo_actions', function (Blueprint $t) {
                $t->id();
                $t->date('action_date')->index();               // 处理日期（按天消隐）
                $t->string('todo_type', 20)->index();           // bookings/renewals/churnRisks/birthdays/trials/newLeads
                // 80 字符：utf8mb4 下唯一索引 (todo_key, action_date) 约 323 字节，
                // 兼容未开启 innodb_large_prefix 的 MySQL 5.6/5.7 旧配置（767 字节上限）
                $t->string('todo_key', 80);
                $t->string('action', 30)->default('已处理');     // 动作文案
                $t->string('remark', 200)->default('');
                $t->unsignedBigInteger('user_id')->index();
                $t->string('user_name')->default('');
                $t->string('user_role', 20)->default('');
                $t->string('venue', 10)->default('');
                $t->timestamps();
                $t->unique(['todo_key', 'action_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('todo_actions');
        if (Schema::hasColumn('customers', 'cards_list')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('cards_list');
            });
        }
    }
};
