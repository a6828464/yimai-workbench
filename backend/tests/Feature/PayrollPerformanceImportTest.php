<?php

namespace Tests\Feature;

use App\Models\PayrollPerformance;
use App\Models\PayrollProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 业绩表导入（`明细总表`）。
 *
 * 本测试用**样表真实数据**逐条核对，基准来自规格 §3.6（t2 用 openpyxl 全量重算，
 * 队长已用 openpyxl 独立复算确认）：
 *
 * | 指标 | 值 |
 * |---|---:|
 * | 数据行数 | 147 |
 * | 299 活动卡行数 / 金额 | 104 / 31,096.00 |
 * | 原始归属 · 个人 / 会馆 / 总计 | 375,863.50 / 19,399.70 / 395,263.20 |
 * | 提点口径 · 个人 / 门店销售额 | 344,767.50 / 364,167.20 |
 * | 销售提成合计 | 21,068.10 |
 *
 * 样表文件在 `/tmp/ymperf/`，**不随仓库发布**（含客户姓名与金额，属高敏数据）。
 * 文件不存在时整组用例跳过并给出明确原因，不让 CI 变红 —— 但要让人知道「基准没跑」。
 */
class PayrollPerformanceImportTest extends TestCase
{
    use RefreshDatabase;

    /** 样表路径（环境变量可覆盖；不存在则跳过基准用例） */
    private function samplePath(): string
    {
        return (string) (env('PAYROLL_PERFORMANCE_SAMPLE') ?: '/tmp/ymperf/2026年8月一麦瑜伽绿地店业绩明细.xlsx');
    }

    private function requireSample(): string
    {
        $p = $this->samplePath();
        if (! is_file($p)) {
            $this->markTestSkipped("业绩样表不存在（{$p}），跳过基准核对。可用环境变量 PAYROLL_PERFORMANCE_SAMPLE 指定路径。");
        }

        return $p;
    }

    private function super(): User
    {
        return User::factory()->create([
            'name' => '超管', 'username' => 'perf-super', 'role' => 'R_SUPER',
            'roles' => ['R_SUPER'], 'venue' => null, 'venues' => ['绿地店', '东部店'], 'status' => '启用',
        ]);
    }

    /**
     * 建 11 个销售员档案 + 别名（与主档实测映射一致）。
     *
     * 这 11 个列名里 **9 个是别名**（只有钱冰璐、黄敏是字面本名）。
     * 不解析别名 → 9 个人直接归零。
     */
    private function seedSalesProfiles(): array
    {
        $map = [
            // 列名 => [真实姓名, 岗位, 底薪, 60分钟课时费]
            '苏米' => ['罗柳柳', '店长', 5000, 110],
            '娟子' => ['徐秀娟', '全职老师', 2500, 135],
            '张芷晴' => ['张情', '全职老师', 4000, 160],
            '钱冰璐' => ['钱冰璐', '专职老师', 0, 150],
            'Nico' => ['李芯萍', '全职老师', 2000, 120],
            'CC' => ['吴艳', '全职老师', 2000, 80],
            '黄敏' => ['黄敏', '专职老师', 0, 160],
            'Lily' => ['郑卫丽', '全职老师', 2000, 80],
            '小鹏' => ['牟志鹏', '顾问', 3000, 0],
            '婷婷' => ['谭婷婷', '馆主', 3000, 200],
            '阿玉' => ['张卫玉', '新媒体', 2500, 0],
        ];
        $profiles = [];
        foreach ($map as $column => [$real, $role, $base, $fee]) {
            $p = PayrollProfile::create([
                'name' => $real, 'venue' => in_array($column, ['苏米', '小鹏', '阿玉'], true) ? '绿地店' : '绿地店',
                'role' => $role, 'base_salary' => $base, 'performance' => 0,
                'fee_private60' => $fee, 'fee_private45' => 0,
                'fee_small' => $fee, 'fee_group' => $fee, 'fee_enterprise' => $fee,
                'dual_base_salary' => in_array($real, ['谭婷婷', '张卫玉'], true),
                'status' => '有效',
            ]);
            if ($column !== $real) {
                $p->aliases = [$column]; $p->save();
            }
            $profiles[$column] = $p;
        }

        return $profiles;
    }

    private function upload(string $path): UploadedFile
    {
        return new UploadedFile($path, basename($path), null, null, true);
    }

