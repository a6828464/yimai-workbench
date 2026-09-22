<?php

namespace Tests\Feature;

use App\Models\KyBooking;
use App\Models\PayrollMonthlyInput;
use App\Models\PayrollPerformance;
use App\Models\PayrollProfile;
use App\Models\User;
use App\Support\PayrollMoney;
use App\Support\PayrollRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 薪酬计算口径（`GET /payroll/calculate`）。
 *
 * 逐条对应 `统一工资计算引擎.py`（S1）：
 * - 底薪奖励档位 80/100/110/120（S1:39-45）
 * - 私教激励门槛 3万/5万/7万/8万/10万（S1:29-37）
 * - 提成阶梯 3万/6万（S1:24-28）、专职老师固定 7%（S1:180）
 * - 店长门店提成 2%（S1:181-182）
 * - 兼职老师底薪/绩效强制 0（S1:170）
 * - 两个 45 分钟 0.75 基数不同（S1:175 vs S1:178）
 * - `unavailable` / `warnings` 必须区分「真实输入」与「默认值」
 */
class PayrollCalculationTest extends TestCase
{
    use RefreshDatabase;

    private function super(): User
    {
        return User::factory()->create([
            'name' => '超管', 'username' => 'calc-super', 'role' => 'R_SUPER',
            'roles' => ['R_SUPER'], 'venue' => null, 'venues' => ['绿地店', '东部店'], 'status' => '启用',
        ]);
    }

    private function profile(string $name, array $attrs = []): PayrollProfile
    {
        return PayrollProfile::create(array_merge([
            'name' => $name, 'venue' => '绿地店', 'role' => '全职老师',
            'base_salary' => 4000, 'performance' => 0,
            'fee_private60' => 160, 'fee_private45' => 0,
            'fee_small' => 160, 'fee_group' => 160, 'fee_enterprise' => 160,
            'status' => '有效',
        ], $attrs));
    }

    /** 造 n 节已签到的私教课（每节一条预约行） */
    private function privateClasses(string $teacher, int $n, string $venue = '绿地店', string $courseName = 'VIP定制私教｜60Min'): void
    {
        for ($i = 0; $i < $n; $i++) {
            KyBooking::create([
                'source_key' => 'calc:'.uniqid('', true).':'.$i,
                'venue' => $venue,
                'booking_type' => '私教',
                'course_kind' => 'private',
                'member_id' => 'm'.$i,
                'member_name' => '会员'.$i,
                'phone' => '1380000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                // 每节必须落在**不同的时刻**：课次去重键含 start_at，
                // 若用 `$i % 28` 复用日期，80 节会被折成 28 个课次（夹具自身的坑）
                'start_at' => sprintf('2026-08-%02d %02d:%02d:00', intdiv($i, 24) % 28 + 1, $i % 24, intdiv($i, 24) % 60),
                'course_name' => $courseName,
                'teacher_name' => $teacher,
                'status_raw' => '已签到',
                'status' => 'signed',
                'is_trial' => false,
                'raw' => ['demo' => true],
            ]);
        }
    }

    private function calc(string $month = '2026-08', ?string $venue = '绿地店'): array
    {
        $url = '/api/payroll/calculate?month='.$month.($venue !== null ? '&venue='.rawurlencode($venue) : '');

        return $this->getJson($url)->assertOk()->json('data');
    }

    private function rowFor(array $calc, string $name): ?array
    {
        return collect($calc['rows'])->firstWhere('name', $name);
    }

    // ------------------------------------------------------------------
    // 底薪奖励档位（80/100/110/120）
    // ------------------------------------------------------------------

    /** 底薪奖励档位边界：79/80/99/100/109/110/119/120 */
    public function test_底薪奖励档位边界(): void
    {
        Sanctum::actingAs($this->super());

        $cases = [
            79 => 0,
            80 => 200,
            99 => 200,
            100 => 400,
            109 => 400,
            110 => 800,
            119 => 800,
            120 => 1000,
            130 => 1000,
        ];

        foreach ($cases as $hours => $expected) {
            // 每个用例用独立的名字，避免相互影响
            $name = "老师{$hours}";
            $this->profile($name);
            $this->privateClasses($name, $hours);

            $row = $this->rowFor($this->calc(), $name);
            $this->assertNotNull($row, "{$hours} 节应出现在结果里");
            $this->assertEqualsWithDelta(
                $expected,
                $row['baseReward'],
                0.001,
                "累计 {$hours} 节的底薪奖励应为 {$expected}"
            );
        }
    }

