<?php

namespace Tests\Feature;

use App\Models\PayrollProfile;
use App\Services\PayrollNameResolver;
use App\Support\PayrollRoles;
use Database\Seeders\PayrollProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 人员主档初始化（`PayrollProfileSeeder`）。
 *
 * 主档含**员工姓名、岗位、底薪、课时费**，属高敏数据，**不随仓库发布**；
 * 因此 seeder 只接受外部路径（环境变量 `PAYROLL_MASTER_XLSX`）。
 * 文件不存在时整组跳过并说明原因 —— 不让 CI 变红，但要让人知道「基准没跑」。
 */
class PayrollProfileSeederTest extends TestCase
{
    use RefreshDatabase;

    private function masterPath(): string
    {
        return (string) (env('PAYROLL_MASTER_XLSX') ?: '/tmp/ymzip/一麦瑜伽工资薪酬核算完整交接包_20260922_024627/04_生产主档与模板/一麦工资人员系统主档_全中文_v2_20260719.xlsx');
    }

    private function requireMaster(): string
    {
        $p = $this->masterPath();
        if (! is_file($p)) {
            $this->markTestSkipped("人员主档不存在（{$p}），跳过初始化核对。可用环境变量 PAYROLL_MASTER_XLSX 指定路径。");
        }

        return $p;
    }

    private function seedMaster(string $path): void
    {
        putenv('PAYROLL_MASTER_XLSX='.$path);
        $_ENV['PAYROLL_MASTER_XLSX'] = $path;
        $_SERVER['PAYROLL_MASTER_XLSX'] = $path;
        $this->seed(PayrollProfileSeeder::class);
    }

    /** 文件缺失时不报错、不阻断（否则 CI / 新装环境会因没有高敏文件而整个 db:seed 失败） */
    public function test_文件缺失时跳过而不报错(): void
    {
        putenv('PAYROLL_MASTER_XLSX=/nonexistent/master.xlsx');
        $_ENV['PAYROLL_MASTER_XLSX'] = '/nonexistent/master.xlsx';
        $_SERVER['PAYROLL_MASTER_XLSX'] = '/nonexistent/master.xlsx';

        $this->seed(PayrollProfileSeeder::class);
        $this->assertSame(0, PayrollProfile::count());
    }

    /** 未配置环境变量时同样静默跳过 */
    public function test_未配置路径时静默跳过(): void
    {
        putenv('PAYROLL_MASTER_XLSX');
        unset($_ENV['PAYROLL_MASTER_XLSX'], $_SERVER['PAYROLL_MASTER_XLSX']);

        $this->seed(PayrollProfileSeeder::class);
        $this->assertSame(0, PayrollProfile::count());
    }

    /** 主档 55 行全部导入，身份标签分布与实测一致 */
    public function test_导入55人与标签分布(): void
    {
        $path = $this->requireMaster();
        $this->seedMaster($path);

        $this->assertSame(55, PayrollProfile::count(), '主档实测 55 行');

        $byRole = PayrollProfile::selectRaw('role, count(*) as c')->groupBy('role')->pluck('c', 'role')->all();
        $expected = [
            '馆主' => 2, '店长' => 2, '全职老师' => 10, '专职老师' => 6, '兼职老师' => 23,
            '顾问' => 3, '新媒体' => 1, '保洁' => 4, '固定发放' => 1, '已离职' => 1, '离职结算' => 2,
        ];
        foreach ($expected as $role => $count) {
            $this->assertSame($count, (int) ($byRole[$role] ?? 0), "岗位「{$role}」应有 {$count} 人");
        }

        // 所有岗位都必须是存储层枚举，不能有 `东部店:顾问` 这种带前缀的写法落库
        foreach (PayrollProfile::pluck('role')->unique() as $role) {
            $this->assertTrue(PayrollRoles::isValidRole($role), "岗位「{$role}」不在枚举内（带门店前缀的写法必须归一化）");
        }
        // 顾问 3 人 = 2 个无前缀 + 1 个 `东部店:顾问`（归一化后都存成「顾问」）
        $this->assertTrue(PayrollProfile::where('name', '蒙天乐')->where('role', '顾问')->exists());
    }

    /** 双底薪例外恰好 3 人（主档「特殊规则」sheet 的 dual_base_salary） */
    public function test_双底薪例外恰好3人(): void
    {
        $path = $this->requireMaster();
        $this->seedMaster($path);

        $names = PayrollProfile::where('dual_base_salary', true)->pluck('name')->sort()->values()->all();
        $this->assertSame(['张卫玉', '蒙澍南', '谭婷婷'], $names);
    }

