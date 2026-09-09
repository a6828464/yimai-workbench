<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\KyCard;
use App\Services\KyClient;
use App\Services\KyMemberSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KyMemberSyncRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_reuses_globally_unique_external_id_when_historical_venue_differs(): void
    {
        config([
            'services.ky.phone' => '13800000000',
            'services.ky.password' => 'secret',
        ]);

        Customer::create([
            'external_id' => 'ky:1:1001',
            'venue' => '东部店',
            'name' => '历史错店会员',
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_ends_with($url, '/passport/api/login')) {
                return Http::response(['data' => ['access_token' => 'test-token']]);
            }
            if (str_ends_with($url, '/member/api/getmembersbycondwithpager')) {
                return Http::response([
                    'errno' => 0,
                    'data' => ['members' => [[
                        'member_id' => '1001',
                        'name' => '历史错店会员',
                        'phone' => '13800000001',
                    ]]],
                ]);
            }
            if (str_ends_with($url, '/mcard/api/getmcardsbycond')) {
                return Http::response([
                    'errno' => 0,
                    'data' => ['mcards' => [[
                        'id' => 'card-1',
                        'member_id' => '1001',
                        'card_title' => '瑜伽次卡',
                        'status' => '5',
                        'type' => '1',
                        'residue_amount' => 10,
                    ]]],
                ]);
            }

            return Http::response(['errno' => 0, 'data' => ['reservations' => []]]);
        });

        $result = KyMemberSyncService::sync('绿地店', '1');

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, Customer::count());
        $this->assertDatabaseHas('customers', [
            'external_id' => 'ky:1:1001',
            'venue' => '绿地店',
        ]);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), KyClient::BASE));
    }

    public function test_sync_fails_before_upstream_requests_when_recent_migrations_are_missing(): void
    {
        Schema::table('customers', function ($table) {
            $table->dropIndex(['enrolled_at']);
        });
        Schema::table('customers', function ($table) {
            $table->dropColumn(['enrolled_at', 'visit_at']);
        });

        Http::fake();

        $this->expectExceptionMessage('数据库结构未升级，请先执行 php artisan migrate --force 后重试');

        try {
            KyMemberSyncService::sync('绿地店', '1');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_identical_fact_upserts_do_not_rewrite_rows(): void
    {
        $now = now()->subHour()->startOfSecond();
        KyBooking::create([
            'source_key' => '1:团课:booking-1',
            'venue' => '绿地店',
            'booking_type' => '团课',
            'member_id' => '1001',
            'member_name' => '会员甲',
            'phone' => '13800000001',
            'start_at' => '2026-09-09 10:00:00',
            'course_name' => '瑜伽',
            'teacher_name' => '老师甲',
            'status_raw' => '已预约',
            'status' => 'booked',
            'is_trial' => false,
            'raw' => ['id' => 'booking-1'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        KyCard::create([
            'source_key' => '1:card-1',
            'venue' => '绿地店',
            'external_id' => 'card-1',
            'card_title' => '瑜伽次卡',
            'member_id' => '1001',
            'member_name' => '会员甲',
            'phone' => '13800000001',
            'consultant_name' => '顾问甲',
            'deal_price' => 1000,
            'price' => 1200,
            'status' => '5',
            'status_format' => '有效',
            'is_taste' => false,
            'sold_at' => '2026-09-01',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->invokePrivate('upsertBookingFacts', [[[
            'source_key' => '1:团课:booking-1', 'venue' => '绿地店', 'booking_type' => '团课',
            'member_id' => '1001', 'member_name' => '会员甲', 'phone' => '13800000001',
            'start_at' => '2026-09-09 10:00:00', 'course_name' => '瑜伽', 'teacher_name' => '老师甲',
            'status_raw' => '已预约', 'status' => 'booked', 'is_trial' => false,
            'raw' => json_encode(['id' => 'booking-1']), 'created_at' => now(), 'updated_at' => now(),
        ]]]);
        $this->invokePrivate('upsertCardFacts', [[[
            'source_key' => '1:card-1', 'venue' => '绿地店', 'external_id' => 'card-1',
            'card_title' => '瑜伽次卡', 'member_id' => '1001', 'member_name' => '会员甲',
            'phone' => '13800000001', 'consultant_name' => '顾问甲', 'deal_price' => 1000.0,
            'price' => 1200.0, 'status' => '5', 'status_format' => '有效', 'is_taste' => false,
            'sold_at' => '2026-09-01', 'created_at' => now(), 'updated_at' => now(),
        ]]]);

        $this->assertTrue(KyBooking::firstOrFail()->updated_at->equalTo($now));
        $this->assertTrue(KyCard::firstOrFail()->updated_at->equalTo($now));
    }

    public function test_repeated_full_page_stops_member_pagination(): void
    {
        config(['services.ky.phone' => '13800000000', 'services.ky.password' => 'secret']);
        Storage::fake('local');
        $rows = array_map(fn (int $id) => ['member_id' => (string) $id], range(1, 5000));
        Http::fake([
            KyClient::BASE.'/passport/api/login' => Http::response(['data' => ['access_token' => 'token']]),
            KyClient::BASE.'/member/api/getmembersbycondwithpager' => Http::response([
                'errno' => 0, 'data' => ['members' => $rows],
            ]),
        ]);

        $result = $this->invokePrivate('pagedRows', [
            'member/api/getmembersbycondwithpager', ['venue_id' => '1'], ['members'],
        ]);

        $this->assertCount(5000, $result);
        Http::assertSentCount(3);
    }

    private function invokePrivate(string $method, array $arguments): mixed
    {
        $reflection = new \ReflectionMethod(KyMemberSyncService::class, $method);

        return $reflection->invoke(null, ...$arguments);
    }
}