    /** 基准①：原始归属口径（含 299）—— 验证**解析**正确 */
    public function test_样表原始归属基准逐项对上(): void
    {
        $path = $this->requireSample();
        Sanctum::actingAs($this->super());
        $this->seedSalesProfiles();

        $res = $this->post('/api/payroll/performance/preview', [
            'file' => $this->upload($path),
            'venue' => '绿地店',
            'month' => '2026-08',
        ])->assertOk()->json('data');

        $this->assertSame(147, $res['counts']['dataRows']);
        $this->assertSame(104, $res['counts']['skippedActivityCardRows']);
        $this->assertSame(144, $res['counts']['personalRows']);
        $this->assertSame(3, $res['counts']['venueRows']);
        $this->assertSame(3, $res['counts']['multiOwnerRows'], '样表有 3 行是一条交易归属两个销售员');
        $this->assertSame(0, $res['counts']['exceptions']);

        $this->assertEqualsWithDelta(375863.50, $res['totals']['raw']['personal'], 0.001);
        $this->assertEqualsWithDelta(19399.70, $res['totals']['raw']['venue'], 0.001);
        $this->assertEqualsWithDelta(395263.20, $res['totals']['raw']['total'], 0.001);
        $this->assertEqualsWithDelta(31096.00, $res['totals']['raw']['activityCardAmount'], 0.001);
    }

    /** 基准②：提点口径（剔除 299）—— 验证**提成**正确 */
    public function test_样表提点口径基准逐项对上(): void
    {
        $path = $this->requireSample();
        Sanctum::actingAs($this->super());
        $this->seedSalesProfiles();

        $res = $this->post('/api/payroll/performance/preview', [
            'file' => $this->upload($path), 'venue' => '绿地店', 'month' => '2026-08',
        ])->assertOk()->json('data');

        $this->assertEqualsWithDelta(344767.50, $res['totals']['forCommission']['personal'], 0.001);
        $this->assertEqualsWithDelta(364167.20, $res['totals']['forCommission']['storeSales'], 0.001);
        // 逐笔 ROUND_HALF_UP 后累加；banker's rounding 会得 21,068.08
        $this->assertEqualsWithDelta(21068.10, $res['totals']['forCommission']['commissionTotal'], 0.001);
    }

    /** 逐人金额（剔除 299 后）—— 队长用 openpyxl 独立复算的基准 */
    public function test_样表逐人提点金额对上(): void
    {
        $path = $this->requireSample();
        Sanctum::actingAs($this->super());
        $this->seedSalesProfiles();

        $byPerson = collect(
            $this->post('/api/payroll/performance/preview', [
                'file' => $this->upload($path), 'venue' => '绿地店', 'month' => '2026-08',
            ])->assertOk()->json('data.byPerson')
        )->keyBy('sourceName');

        $expected = [
            '张芷晴' => [101219.50, 89857.50],
            '黄敏' => [65596.50, 64101.50],
            'Nico' => [66567.50, 63876.50],
            '钱冰璐' => [55586.00, 51400.00],
            '娟子' => [37342.00, 35548.00],
            '婷婷' => [19154.00, 18556.00],
            'CC' => [13137.00, 12838.00],
            'Lily' => [10384.00, 8590.00],
            '苏米' => [3588.00, 0.00],
            '阿玉' => [2990.00, 0.00],
            '小鹏' => [299.00, 0.00],
        ];
        foreach ($expected as $column => [$raw, $commission]) {
            $row = $byPerson->get($column);
            $this->assertNotNull($row, "销售员列「{$column}」必须出现在 byPerson 里");
            $this->assertEqualsWithDelta($raw, $row['rawAmount'], 0.001, "「{$column}」原始金额");
            $this->assertEqualsWithDelta($commission, $row['commissionAmount'], 0.001, "「{$column}」提点金额");
        }
    }

    /** 9 个别名全部解析成真实姓名（不解析就 9 人归零） */
    public function test_9个别名全部解析为真实姓名(): void
    {
        $path = $this->requireSample();
        Sanctum::actingAs($this->super());
        $this->seedSalesProfiles();

        $byPerson = collect(
            $this->post('/api/payroll/performance/preview', [
                'file' => $this->upload($path), 'venue' => '绿地店', 'month' => '2026-08',
            ])->assertOk()->json('data.byPerson')
        )->keyBy('sourceName');

        $aliases = [
            '苏米' => '罗柳柳', '娟子' => '徐秀娟', '张芷晴' => '张情', 'Nico' => '李芯萍',
            'CC' => '吴艳', 'Lily' => '郑卫丽', '小鹏' => '牟志鹏', '婷婷' => '谭婷婷', '阿玉' => '张卫玉',
        ];
        foreach ($aliases as $column => $real) {
            $this->assertSame($real, $byPerson->get($column)['resolvedName'] ?? null, "「{$column}」应解析为「{$real}」");
        }
    }