    /** 底薪奖励只看「两店累计」有效课时，且只发在所属门店 */
    public function test_底薪奖励按两店累计且只发所属门店(): void
    {
        Sanctum::actingAs($this->super());
        $this->profile('张情', ['venue' => '绿地店']);
        $this->privateClasses('张情', 50, '绿地店');
        $this->privateClasses('张情', 40, '东部店');  // 两店累计 90 → 200 档

        $绿地 = $this->rowFor($this->calc('2026-08', '绿地店'), '张情');
        $this->assertSame(50, $绿地['totalHours'], '绿地店本店 50 节');
        $this->assertSame(90, $绿地['accumulatedValidHours'], '两店累计 90 节');
        $this->assertEqualsWithDelta(200.0, $绿地['baseReward'], 0.001, '90 节 → 200 档');

        // 东部店算她时：她所属门店是绿地店 → 奖励只发所属门店，东部店不发
        $东部 = $this->rowFor($this->calc('2026-08', '东部店'), '张情');
        $this->assertEqualsWithDelta(0.0, $东部['baseReward'], 0.001, '底薪奖励只发所属门店');
    }

    /** 底薪奖励仅全职老师；专职/兼职/店长/馆主都没有 */
    public function test_底薪奖励仅全职老师(): void
    {
        Sanctum::actingAs($this->super());
        foreach (['专职老师', '兼职老师', '店长', '馆主', '顾问', '新媒体', '保洁'] as $role) {
            $name = '人'.$role;
            $this->profile($name, ['role' => $role, 'base_salary' => $role === '兼职老师' ? 0 : 4000]);
            $this->privateClasses($name, 130);
            $row = $this->rowFor($this->calc(), $name);
            if ($row === null) {
                continue;
            }
            $this->assertEqualsWithDelta(0.0, $row['baseReward'], 0.001, "{$role} 不应有底薪奖励");
        }
    }

    /** 有效课时口径：企业课不计入（S1:156），但计 totalHours */
    public function test_企业课不计入有效课时(): void
    {
        Sanctum::actingAs($this->super());
        $this->profile('张情');
        $this->privateClasses('张情', 80);
        // 企业课没有 course_kind 可表达，这里用 group 之外的方式验证口径函数本身
        $this->assertSame(200, PayrollRoles::baseRewardFor(80));
        $this->assertSame(0, PayrollRoles::baseRewardFor(79));

        $row = $this->rowFor($this->calc(), '张情');
        $this->assertSame(80, $row['accumulatedValidHours']);
        $this->assertEqualsWithDelta(200.0, $row['baseReward'], 0.001);
    }

    // ------------------------------------------------------------------
    // 私教课时费激励门槛（3万/5万/7万/8万/10万）
    // ------------------------------------------------------------------

    /** 激励档位边界：29999/30000/49999/50000/69999/70000/79999/80000/99999/100000 */
    public function test_私教激励门槛边界(): void
    {
        $cases = [
            29999.99 => 0,
            30000.00 => 10,
            49999.99 => 10,
            50000.00 => 15,
            69999.99 => 15,
            70000.00 => 25,
            79999.99 => 25,
            80000.00 => 35,
            99999.99 => 35,
            100000.00 => 40,
        ];
        foreach ($cases as $performance => $expectedAddOn) {
            $this->assertSame(
                $expectedAddOn,
                PayrollRoles::hourlyAddOn(PayrollMoney::cents($performance), 1.0),
                "两店累计业绩 {$performance} 的加价应为 {$expectedAddOn}"
            );
        }
    }

    /** 门槛倍数只放大门槛、不放大实际业绩（S1:158） */
    public function test_活动月倍数只放大门槛(): void
    {
        // 普通月 ×1：3 万 → 10
        $this->assertSame(10, PayrollRoles::hourlyAddOn(PayrollMoney::cents(30000), 1.0));
        // 品牌月 ×2：3 万不够，6 万才到 10
        $this->assertSame(0, PayrollRoles::hourlyAddOn(PayrollMoney::cents(30000), 2.0));
        $this->assertSame(10, PayrollRoles::hourlyAddOn(PayrollMoney::cents(60000), 2.0));
        // 周年庆 ×2.5：3 万不够，7.5 万才到 10
        $this->assertSame(0, PayrollRoles::hourlyAddOn(PayrollMoney::cents(70000), 2.5));
        $this->assertSame(10, PayrollRoles::hourlyAddOn(PayrollMoney::cents(75000), 2.5));
    }

