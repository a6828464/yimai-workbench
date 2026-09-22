<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 重算全部会员的经营分层（P0-P5）。
 *
 * 分层是**派生数据**：它完全由卡项快照（cards_list / card_stats）、出勤三窗口、
 * 待复活开关、累计消费金额决定。正常路径（同步、改阈值）都会自动重算，
 * 这个命令用于两类场景：
 *   1. 迁移后的一次性回填（见 2026_09_22_000001 迁移，迁移里也调同一个函数）；
 *   2. 口径上线后怀疑分层与事实不符时，人工强制对齐。
 *
 * 之所以要单独做命令而不是「迁移里写一段 SQL」：分层规则会随阈值调整而变，
 * 写死在迁移里的 SQL 只对上线那一刻的数据有效，之后每次改阈值都会重新漂移。
 * 唯一口径来源是 helpers 的 customerDecision()，命令与迁移都只调它。
 */
class RecalculateMemberLayers extends Command
{
    protected $signature = 'rebuild:layers {--dry-run : 只统计将要变化的行数，不写库}';

    protected $description = '重算会员经营分层（P0-P5）并落库；--dry-run 只预演不写入';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->line('预演模式：不写库');
        }

        $before = $this->distribution();
        $this->line('重算前：'.$this->format($before));

        try {
            if ($dryRun) {
                // 预演：逐行算目标分层，只统计变化量
                $changed = 0;
                Customer::query()
                    ->select([
                        'id', 'layer', 'main_card', 'remain_times', 'expire_date', 'last_visit',
                        'attend_m1', 'attend_m2', 'attend_m3', 'in_revive', 'card_paid_amount',
                        'card_stats', 'cards_list',
                    ])
                    ->chunkById(500, function ($customers) use (&$changed) {
                        foreach ($customers as $c) {
                            if ((string) $c->layer !== customerLayerFor($c)) {
                                $changed++;
                            }
                        }
                    });
            } else {
                $changed = recalculateMemberLayers();
            }
        } catch (Throwable $e) {
            $this->error('重算失败：'.$e->getMessage());

            return self::FAILURE;
        }

        $after = $dryRun ? $before : $this->distribution();
        $this->info(($dryRun ? '预计变化' : '实际更新')." {$changed} 行");
        $this->line('重算后：'.$this->format($after));

        return self::SUCCESS;
    }

    /** @return array<string,int> layer => 行数（按 P0..P5 排序，缺失层补 0） */
    private function distribution(): array
    {
        $rows = DB::table('customers')
            ->selectRaw('layer, COUNT(*) as cnt')
            ->groupBy('layer')
            ->pluck('cnt', 'layer')
            ->all();

        $out = [];
        foreach (array_keys(layerDefinitions()) as $layer) {
            $out[$layer] = (int) ($rows[$layer] ?? 0);
        }

        return $out;
    }

    private function format(array $dist): string
    {
        return implode(' / ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($dist), array_values($dist)));
    }
}
