<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * v3.3.0 · 会员经营分层落库 + 待续费判定精准化（结构部分）。
 *
 * 两件事：
 *
 * 1) `customers(venue, layer)` 联合索引。
 *    经营池分层页面按「本店 + 分层」筛选，`layer` 列此前只有单列索引
 *    （customers_layer_idx），与 venue 组合时优化器仍要回表过滤。
 *    联合索引让「店长看本店某分层」变成一次索引区间扫描。
 *
 * 2) 一次性回填历史分层。
 *    「P0-P4 恒为空」的根因是同步把新会员写死成 `layer='P4'`，
 *    历史行因此全部停在 P4/P5。光改代码不会纠正**已存在**的行，
 *    所以这里回填一次；回填调用的是与运行时**同一个** recalculateMemberLayers()，
 *    不另写一份 SQL —— 否则口径立刻分叉成两套。
 *
 * 为什么回填放在迁移里而不是只靠 `php artisan rebuild:layers`：
 * 迁移是「代码上线即自动对齐」的保证；命令是「上线后怀疑不准时」的手工工具。
 * 两者共用同一实现，重复执行无害（幂等）。
 *
 * 幂等：indexOnce 沿用 2026_09_17_000003 的写法；回填本身只写变化的行。
 * 回填失败**不阻断迁移**——分层是派生数据，卡项事实才是源头，
 * 上线后跑一次 rebuild:layers 即可，不该让结构变更卡住部署。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $t) {
                $this->indexOnce($t, ['venue', 'layer'], 'customers_venue_layer_idx');
            });
        }

        // 回填历史分层：与运行时同源（helpers.php 的 recalculateMemberLayers）
        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'layer')) {
            try {
                $changed = recalculateMemberLayers();
                Log::info('迁移回填会员经营分层完成', ['changed' => $changed]);
            } catch (Throwable $e) {
                // 只记录，不抛出：结构变更不应因派生数据回填失败而中断部署
                Log::warning('迁移回填会员经营分层失败，请上线后执行 php artisan rebuild:layers', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // 索引只影响性能，不回滚（回滚会让本店分层筛选退回全表扫描）。
        // 分层值也不回滚：它是卡项事实的派生结果，回滚到「全部 P4」等于把缺陷装回去。
    }

    private function indexOnce(Blueprint $t, array $columns, string $name): void
    {
        $existing = collect(Schema::getIndexes($t->getTable()))->pluck('name')->contains($name);
        if (! $existing) {
            $t->index($columns, $name);
        }
    }
};