    /**
     * 苏米（罗柳柳）剔除 299 后个人业绩为 **0**，但她仍应拿本店门店提成 2%。
     *
     * 这是最容易写错的一条：不能因为她「个人业绩 0」就把店长提成也跳过。
     */
    public function test_苏米个人提点为0但仍拿门店提成(): void
    {
        $path = $this->requireSample();
        Sanctum::actingAs($this->super());
        $profiles = $this->seedSalesProfiles();

        $this->post('/api/payroll/performance/commit', [
            'file' => $this->upload($path), 'venue' => '绿地店', 'month' => '2026-08',
        ])->assertOk();

        // 她的档案是店长 → 门店提成率 2%
        $this->assertSame('店长', $profiles['苏米']->role);
        $this->assertSame('0.02', $profiles['苏米']->storeCommissionRateValue());

        $calc = $this->getJson('/api/payroll/calculate?month=2026-08&venue='.rawurlencode('绿地店'))
            ->assertOk()->json('data');
        $row = collect($calc['rows'])->firstWhere('name', '罗柳柳');
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(0.0, $row['personalPerformance'], 0.001);
        $this->assertEqualsWithDelta(0.0, $row['commission'], 0.001);
        // 364,167.20 × 2% = 7,283.34
        $this->assertEqualsWithDelta(7283.34, $row['storeCommission'], 0.001);
    }

    /** 幂等：同一份表重复导入，金额与行数完全不变（全量替换，不是追加） */
    public function test_重复导入幂等(): void
    {
        $path = $this->requireSample();
        Sanctum::actingAs($this->super());
        $this->seedSalesProfiles();

        $first = $this->post('/api/payroll/performance/commit', [
            'file' => $this->upload($path), 'venue' => '绿地店', 'month' => '2026-08',
        ])->assertOk()->json('data');
        $countAfterFirst = PayrollPerformance::where('venue', '绿地店')->where('month', '2026-08')->count();

        $second = $this->post('/api/payroll/performance/commit', [
            'file' => $this->upload($path), 'venue' => '绿地店', 'month' => '2026-08',
        ])->assertOk()->json('data');

        $countAfterSecond = PayrollPerformance::where('venue', '绿地店')->where('month', '2026-08')->count();

        $this->assertSame($countAfterFirst, $countAfterSecond, '重复导入不得增加行数（追加会让金额翻倍）');
        $this->assertEqualsWithDelta(
            $first['totals']['forCommission']['personal'],
            $second['totals']['forCommission']['personal'],
            0.001
        );
        $this->assertEqualsWithDelta(
            $first['totals']['forCommission']['storeSales'],
            $second['totals']['forCommission']['storeSales'],
            0.001
        );
        $this->assertTrue($second['unchanged'], '指纹一致时应返回 unchanged=true 且不写库');
    }

    /** 换一份表覆盖同月 → 必须回 replaced.rows，避免用户以为在追加 */
    public function test_覆盖同月时回replaced行数(): void
    {
        $path = $this->requireSample();
        Sanctum::actingAs($this->super());
        $this->seedSalesProfiles();

        $this->post('/api/payroll/performance/commit', [
            'file' => $this->upload($path), 'venue' => '绿地店', 'month' => '2026-08',
        ])->assertOk();

        // 第二份：不同内容（少一行）→ 指纹不同，触发替换
        $tmp = $this->makeSmallXlsx();
        $res = $this->post('/api/payroll/performance/commit', [
            'file' => new UploadedFile($tmp, '小表.xlsx', null, null, true),
            'venue' => '绿地店', 'month' => '2026-08',
        ])->assertOk()->json('data');

        $this->assertFalse($res['unchanged']);
        $this->assertGreaterThan(0, $res['replaced']['rows'], '必须告知覆盖了多少行');
    }

    /** 表头声明 16384 列但实际只有 19 列 —— 必须按有效列扫，且不把占位空单元格当数据 */
    public function test_声明16384列时按有效列解析(): void
    {
        $path = $this->requireSample();
        Sanctum::actingAs($this->super());
        $this->seedSalesProfiles();

        $res = $this->post('/api/payroll/performance/preview', [
            'file' => $this->upload($path), 'venue' => '绿地店', 'month' => '2026-08',
        ])->assertOk()->json('data');

        // 分配列只应有 11 个销售员列（备注被排除）
        $this->assertSame(11, $res['counts']['distinctPeople']);
        $this->assertSame(0, $res['counts']['exceptions'], '占位空单元格不得被当成「无归属」行');
    }

