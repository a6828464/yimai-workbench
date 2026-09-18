<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 补几个高频过滤列的索引。
 *
 * 1) `customers.updated_at` —— 五清单缓存的「表指纹」是
 *    `SELECT MAX(updated_at), COUNT(*) FROM customers`，这个指纹在 /customers、/today/todo、
 *    /today/alerts 的每个请求里都会被算一次（memberListIds → filteredIds）。没有索引时
 *    这是一次全表扫描；补上之后 MAX 走索引反向扫描、COUNT 走这条窄索引。
 *    指纹不能去掉 —— 它是「某条写入路径忘了失效」时的自愈兜底，去掉会让清单永久发旧值。
 *
 * 2) `leads.trial_teacher_user_id` / `leads.created_by_user_id` —— 老师侧范围过滤
 *    （scopeLeadsForUser、课后分析的候选人查询、留资删除权限）都在按这两列过滤。
 *
 * 3) `ky_bookings.member_id` / `ky_bookings.phone` —— 出勤窗口重算、新客培养、
 *    今日待办按手机号关联排课事实时都在用；这两列此前完全没有索引。
 *
 * 幂等：沿用 2026_09_09_000002 的 indexOnce 写法，生产半完成状态下重跑安全。
 * 注意：ky_bookings 在双店两年数据下可能较大，加索引会走 INPLACE 重建，
 * 建议在低峰执行（迁移日志里记录了耗时）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $t) {
                $this->indexOnce($t, ['updated_at'], 'customers_updated_at_idx');
            });
        }

        if (Schema::hasTable('leads')) {
            Schema::table('leads', function (Blueprint $t) {
                $this->indexOnce($t, ['trial_teacher_user_id'], 'leads_trial_teacher_user_idx');
                $this->indexOnce($t, ['created_by_user_id'], 'leads_created_by_user_idx');
            });
        }

        if (Schema::hasTable('ky_bookings')) {
            Schema::table('ky_bookings', function (Blueprint $t) {
                $this->indexOnce($t, ['member_id'], 'ky_bookings_member_idx');
                $this->indexOnce($t, ['phone'], 'ky_bookings_phone_idx');
            });
        }
    }

    public function down(): void
    {
        // 索引只影响性能，不回滚（回滚会让上述查询退回全表扫描）
    }

    private function indexOnce(Blueprint $t, array $columns, string $name): void
    {
        $existing = collect(Schema::getIndexes($t->getTable()))->pluck('name')->contains($name);
        if (! $existing) {
            $t->index($columns, $name);
        }
    }
};
