<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\KyMemberSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * t9（round 2）：针对 t8 六项修复的**边界反例**。
 *
 * 只回答一个问题：修复本身有没有留下新的缝（不是重复 t6/t7 的验收矩阵）。
 * 每个用例打印一行 `R2PROBE` 供报告引用。
 */
class Round2ProbeV39Test extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private static int $memberSeq = 0;

    private function ymd(int $offset): string
    {
        return now()->startOfDay()->addDays($offset)->toDateString();
    }

    private function card(string $title, int $type, string $status, ?string $deadline, int|float|null $residue, array $more = []): array
    {
        $this->seq++;

        return array_merge([
            'id' => 'r2-'.$this->seq,
            'card_no' => 'R2'.str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'card_id' => 'r2p-'.$this->seq,
            'card_title' => $title,
            'type' => (string) $type,
            'status' => $status,
            'is_taste' => '0',
            'can_op' => '1',
            'residue_amount' => $residue,
            'residue_days' => null,
            'curr_unit_cash_value' => '1',
            'usage_total' => 0,
            'create_time' => $this->ymd(-180),
            'deadline' => $deadline,
            'expiry_days' => 0,
        ], $more);
    }

    private function aggregate(array $rows, ?array $rules = null): array
    {
        $m = new \ReflectionMethod(KyMemberSyncService::class, 'summarizeCards');
        $m->setAccessible(true);

        return $m->invoke(null, $rows, $rules ?? rules());
    }

    private function member(string $name, array $rows, array $extra = []): Customer
    {
        $sum = $this->aggregate($rows);
        self::$memberSeq++;

        return Customer::create(array_merge([
            'name' => $name,
            'phone' => '135'.str_pad((string) self::$memberSeq, 8, '0', STR_PAD_LEFT),
            'phone_tail' => '0000',
            'external_id' => 'ky:'.(500000 + self::$memberSeq),
            'venue' => '绿地店',
            'source' => 'KeepYoga',
            'owner' => '店长',
            'consultant' => '店长',
            'status' => '在籍',
            'layer' => 'P4',
            'main_card' => $sum['main_card'],
            'remain_times' => $sum['remain_times'],
            'expire_date' => $sum['expire_date'],
            'card_paid_amount' => 0,
            'card_stats' => $sum['card_stats'],
            'cards_list' => $sum['cards_list'],
            'attend_m1' => 6,
            'attend_m2' => 6,
            'attend_m3' => 6,
            'in_revive' => false,
            'last_visit' => $this->ymd(-3),
        ], $extra));
    }

    private function legacyMember(string $name, array $attrs): Customer
    {
        self::$memberSeq++;

        return Customer::create(array_merge([
            'name' => $name,
            'phone' => '134'.str_pad((string) self::$memberSeq, 8, '0', STR_PAD_LEFT),
            'phone_tail' => '0000',
            'external_id' => 'ky:'.(600000 + self::$memberSeq),
            'venue' => '绿地店',
            'source' => 'KeepYoga',
            'owner' => '店长',
            'consultant' => '店长',
            'status' => '在籍',
            'layer' => 'P4',
            'attend_m1' => 6,
            'attend_m2' => 6,
            'attend_m3' => 6,
            'in_revive' => false,
            'last_visit' => $this->ymd(-3),
        ], $attrs));
    }

    private function decide(Customer $c, ?array $rules = null): array
    {
        return customerDecision($c->fresh(), $rules ?? rules());
    }

    private function probe(string $id, array $facts): void
    {
        fwrite(STDERR, "\nR2PROBE {$id} ".json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * R2-01（负向对照）：A-01 的输入域——`cards_list` / `historyCards` **不含**体验/赠品卡，
     * 所以判定层的 `annotateSeries()` 不可能把 excluded 实例算进 seriesCount。
     *
     * 这条是 round-2 的自我证伪：我原本怀疑「判定侧喂 annotateSeries 的集合包含 excluded，
     * 与同步侧不一致」，实测证伪 —— 双端在同步时就已过滤，且旧 cards_list 无 card_id，
     * 被 title 排除的卡也不可能与真实卡同系列。
     */
    public function test_r2_01_excluded_instances_never_reach_series_annotation(): void
    {
        $taste = $this->card('员工卡', 1, '5', $this->ymd(200), 8);
        $real = $this->card('私教10节', 1, '5', $this->ymd(200), 8);
        $sum = $this->aggregate([$taste, $real]);

        $c = $this->member('R2-01', [$taste, $real]);
        $d = $this->decide($c);

        $this->probe('R2-01', [
            'cardsListTitles' => array_column($sum['cards_list'], 'title'),
            'historyCards' => count($sum['card_stats']['historyCards'] ?? []),
            'excludedCards' => array_column($sum['card_stats']['excludedCards'] ?? [], 'title'),
            'decisionInstances' => array_map(fn ($i) => [$i['title'], $i['seriesIndex'], $i['seriesCount']], $d['renewal']['instances']),
        ]);

        $this->assertNotContains('员工卡', array_column($sum['cards_list'], 'title'), '体验卡不进 cards_list');
        $this->assertSame(['员工卡'], array_column($sum['card_stats']['excludedCards'] ?? [], 'title'));
        $this->assertCount(1, $d['renewal']['instances'], '判定域只有真实卡');
        $this->assertSame(1, $d['renewal']['instances'][0]['seriesCount'], '单卡系列计数=1（excluded 不参与）');
    }

    /** R2-02：A-02 的边界——历史期限卡 validDays=null 不得报 valid_days_missing（不进命中域）+ 文案不得空日期 */
    public function test_r2_02_history_time_card_without_valid_days(): void
    {
        $row = $this->card('历史年卡', 2, '6', null, 0, ['residue_days' => 10, 'expiry_days' => 0, 'expiry_months' => 0]);
        $c = $this->member('R2-02', [$row]);
        $d = $this->decide($c);

        $this->probe('R2-02', [
            'degraded' => $d['renewal']['degradedCodes'],
            'remaining' => $d['renewal']['instances'][0]['remaining'] ?? null,
            'note' => $d['renewal']['instances'][0]['note'] ?? null,
            'in' => $d['renewal']['in'],
        ]);

        $this->assertNotContains('valid_days_missing', $d['renewal']['degradedCodes'], '历史实例不进命中域，不报占比降级');
        $this->assertSame(0, (int) ($d['renewal']['instances'][0]['remaining'] ?? -1), '历史期限卡剩余恒 0');
        $this->assertStringNotContainsString('已于  到期', (string) ($d['renewal']['instances'][0]['note'] ?? ''));
    }

    /** R2-03：A-01 × A-10——三张同字段、无身份的过期期限卡：序号 1/2/3，且每张 remaining 都是 0 */
    public function test_r2_03_three_identical_expired_time_cards(): void
    {
        $legacyCards = [];
        for ($n = 0; $n < 3; $n++) {
            $legacyCards[] = ['title' => '无身份年卡', 'unit' => '天', 'residue' => 0, 'bound' => null,
                'deadline' => $this->ymd(-15), 'status' => '过期', 'unactivated' => false, 'type' => '2', 'validDays' => 365];
        }
        $c = $this->legacyMember('R2-03', [
            'main_card' => '无身份年卡',
            'remain_times' => null,
            'expire_date' => $this->ymd(-15),
            'card_paid_amount' => 0,
            'card_stats' => ['dataVersion' => 1],
            'cards_list' => $legacyCards,
        ]);
        $d = $this->decide($c);

        $this->probe('R2-03', [
            'instances' => array_map(fn ($i) => [$i['seriesIndex'], $i['seriesCount'], $i['remaining'], $i['note']], $d['renewal']['instances']),
            'expiredBalanceIn' => $d['expiredBalance']['in'],
            'layer' => $d['layer'],
        ]);

        $this->assertSame([1, 2, 3], array_column($d['renewal']['instances'], 'seriesIndex'));
        $this->assertSame([0, 0, 0], array_map(fn ($i) => (int) $i['remaining'], $d['renewal']['instances']));
        $this->assertFalse($d['expiredBalance']['in'], '期限卡过期不产生过期余额');
    }

    /** R2-04：A-02 的抑制边界——默认规则（占比关闭）时 renewal.degraded 不含该码，但 instances[] 仍可见 */
    public function test_r2_04_valid_days_missing_suppressed_but_visible_per_instance(): void
    {
        $row = $this->card('缺有效期年卡', 2, '5', $this->ymd(200), 0, ['expiry_days' => 0, 'expiry_months' => 0]);
        $c = $this->member('R2-04', [$row]);
        $d = $this->decide($c);

        $this->probe('R2-04', [
            'renewalDegraded' => $d['renewal']['degradedCodes'],
            'instanceDegraded' => $d['renewal']['instances'][0]['degraded'] ?? [],
            'expirePercent' => (int) (rules()['renewalExpirePercent'] ?? 0),
        ]);

        $this->assertNotContains('valid_days_missing', $d['renewal']['degradedCodes'], '占比关闭时不打扰运营');
        $this->assertContains('valid_days_missing', $d['renewal']['instances'][0]['degraded'], '逐卡诊断仍可查（B-02）');
    }
}