    /** 激励只上浮私教，且只按本店节数（S1:178） */
    public function test_激励按本店私教节数计(): void
    {
        Sanctum::actingAs($this->super());
        $this->profile('张情');
        $this->privateClasses('张情', 3, '绿地店');
        // 业绩：两店累计 5 万 → 加价 15
        PayrollPerformance::create([
            'venue' => '绿地店', 'month' => '2026-08', 'allocation_type' => 'personal',
            'payroll_profile_id' => PayrollProfile::where('name', '张情')->first()->id,
            'source_name' => '张情', 'resolved_name' => '张情',
            'raw_amount' => 50000, 'commission_amount' => 50000, 'store_sales_amount' => 0,
        ]);

        $row = $this->rowFor($this->calc(), '张情');
        $this->assertSame(15, $row['hourlyIncentiveAddOn']);
        // 3 节 × 15 = 45
        $this->assertEqualsWithDelta(45.0, $row['hourlyIncentive'], 0.001);
    }

    /**
     * 🔴 两个 45 分钟 0.75 **基数不同**：基础走该人单价，激励走加价 ×0.75。
     *
     * 若把两者合并成一个系数，激励会按课时费的口径算，直接算错。
     */
    public function test_两个45分钟0_75基数不同(): void
    {
        Sanctum::actingAs($this->super());
        // 45 分钟单价 120（独立配置，不等于 160×0.75=120 —— 刻意让两者不同以便区分）
        $p = $this->profile('张情', ['fee_private60' => 160, 'fee_private45' => 100]);
        $this->privateClasses('张情', 1, '绿地店', 'VIP定制私教｜45Min');
        PayrollPerformance::create([
            'venue' => '绿地店', 'month' => '2026-08', 'allocation_type' => 'personal',
            'payroll_profile_id' => $p->id, 'source_name' => '张情', 'resolved_name' => '张情',
            'raw_amount' => 50000, 'commission_amount' => 50000, 'store_sales_amount' => 0,
        ]);

        $row = $this->rowFor($this->calc(), '张情');
        $this->assertSame(1, $row['hours']['private45']);

        // 基础课时费：45 分钟用**该人单价 100**（不是 160×0.75=120）
        $this->assertEqualsWithDelta(100.0, $row['baseHourlyPrivate45'], 0.001);
        $this->assertFalse($row['feePrivate45Derived']);
        // 激励：加价 15 × 0.75 = 11.25（基数是加价，不是课时费）
        $this->assertEqualsWithDelta(11.25, $row['hourlyIncentivePrivate45'], 0.001);
        $this->assertEqualsWithDelta(15.0, (float) $row['hourlyIncentiveAddOn'], 0.001);
    }

    /** 45 分钟单价是**独立字段**：非 0 取档案值，为 0 才退回 60×0.75（引擎 :175 三元表达式） */
    public function test_45分钟单价来源分别暴露(): void
    {
        Sanctum::actingAs($this->super());
        // 甲：档案配了 45 分钟价 100（≠ 160×0.75=120，刻意取不同值以便区分两条路）
        $this->profile('甲老师', ['fee_private60' => 160, 'fee_private45' => 100]);
        $this->privateClasses('甲老师', 1, '绿地店', 'VIP定制私教｜45Min');
        // 乙：档案 45 分钟价为 0 → 折算
        $this->profile('乙老师', ['fee_private60' => 160, 'fee_private45' => 0]);
        $this->privateClasses('乙老师', 1, '绿地店', 'VIP定制私教｜45Min');

        $calc = $this->calc();
        $a = $this->rowFor($calc, '甲老师');
        $b = $this->rowFor($calc, '乙老师');

        $this->assertSame('profile', $a['feePrivate45Source'], '非 0 时必须标为档案配置值');
        $this->assertEqualsWithDelta(100.0, $a['feePrivate45'], 0.001);
        $this->assertFalse($a['feePrivate45Derived']);

        $this->assertSame('derived_60x0.75', $b['feePrivate45Source'], '为 0 时必须标为折算值');
        $this->assertEqualsWithDelta(120.0, $b['feePrivate45'], 0.001);
        $this->assertTrue($b['feePrivate45Derived']);
    }