    /** 45 分钟课时费作为**独立字段**导入，不是读取时算出来的派生值 */
    public function test_45分钟课时费独立导入(): void
    {
        $path = $this->requireMaster();
        $this->seedMaster($path);

        // 主档实测 6 人配了非 0 的 45 分钟价
        $rows = PayrollProfile::where('fee_private45', '>', 0)->get();
        $this->assertCount(6, $rows, '主档实测 6 人配了 45 分钟课时费');

        $map = $rows->mapWithKeys(fn ($p) => [$p->name => (float) $p->fee_private45])->all();
        $expected = ['李芯萍' => 90.0, '何泸' => 75.0, '吴艳' => 60.0, '郑卫丽' => 60.0, '唐应宁' => 97.5, '邱亿' => 90.0];
        foreach ($expected as $name => $fee) {
            $this->assertEqualsWithDelta($fee, $map[$name] ?? null, 0.001, "「{$name}」45 分钟课时费应为 {$fee}");
        }

        // 配了 45 分钟价的人不应被当成「折算值」
        $p = PayrollProfile::where('name', '李芯萍')->first();
        $this->assertFalse($p->private45IsDerived());
        $this->assertEqualsWithDelta(90.0, $p->private45FeeCents() / 100, 0.001, '45 分钟价是独立字段，不是 120×0.75');

        // 没配的人走 60 × 0.75 折算
        $q = PayrollProfile::where('name', '张情')->first();
        $this->assertTrue($q->private45IsDerived());
        $this->assertEqualsWithDelta(120.0, $q->private45FeeCents() / 100, 0.001);
    }

    /** 业绩表 11 个销售员列名**全部**可解析（9 个是别名） */
    public function test_业绩表11个列名全部可解析(): void
    {
        $path = $this->requireMaster();
        $this->seedMaster($path);

        $resolver = new PayrollNameResolver;
        $expected = [
            '苏米' => '罗柳柳', '娟子' => '徐秀娟', '张芷晴' => '张情', '钱冰璐' => '钱冰璐',
            'Nico' => '李芯萍', 'CC' => '吴艳', '黄敏' => '黄敏', 'Lily' => '郑卫丽',
            '小鹏' => '牟志鹏', '婷婷' => '谭婷婷', '阿玉' => '张卫玉',
        ];
        foreach ($expected as $column => $real) {
            $p = $resolver->resolve($column);
            $this->assertNotNull($p, "业绩表列名「{$column}」解析不出来（这些人会归零）");
            $this->assertSame($real, $p->name, "「{$column}」应解析为「{$real}」");
        }
    }

    /** 别名歧义：`婷婷 → 谭婷婷` 与 `徐婷婷 → 徐婷` 是两个人，禁止模糊匹配 */
    public function test_婷婷与徐婷婷是两个人(): void
    {
        $path = $this->requireMaster();
        $this->seedMaster($path);

        $resolver = new PayrollNameResolver;
        $this->assertSame('谭婷婷', $resolver->resolve('婷婷')?->name);
        // 「徐婷婷」本身是主档里一个人的**本名**（兼职老师），不能被「婷婷」抢走
        $this->assertSame('徐婷婷', $resolver->resolve('徐婷婷')?->name);
        $this->assertNotSame(
            $resolver->resolve('婷婷')?->id,
            $resolver->resolve('徐婷婷')?->id,
            '两个不同的人不得解析到同一条档案'
        );
    }

    /** 别名不含本人本名（本名恒可解析，无需占别名位），且 103 行 = 55 本名 + 48 别名 */
    public function test_别名不含本人本名(): void
    {
        $path = $this->requireMaster();
        $this->seedMaster($path);

        $names = PayrollProfile::pluck('name')->all();
        $aliasList = PayrollProfile::all(['aliases'])->pluck('aliases')->flatten()->filter()->values()->all();
        $this->assertSame(48, count($aliasList), '主档 103 行别名中 55 行是本人本名，真正的别名 48 条');
        $this->assertSame([], array_values(array_intersect($names, $aliasList)), '别名表不应包含任何人的本名');
    }

    /** seeder 幂等：重复跑不产生重复行 */
    public function test_重复执行幂等(): void
    {
        $path = $this->requireMaster();
        $this->seedMaster($path);
        $before = [PayrollProfile::count(), PayrollProfile::all(['aliases'])->sum(fn ($p) => count((array) ($p->aliases ?? [])))];

        $this->seedMaster($path);
        $after = [PayrollProfile::count(), PayrollProfile::all(['aliases'])->sum(fn ($p) => count((array) ($p->aliases ?? [])))];

        $this->assertSame($before, $after, 'seeder 重复执行不得产生重复档案或别名');
    }

    /** 有登录账号的人自动绑定 user_id（同名唯一时） */
    public function test_同名唯一时自动绑定账号(): void
    {
        $path = $this->requireMaster();
        $u = \App\Models\User::factory()->create([
            'name' => '张情', 'username' => 'seed-bind', 'role' => 'R_TEACHER', 'roles' => ['R_TEACHER'],
            'venue' => '绿地店', 'venues' => ['绿地店'], 'status' => '启用',
        ]);
        $this->seedMaster($path);

        $p = PayrollProfile::where('name', '张情')->first();
        $this->assertSame((int) $u->id, (int) $p->user_id);
    }
}