    /** 非本月 sheet（瑜伽服饰）→ 不导入、不报错，只回 info */
    public function test_跳过瑜伽服饰sheet并回info(): void
    {
        $path = $this->requireSample();
        Sanctum::actingAs($this->super());
        $this->seedSalesProfiles();

        $res = $this->post('/api/payroll/performance/preview', [
            'file' => $this->upload($path), 'venue' => '绿地店', 'month' => '2026-08',
        ])->assertOk()->json('data');

        $notice = collect($res['notices'])->firstWhere('code', 'SHEET_SKIPPED');
        $this->assertNotNull($notice, '必须回一条 SHEET_SKIPPED info 提示');
        $this->assertSame('info', $notice['level']);
        $this->assertStringContainsString('瑜伽服饰销售登记表', $notice['message']);
    }

    /** 别名对不上 → 异常清单，且**不得静默丢弃** */
    public function test_别名对不上进异常清单且不静默丢弃(): void
    {
        Sanctum::actingAs($this->super());
        // 只建「张情」并登记别名「张芷晴」；「陌生名字」没有任何档案能对上
        $p = PayrollProfile::create([
            'name' => '张情', 'venue' => '绿地店', 'role' => '全职老师',
            'base_salary' => 4000, 'performance' => 0, 'fee_private60' => 160,
            'fee_private45' => 0, 'fee_small' => 160, 'fee_group' => 160,
            'fee_enterprise' => 160, 'status' => '有效',
        ]);
        $p->aliases = ['张芷晴']; $p->save();

        $tmp = $this->makeXlsx([
            ['一麦瑜伽2026年8月业绩明细', null, null, null, null, null, '销售人员'],
            ['日期', '星期', '会员姓名', '收款类型', '收款方式', '金额', '会馆', '张芷晴', '陌生名字', '备注'],
            ['2026-08-01', '六', '客户甲', '私教月卡', '蜜支付', 1000, null, 600, 400, null],
        ]);

        $res = $this->post('/api/payroll/performance/preview', [
            'file' => new UploadedFile($tmp, 't.xlsx', null, null, true),
            'venue' => '绿地店', 'month' => '2026-08',
        ])->assertOk()->json('data');

        $codes = array_column($res['exceptions'], 'code');
        $this->assertContains('UNMATCHED_NAME', $codes, '对不上的列名必须进异常清单');
        $bad = collect($res['exceptions'])->firstWhere('code', 'UNMATCHED_NAME');
        $this->assertSame('陌生名字', $bad['sourceName']);
        $this->assertEqualsWithDelta(400.0, $bad['amount'], 0.001, '异常行金额必须保留供核对');
    }

    /** 分配不平 → ALLOCATION_MISMATCH，该行不导入 */
    public function test_分配不平进异常清单(): void
    {
        Sanctum::actingAs($this->super());
        $p = PayrollProfile::create([
            'name' => '张情', 'venue' => '绿地店', 'role' => '全职老师',
            'base_salary' => 4000, 'performance' => 0, 'fee_private60' => 160,
            'fee_private45' => 0, 'fee_small' => 160, 'fee_group' => 160,
            'fee_enterprise' => 160, 'status' => '有效',
        ]);
        $p->aliases = ['张芷晴']; $p->save();

        // 金额 1000，但只分配了 600
        $tmp = $this->makeXlsx([
            ['标题'],
            ['日期', '星期', '会员姓名', '收款类型', '收款方式', '金额', '会馆', '张芷晴', '备注'],
            ['2026-08-01', '六', '客户甲', '私教月卡', '蜜支付', 1000, null, 600, null],
        ]);

        $res = $this->post('/api/payroll/performance/preview', [
            'file' => new UploadedFile($tmp, 't.xlsx', null, null, true),
            'venue' => '绿地店', 'month' => '2026-08',
        ])->assertOk()->json('data');

        $this->assertContains('ALLOCATION_MISMATCH', array_column($res['exceptions'], 'code'));
        $this->assertSame(0, $res['counts']['importedAllocations'], '不平的行不得导入');
    }