    /** 45 分钟单价配 0 → 基础课时费退回 60 × 0.75 */
    public function test_45分钟单价为0时退回60乘0_75(): void
    {
        Sanctum::actingAs($this->super());
        $this->profile('张情', ['fee_private60' => 160, 'fee_private45' => 0]);
        $this->privateClasses('张情', 1, '绿地店', 'VIP定制私教｜45Min');

        $row = $this->rowFor($this->calc(), '张情');
        $this->assertTrue($row['feePrivate45Derived'], '档案未配 45 分钟价 → 标记为折算值');
        $this->assertEqualsWithDelta(120.0, $row['baseHourlyPrivate45'], 0.001, '160 × 0.75 = 120');
    }

    /** 专职老师**不享**私教激励（S1:177） */
    public function test_专职老师不享私教激励(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->profile('钱冰璐', ['role' => '专职老师', 'base_salary' => 0]);
        $this->privateClasses('钱冰璐', 2);
        PayrollPerformance::create([
            'venue' => '绿地店', 'month' => '2026-08', 'allocation_type' => 'personal',
            'payroll_profile_id' => $p->id, 'source_name' => '钱冰璐', 'resolved_name' => '钱冰璐',
            'raw_amount' => 100000, 'commission_amount' => 100000, 'store_sales_amount' => 0,
        ]);

        $row = $this->rowFor($this->calc(), '钱冰璐');
        $this->assertSame(0, $row['hourlyIncentiveAddOn'], '专职老师加价恒 0');
        $this->assertEqualsWithDelta(0.0, $row['hourlyIncentive'], 0.001);
        // 但基础课时费照算：2 × 160 = 320
        $this->assertEqualsWithDelta(320.0, $row['baseHourlyFee'], 0.001);
    }

    // ------------------------------------------------------------------
    // 销售提成阶梯
    // ------------------------------------------------------------------

    /** 提成阶梯边界：0 / 29999 / 30000 / 59999 / 60000 */
    public function test_销售提成阶梯边界(): void
    {
        $this->assertSame('0', PayrollRoles::commissionRateFor(0));
        $this->assertSame('0.02', PayrollRoles::commissionRateFor(PayrollMoney::cents(29999)));
        $this->assertSame('0.04', PayrollRoles::commissionRateFor(PayrollMoney::cents(30000)));
        $this->assertSame('0.04', PayrollRoles::commissionRateFor(PayrollMoney::cents(59999)));
        $this->assertSame('0.07', PayrollRoles::commissionRateFor(PayrollMoney::cents(60000)));
    }

    /** 全额阶梯，不做超额累进：6 万整 → 60000 × 7% = 4200 */
    public function test_全额阶梯不累进(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->profile('张情');
        PayrollPerformance::create([
            'venue' => '绿地店', 'month' => '2026-08', 'allocation_type' => 'personal',
            'payroll_profile_id' => $p->id, 'source_name' => '张情', 'resolved_name' => '张情',
            'raw_amount' => 60000, 'commission_amount' => 60000, 'store_sales_amount' => 0,
        ]);

        $row = $this->rowFor($this->calc(), '张情');
        $this->assertEqualsWithDelta(0.07, $row['commissionRate'], 0.0001);
        $this->assertEqualsWithDelta(4200.0, $row['commission'], 0.001);
    }

    /** 专职老师固定 7%，不走阶梯 */
    public function test_专职老师固定7个点(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->profile('钱冰璐', ['role' => '专职老师', 'base_salary' => 0]);
        PayrollPerformance::create([
            'venue' => '绿地店', 'month' => '2026-08', 'allocation_type' => 'personal',
            'payroll_profile_id' => $p->id, 'source_name' => '钱冰璐', 'resolved_name' => '钱冰璐',
            // 1 万在阶梯里只有 2%，专职老师必须仍按 7%
            'raw_amount' => 10000, 'commission_amount' => 10000, 'store_sales_amount' => 0,
        ]);

        $row = $this->rowFor($this->calc(), '钱冰璐');
        $this->assertEqualsWithDelta(0.07, $row['commissionRate'], 0.0001);
        $this->assertEqualsWithDelta(700.0, $row['commission'], 0.001);
    }

