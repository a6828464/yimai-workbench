<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\KyClient;
use App\Services\KyMemberSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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
}