    /** 无个人归属且会馆为空 → UNALLOCATED，不静默丢弃 */
    public function test_无归属行进异常清单(): void
    {
        Sanctum::actingAs($this->super());

        $tmp = $this->makeXlsx([
            ['标题'],
            ['日期', '星期', '会员姓名', '收款类型', '收款方式', '金额', '会馆', '张芷晴', '备注'],
            ['2026-08-01', '六', '客户甲', '私教月卡', '蜜支付', 1000, null, null, null],
        ]);

        $res = $this->post('/api/payroll/performance/preview', [
            'file' => new UploadedFile($tmp, 't.xlsx', null, null, true),
            'venue' => '绿地店', 'month' => '2026-08',
        ])->assertOk()->json('data');

        $this->assertContains('UNALLOCATED', array_column($res['exceptions'], 'code'));
        $this->assertEqualsWithDelta(0.0, $res['totals']['raw']['total'], 0.001, '无归属行不计入任何口径');
    }

    /** 日期不在目标月份 → 正常跨月过滤，不算异常 */
    public function test_跨月行跳过且不算异常(): void
    {
        Sanctum::actingAs($this->super());
        $p = PayrollProfile::create([
            'name' => '张情', 'venue' => '绿地店', 'role' => '全职老师',
            'base_salary' => 4000, 'performance' => 0, 'fee_private60' => 160,
            'fee_private45' => 0, 'fee_small' => 160, 'fee_group' => 160,
            'fee_enterprise' => 160, 'status' => '有效',
        ]);
        $p->aliases = ['张芷晴']; $p->save();

        $tmp = $this->makeXlsx([
            ['标题'],
            ['日期', '星期', '会员姓名', '收款类型', '收款方式', '金额', '会馆', '张芷晴', '备注'],
            ['2026-07-31', '五', '上月客户', '私教月卡', '蜜支付', 500, null, 500, null],
            ['2026-08-01', '六', '本月客户', '私教月卡', '蜜支付', 1000, null, 1000, null],
        ]);

        $res = $this->post('/api/payroll/performance/preview', [
            'file' => new UploadedFile($tmp, 't.xlsx', null, null, true),
            'venue' => '绿地店', 'month' => '2026-08',
        ])->assertOk()->json('data');

        $this->assertSame(1, $res['counts']['skippedOutOfMonthRows']);
        $this->assertSame(0, $res['counts']['exceptions'], '跨月是正常过滤，不是异常');
        $this->assertEqualsWithDelta(1000.0, $res['totals']['raw']['personal'], 0.001);
    }

    /** 缺「金额」列 → 整体失败 INVALID_FILE，不做任何写入 */
    public function test_缺金额列整体失败(): void
    {
        Sanctum::actingAs($this->super());
        $tmp = $this->makeXlsx([
            ['标题'],
            ['日期', '星期', '会员姓名', '收款类型', '收款方式', '会馆', '张芷晴', '备注'],
            ['2026-08-01', '六', '客户甲', '私教月卡', '蜜支付', null, 600, null],
        ]);

        $res = $this->post('/api/payroll/performance/preview', [
            'file' => new UploadedFile($tmp, 't.xlsx', null, null, true),
            'venue' => '绿地店', 'month' => '2026-08',
        ])->assertStatus(422);
        $this->assertSame('INVALID_FILE', $res->json('code'));
        $this->assertSame(0, PayrollPerformance::count());
    }

    /** 缺「明细总表」sheet → INVALID_FILE */
    public function test_缺sheet整体失败(): void
    {
        Sanctum::actingAs($this->super());
        $tmp = $this->makeXlsx([
            ['日期', '金额'],
            ['2026-08-01', 100],
        ], '别的表');

        $res = $this->post('/api/payroll/performance/preview', [
            'file' => new UploadedFile($tmp, 't.xlsx', null, null, true),
            'venue' => '绿地店', 'month' => '2026-08',
        ])->assertStatus(422);
        $this->assertSame('INVALID_FILE', $res->json('code'));
    }

    /** 非 .xlsx → INVALID_FILE */
    public function test_非xlsx整体失败(): void
    {
        Sanctum::actingAs($this->super());
        $tmp = tempnam(sys_get_temp_dir(), 'perf').'.xls';
        file_put_contents($tmp, 'not a zip');

        $res = $this->post('/api/payroll/performance/preview', [
            'file' => new UploadedFile($tmp, 't.xls', null, null, true),
            'venue' => '绿地店', 'month' => '2026-08',
        ])->assertStatus(422);
        $this->assertSame('INVALID_FILE', $res->json('code'));
    }