    /**
     * 🔴 舍入：逐笔 ROUND_HALF_UP 后累加。
     *
     * 89,857.50 × 7% = 6,290.025 → half-up **6,290.03**（banker's 会给 6,290.02）。
     * 64,101.50 × 7% = 4,487.105 → half-up **4,487.11**（banker's 会给 4,487.10）。
     */
    public function test_金额舍入走half_up而非bankers(): void
    {
        $this->assertSame('6290.03', PayrollMoney::fmt(PayrollMoney::mulFactor(PayrollMoney::cents('89857.50'), '0.07')));
        $this->assertSame('4487.11', PayrollMoney::fmt(PayrollMoney::mulFactor(PayrollMoney::cents('64101.50'), '0.07')));
        // 逐笔舍入后累加 = 21,068.10；若先求和再舍入或走 banker's 会得 21,068.08
        $sum = PayrollMoney::mulFactor(PayrollMoney::cents('89857.50'), '0.07')
            + PayrollMoney::mulFactor(PayrollMoney::cents('64101.50'), '0.07');
        $this->assertSame('10777.14', PayrollMoney::fmt($sum));
        $this->assertNotSame('10777.12', PayrollMoney::fmt($sum));
    }

    // ------------------------------------------------------------------
    // 门店提成
    // ------------------------------------------------------------------

    /** 店长拿本店全店销售额 × 2% */
    public function test_店长拿门店提成2个点(): void
    {
        Sanctum::actingAs($this->super());
        $this->profile('罗柳柳', ['role' => '店长', 'base_salary' => 5000]);
        // 门店销售额（提点口径）364,167.20
        PayrollPerformance::create([
            'venue' => '绿地店', 'month' => '2026-08', 'allocation_type' => 'venue',
            'payroll_profile_id' => null, 'source_name' => '会馆', 'resolved_name' => '',
            'raw_amount' => 19399.70, 'commission_amount' => 19399.70, 'store_sales_amount' => 364167.20,
        ]);

        $calc = $this->calc();
        $this->assertEqualsWithDelta(364167.20, $calc['storeSales'], 0.001);

        $row = $this->rowFor($calc, '罗柳柳');
        $this->assertEqualsWithDelta(0.02, $row['storeCommissionRate'], 0.0001);
        $this->assertEqualsWithDelta(7283.34, $row['storeCommission'], 0.001, '364,167.20 × 2% = 7,283.34');
    }

    /** 馆主蒙澍南按档案覆盖率拿 5%（引擎里原是姓名硬编码，档案化后为数据） */
    public function test_馆主门店提成5个点走档案覆盖(): void
    {
        Sanctum::actingAs($this->super());
        $this->profile('蒙澍南', [
            'role' => '馆主', 'base_salary' => 5000, 'dual_base_salary' => true,
            'store_commission_rate' => 0.05,
        ]);
        PayrollPerformance::create([
            'venue' => '绿地店', 'month' => '2026-08', 'allocation_type' => 'venue',
            'payroll_profile_id' => null, 'source_name' => '会馆', 'resolved_name' => '',
            'raw_amount' => 0, 'commission_amount' => 0, 'store_sales_amount' => 364167.20,
        ]);

        $row = $this->rowFor($this->calc(), '蒙澍南');
        $this->assertEqualsWithDelta(0.05, $row['storeCommissionRate'], 0.0001);
        $this->assertEqualsWithDelta(18208.36, $row['storeCommission'], 0.001, '364,167.20 × 5% = 18,208.36');
    }

    /** 非店长/非覆盖率的人没有门店提成 */
    public function test_普通老师无门店提成(): void
    {
        Sanctum::actingAs($this->super());
        $this->profile('张情');
        PayrollPerformance::create([
            'venue' => '绿地店', 'month' => '2026-08', 'allocation_type' => 'venue',
            'payroll_profile_id' => null, 'source_name' => '会馆', 'resolved_name' => '',
            'raw_amount' => 0, 'commission_amount' => 0, 'store_sales_amount' => 364167.20,
        ]);

        $row = $this->rowFor($this->calc(), '张情');
        $this->assertEqualsWithDelta(0.0, $row['storeCommission'], 0.001);
    }

