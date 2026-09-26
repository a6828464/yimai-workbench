<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\User;
use App\Services\KyMemberSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CardStatsRenewalTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_with_one_used_up_card_and_one_healthy_card_is_not_flagged(): void
    {
        // 用户场景：两张卡，其中一张用完没余额，另一张还有 40 节 → 不应标记待续费
        $c = $this->customer('多卡会员', ['main_card' => '私教年卡', 'attend_m3' => 5], [
            'countResidue' => 40, 'countBound' => 90, 'daysLeft' => null, 'daysTotal' => null,
        ]);

        $this->assertNotInRenewalList($c);
    }

    public function test_big_count_card_tail_is_flagged_by_percent(): void
    {
        // 200 节大卡用剩 15 节：绝对阈值(10)没到，但占比 7.5% ≤ 20% → 标记
        $c = $this->customer('大卡尾段会员', ['attend_m3' => 5], [
            'countResidue' => 15, 'countBound' => 200, 'daysLeft' => null, 'daysTotal' => null,
        ]);

        $this->assertInRenewalList($c);
    }

    public function test_unactivated_card_inventory_keeps_member_out_of_renewal(): void
    {
        // 未开卡 50 节 = 满库存 100% → 不标记（剩余课时合计已含未开卡）
        $c = $this->customer('未开卡会员', ['attend_m3' => 5], [
            'countResidue' => 50, 'countBound' => 50, 'daysLeft' => null, 'daysTotal' => null,
        ]);

        $this->assertNotInRenewalList($c);
    }

    public function test_time_card_member_is_judged_by_expire_days_not_zero_lessons(): void
    {
        // 纯时间卡会员：无次卡（countResidue=null），不能因「0 节」被误判；到期临近才标记
        $far = $this->customer('时间卡远期', ['main_card' => '年卡', 'attend_m3' => 5], [
            'countResidue' => null, 'countBound' => 0, 'daysLeft' => 200, 'daysTotal' => 365,
        ], ['expire_date' => now()->addDays(200)->toDateString()]);
        $this->assertNotInRenewalList($far);

        $near = $this->customer('时间卡临期', ['main_card' => '年卡', 'attend_m3' => 5], [
            'countResidue' => null, 'countBound' => 0, 'daysLeft' => 20, 'daysTotal' => 365,
        ], ['expire_date' => now()->addDays(20)->toDateString()]);
        $this->assertInRenewalList($near);
    }

    public function test_time_card_percent_rule_can_be_enabled(): void
    {
        AppSetting::create(['rules' => [
            'renewalThreshold' => 10, 'renewalCountPercent' => 20,
            'renewalExpireDays' => 30, 'renewalExpirePercent' => 10,
            'vipAmountThreshold' => 30000, 'declineMode' => 'strict',
            'predropMin' => 15, 'predropMax' => 30, 'reviveDays' => 30,
        ]]);
        // 有效期用了 91.8%（30/365 剩余），到期日还远 → 占比规则命中
        $c = $this->customer('有效期占比会员', ['main_card' => '年卡', 'attend_m3' => 5], [
            'countResidue' => null, 'countBound' => 0, 'daysLeft' => 30, 'daysTotal' => 365,
        ], ['expire_date' => now()->addDays(200)->toDateString()]);

        $this->assertInRenewalList($c);
    }

    public function test_legacy_customers_without_card_stats_fall_back_to_old_fields(): void
    {
        $low = $this->customer('旧数据少课时', ['attend_m3' => 5], null, ['remain_times' => 5]);
        $this->assertInRenewalList($low);

        $far = $this->customer('旧数据远期', ['attend_m3' => 5], null, [
            'expire_date' => now()->addDays(60)->toDateString(),
        ]);
        $this->assertNotInRenewalList($far);
    }

    public function test_member_without_any_count_card_is_not_flagged_as_zero_remaining(): void
    {
        // 无次卡且无到期信息：不能因 remain 缺省被当成 0 节
        $c = $this->customer('无次卡会员', ['main_card' => '年卡', 'attend_m3' => 5], [
            'countResidue' => null, 'countBound' => 0, 'daysLeft' => null, 'daysTotal' => null,
        ]);

        $this->assertNotInRenewalList($c);
    }

    public function test_rules_endpoints_accept_and_return_renewal_thresholds(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'super', 'role' => 'R_SUPER', 'status' => '启用']));

        $this->putJson('/api/member-rules', [
            'renewalThreshold' => 8,
            'renewalCountPercent' => 15,
            'renewalExpireDays' => 45,
            'renewalExpirePercent' => 5,
            'vipAmountThreshold' => 30000,
            'declineMode' => 'strict',
            'predropMin' => 15,
            'predropMax' => 30,
            'reviveDays' => 30,
        ])->assertOk()
            ->assertJsonPath('data.renewalCountPercent', 15)
            ->assertJsonPath('data.renewalExpireDays', 45)
            ->assertJsonPath('data.renewalExpirePercent', 5);

        $this->getJson('/api/member-rules')->assertOk()
            ->assertJsonPath('data.renewalThreshold', 8)
            ->assertJsonPath('data.renewalExpireDays', 45);
    }

    private function assertInRenewalList(Customer $c): void
    {
        $ids = $this->renewalIds();
        $this->assertContains($c->id, $ids, "会员 {$c->name} 应在待续课清单");
    }

    private function assertNotInRenewalList(Customer $c): void
    {
        $ids = $this->renewalIds();
        $this->assertNotContains($c->id, $ids, "会员 {$c->name} 不应在待续课清单");
    }

    private function renewalIds(): array
    {
        Sanctum::actingAs(User::factory()->create(['username' => uniqid('s'), 'role' => 'R_SUPER', 'status' => '启用']));
        $rows = $this->getJson('/api/customers?list='.urlencode('待续课').'&size=500')->assertOk()->json('data.records');

        return array_map(fn ($r) => $r['id'], $rows);
    }

    private function customer(string $name, array $overrides, ?array $cardStats, array $extra = []): Customer
    {
        return Customer::create(array_merge([
            'name' => $name,
            'phone' => '1380000'.random_int(1000, 9999),
            'phone_tail' => '0000',
            'venue' => '绿地店',
            'source' => 'KeepYoga',
            'owner' => '店长',
            'consultant' => '店长',
            'main_card' => '私教卡',
            'layer' => 'P4',
            'status' => '在籍',
        ], $overrides, $extra, $cardStats !== null ? ['card_stats' => $cardStats] : []));
    }


    // ═══════════════════════════════════════════════════════════════════════
    // 过期卡口径（v3.3.4 / t2 第 6-8 条）：用户决策两条必须同时成立且互不干扰
    //   「1. 过期卡不纳入待续费 —— status=6 不得重新进入待续费窗口。」
    //   「2. 但过期卡数据必须保留可查。」
    // 保留落在 card_stats.expiredCards；不入待续费由 ACTIVE_CARD_STATUSES 不含 6 保证。
    // 两条路径分开：cards_list 是判定层分类输入，塞进去会立刻经 C8 回溯分支复活待续费。
    // ═══════════════════════════════════════════════════════════════════════

    private function expiredSummarize(array $cards): array
    {
        return (new \ReflectionMethod(KyMemberSyncService::class, 'summarizeCards'))->invoke(null, $cards);
    }

    /** 过期卡原始行（deadline 是 Unix 时间戳 —— 上游陷阱） */
    private function expiredCard(array $overrides = []): array
    {
        return array_merge([
            'card_title' => '私教30次',
            'status' => '6',                  // 6 = 过期
            'type' => '1',
            'is_taste' => '0',
            'residue_amount' => '12',
            'usage_total' => '8400.00',       // 18 次 × 466.6667
            'curr_unit_cash_value' => '466.6666666667',
            'consume_amount_format' => '18次',
            'deal_price' => '13999',
            'expiry_days' => '0',
            'deadline' => $this->tsForDaysAgo(400),
        ], $overrides);
    }

    /**
     * 生成「经 date() 归一化后恰好等于 $days 天前」的上游时间戳。
     *
     * 不能直接传 now()->subDays($n)->startOfDay()->timestamp：那是上海时区当日 00:00，
     * 而 date() 用 createFromTimestamp()（**UTC** 语义）解释会得到**前一天**，
     * 于是一个「183 天」的夹具实际变成 184 天，把边界断言测错位。
     * 取目标日的 UTC 12:00（= 上海 20:00，同日）即可稳定还原。
     */
    private function tsForDaysAgo(int $days): string
    {
        $date = \Carbon\CarbonImmutable::today()->subDays($days)->toDateString();

        return (string) \Carbon\CarbonImmutable::parse($date.' 12:00:00', 'UTC')->timestamp;
    }

    /** 用「卡项原始行」建会员：经真实 summarizeCards 聚合 */
    private function expiredMember(string $name, array $cards): Customer
    {
        $sum = (new \ReflectionMethod(KyMemberSyncService::class, 'summarizeCards'))->invoke(null, $cards);

        return $this->customer($name, [
            'layer' => 'P4',
            'external_id' => 'ky:expired-'.$name,
            'main_card' => $sum['main_card'],
            'remain_times' => $sum['remain_times'],
            'expire_date' => $sum['expire_date'],
        ], $sum['card_stats'], [
            'cards_list' => $sum['cards_list'],
            'attend_m1' => 6, 'attend_m2' => 6, 'attend_m3' => 6,
            // 2026-09-25 炸弹口径加第 5 条（超 183 天无出勤）后，夹具默认从
            // subDays(3) 改为 subDays(400)：本组用例测的是**卡项**口径
            // （保留区/边界/互不干扰），出勤不是它们要验证的维度。
            'last_visit' => now()->subDays(400)->toDateString(),
        ]);
    }

    private function bombRules(): array
    {
        return [
            'renewalThreshold' => 10, 'renewalCountPercent' => 15,
            'renewalExpireDays' => 30, 'renewalExpirePercent' => 0,
            'vipAmountThreshold' => 30000, 'declineMode' => 'strict',
            'predropMin' => 15, 'predropMax' => 30, 'reviveDays' => 30,
            'renewalExpiredBackfillDays' => 90,
        ];
    }

    public function test_expired_card_is_excluded_from_active_summary(): void
    {
        $sum = $this->expiredSummarize([$this->expiredCard()]);

        $this->assertNull($sum['remain_times'], '过期卡不得贡献剩余节数');
        $this->assertNull($sum['card_stats']['countResidue'], '过期卡不得进入 countResidue');
        $this->assertSame(0, $sum['card_stats']['countBound'], '过期卡不得进入分母');
        $this->assertSame([], $sum['cards_list'], '过期卡不得进入判定层的 cards_list');
    }

    public function test_member_with_only_expired_cards_is_not_renewal(): void
    {
        $c = $this->expiredMember('仅过期卡会员', [$this->expiredCard()]);

        $this->assertNotContains($c->id, $this->renewalIds(),
            '过期卡（status=6）不得重新进入待续费窗口 —— 用户决策第 1 条');
    }

    public function test_expired_card_inside_backfill_window_does_not_revive_renewal(): void
    {
        // 过期 30 天 —— 落在 renewalExpiredBackfillDays(90) 回溯窗内
        $c = $this->expiredMember('回溯窗内过期卡', [$this->expiredCard([
            'deadline' => $this->tsForDaysAgo(30),
            'residue_amount' => '5',
        ])]);

        $this->assertNotContains($c->id, $this->renewalIds(),
            '回溯窗内的过期卡一旦进入判定层就会复活待续费（C8 分支），必须拦住');
    }

    public function test_active_card_decision_is_not_polluted_by_expired_card(): void
    {
        $sum = $this->expiredSummarize([
            $this->expiredCard(['card_title' => '在用私教', 'status' => '5', 'residue_amount' => '20',
                'usage_total' => '4666.67', 'deadline' => (string) now()->addDays(300)->timestamp]),
            $this->expiredCard(['card_title' => '沉睡过期卡', 'residue_amount' => '12']),
        ]);

        $this->assertSame(20, $sum['card_stats']['countResidue'], '分子只含在用卡');
        $this->assertSame(30, $sum['card_stats']['countBound'], '分母只含在用卡：20 剩余 + 10 已耗');
        $this->assertCount(1, $sum['cards_list'], 'cards_list 只含在用卡');
    }

    public function test_expired_card_data_is_retained_with_residue_and_deadline(): void
    {
        $sum = $this->expiredSummarize([$this->expiredCard()]);
        $kept = $sum['card_stats']['expiredCards'];

        $this->assertCount(1, $kept, '过期卡必须保留可查（用户决策第 2 条）');
        $this->assertSame(12, $kept[0]['residue'], '必须保留余额：否则沉睡节数不可算');
        $this->assertSame('6', $kept[0]['status'], '必须保留原始状态');
        // 口径第 7 条要求保留「含卡类型」：下游炸弹会员只认 type=1，
        // 缺了它就无法复核「为什么排除了时间卡」。
        $this->assertSame('1', $kept[0]['type'], '必须保留卡类型（1=次卡/节）');
        $this->assertNotEmpty($kept[0]['deadline'], '必须保留到期日：否则「过期超 6 个月」不可算');
        // deadline 必须已从 Unix 时间戳转成 Y-m-d（上游给的是时间戳，直接当字符串比大小会错）
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $kept[0]['deadline'],
            'deadline 必须归一化为 Y-m-d（时间戳陷阱）');
        // 日期值断言用「与上海时区的年月日一致」表达，而不是直接比对 now()->subDays(400)。
        // 原因：`KyMemberSyncService::date()` 用的是 `createFromTimestamp($ts)`（**UTC** 语义），
        // 而库内时间戳来自上海时区的当日 00:00，两者相差 8 小时 —— 恰好让日期少一天。
        // 实测（ky_cards_live_20260924.json，2822 张带 deadline 的卡）：**全部** deadline
        // 时间戳都是 15:59 UTC = 23:59 上海，故线上数据未暴露该偏差；
        // 但 create_time / activate_time / auto_activate_time 有 1317～2746 个值会因此少一天。
        // 这不在本任务范围内（不新增迁移、不改既有时间语义），此处只锁「归一化后是合法日期」，
        // 并把偏差作为已知问题记录在 docs/口径 文档，不在此处悄悄改语义。
        $this->assertSame(\Carbon\CarbonImmutable::today()->subDays(400)->toDateString(), $kept[0]['deadline'],
            'deadline 必须等于上游时间戳所表示的日期（夹具用 UTC 正午生成，见 tsForDaysAgo）');
    }

    public function test_retention_does_not_feed_the_decision_layer(): void
    {
        $sum = $this->expiredSummarize([$this->expiredCard()]);

        $this->assertNotEmpty($sum['card_stats']['expiredCards'], '保留区有数据');
        $this->assertSame([], $sum['cards_list'],
            '保留区**不得**流进 cards_list：那是判定层的分类输入，进去就会复活待续费');
    }

    public function test_retention_only_keeps_count_cards_with_balance_and_deadline(): void
    {
        $sum = $this->expiredSummarize([
            $this->expiredCard(['card_title' => '过期次卡有余额', 'residue_amount' => '12']),
            $this->expiredCard(['card_title' => '过期时间卡', 'type' => '2', 'residue_amount' => '88']),
            $this->expiredCard(['card_title' => '过期次卡零余额', 'residue_amount' => '0']),
            $this->expiredCard(['card_title' => '过期次卡无到期日', 'deadline' => '']),
            $this->expiredCard(['card_title' => '过期体验卡', 'is_taste' => '1']),
        ]);
        $titles = array_column($sum['card_stats']['expiredCards'], 'title');

        $this->assertSame(['过期次卡有余额'], $titles,
            '保留区口径：次卡 + 有余额 + 有到期日 + 非体验/员工/测试（单位混入会让沉睡节数失真）');
    }

    public function test_retention_is_reachable_through_member_list(): void
    {
        $c = $this->expiredMember('可查炸弹会员', [$this->expiredCard()]);
        $got = computeMemberLists($this->bombRules());

        $this->assertContains($c->id, $got['lists']['炸弹会员'],
            '保留的过期卡必须能推出「炸弹会员」清单项（否则「保留可查」只落了一半）');
        $this->assertArrayHasKey($c->id, $got['watch']['炸弹会员'], 'watch 明细必须可查');
        $this->assertSame(12, $got['watch']['炸弹会员'][$c->id]['sections'], '沉睡节数必须可查');
        $this->assertSame(['bomb_expired'], $got['watch']['炸弹会员'][$c->id]['whyCodes']);
    }

    public function test_retention_does_not_make_recent_expiry_a_bomb(): void
    {
        $c = $this->expiredMember('近期过期会员', [$this->expiredCard([
            'deadline' => $this->tsForDaysAgo(100),
        ])]);
        $got = computeMemberLists($this->bombRules());

        $this->assertNotEmpty($c->fresh()->card_stats['expiredCards'], '数据仍要保留可查');
        $this->assertNotContains($c->id, $got['lists']['炸弹会员'], '只过期 100 天（< 6 个月）不算炸弹');
    }

    public function test_bomb_boundary_at_six_months(): void
    {
        $exact = $this->expiredMember('恰好183天', [$this->expiredCard(['deadline' => $this->tsForDaysAgo(183)])]);
        $over = $this->expiredMember('过期184天', [$this->expiredCard(['deadline' => $this->tsForDaysAgo(184)])]);
        $got = computeMemberLists($this->bombRules());

        $this->assertNotContains($exact->id, $got['lists']['炸弹会员'], '恰好 183 天 = 未「超过」6 个月');
        $this->assertContains($over->id, $got['lists']['炸弹会员'], '184 天必须算炸弹');
    }

    public function test_bomb_member_is_never_in_renewal_list(): void
    {
        $c = $this->expiredMember('炸弹但非待续费', [$this->expiredCard()]);
        $got = computeMemberLists($this->bombRules());

        $this->assertContains($c->id, $got['lists']['炸弹会员']);
        $this->assertNotContains($c->id, $got['lists']['待续课'],
            '炸弹会员必须**不**同时是待续费会员 —— 两条路径互不干扰');
    }

    public function test_expired_unactivated_card_is_retained(): void
    {
        // status=7（未开卡）在在用白名单里，由 cards_list 表达 —— 不进保留区，避免两处各存一份
        $sum = $this->expiredSummarize([$this->expiredCard(['status' => '7'])]);

        $this->assertCount(1, $sum['cards_list'], '未开卡属于在用白名单，由 cards_list 表达');
        $this->assertSame([], $sum['card_stats']['expiredCards'], '同一条卡不得在两处各存一份（会分叉）');
    }
}
