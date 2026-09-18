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
