<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\KyCard;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Services\KyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsTrendsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_scopes_summary_and_uses_keepyoga_trial_booking_facts(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'analytics-test', 'role' => 'R_SUPER']));

        // 「CRM体验客」的手机号与下方 green-signed 的随心瑜预约一致：
        // 同一个人同时出现在留资与预约两处，到店人数只能算一个（按人去重口径）
        Lead::create([
            'lead_date' => '2026-08-29',
            'name' => 'CRM体验客',
            'phone' => '13800000002',
            'venue' => '绿地店',
            'status' => '已体验',
        ]);
        Lead::create([
            'lead_date' => '2026-08-29',
            'name' => '线上成交客',
            'phone' => '13800000009',
            'venue' => '绿地店',
            'source' => '美团',
            'order_platform' => '美团',
            'status' => '已成交',
            'deal_at' => '2026-08-29 12:00:00',
        ]);
        KyCard::create([
            'source_key' => '1:green-card',
            'venue' => '绿地店',
            'external_id' => 'green-card',
            'card_title' => '私教卡',
            'deal_price' => 3000,
            'is_taste' => false,
            'status_format' => '正常',
            'sold_at' => '2026-08-29',
        ]);
        KyCard::create([
            'source_key' => '1:green-taste-card',
            'venue' => '绿地店',
            'external_id' => 'green-taste-card',
            'card_title' => '体验卡',
            'deal_price' => 9999,
            'is_taste' => true,
            'status_format' => '正常',
            'sold_at' => '2026-08-29',
        ]);
        $this->booking('green-booked', '绿地店', '13800000001', 'booked');
        $this->booking('green-signed', '绿地店', '13800000002', 'signed');
        $this->booking('east-cancelled', '东部店', '13800000003', 'cancelled');
        $this->booking('green-private-signed', '绿地店', '13800000004', 'signed', '私教', false, '2');
        $this->booking('green-small-signed', '绿地店', '13800000005', 'signed', '团课', false, '3');

        $this->getJson('/api/analytics/trends?start=2026-08-29&end=2026-08-29&venue='.urlencode('绿地店'))
            ->assertOk()
            ->assertJsonPath('data.summary.bookingCount', 4)
            ->assertJsonPath('data.summary.trialCount', 2)
            ->assertJsonPath('data.summary.classCount', 3)
            ->assertJsonPath('data.summary.privateBookingCount', 1)
            ->assertJsonPath('data.summary.smallBookingCount', 1)
            ->assertJsonPath('data.summary.groupBookingCount', 2)
            ->assertJsonPath('data.summary.privateClassCount', 1)
            ->assertJsonPath('data.summary.smallClassCount', 1)
            ->assertJsonPath('data.summary.groupClassCount', 1)
            ->assertJsonPath('data.summary.cardSalesCount', 1)
            ->assertJsonPath('data.summary.dealAmount', 3000)
            ->assertJsonPath('data.summary.onlineLeadCount', 1)
            ->assertJsonPath('data.summary.onlineDealRate', 100)
            // 到店人数按人去重：CRM 留资与随心瑜预约里同一个人只算一次
            ->assertJsonPath('data.summary.visitCount', 2)
            ->assertJsonPath('data.summary.dealCount', 1);

        $this->getJson('/api/analytics/trends?start=2026-08-29&end=2026-08-29&venue='.urlencode('东部店'))
            ->assertOk()
            ->assertJsonPath('data.summary.bookingCount', 0)
            ->assertJsonPath('data.summary.trialCount', 0);

        config(['services.ky.phone' => '13800000000', 'services.ky.password' => 'secret']);
        Cache::put('ky_access_token', 'token');
        Http::fake([
            KyClient::BASE.'/member/api/getvisitors' => Http::response(['errno' => 0, 'data' => ['total' => 3]]),
        ]);

        $response = KyClient::call('/member/api/getvisitors', ['venue_id' => '1']);

        $this->assertSame(3, $response['data']['total']);
        Http::assertSent(fn ($request) => $request->url() === KyClient::BASE.'/member/api/getvisitors');

        Task::create([
            'title' => '本店待办',
            'customer_name' => '客户',
            'venue' => '绿地店',
            'owner' => '未分配',
            'priority' => '中',
            'deadline' => '2026-08-30 18:00',
            'status' => '待接收',
            'standard' => '完成处理',
        ]);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.unreadCount', 1)
            ->assertJsonPath('data.items.0.path', '/yimai/tasks');
        $this->patchJson('/api/notifications/tasks-1/read')->assertOk();
        $this->getJson('/api/notifications')->assertJsonPath('data.unreadCount', 0);

        $this->assertSame('incomplete', contractPartyState(['customer_sign_status' => '待会员签署'], 'customer'));
        $this->assertSame('completed', contractPartyState(['venue_sign_time' => '2026-08-29 10:00:00'], 'venue'));
        $this->assertSame('unknown', contractPartyState([], 'customer'));
    }

    /**
     * 到店判定：核销时间与留资日期只要有一个落在区间就算。
     *
     * 之前写成「核销时间在区间，或者（核销时间为空且留资日期在区间）」，
     * 于是上月买券、本月到店的线上客人会被排除——这是线上到店人数偏少的原因之一。
     */
    public function test_visit_counts_lead_when_either_redeem_or_lead_date_in_range(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'visit-scope', 'role' => 'R_SUPER']));

        // 上月买券（核销时间在区间外）、本月留资：应计入本月到店
        Lead::create([
            'lead_date' => '2026-09-10', 'name' => '上月买券本月到店', 'phone' => '13900009001',
            'source' => '美团', 'order_platform' => '美团', 'venue' => '绿地店',
            'status' => '已体验', 'redeemed_at' => '2026-08-05 10:00:00',
        ]);
        // 上月留资、本月核销到店：也应计入
        Lead::create([
            'lead_date' => '2026-08-20', 'name' => '上月留资本月到店', 'phone' => '13900009002',
            'source' => '抖音', 'order_platform' => '抖音', 'venue' => '绿地店',
            'status' => '已体验', 'redeemed_at' => '2026-09-12 10:00:00',
        ]);
        // 都落在区间外：不计入
        Lead::create([
            'lead_date' => '2026-07-01', 'name' => '早就到过店', 'phone' => '13900009003',
            'source' => '美团', 'order_platform' => '美团', 'venue' => '绿地店',
            'status' => '已体验', 'redeemed_at' => '2026-07-02 10:00:00',
        ]);

        $summary = $this->getJson('/api/analytics/trends?start=2026-09-01&end=2026-09-30&venue='.urlencode('绿地店'))
            ->assertOk()->json('data.summary');

        $this->assertSame(2, $summary['onlineVisitCount']);
        $this->assertSame(2, $summary['visitCount']);
        // 来源构成可核对
        $this->assertSame(2, $summary['visitBreakdown']['fromLeadStatus']);
    }

    public function test_trends_deal_and_redeem_date_window_is_sql_pushed(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'trends-window', 'role' => 'R_SUPER']));
        $venue = '绿地店';

        // 成交事件在窗口内 → 计入本月成交/核销
        Lead::create([
            'lead_date' => '2026-08-10', 'name' => '窗口内成交', 'venue' => $venue, 'source' => '美团',
            'order_platform' => '美团', 'status' => '已成交', 'deal_amount' => 500,
            'deal_at' => '2026-08-29 12:00:00', 'redeemed_at' => '2026-08-29 09:00:00', 'redeem_amount' => 99,
        ]);
        // 成交事件在窗口外（早于 start）→ 即便 lead_date 在窗口内也不计入成交金额
        Lead::create([
            'lead_date' => '2026-08-29', 'name' => '窗口外成交', 'venue' => $venue, 'source' => '到店',
            'status' => '已成交', 'deal_amount' => 800, 'deal_at' => '2026-07-29 12:00:00',
        ]);
        // 无成交事件时间 → 按 lead_date 回退计入
        Lead::create([
            'lead_date' => '2026-08-29', 'name' => '无成交时间', 'venue' => $venue, 'source' => '到店',
            'status' => '已成交', 'deal_amount' => 200,
        ]);

        $summary = $this->getJson('/api/analytics/trends?start=2026-08-20&end=2026-08-30&venue='.urlencode($venue))
            ->assertOk()->json('data.summary');

        // 成交计数：窗口内成交 + 无成交时间回退 lead_date = 2；窗口外成交（deal_at 早于 start）不计
        $this->assertSame(2, (int) $summary['dealCount']);
        // 留资登记成交（按成交日、含全部来源）：窗口内 500 + 回退 200 = 700，与售卡 dealAmount 解耦
        $this->assertSame(2, (int) $summary['registeredDealCount']);
        $this->assertSame(700.0, (float) $summary['registeredDealAmount']);
        // 核销金额按 redeemed_at 窗口计入（deal_at/redeemed_at 过滤已下推 SQL）
        $this->assertSame(99.0, (float) $summary['redeemAmount']);
    }

    public function test_online_deal_rate_divides_by_online_visits_not_online_leads(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'online-rate', 'role' => 'R_SUPER']));
        $venue = '绿地店';

        // 线上留资 4 人：到店 2 人（其中 1 人成交），另 1 人仅留资、1 人到店未成交
        foreach ([
            ['线上到店并成交', '美团', '已成交'],
            ['线上到店未成交', '大众点评', '已体验'],
            ['线上仅留资', '抖音', '新留资'],
            ['线上约体验未到店', '小红书', '已约体验'],
        ] as [$name, $source, $status]) {
            Lead::create([
                'lead_date' => '2026-08-29', 'name' => $name, 'venue' => $venue,
                'source' => $source, 'order_platform' => $source, 'status' => $status,
                'deal_at' => $status === '已成交' ? '2026-08-29 12:00:00' : null,
            ]);
        }
        // 非线上来源（自然到店）成交 1 人：不得计入线上口径
        Lead::create([
            'lead_date' => '2026-08-29', 'name' => '自然到店成交', 'venue' => $venue,
            'source' => '自然到店', 'status' => '已成交', 'deal_at' => '2026-08-29 12:00:00',
        ]);

        $summary = $this->getJson('/api/analytics/trends?start=2026-08-29&end=2026-08-29&venue='.urlencode($venue))
            ->assertOk()->json('data.summary');

        // 三项都只算线上：留资 4 / 到店 2 / 成交 1，自然到店不进任何一项
        $this->assertSame(4, (int) $summary['onlineLeadCount']);
        $this->assertSame(2, (int) $summary['onlineVisitCount']);
        $this->assertSame(1, (int) $summary['onlineDealCount']);
        // 成交率 = 成交 ÷ 到店 = 1/2；若误按「成交 ÷ 留资」会得到 25
        $this->assertSame(50.0, (float) $summary['onlineDealRate']);
        $this->assertSame(50.0, (float) $summary['onlineLeadToVisitRate']);
    }

    // ==================== 新媒体线上运营业绩机制（t41） ====================
    //
    // 口径（用户二次确认）：2 个月时效（留资月 + 下一个自然月）、到店奖励 20 元/人、
    // 核销提成 = 有效成交率 × 时效内线上核销金额、线下渠道完全不算。
    //
    // ⚠️ 成交率分母含「上月留资、本月到店」的人（用户例子里的 +2）。用户口述曾写
    // `7/10`，与他自己「到店 10+2」矛盾，二次确认用 **12**（分子分母同源）。

    /** 造一条线上/线下留资 */
    private function lead(string $name, string $phone, string $date, array $overrides = []): Lead
    {
        return Lead::create(array_merge([
            'name' => $name,
            'phone' => $phone,
            'lead_date' => $date,
            'venue' => '绿地店',
            'status' => '已体验',
            'source' => '美团',
            'order_platform' => '美团',
        ], $overrides));
    }

    /** 取某月的新媒体业绩块 */
    private function mediaOf(string $month): array
    {
        return $this->getJson("/api/analytics/trends?start={$month}-01&end={$month}-31")
            ->assertOk()->json('data.summary.mediaPerformance');
    }

    /**
     * 【核心】必须能对上用户给的验算例子（算钱的功能，要能对账）。
     *
     * 10 月：留资 20 / 到店 10 / 成交 6；另有 9 月的 2 个客资在 10 月到店（其中 1 个成交）。
     *   到店奖励 = (10 + 2) × 20 = 240 元
     *   核销提成 = (6 + 1) ÷ (10 + 2) × 核销金额 = 7 ÷ 12 × 核销金额
     *
     * 本例核销金额设为 1200 元（便于心算）：240 奖励、7/12 ≈ 58.33%、提成 = 700 元。
     */
    public function test_media_performance_matches_users_worked_example(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'media-example', 'role' => 'R_SUPER']));

        // 10 月：20 留资，其中 10 到店、6 成交。
        // 核销金额 1200 元挂在**已到店的第 1 人**身上（不额外造第 11 个到店者，
        // 否则到店数就不是用户例子里的 10 了 —— 这个坑我踩过一次，故写明）。
        for ($i = 1; $i <= 20; $i++) {
            $phone = '1391000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $visited = $i <= 10;
            $dealt = $i <= 6;
            $this->lead("十月客{$i}", $phone, '2025-10-05', [
                'status' => $dealt ? '已成交' : ($visited ? '已体验' : '已约体验'),
                'deal_at' => $dealt ? '2025-10-20 12:00:00' : null,
                'deal_amount' => $dealt ? 5000 : null,
                'redeemed_at' => $visited ? '2025-10-06 10:00:00' : null,
                'redeem_amount' => $i === 1 ? 1200 : null,
            ]);
        }
        // 9 月：2 个客资在 10 月到店，其中 1 个成交（仍在时效内：9 月留资 → 10.31 前有效）。
        // 「本月到店」由核销事件日（redeemed_at）落在本月认定 —— 这正是 VisitMetrics
        // 「上月买券、本月才到店」那条口径（lead_date 在区间外、redeemed_at 在区间内）。
        foreach ([1, 2] as $i) {
            $phone = '1392000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $dealt = $i === 1;
            $this->lead("九月客{$i}", $phone, '2025-09-10', [
                'status' => $dealt ? '已成交' : '已体验',
                'deal_at' => $dealt ? '2025-10-08 12:00:00' : null,
                'deal_amount' => $dealt ? 3000 : null,
                'redeemed_at' => '2025-10-08 10:00:00',
            ]);
        }

        $media = $this->mediaOf('2025-10');

        // ---- 用户例子的两个中间值（显式钉住，供核对）----
        $this->assertSame(12, $media['breakdown']['validVisitCount'], '有效到店应为 10 + 2 = 12');
        $this->assertSame(2, $media['breakdown']['validVisitsFromPrevMonth'], '其中上月留资 2 人');
        $this->assertSame(7, $media['breakdown']['validDealCount'], '有效成交应为 6 + 1 = 7');
        $this->assertSame(1200.0, (float) $media['breakdown']['validRedeemAmount']);

        $this->assertSame(240.0, (float) $media['visitRewardAmount'], '(10+2) × 20 = 240 元');
        // 7 / 12 = 58.333…% —— 若误用分母 10 会得到 70（用户口述里写错过，这里钉死 12）
        $this->assertSame(58.33, (float) $media['dealRate'], '7 ÷ 12 ≈ 58.33%（不是 7÷10=70）');
        $this->assertSame(700.0, (float) $media['commissionAmount'], '7/12 × 1200 = 700 元');
    }

    /**
     * 【边界①】留资月末最后一天 + 次月最后一天到店 = 有效（时效按**月**推移，不按天）。
     */
    public function test_media_validity_includes_last_day_of_next_month(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'media-b1', 'role' => 'R_SUPER']));

        // 9.30 留资，10.31 到店（次月最后一天）→ 有效
        $this->lead('月末边界', '13940000001', '2025-09-30', [
            'status' => '已体验',
            'redeemed_at' => '2025-10-31 23:00:00',
        ]);

        $media = $this->mediaOf('2025-10');
        $this->assertSame(1, $media['breakdown']['validVisitCount'], '9.30 留资、10.31 到店应算有效（月末边界含端点）');
        $this->assertSame(20.0, (float) $media['visitRewardAmount']);
    }

    /** 【边界②】次月最后一天之后 1 天（11.1）到店 = 无效。 */
    public function test_media_validity_excludes_day_after_next_month(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'media-b2', 'role' => 'R_SUPER']));

        // 9.30 留资，11.1 到店 → 越期（窗口末日是 10.31）
        $this->lead('越期客', '13940000002', '2025-09-30', [
            'status' => '已体验',
            'redeemed_at' => '2025-11-01 09:00:00',
        ]);

        $this->assertSame(0, $this->mediaOf('2025-11')['breakdown']['validVisitCount'], '11.1 到店已越期，不计奖励');
    }

    /** 【边界③】留资当月内到店 = 有效。 */
    public function test_media_validity_includes_same_month_visit(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'media-b3', 'role' => 'R_SUPER']));

        $this->lead('同月客', '13940000003', '2025-10-02', [
            'status' => '已体验',
            'redeemed_at' => '2025-10-03 10:00:00',
        ]);

        $this->assertSame(1, $this->mediaOf('2025-10')['breakdown']['validVisitCount'], '当月留资、当月到店应算有效');
    }

    /**
     * 【边界④】无法核对时效时的策略：**失败关闭**，并在明细里单列计数。
     *
     * ## 选失败关闭的理由
     *
     * 这是算钱的功能，宁可少算不漏算；且「找不到线上留资」的人本来就不是新媒体新客
     * （`isOnlineLead()` 为假 ⇒ 压根不进 `onlineIdentities`），口径自洽。
     *
     * ## ⚠️ 「无法核对」的可达路径只有一条（实测确认，别写错测试）
     *
     * 我起初以为可达路径是「有到店、但没有对应留资」，**实测发现那条不可达**：
     *  - 没有线上留资 ⇒ 不在 `onlineIdentities` 里 ⇒ 下面循环根本不遍历它（不算 unpaired）；
     *  - 三个到店来源都必然带得出日期（预约按 `start_at` 取数、留资状态有 `redeemed_at`/`lead_date`、
     *    体验卡在 `day === ''` 时就 continue 了）⇒ `visitDates` 不会缺。
     *
     * 真正可达的只有**脏数据**：线上来源的留资行 `lead_date` 为空串（schema 是 NOT NULL
     * 但允许空串，已实测可插入）。所以本用例构造的是这一种 —— 它才是「无法核对」的真实来源。
     * 保留这个计数是为了让运营能发现数据异常，而不是让金额静默变小。
     */
    public function test_media_unpaired_rows_are_excluded_and_counted(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'media-b4', 'role' => 'R_SUPER']));

        // 脏数据：线上来源、已到店，但留资日为空串 ⇒ 无法判断时效 ⇒ 失败关闭
        $this->lead('留资日缺失', '13950000001', '', [
            'status' => '已体验',
            'redeemed_at' => '2025-10-06 10:00:00',
        ]);
        // 对照：一条正常数据，确保不是整体算不出来
        $this->lead('正常到店客', '13950000002', '2025-10-04', [
            'status' => '已体验',
            'redeemed_at' => '2025-10-05 10:00:00',
        ]);

        $media = $this->mediaOf('2025-10');
        $this->assertSame(1, $media['breakdown']['validVisitCount'], '只有能核对时效的那 1 人有效');
        $this->assertSame(1, $media['breakdown']['unpairedVisitCount'], '留资日缺失者必须单列，不能静默计入或静默消失');
        $this->assertSame(20.0, (float) $media['visitRewardAmount'], '只按可核对的那 1 人发奖励');
    }

    /**
     * 【线下渠道排除】线下留资即使到店并成交，也完全不进新媒体三项。
     *
     * 构造一个线下来源（自然到店）的到店+成交客资，断言它不影响任何一项金额与计数。
     */
    public function test_media_excludes_offline_channel_entirely(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'media-offline', 'role' => 'R_SUPER']));

        // 线上 1 人到店（基准）
        $this->lead('线上到店客', '13960000001', '2025-10-04', ['status' => '已体验', 'redeemed_at' => '2025-10-05 10:00:00']);
        // 线下 1 人到店 + 成交 + 有核销金额：来源/order_platform 都不含线上关键词
        $this->lead('线下到店成交客', '13960000002', '2025-10-04', [
            'status' => '已成交',
            'source' => '自然到店',
            'order_platform' => '自然到店',
            'deal_at' => '2025-10-06 10:00:00',
            'deal_amount' => 9999,
            'redeem_amount' => 8888,
            'redeemed_at' => '2025-10-07 10:00:00',
        ]);

        $media = $this->mediaOf('2025-10');
        $this->assertSame(1, $media['breakdown']['validVisitCount'], '线下到店不得计入（只有线上那 1 人）');
        $this->assertSame(0, $media['breakdown']['validDealCount'], '线下成交不得计入');
        $this->assertSame(0.0, (float) $media['breakdown']['validRedeemAmount'], '线下核销金额不得计入提成基数');
        $this->assertSame(20.0, (float) $media['visitRewardAmount'], '只有线上那 1 人的 20 元');
        $this->assertSame(8888.0, (float) $media['breakdown']['excludedRedeemAmount'], '线下被排除的核销额要能被运营看到');
    }

    /**
     * 【越期核销】核销金额只算时效内的线上核销：老客（留资超出 2 个月）的核销不计入提成基数。
     */
    public function test_media_excludes_outdated_redeem_amount(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'media-redeem', 'role' => 'R_SUPER']));

        $this->lead('时效内核销', '13970000001', '2025-10-02', [
            'status' => '已体验', 'redeem_amount' => 500, 'redeemed_at' => '2025-10-10 10:00:00',
        ]);
        // 7 月留资（10 月核销已越期：窗口末日 8.31）→ 不得进提成基数
        $this->lead('越期核销', '13970000002', '2025-07-01', [
            'status' => '已体验', 'redeem_amount' => 4000, 'redeemed_at' => '2025-10-11 10:00:00',
        ]);

        $media = $this->mediaOf('2025-10');
        $this->assertSame(500.0, (float) $media['breakdown']['validRedeemAmount'], '只算时效内的 500');
        $this->assertSame(4000.0, (float) $media['breakdown']['excludedRedeemAmount'], '越期 4000 应被排除');
    }

    /** 【参数化】奖励单价与时效月数可调（rules 机制），且口径说明随参数变化。 */
    public function test_media_params_are_configurable(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'media-params', 'role' => 'R_SUPER']));
        setRules(['mediaVisitReward' => 35, 'mediaValidMonths' => 3]);

        $this->lead('参数客', '13980000001', '2025-09-01', ['status' => '已体验', 'redeemed_at' => '2025-10-05 10:00:00']);

        $media = $this->mediaOf('2025-10');
        $this->assertSame(35.0, (float) $media['params']['visitReward'], '单价应取 rules 里的 35');
        $this->assertSame(3, $media['params']['validMonths'], '时效应取 rules 里的 3');
        $this->assertSame(35.0, (float) $media['visitRewardAmount'], '9.1 留资 → 3 个月时效覆盖 10 月，按 35 元/人');
        $this->assertStringContainsString('3 个月', $media['params']['rule']);
    }

    /** 【配置健壮性】误配（0 月 / 负数单价 / 非数字）必须回落到安全默认值，不能把奖励算塌。 */
    public function test_media_params_fall_back_on_invalid_config(): void
    {
        setRules(['mediaVisitReward' => -5, 'mediaValidMonths' => 0]);
        $p = \mediaPerformanceParams();
        $this->assertSame(20.0, $p['visitReward'], '负单价回落 20');
        $this->assertSame(2, $p['validMonths'], '0 月会让所有到店失效，属误配，回落 2');

        setRules(['mediaVisitReward' => 'abc', 'mediaValidMonths' => null]);
        $p2 = \mediaPerformanceParams();
        $this->assertSame(20.0, $p2['visitReward']);
        $this->assertSame(2, $p2['validMonths']);
    }

    /** 【口径可核对】接口返回的明细必须能让人自己把账对上（含公式文案）。 */
    public function test_media_breakdown_is_self_explanatory(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'media-detail', 'role' => 'R_SUPER']));
        $this->lead('明细客', '13990000001', '2025-10-03', ['status' => '已体验', 'redeemed_at' => '2025-10-04 10:00:00']);

        $media = $this->mediaOf('2025-10');
        foreach (['validVisitCount', 'validVisitsFromPrevMonth', 'validDealCount', 'validDealsFromPrevMonth', 'validRedeemAmount', 'unpairedVisitCount'] as $k) {
            $this->assertArrayHasKey($k, $media['breakdown'], "明细缺字段 {$k}");
        }
        foreach (['visitReward', 'dealRate', 'commission'] as $k) {
            $this->assertArrayHasKey($k, $media['formula'], "口径说明缺 {$k}");
        }
        // 分母含上月留资的人数必须出现在公式文案里，运营才能自行核对（用户自己都记成了 /10）
        $this->assertStringContainsString('÷ 有效到店人数', $media['formula']['dealRate']);
        $this->assertStringContainsString('留资月', $media['params']['rule']);
    }

    /**
     * 【多次留资的取舍】同一人有 **2 条线上留资**时，以**最早**那条起算时效。
     *
     * ## 为什么取最早
     *
     * 「新客」属性只成立一次：他 1 月就通过新媒体进来过，10 月再登记一次不叫新新客。
     * 取最新会把老客反复登记当成新新客，**人为拉长时效窗口 = 放宽发钱**；
     * 取最早是**更严**的一侧，符合「算钱宁可少算不漏算」。
     * 本用例钉住这个方向：1 月 + 10 月两次线上留资、10 月到店 ⇒ 因窗口（1 月→2 月底）
     * 已过而**不发**奖励。谁改成「取最新」，这里会红。
     */
    public function test_media_uses_earliest_online_lead_date_when_duplicated(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'media-dup', 'role' => 'R_SUPER']));

        // 同号两条线上留资：1 月（最早）与 10 月（较新），10 月到店
        $this->lead('多次留资客', '13911000001', '2025-01-05', ['status' => '已体验']);
        $this->lead('多次留资客', '13911000001', '2025-10-05', [
            'status' => '已体验',
            'redeemed_at' => '2025-10-06 10:00:00',
        ]);

        $media = $this->mediaOf('2025-10');
        $this->assertSame(0, $media['breakdown']['validVisitCount'], '以最早的 1 月留资起算 ⇒ 10 月到店已越期，不奖励');
        $this->assertSame(0.0, (float) $media['visitRewardAmount']);
    }

    /**
     * 【线下留资在前、线上留资在后】时效按**线上**那条起算，不按线下那条。
     *
     * 这是「取最早线上留资」而非「取最早任意留资」的关键差别：若误用线下那条
     * （1 月）会把窗口提前到 2 月底，10 月的真实线上新客被**误杀**。
     */
    public function test_media_window_starts_from_online_lead_not_offline_one(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'media-mix', 'role' => 'R_SUPER']));

        // 1 月线下留资（自然到店），10 月线上留资，10 月到店
        $this->lead('先线下后线上', '13911000002', '2025-01-05', [
            'status' => '已体验', 'source' => '自然到店', 'order_platform' => '自然到店',
        ]);
        $this->lead('先线下后线上', '13911000002', '2025-10-03', [
            'status' => '已体验',
            'redeemed_at' => '2025-10-04 10:00:00',
        ]);

        $media = $this->mediaOf('2025-10');
        $this->assertSame(1, $media['breakdown']['validVisitCount'], '时效应从 10 月那条线上留资起算（窗口到 11.30）');
        $this->assertSame(20.0, (float) $media['visitRewardAmount']);
    }

    private function booking(
        string $key,
        string $venue,
        string $phone,
        string $status,
        string $type = '团课',
        bool $isTrial = true,
        string $courseType = '1'
    ): void {
        KyBooking::create([
            'source_key' => $key,
            'venue' => $venue,
            'booking_type' => $type,
            'member_name' => '新客体验',
            'phone' => $phone,
            'start_at' => '2026-08-29 10:00:00',
            'course_name' => '体验课',
            'status_raw' => $status,
            'status' => $status,
            'is_trial' => $isTrial,
            'raw' => ['course_type' => $courseType],
        ]);
    }

    /**
     * 会员统计按角色按人收窄，且缓存不跨账号串数据。
     *
     * 修之前 /analytics/summary 的会员只卡门店：服务老师看到的是全店会员数却被标成"我的"；
     * 而缓存键只有「角色 + 门店」，两个同店老师会互相拿到对方的数字。
     */
    public function test_summary_member_counts_are_person_scoped_and_cache_is_per_user(): void
    {
        Customer::create([
            'name' => '李顾问名下会员', 'phone' => '13900004001', 'venue' => '绿地店',
            'external_id' => 'ky:1001', 'layer' => 'P2', 'consultant' => '李顾问',
        ]);
        Customer::create([
            'name' => '张三名下会员', 'phone' => '13900004002', 'venue' => '绿地店',
            'external_id' => 'ky:1002', 'layer' => 'P2', 'consultant' => '张三',
        ]);

        $li = User::factory()->create([
            'username' => 'sum-li', 'name' => '李顾问', 'role' => 'R_SERVICE', 'roles' => ['R_SERVICE'],
            'venue' => '绿地店', 'venues' => ['绿地店'], 'status' => '启用',
        ]);
        $zhang = User::factory()->create([
            'username' => 'sum-zhang', 'name' => '张三', 'role' => 'R_SERVICE', 'roles' => ['R_SERVICE'],
            'venue' => '绿地店', 'venues' => ['绿地店'], 'status' => '启用',
        ]);

        Sanctum::actingAs($li);
        $this->assertSame(1, (int) $this->getJson('/api/analytics/summary')->assertOk()->json('data.totalMembers'));

        // 缓存不能把李顾问的数字发给张三（非超管的键必须含账号 id）
        Sanctum::actingAs($zhang);
        $this->assertSame(1, (int) $this->getJson('/api/analytics/summary')->assertOk()->json('data.totalMembers'));
    }
}
