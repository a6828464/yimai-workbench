<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\KyMemberSyncService;
use App\Support\CardLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * t9 复核：**冻结快照 → 真实链路**独立对账（round 2）。
 *
 * 存在意义：t4/t8 报告里的 `in=190 / P0=190 / P3=63 / P4=542 / P5=765` 是他们的
 * 自述。这里用**同一份快照 + 同一份生产链路**（`summarizeCards` → `customerDecision`）
 * 独立重算，任何人改坏了判定语义都会在这里红。
 *
 * 口径（必须与规范 §8.0 的 S-T1/S-H1 一致）：
 *  - 快照行按 `(venue_id, id)` 去重，`can_op=1` 优先（同 `summarizeCards()` 的镜像行规则）；
 *  - 会员-门店行 = `(venue_id, member_id)` 分组，每组单独喂给 `summarizeCards()`；
 *  - 出勤三窗口与 `last_visit` 置 0/null（快照不含出勤），因此 P1/P2 结构上为 0，
 *    这正是「in / P0 / P3 / P4 / P5」五项可加总为 1560 的原因；
 *  - 规则取默认值 `rules()`（测试库无 AppSetting 行）。
 *
 * 快照不在本机时自动跳过（不阻塞 CI）。
 */
class SnapshotReconciliationV39Test extends TestCase
{
    use RefreshDatabase;

    private const SNAPSHOT = '/Users/ttt/yimai-master-data/ky_cards_live_20260924.json';

    /** @return array{0:int,1:array<string,int>,2:int,3:int} [in, layers, expiredBalanceIn, total] */
    private function reconcile(): array
    {
        $raw = json_decode((string) file_get_contents(self::SNAPSHOT), true);
        $this->assertIsArray($raw);

        // ① 去重：同一 (venue,id) 保留 can_op=1 的那行
        $best = [];
        foreach ($raw as $row) {
            $key = (string) ($row['venue_id'] ?? '').'|'.(string) ($row['id'] ?? '');
            if (! isset($best[$key])) {
                $best[$key] = $row;

                continue;
            }
            if ((string) ($row['can_op'] ?? '') === '1' && (string) ($best[$key]['can_op'] ?? '') !== '1') {
                $best[$key] = $row;
            }
        }

        // ② 按会员-门店行分组
        $members = [];
        foreach ($best as $row) {
            $members[(string) ($row['venue_id'] ?? '').'|'.(string) ($row['member_id'] ?? '')][] = $row;
        }

        $method = new \ReflectionMethod(KyMemberSyncService::class, 'summarizeCards');
        $method->setAccessible(true);
        $rules = rules();

        $in = 0;
        $expiredBalanceIn = 0;
        $layers = [];
        foreach ($members as $rows) {
            $sum = $method->invoke(null, $rows, $rules);
            $c = (new Customer)->forceFill([
                'main_card' => $sum['main_card'],
                'remain_times' => $sum['remain_times'],
                'expire_date' => $sum['expire_date'],
                'card_stats' => $sum['card_stats'],
                'cards_list' => $sum['cards_list'],
                'card_paid_amount' => 0,
                'attend_m1' => 0,
                'attend_m2' => 0,
                'attend_m3' => 0,
                'last_visit' => null,
                'in_revive' => false,
            ]);
            $d = customerDecision($c, $rules);
            if ($d['renewal']['in']) {
                $in++;
            }
            if ($d['expiredBalance']['in']) {
                $expiredBalanceIn++;
            }
            $layers[$d['layer']] = ($layers[$d['layer']] ?? 0) + 1;
        }
        ksort($layers);

        return [$in, $layers, $expiredBalanceIn, count($members)];
    }

    public function test_snapshot_reconciliation_matches_v39_baseline(): void
    {
        if (! is_file(self::SNAPSHOT)) {
            $this->markTestSkipped('快照不存在：'.self::SNAPSHOT);
        }

        [$in, $layers, $expiredBalanceIn, $total] = $this->reconcile();

        fwrite(STDERR, "\nSNAPSHOT ".json_encode([
            'in' => $in,
            'layers' => $layers,
            'expiredBalanceIn' => $expiredBalanceIn,
            'members' => $total,
        ], JSON_UNESCAPED_UNICODE)."\n");

        $this->assertSame(1560, $total, '会员-门店行数（去重后）');
        $this->assertSame(190, $in, '待续费人数不得漂移');
        $this->assertSame(190, $layers['P0'] ?? 0, 'P0 = 命中数（出勤置空时）');
        $this->assertSame(63, $layers['P3'] ?? 0, 'P3 过期有余额');
        $this->assertSame(542, $layers['P4'] ?? 0, 'P4');
        $this->assertSame(765, $layers['P5'] ?? 0, 'P5（只持体验/赠品卡）');
        $this->assertArrayNotHasKey('P1', $layers, '出勤置空时不应有 P1');
        $this->assertArrayNotHasKey('P2', $layers, '出勤置空时不应有 P2');
        // 过期有余额（与 layer 正交：命中待续费的人也可能有沉睡余额）
        $this->assertSame(80, $expiredBalanceIn, '过期有余额人数');
    }

    /** 守门指标：任何 instance 的 state 与 remaining 必须满足 §2.4 的四条硬约束 */
    public function test_snapshot_instances_obey_lifecycle_invariants(): void
    {
        if (! is_file(self::SNAPSHOT)) {
            $this->markTestSkipped('快照不存在：'.self::SNAPSHOT);
        }

        $raw = json_decode((string) file_get_contents(self::SNAPSHOT), true);
        $violations = [];
        foreach ($raw as $row) {
            $i = CardLifecycle::normalize($row);
            if ($i['type'] === CardLifecycle::TYPE_TIME && $i['deadline'] !== null
                && $i['daysUntil'] !== null && $i['daysUntil'] < 0 && $i['remaining'] !== 0.0) {
                $violations[] = 'type2 过期但 remaining≠0: '.($i['id'] ?? '?');
            }
            if ($i['state'] === 'history' && CardLifecycle::isUsable($i)) {
                $violations[] = 'history 实例被判 usable: '.($i['id'] ?? '?');
            }
            if ($i['state'] === 'current' && $i['daysUntil'] !== null && $i['daysUntil'] < 0) {
                $violations[] = 'current 实例 deadline 已过: '.($i['id'] ?? '?');
            }
        }
        $this->assertSame([], $violations, '生命周期不变量被破坏');
    }
}