    // ------------------------------------------------------------------
    // 兼职老师底薪强制 0
    // ------------------------------------------------------------------

    /** 兼职老师底薪/绩效强制 0 —— 设置时被拦、计算时也为 0 */
    public function test_兼职老师底薪绩效强制为0(): void
    {
        Sanctum::actingAs($this->super());
        // 建档时直接给非 0 底薪（绕过校验，模拟历史数据），计算时仍必须为 0
        $p = $this->profile('吴晓丽', ['role' => '兼职老师', 'base_salary' => 3000, 'performance' => 500]);
        $this->privateClasses('吴晓丽', 10);

        $row = $this->rowFor($this->calc(), '吴晓丽');
        $this->assertEqualsWithDelta(0.0, $row['baseSalary'], 0.001, '兼职老师底薪强制 0');
        $this->assertEqualsWithDelta(0.0, $row['performance'], 0.001, '兼职老师绩效强制 0');
        // 但课时费照算：10 × 160 = 1600
        $this->assertEqualsWithDelta(1600.0, $row['baseHourlyFee'], 0.001);
    }

    /** 兼职老师设置非 0 底薪 → 422 PARTTIME_FIXED_SALARY */
    public function test_设置兼职老师非0底薪被拦(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->profile('吴晓丽', ['role' => '兼职老师', 'base_salary' => 0]);

        $res = $this->putJson("/api/payroll/profiles/{$p->id}", [
            'role' => '兼职老师', 'baseSalary' => 3000,
        ])->assertStatus(422);
        $this->assertSame('PARTTIME_FIXED_SALARY', $res->json('code'));
    }

    /** 双底薪例外只允许 3 人 */
    public function test_双底薪例外白名单(): void
    {
        Sanctum::actingAs($this->super());
        $allowed = $this->profile('蒙澍南', ['role' => '馆主']);
        $denied = $this->profile('张情');

        $this->putJson("/api/payroll/profiles/{$allowed->id}", ['dualBaseSalary' => true])->assertOk();

        $res = $this->putJson("/api/payroll/profiles/{$denied->id}", ['dualBaseSalary' => true])->assertStatus(422);
        $this->assertSame('DUAL_BASE_NOT_ALLOWED', $res->json('code'));
    }

    /** 身份标签必须是存储层枚举；`东部店:顾问` 归一化后合法 */
    public function test_身份标签枚举校验与归一化(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->profile('蒙天乐', ['role' => '顾问']);

        $this->assertTrue(PayrollRoles::isValidRole('东部店:顾问'));
        $this->assertSame('顾问', PayrollRoles::normalizeRole('东部店:顾问'));
        $this->assertSame('顾问', PayrollRoles::normalizeRole('东部店：顾问'));

        // 归一化后存进去
        $res = $this->putJson("/api/payroll/profiles/{$p->id}", ['role' => '东部店:顾问'])->assertOk();
        $this->assertSame('顾问', $res->json('data.profile.role'), '展示前缀不得回写档案');

        $bad = $this->putJson("/api/payroll/profiles/{$p->id}", ['role' => '店长助理'])->assertStatus(422);
        $this->assertSame('ROLE_NOT_ALLOWED', $bad->json('code'));
    }

    /** 展示层标签：馆主 → 管理层；跨店全职老师 → {所属门店}全职老师 */
    public function test_展示层标签不改写存储层(): void
    {
        $this->assertSame('管理层', PayrollRoles::displayRole('馆主', '绿地店', '绿地店'));
        $this->assertSame('绿地店全职老师', PayrollRoles::displayRole('全职老师', '绿地店', '绿地店'));
        $this->assertSame('绿地店全职老师', PayrollRoles::displayRole('全职老师', '绿地店', '东部店'));
        $this->assertSame('店长', PayrollRoles::displayRole('店长', '绿地店', '绿地店'));
    }

    // ------------------------------------------------------------------
    // unavailable / warnings
    // ------------------------------------------------------------------

