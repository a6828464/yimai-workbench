<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 修「已分配负责人却还挂着『分配负责人并完善会员档案』」的历史数据。
 *
 * 起因（馆主 2026-11-05 反馈）：会员详情里明明已经有会籍顾问，仍显示
 * 「分配负责人并完善会员档案」——因为同步建档时把这句话**写死**成了 next_action，
 * 之后无论是同步给的顾问还是人工改的顾问，都没人回头改它。
 *
 * 口径：只要 `consultant` 有值，或 `owner` 是一个真人（非空、非「未分配」），
 * 负责人就算定了，待办收敛为「完善会员档案」；只有真的没人认领才保留原话。
 *
 * down 不做反向改写：这是一次性的语义纠偏，回滚迁移不应该把"已分配的人"
 * 重新说成"待分配"（那会制造新的错误信息）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers') || ! Schema::hasColumn('customers', 'next_action')) {
            return;
        }

        DB::table('customers')
            ->where('next_action', '分配负责人并完善会员档案')
            ->where(function ($q) {
                $q->where(function ($a) {
                    $a->whereNotNull('consultant')->where('consultant', '!=', '');
                })->orWhere(function ($b) {
                    $b->whereNotNull('owner')
                        ->where('owner', '!=', '')
                        ->where('owner', '!=', '未分配');
                });
            })
            ->update(['next_action' => '完善会员档案']);
    }

    public function down(): void
    {
        // 见类注释：不回滚语义纠偏
    }
};
