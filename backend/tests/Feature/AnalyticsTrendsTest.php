<?php

namespace Tests\Feature;

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

        Lead::create([
            'lead_date' => '2026-08-29',
            'name' => 'CRM体验客',
            'venue' => '绿地店',
            'status' => '已体验',
        ]);
        Lead::create([
            'lead_date' => '2026-08-29',
            'name' => '线上成交客',
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
            ->assertJsonPath('data.summary.visitCount', 2)
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
            ->assertJsonPath('data.summary.onlineDealRate', 100);

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
}
