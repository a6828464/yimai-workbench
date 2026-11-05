<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 会员状态标记（人工维护的经营状态）。
 *
 * 为什么需要独立一列：现有字段各自表达的是别的事 ——
 *  - `layer`（P0-P5）是按卡项/出勤**算出来的**经营分层，会随同步重算，不能手改；
 *  - `in_revive`（bool）只是「待复活」这一个开关，没法表达「待续费 / 正常 / 已暂停」；
 *  - `status` 是档案完善度（跟进中/待完善），不是经营状态。
 * 馆主要的是"能一眼看出这个会员现在是什么状态、并且能手动改"，所以新增 `member_state`。
 *
 * 取值（后端 `Customer::MEMBER_STATES` 唯一来源）：正常 / 待续费 / 待复活 / 已暂停 / 已流失。
 * 与 `in_revive` 的联动在写入侧处理：选「待复活」→ in_revive=true，选其它 → false，
 * 保证「五清单的待复活」与「人工状态」不会一个说 A 一个说 B。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers') || Schema::hasColumn('customers', 'member_state')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            // 默认「正常」而不是空串：列表/详情不用到处判空，历史行也有明确语义
            $table->string('member_state', 12)->default('正常')->after('status');
            $table->index('member_state');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('customers') || ! Schema::hasColumn('customers', 'member_state')) {
            return;
        }
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['member_state']);
            $table->dropColumn('member_state');
        });
    }
};