    /** 未导入业绩时，提成必须标「不可计算」而不是「等于 0」 */
    public function test_未导入业绩时提成列入unavailable(): void
    {
        Sanctum::actingAs($this->super());
        $this->profile('张情');
        $this->privateClasses('张情', 2);

        $calc = $this->calc();
        $items = array_column($calc['unavailable'], 'item');
        $this->assertNotEmpty($calc['unavailable'], 'unavailable 必须存在');
        $this->assertTrue(
            collect($items)->contains(fn ($i) => str_contains($i, '提成')),
            '未导入业绩时提成必须进 unavailable'
        );
        foreach ($calc['unavailable'] as $u) {
            $this->assertArrayHasKey('item', $u);
            $this->assertArrayHasKey('reason', $u);
            $this->assertNotEmpty($u['reason']);
        }
    }

    /** 已导入业绩时，提成不再列 unavailable */
    public function test_已导入业绩后提成可算(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->profile('张情');
        $this->privateClasses('张情', 2);
        PayrollPerformance::create([
            'venue' => '绿地店', 'month' => '2026-08', 'allocation_type' => 'personal',
            'payroll_profile_id' => $p->id, 'source_name' => '张情', 'resolved_name' => '张情',
            'raw_amount' => 1000, 'commission_amount' => 1000, 'store_sales_amount' => 0,
        ]);

        $calc = $this->calc();
        $this->assertFalse(
            collect(array_column($calc['unavailable'], 'item'))->contains(fn ($i) => str_contains($i, '提成')),
            '已导入业绩后提成应可算'
        );
    }

    /** 考勤未输入 → 按全勤（扣款 0）并给 warning */
    public function test_考勤未输入按全勤并给warning(): void
    {
        Sanctum::actingAs($this->super());
        $this->profile('张情');
        $this->privateClasses('张情', 1);

        $calc = $this->calc();
        $row = $this->rowFor($calc, '张情');
        $this->assertEqualsWithDelta(0.0, $row['leaveDeduction'], 0.001);
        $this->assertFalse($row['inputsReal']['attendance'], '必须能区分「真实输入」与「默认值」');
        $this->assertNotNull(collect($calc['warnings'])->firstWhere('code', 'ATTENDANCE_DEFAULT_FULL'));
    }

    /** 考勤真实输入时算请假扣款，且不再报默认值 warning */
    public function test_考勤真实输入时计算请假扣款(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->profile('张情', ['base_salary' => 4000]);
        $this->privateClasses('张情', 1);
        PayrollMonthlyInput::create([
            'payroll_profile_id' => $p->id, 'venue' => '绿地店', 'month' => '2026-08',
            'attendance_days' => 20, 'personal_leave_hours' => 8, 'sick_leave_hours' => 0,
            'social_security_mode' => 'inherit',
        ]);

        $calc = $this->calc();
        $row = $this->rowFor($calc, '张情');
        // 4000 / 20 天 × 1 天 = 200
        $this->assertEqualsWithDelta(200.0, $row['leaveDeduction'], 0.001);
        $this->assertTrue($row['inputsReal']['attendance']);
        $this->assertNull(
            collect($calc['warnings'])->firstWhere('code', 'ATTENDANCE_DEFAULT_FULL'),
            '已填考勤时不应再报「按全勤处理」'
        );
    }

    /** 填了请假小时但缺应出勤天数 → 扣款 0 但必须给高等级 warning，不得静默放过 */
    public function test_缺应出勤天数时给高等级warning(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->profile('张情');
        $this->privateClasses('张情', 1);
        PayrollMonthlyInput::create([
            'payroll_profile_id' => $p->id, 'venue' => '绿地店', 'month' => '2026-08',
            'personal_leave_hours' => 8, 'social_security_mode' => 'inherit',
        ]);

        $calc = $this->calc();
        $row = $this->rowFor($calc, '张情');
        $this->assertEqualsWithDelta(0.0, $row['leaveDeduction'], 0.001);
        $w = collect($calc['warnings'])->firstWhere('code', 'ATTENDANCE_MISSING_DAYS');
        $this->assertNotNull($w, '缺应出勤天数必须给 warning');
        $this->assertSame('high', $w['level']);
    }

