<?php

namespace Tests\Feature;

use App\Models\KyBooking;
use App\Models\PayrollMonthlyInput;
use App\Models\PayrollProfile;
use App\Models\User;
use App\Services\PayrollNameResolver;
use Database\Seeders\PayrollProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 端到端：用**真实主档 + 真实业绩样表**跑完整链路。
 *
 * 链路：初始化 55 人档案 → 导入绿地店 2026-08 业绩 → 读课时 → 月度输入 →
 * 薪酬计算，逐项核对规格 §3.6 的基准数字。
 *
 * 两个真实文件都在仓库外（含员工姓名/底薪/客户金额，属高敏数据），
 * 任一缺失则跳过并说明原因。
 */
class PayrollEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private function masterPath(): string
    {
        return (string) (env('PAYROLL_MASTER_XLSX') ?: '/tmp/ymzip/一麦瑜伽工资薪酬核算完整交接包_20260922_024627/04_生产主档与模板/一麦工资人员系统主档_全中文_v2_20260719.xlsx');
    }

    private function samplePath(): string
    {
        return (string) (env('PAYROLL_PERFORMANCE_SAMPLE') ?: '/tmp/ymperf/2026年8月一麦瑜伽绿地店业绩明细.xlsx');
    }

    public function test_真实主档与样表跑完整链路(): void
    {
        if (! is_file($this->masterPath())) {
            $this->markTestSkipped('人员主档不存在，跳过端到端核对');
        }
        if (! is_file($this->samplePath())) {
            $this->markTestSkipped('业绩样表不存在，跳过端到端核对');
        }

        // ---- 1. 初始化人员档案（真实主档 55 人） ----
        putenv('PAYROLL_MASTER_XLSX='.$this->masterPath());
        $_ENV['PAYROLL_MASTER_XLSX'] = $this->masterPath();
        $_SERVER['PAYROLL_MASTER_XLSX'] = $this->masterPath();
        $this->seed(PayrollProfileSeeder::class);
        $this->assertSame(55, PayrollProfile::count());

        $super = User::factory()->create([
            'name' => '超管', 'username' => 'e2e-super', 'role' => 'R_SUPER', 'roles' => ['R_SUPER'],
            'venue' => null, 'venues' => ['绿地店', '东部店'], 'status' => '启用',
        ]);
        Sanctum::actingAs($super);

        // ---- 2. 导入绿地店 2026-08 业绩（真实样表） ----
        $commit = $this->post('/api/payroll/performance/commit', [
            'file' => new UploadedFile($this->samplePath(), '2026年8月一麦瑜伽绿地店业绩明细.xlsx', null, null, true),
            'venue' => '绿地店', 'month' => '2026-08',
        ])->assertOk()->json('data');

        $this->assertSame(0, $commit['counts']['exceptions'], '真实样表不应有异常行');
        $this->assertEqualsWithDelta(375863.50, $commit['totals']['raw']['personal'], 0.001, '原始归属个人合计');
        $this->assertEqualsWithDelta(19399.70, $commit['totals']['raw']['venue'], 0.001, '原始归属会馆合计');
        $this->assertEqualsWithDelta(395263.20, $commit['totals']['raw']['total'], 0.001, '原始归属总计');
        $this->assertEqualsWithDelta(344767.50, $commit['totals']['forCommission']['personal'], 0.001, '提点口径个人合计');
        $this->assertEqualsWithDelta(364167.20, $commit['totals']['forCommission']['storeSales'], 0.001, '提点口径门店销售额');
        $this->assertEqualsWithDelta(21068.10, $commit['totals']['forCommission']['commissionTotal'], 0.001, '销售提成合计（逐笔 half-up）');

        // ---- 3. 造一点真实课时（演示数据全为 group 且无时长，这里补私教） ----
        $zhangqing = PayrollProfile::where('name', '张情')->firstOrFail();
        $this->assertSame(160.0, (float) $zhangqing->fee_private60);
        // 80 节私教（60 分钟）→ 底薪奖励 200 档
        for ($i = 0; $i < 80; $i++) {
            KyBooking::create([
                'source_key' => 'e2e:'.uniqid('', true).':'.$i,
                'venue' => '绿地店', 'booking_type' => '私教', 'course_kind' => 'private',
                'member_id' => 'm'.$i, 'member_name' => '会员'.$i, 'phone' => '1380000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'start_at' => sprintf('2026-08-%02d %02d:%02d:00', intdiv($i, 24) % 28 + 1, $i % 24, intdiv($i, 24) % 60),
                'course_name' => 'VIP定制私教｜60Min', 'teacher_name' => '芷晴',
                'status_raw' => '已签到', 'status' => 'signed', 'is_trial' => false, 'raw' => ['demo' => true],
            ]);
        }

        // ---- 4. 读课时：别名「芷晴」应解析到「张情」 ----
        $hours = $this->getJson('/api/payroll/hours?month=2026-08&venue='.rawurlencode('绿地店'))->assertOk()->json('data');
        $row = collect($hours['rows'])->firstWhere('name', '张情');
        $this->assertNotNull($row, '别名「芷晴」应解析到「张情」');
        $this->assertSame(80, $row['private60']);
        $this->assertSame(80, $row['accumulatedHours']);
        $this->assertEqualsWithDelta(200.0, $row['baseReward'], 0.001, '80 节 → 底薪奖励 200');

        // ---- 5. 薪酬计算 ----
        $calc = $this->getJson('/api/payroll/calculate?month=2026-08&venue='.rawurlencode('绿地店'))
            ->assertOk()->json('data');

        $this->assertEqualsWithDelta(364167.20, $calc['storeSales'], 0.001, '门店提成基数走提点口径');

        // 张情：个人业绩 89,857.50（提点口径）→ 7% = 6,290.03
        $zq = collect($calc['rows'])->firstWhere('name', '张情');
        $this->assertEqualsWithDelta(89857.50, $zq['personalPerformance'], 0.001);
        $this->assertEqualsWithDelta(0.07, $zq['commissionRate'], 0.0001);
        $this->assertEqualsWithDelta(6290.03, $zq['commission'], 0.001, '89,857.50 × 7% 逐笔 half-up');
        // 底薪 4000 + 奖励 200 + 课时费 80×160=12800 + 私教激励
        $this->assertEqualsWithDelta(12800.0, $zq['baseHourlyFee'], 0.001);
        // 两店累计业绩 89,857.50 ≥ 8 万 → 加价 35；80 节 × 35 = 2800
        $this->assertSame(35, $zq['hourlyIncentiveAddOn']);
        $this->assertEqualsWithDelta(2800.0, $zq['hourlyIncentive'], 0.001);

        // 罗柳柳（苏米）：个人业绩 0 但拿本店 2% 门店提成
        $lll = collect($calc['rows'])->firstWhere('name', '罗柳柳');
        $this->assertEqualsWithDelta(0.0, $lll['personalPerformance'], 0.001);
        $this->assertEqualsWithDelta(7283.34, $lll['storeCommission'], 0.001);

        // 专职老师固定 7%
        $qbl = collect($calc['rows'])->firstWhere('name', '钱冰璐');
        $this->assertEqualsWithDelta(0.07, $qbl['commissionRate'], 0.0001, '专职老师固定 7%');
        $this->assertEqualsWithDelta(51400.0, $qbl['personalPerformance'], 0.001);
        $this->assertEqualsWithDelta(3598.0, $qbl['commission'], 0.001);
        $this->assertSame(0, $qbl['hourlyIncentiveAddOn'], '专职老师不享私教激励');

        // ---- 6. 提成合计必须等于样表基准 21,068.10 ----
        $commissionSum = 0.0;
        foreach ($calc['rows'] as $r) {
            $commissionSum += $r['commission'];
        }
        $this->assertEqualsWithDelta(21068.10, $commissionSum, 0.001, '逐人舍入后求和 = 21,068.10（不是 21,068.08）');

        // ---- 7. 社保三态：设一次 557.76，下月沿用 ----
        $this->putJson('/api/payroll/monthly-inputs', [
            'month' => '2026-08', 'venue' => '绿地店',
            'rows' => [
                ['profileId' => $zhangqing->id, 'socialSecurity' => 557.76, 'socialSecurityMode' => 'set', 'attendanceDays' => 27],
            ],
        ])->assertOk();

        // 9 月不操作 → 沿用 8 月的 557.76
        $sep = collect($this->getJson('/api/payroll/monthly-inputs?month=2026-09&venue='.rawurlencode('绿地店'))->json('data.rows'))
            ->firstWhere('name', '张情');
        $this->assertSame('inherit', $sep['socialSecurityMode']);
        $this->assertEqualsWithDelta(557.76, $sep['socialSecurity'], 0.001);
        $this->assertSame('2026-08', $sep['socialSecurityInheritedFrom']);

        // 8 月本身：显式设置
        $aug = collect($this->getJson('/api/payroll/monthly-inputs?month=2026-08&venue='.rawurlencode('绿地店'))->json('data.rows'))
            ->firstWhere('name', '张情');
        $this->assertSame('set', $aug['socialSecurityMode']);
        $this->assertEqualsWithDelta(557.76, $aug['socialSecurity'], 0.001);

        // 计算里社保生效
        $calc2 = $this->getJson('/api/payroll/calculate?month=2026-08&venue='.rawurlencode('绿地店'))->assertOk()->json('data');
        $zq2 = collect($calc2['rows'])->firstWhere('name', '张情');
        $this->assertEqualsWithDelta(557.76, $zq2['socialSecurity'], 0.001);
        $this->assertEqualsWithDelta($zq2['gross'] - 557.76 - $zq2['tax'], $zq2['net'], 0.01);

        // ---- 8. 响应里必须有 unavailable 清单 ----
        $this->assertNotEmpty($calc2['unavailable']);
        foreach ($calc2['unavailable'] as $u) {
            $this->assertArrayHasKey('item', $u);
            $this->assertArrayHasKey('reason', $u);
        }

        // ---- 9. 幂等：重复导入同一份表金额不变 ----
        $again = $this->post('/api/payroll/performance/commit', [
            'file' => new UploadedFile($this->samplePath(), '2026年8月一麦瑜伽绿地店业绩明细.xlsx', null, null, true),
            'venue' => '绿地店', 'month' => '2026-08',
        ])->assertOk()->json('data');
        $this->assertTrue($again['unchanged']);
        $this->assertEqualsWithDelta(344767.50, $again['totals']['forCommission']['personal'], 0.001);
        $this->assertEqualsWithDelta(364167.20, $again['totals']['forCommission']['storeSales'], 0.001);
    }
}
