<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KyCard;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B2-1：营收字段的**字段级**收敛（t15）。
 *
 * 背景：馆主的提成按销售金额计算，所以「本店售卡实收 / 课时耗卡金额」对一线可见
 * 就等于公开了提成基数 —— 一条薪酬侧信道。但**不能**把 /analytics/* 整块收成超管专属：
 * 其中 5 个端点对老师是按人 scope 的，老师的今日视图靠它们。
 *
 * 所以这里钉三件事：
 *  1. 非管理层拿到 `revenueMix = null`，且 `trends` 里售卡相关的数字全为 0；
 *  2. 管理层（超管 / 店长）拿到的数字**不变**（是真值，不是 0）；
 *  3. 其余指标（会员数 / 待续课 / 任务完成率…）对老师照旧 —— 端点没有被整块拦住；
 *     响应键名一个不少（两边解析不用改）。
 */
class AnalyticsRevenueVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const CARD_PRICE = 5600.00;

    // ---------------------------------------------------------------- 1. summary

    /** 非管理层：revenueMix 必须是 null，但其他指标照旧可读。 */
    public function test_service_teacher_gets_null_revenue_mix_but_keeps_other_metrics(): void
    {
        $this->seedRevenueWorld();
        $teacher = $this->user('b2-service', 'B2服务老师', 'R_SERVICE', '绿地店');
        Sanctum::actingAs(User::findOrFail($teacher->id));

        $resp = $this->getJson('/api/analytics/summary')->assertOk();
        $data = $resp->json('data');

        $this->assertArrayHasKey('revenueMix', $data, '键必须保留（只是值变 null），否则前端类型/渲染会炸');
        $this->assertNull($data['revenueMix'], '一线不该拿到售卡实收与课时耗卡金额');

        // 端点没有被整块拦截：老师的核心指标仍在
        foreach (['totalCustomers', 'totalMembers', 'renewalTasks', 'taskRate', 'assignRate'] as $key) {
            $this->assertArrayHasKey($key, $data, "老师仍需要 {$key}，端点不得整块收敛");
        }
    }

    /** 管理层：数字必须仍是真值（证明收敛的是「谁看得见」，不是把功能关掉）。 */
    public function test_manager_and_super_still_see_real_revenue_mix(): void
    {
        $this->seedRevenueWorld();

        foreach ([
            'super' => ['R_SUPER', null],
            'manager' => ['R_MANAGER', '绿地店'],
        ] as $label => [$role, $venue]) {
            $u = $this->user("b2-{$label}", "B2{$label}", $role, $venue);
            Sanctum::actingAs(User::findOrFail($u->id));

            $mix = $this->getJson('/api/analytics/summary')->assertOk()->json('data.revenueMix');

            $this->assertIsArray($mix, "{$label} 必须拿到 revenueMix");
            $this->assertSame(self::CARD_PRICE, (float) $mix['cardSales'], "{$label} 的售卡实收必须仍是真值");
            $this->assertArrayHasKey('private', $mix);
            $this->assertArrayHasKey('consumptionTotal', $mix);
        }
    }

    // ----------------------------------------------------------------- 2. trends

    /** 非管理层：trends 里不得出现任何售卡金额；管理层保持真值。 */
    public function test_trends_hides_card_money_from_non_management(): void
    {
        $this->seedRevenueWorld();
        $today = now()->toDateString();
        $query = "/api/analytics/trends?start={$today}&end={$today}";

        // 老师侧
        $teacher = $this->user('b2-trend-service', 'B2趋势老师', 'R_SERVICE', '绿地店');
        Sanctum::actingAs(User::findOrFail($teacher->id));
        $teacherData = $this->getJson($query)->assertOk()->json('data');

        $this->assertSame(0, (int) $teacherData['summary']['cardSalesCount'], '老师不该拿到售卡张数');
        $this->assertSame(0.0, (float) $teacherData['summary']['dealAmount'], '老师不该拿到售卡实收合计');
        $this->assertNotEmpty($teacherData['daily'], '老师应有自己的留资日行（否则本用例断言空转）');
        foreach ($teacherData['daily'] as $row) {
            foreach ($row as $key => $value) {
                if ($key === 'date') {
                    continue;
                }
                $this->assertSame(0, (int) ($value['cardSales'] ?? 0), '老师侧的日售卡张数必须为 0');
                $this->assertSame(0.0, (float) ($value['amount'] ?? 0), '老师侧的日售卡金额必须为 0');
            }
        }

        // 超管侧：同一批数据必须是真值
        $super = $this->user('b2-trend-super', 'B2趋势超管', 'R_SUPER', null);
        Sanctum::actingAs(User::findOrFail($super->id));
        $superData = $this->getJson($query)->assertOk()->json('data');

        $this->assertSame(1, (int) $superData['summary']['cardSalesCount'], '超管的售卡张数必须仍是 1');
        $this->assertSame(self::CARD_PRICE, (float) $superData['summary']['dealAmount'], '超管的售卡实收必须仍是真值');
    }

    // ------------------------------------------------------------- 数据准备

    /**
     * 一张当天的售卡（实收 5600）+ 一条今天的留资 + 两位会员。
     * 售卡是 revenueMix 与 trends 的唯一金额来源；留资用来让 trends 的 daily 行非空。
     */
    private function seedRevenueWorld(): void
    {
        KyCard::create([
            'source_key' => 'b2-card-1',
            'venue' => '绿地店',
            'external_id' => 'b2-ext-1',
            'card_title' => '私教50次',
            'member_id' => '880001',
            'member_name' => 'B2会员',
            'phone' => '13900000011',
            'deal_price' => self::CARD_PRICE,
            'price' => self::CARD_PRICE,
            'status' => '正常',
            'status_format' => '正常',
            'is_taste' => false,
            'sold_at' => now()->toDateString(),
        ]);

        Lead::create([
            'lead_date' => now()->toDateString(), 'name' => 'B2留资', 'phone' => '13900000012',
            'demand' => '体验课', 'source' => '美团', 'venue' => '绿地店',
            'service_teacher' => 'B2服务老师', 'status' => '新留资',
        ]);

        Customer::create([
            'name' => 'B2会员', 'phone' => '13900000011', 'phone_tail' => '0011',
            'venue' => '绿地店', 'source' => 'KeepYoga', 'consultant' => 'B2服务老师',
            'owner' => 'B2服务老师', 'status' => '在籍', 'layer' => 'P4', 'main_card' => '私教50次',
            'external_id' => 'ky:77001:880001',
        ]);
    }

    private function user(string $username, string $name, string $role, ?string $venue): User
    {
        return User::factory()->create([
            'username' => $username,
            'name' => $name,
            'role' => $role,
            'roles' => [$role],
            'venue' => $venue,
            'venues' => $venue ? [$venue] : ['绿地店', '东部店'],
            'status' => '启用',
        ]);
    }
}