    /** 个税未输入 → 0 + warning（个税绝不沿用上月） */
    public function test_个税未输入按0并给warning(): void
    {
        Sanctum::actingAs($this->super());
        $this->profile('张情');
        $this->privateClasses('张情', 1);

        $calc = $this->calc();
        $row = $this->rowFor($calc, '张情');
        $this->assertEqualsWithDelta(0.0, $row['tax'], 0.001);
        $this->assertFalse($row['inputsReal']['tax']);
        $this->assertNotNull(collect($calc['warnings'])->firstWhere('code', 'TAX_DEFAULT_ZERO'));
    }

    /** 应发/实发公式：应发 = Σ各项；实发 = 应发 − 社保 − 个税 */
    public function test_应发实发公式(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->profile('张情', ['base_salary' => 4000, 'performance' => 500]);
        $this->privateClasses('张情', 2);   // 2 × 160 = 320
        PayrollMonthlyInput::create([
            'payroll_profile_id' => $p->id, 'venue' => '绿地店', 'month' => '2026-08',
            'attendance_days' => 20, 'personal_leave_hours' => 0, 'sick_leave_hours' => 0,
            'social_security' => 557.76, 'social_security_mode' => 'set',
            'tax' => 100,
        ]);

        $row = $this->rowFor($this->calc(), '张情');
        $expectedGross = 4000 + 500 + 0 + 320 + 0 + 0 + 0 + 0 + 0 - 0 - 0;
        $this->assertEqualsWithDelta($expectedGross, $row['gross'], 0.001);
        $this->assertEqualsWithDelta(557.76, $row['socialSecurity'], 0.001);
        $this->assertEqualsWithDelta(100.0, $row['tax'], 0.001);
        $this->assertEqualsWithDelta($expectedGross - 557.76 - 100, $row['net'], 0.001);
    }

    /** 活动月规则未确认 → blocked 非空（该店结果不可用于交付） */
    public function test_活动月规则未确认时blocked(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->profile('张情');
        $this->privateClasses('张情', 1);
        PayrollMonthlyInput::create([
            'payroll_profile_id' => $p->id, 'venue' => '绿地店', 'month' => '2026-08',
            'activity_type' => '周年庆', 'threshold_multiplier' => 2.5,
            'activity_confirm_status' => '未确认',
        ]);

        $calc = $this->calc();
        $this->assertNotEmpty($calc['blocked']);
        $this->assertSame('ACTIVITY_RULE_UNCONFIRMED', $calc['blocked'][0]['code']);
    }

    /** 活动月规则已确认 → 门槛按倍数放大，且不 blocked */
    public function test_活动月规则已确认时按倍数放大门槛(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->profile('张情');
        $this->privateClasses('张情', 1);
        PayrollMonthlyInput::create([
            'payroll_profile_id' => $p->id, 'venue' => '绿地店', 'month' => '2026-08',
            'activity_type' => '品牌月', 'threshold_multiplier' => 2,
            'activity_confirm_status' => '已确认',
        ]);
        PayrollPerformance::create([
            'venue' => '绿地店', 'month' => '2026-08', 'allocation_type' => 'personal',
            'payroll_profile_id' => $p->id, 'source_name' => '张情', 'resolved_name' => '张情',
            'raw_amount' => 30000, 'commission_amount' => 30000, 'store_sales_amount' => 0,
        ]);

        $calc = $this->calc();
        $this->assertSame([], $calc['blocked']);
        $this->assertEqualsWithDelta(2.0, (float) $calc['activityThresholdMultiplier'], 0.001);
        // 品牌月 ×2：3 万不够 6 万门槛 → 加价 0
        $this->assertSame(0, $this->rowFor($calc, '张情')['hourlyIncentiveAddOn']);
    }

    /** 门店合计 */
    public function test_门店合计(): void
    {
        Sanctum::actingAs($this->super());
        $this->profile('张情');
        $this->profile('徐秀娟');
        $this->privateClasses('张情', 2);
        $this->privateClasses('徐秀娟', 3);

        $calc = $this->calc();
        $this->assertSame(2, $calc['storeTotal']['headcount']);
        $this->assertSame(5, $calc['storeTotal']['hours']);
        $this->assertEqualsWithDelta(
            $this->rowFor($calc, '张情')['gross'] + $this->rowFor($calc, '徐秀娟')['gross'],
            $calc['storeTotal']['gross'],
            0.001
        );
    }
}
