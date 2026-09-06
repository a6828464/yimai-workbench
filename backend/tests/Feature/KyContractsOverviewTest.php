<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KyContractsOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_contracts_uses_signing_status_and_signatory_fields(): void
    {
        Sanctum::actingAs($this->user('super', '超管', 'R_SUPER', null));
        $this->fakeKeepYoga();

        $res = $this->getJson('/api/ky/pending-contracts')->assertOk()->json('data');

        $green = $res['venues']['绿地店'];
        $this->assertSame(5, $green['signing']);
        // 顾客甲：顾客未签（0）、场馆已签（2）；顾客乙相反
        $this->assertSame(1, $green['pendingCustomer']);
        $this->assertSame(1, $green['pendingVenue']);
        $this->assertSame(0, $green['unknown']);
        $this->assertTrue($green['fieldConfirmed']);
        $this->assertSame(116, $green['expired']);

        $east = $res['venues']['东部店'];
        $this->assertSame(3, $east['signing']);
        $this->assertSame(43, $east['expired']);
    }

    public function test_pending_contracts_is_venue_scoped_for_manager(): void
    {
        Sanctum::actingAs($this->user('manager-green', '绿地店长', 'R_MANAGER', '绿地店'));
        $this->fakeKeepYoga();

        $res = $this->getJson('/api/ky/pending-contracts')->assertOk()->json('data');

        $this->assertArrayHasKey('绿地店', $res['venues']);
        $this->assertArrayNotHasKey('东部店', $res['venues']);
    }

    public function test_ky_overview_aggregates_keepyoga_kpis_and_guards_roles(): void
    {
        Sanctum::actingAs($this->user('teacher-a', '老师A', 'R_TEACHER', '绿地店'));
        $this->getJson('/api/ky/overview')->assertForbidden();

        Sanctum::actingAs($this->user('manager-green', '绿地店长', 'R_MANAGER', '绿地店'));
        $this->fakeKeepYoga();

        $res = $this->getJson('/api/ky/overview')->assertOk()->json('data');

        $this->assertArrayHasKey('绿地店', $res['venues']);
        $this->assertArrayNotHasKey('东部店', $res['venues']);
        $green = $res['venues']['绿地店'];
        $this->assertSame(29634.9, $green['thisMonthRevenue']);
        $this->assertSame(23473.4, $green['thisMonthUsage']);
        $this->assertSame(1529459.91, $green['remainingAssets']);
        $this->assertTrue($green['activityAvailable']);
        $this->assertSame(261, $green['activeMembers']);
        $this->assertSame(14, $green['riskMembers']);
        $this->assertSame(730, $green['lostMembers']);
        $this->assertTrue($green['visitorAvailable']);
        $this->assertSame(3, $green['monthNewVisitors']);
        $this->assertSame(1, $green['monthVisitorConversions']);
    }

    public function test_ky_overview_reports_venue_error_without_failing_request(): void
    {
        Sanctum::actingAs($this->user('manager-green', '绿地店长', 'R_MANAGER', '绿地店'));
        Http::fake([
            'cloud.keepyoga.com/passport/api/login' => Http::response([
                'errno' => '0', 'data' => ['access_token' => 'test-token'],
            ]),
            'cloud.keepyoga.com/venue/api/getvenuedataoverview' => Http::response([
                'errno' => '6', 'error' => '登录失效',
            ]),
        ]);

        $res = $this->getJson('/api/ky/overview')->assertOk()->json('data');
        $this->assertArrayHasKey('error', $res['venues']['绿地店']);
    }

    public function test_today_todo_returns_current_active_rules(): void
    {
        AppSetting::create(['rules' => [
            'renewalThreshold' => 8,
            'vipAmountThreshold' => 20000,
            'declineMode' => 'strict',
            'predropMin' => 10,
            'predropMax' => 25,
            'reviveDays' => 45,
        ]]);
        Sanctum::actingAs($this->user('manager-green', '绿地店长', 'R_MANAGER', '绿地店'));

        $this->getJson('/api/today/todo')
            ->assertOk()
            ->assertJsonPath('data.rules.renewalThreshold', 8)
            ->assertJsonPath('data.rules.predropMin', 10)
            ->assertJsonPath('data.rules.predropMax', 25)
            ->assertJsonPath('data.rules.reviveDays', 45)
            ->assertJsonPath('data.rules.vipAmountThreshold', 20000);
    }

    private function user(string $username, string $name, string $role, ?string $venue): User
    {
        return User::factory()->create(compact('username', 'name', 'role', 'venue'));
    }

    /**
     * 按随心瑜后台解读实测口径伪造上游：登录 + 合同（status=1 签署中 / 5 已过期）+ 数据三件套
     */
    private function fakeKeepYoga(): void
    {
        Http::fake([
            'cloud.keepyoga.com/passport/api/login' => Http::response([
                'errno' => '0', 'data' => ['access_token' => 'test-token'],
            ]),
            'cloud.keepyoga.com/venue/api/getallcontractlist' => function (Request $request) {
                $status = (string) ($request['contract_status'] ?? '');
                if ($status === '1') {
                    return Http::response([
                        'errno' => '0',
                        'data' => [
                            'total' => (string) ($request['venue_id'] === '4250' ? 3 : 5),
                            'list' => [
                                [
                                    'id' => '20260901001', 'name' => '顾客甲',
                                    'status' => '1', 'status_text' => '签署中',
                                    'customer_signatory_status' => '0', 'venue_signatory_status' => '2',
                                ],
                                [
                                    'id' => '20260901002', 'name' => '顾客乙',
                                    'status' => '1', 'status_text' => '签署中',
                                    'customer_signatory_status' => '2', 'venue_signatory_status' => '0',
                                ],
                            ],
                        ],
                    ]);
                }

                return Http::response([
                    'errno' => '0',
                    'data' => ['total' => $status === '5' ? ($request['venue_id'] === '4250' ? 43 : 116) : 0, 'list' => []],
                ]);
            },
            'cloud.keepyoga.com/venue/api/getvenuedataoverview' => Http::response([
                'errno' => '0',
                'data' => [
                    'this_month_revenue_total' => '29634.90',
                    'last_month_revenue_total' => '350533.53',
                    'this_month_usage_total' => '23473.40',
                    'remaining_assets_total' => '1529459.91',
                ],
            ]),
            'cloud.keepyoga.com/venue/api/getmembershipactivityanalysis' => Http::response([
                'errno' => '0',
                'data' => [
                    'total_members' => '1475', 'total_active_members' => '261',
                    'total_this_month_classes_members' => '103', 'total_last_month_classes_members' => '156',
                    'total_risk_members' => '14', 'total_inactive_members' => '227', 'total_lost_members' => '730',
                ],
            ]),
            'cloud.keepyoga.com/venue/api/getvisitorconversion' => Http::response([
                'errno' => '0',
                'data' => [
                    'total_visitors' => '784', 'total_this_month_new_add_visitors' => '3',
                    'total_this_month_classes_visitors' => '6', 'total_this_month_revisit_visitors' => '3',
                    'total_this_month_visitors_conversion_members' => '1',
                ],
            ]),
        ]);
    }
}