    /** commit 带过期 previewSha256 → 409 PREVIEW_STALE */
    public function test_预览指纹不一致返回409(): void
    {
        $path = $this->requireSample();
        Sanctum::actingAs($this->super());
        $this->seedSalesProfiles();

        $res = $this->post('/api/payroll/performance/commit', [
            'file' => $this->upload($path), 'venue' => '绿地店', 'month' => '2026-08',
            'previewSha256' => str_repeat('a', 64),
        ])->assertStatus(409);
        $this->assertSame('PREVIEW_STALE', $res->json('code'));
    }

    /** 有异常行时 commit 默认拒绝写入 */
    public function test_有异常行时commit默认拒绝(): void
    {
        Sanctum::actingAs($this->super());
        $tmp = $this->makeXlsx([
            ['标题'],
            ['日期', '星期', '会员姓名', '收款类型', '收款方式', '金额', '会馆', '张芷晴', '备注'],
            ['2026-08-01', '六', '客户甲', '私教月卡', '蜜支付', 1000, null, null, null],
        ]);

        $res = $this->post('/api/payroll/performance/commit', [
            'file' => new UploadedFile($tmp, 't.xlsx', null, null, true),
            'venue' => '绿地店', 'month' => '2026-08',
        ])->assertStatus(422);
        $this->assertSame('COMMIT_HAS_EXCEPTIONS', $res->json('code'));
        $this->assertSame(0, PayrollPerformance::count());
    }

    /** 两店分别导入：一个文件只对应一家店，互不覆盖 */
    public function test_两店分别导入互不覆盖(): void
    {
        $path = $this->requireSample();
        Sanctum::actingAs($this->super());
        $this->seedSalesProfiles();

        $this->post('/api/payroll/performance/commit', [
            'file' => $this->upload($path), 'venue' => '绿地店', 'month' => '2026-08',
        ])->assertOk();
        $this->post('/api/payroll/performance/commit', [
            'file' => $this->upload($path), 'venue' => '东部店', 'month' => '2026-08',
        ])->assertOk();

        $this->assertSame(1, PayrollPerformance::where('venue', '绿地店')->where('month', '2026-08')->count() > 0 ? 1 : 0);
        $this->assertTrue(PayrollPerformance::where('venue', '东部店')->where('month', '2026-08')->exists());
        $this->assertTrue(PayrollPerformance::where('venue', '绿地店')->where('month', '2026-08')->exists());
    }

    /** GET /payroll/performance 返回两套口径 */
    public function test_业绩汇总返回两套口径(): void
    {
        $path = $this->requireSample();
        Sanctum::actingAs($this->super());
        $this->seedSalesProfiles();

        $this->post('/api/payroll/performance/commit', [
            'file' => $this->upload($path), 'venue' => '绿地店', 'month' => '2026-08',
        ])->assertOk();

        $res = $this->getJson('/api/payroll/performance?month=2026-08&venue='.rawurlencode('绿地店'))
            ->assertOk()->json('data');

        $this->assertTrue($res['imported']);
        $this->assertEqualsWithDelta(375863.50, $res['totals']['raw']['personal'], 0.001);
        $this->assertEqualsWithDelta(344767.50, $res['totals']['forCommission']['personal'], 0.001);
        $this->assertEqualsWithDelta(364167.20, $res['totals']['forCommission']['storeSales'], 0.001);
    }

    /** 未导入时 imported=false（区分「提成为 0」与「没导入」） */
    public function test_未导入时明确标记(): void
    {
        Sanctum::actingAs($this->super());
        $res = $this->getJson('/api/payroll/performance?month=2026-08')->assertOk()->json('data');
        $this->assertFalse($res['imported']);
    }

    // ---- 夹具 ----

    /** 造一个小的合法业绩表（走与样表相同的结构：表头第 2 行） */
    private function makeXlsx(array $rows, string $sheet = '明细总表'): string
    {
        require_once __DIR__.'/support/mkxlsx.php';
        $path = tempnam(sys_get_temp_dir(), 'perf').'.xlsx';
        mkXlsx($path, $sheet, $rows);

        return $path;
    }

    private function makeSmallXlsx(): string
    {
        return $this->makeXlsx([
            ['一麦瑜伽2026年8月业绩明细', null, null, null, null, null, '销售人员'],
            ['日期', '星期', '会员姓名', '收款类型', '收款方式', '金额', '会馆', '苏米', '备注'],
            ['2026-08-01', '六', '客户甲', '私教月卡', '蜜支付', 1000, null, 1000, null],
        ]);
    }
}
