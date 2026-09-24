<?php

namespace Tests\Feature;

use App\Models\PayrollProfile;
use App\Models\User;
use App\Services\PayrollNameResolver;
use App\Support\PayrollMasterImporter;
use App\Support\PayrollRoles;
use Database\Seeders\PayrollProfileFromMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 新版薪酬人员主档导入（`PayrollMasterImporter` + `PayrollProfileFromMasterSeeder`）。
 *
 * ## 夹具策略：脱敏合成夹具为主，真实文件为可选加强
 *
 * 真实主档含身份证号/手机号/银行卡号，**严禁**把它的内容复制进仓库任何文件。
 * 所以本组用例的**主证据是自建的脱敏夹具**（结构与真实文件逐列一致，
 * 但姓名/金额/缺口都是编造的），这样 CI 上也能跑，且断言不依赖仓库外文件。
 *
 * 真实文件（`/Users/ttt/yimai-master-data/*.xlsx`）存在时，再加一组「实际结构核对」
 * 用例（只断言**行数与统计**，不打印任何 PII）；文件不存在则 `markTestSkipped` ——
 * 与 `PayrollProfileSeederTest` 的既有约定一致。
 */
class PayrollMasterImportTest extends TestCase
{
    use RefreshDatabase;

    /** 新版主档的 26 列（与真实文件逐列一致） */
    private const COLUMNS = [
        '人员编号', '真实姓名', '所属门店', '岗位', '人员状态', '所有显示名/昵称',
        '手机', '身份证号', '基本底薪', '绩效', '60分钟课时费', '45分钟课时费',
        '小班课时费', '团课课时费', '企业课课时费', '收款户名', '银行卡号',
        '开户行/网点', '收款银行', '联行号', '转账类型', '企业微信账号',
        '账户确认状态', '重点提醒', '资料状态', '来源文件',
    ];

    private function realMasterPath(): string
    {
        return (string) (env('PAYROLL_MASTER_XLSX_V2')
            ?: '/Users/ttt/yimai-master-data/一麦薪酬人员主档_含身份银行卡_20260924_095212.xlsx');
    }

    private function realMappingPath(): string
    {
        return (string) (env('PAYROLL_MAPPING_XLSX')
            ?: '/Users/ttt/yimai-master-data/一麦两店人员映射文件_当前版_20260924_095212.xlsx');
    }

    /**
     * 按列名组装夹具行（脱敏）。
     *
     * 用「列名 => 值」而不是位置数组：真实主档有 26 列，位置写错会静默读到隔壁列，
     * 而按列名组装时漏了谁一眼可见。
     *
     * @param  array<string, string>  $values
     * @return array<int, string>
     */
    private function row(array $values): array
    {
        return array_map(fn ($c) => $values[$c] ?? '', self::COLUMNS);
    }

    /**
     * 生成脱敏夹具 xlsx（结构照抄真实文件：sheet 名、表头行、26 列）。
     *
     * @param  array<int, array<string, string>>  $people
     */
    private function fixture(array $people, string $path = ''): string
    {
        require_once __DIR__.'/support/mkxlsx.php';
        $path = $path !== '' ? $path : tempnam(sys_get_temp_dir(), 'v2master').'.xlsx';
        $rows = [self::COLUMNS];
        foreach ($people as $p) {
            $rows[] = $this->row($p);
        }
        mkXlsx($path, PayrollMasterImporter::SHEET, $rows);

        return $path;
    }

    /**
     * 脱敏夹具的两个人（`张芷晴, 芷晴` 正是验收点名的那例）。
     *
     * @return array<int, array<string, string>>
     */
    private function samplePeople(): array
    {
        return [
            [
                '人员编号' => 'YM-TEST0001', '真实姓名' => '张芷晴', '所属门店' => '绿地店',
                '岗位' => '店长', '人员状态' => '有效', '所有显示名/昵称' => '张芷晴, 芷晴',
                '手机' => '', '身份证号' => '', '基本底薪' => '5000', '绩效' => '0',
                '60分钟课时费' => '110', '45分钟课时费' => '0', '小班课时费' => '110',
                '团课课时费' => '110', '企业课课时费' => '110',
                '收款户名' => '', '银行卡号' => '', '开户行/网点' => '', '收款银行' => '',
                '联行号' => '', '转账类型' => '', '企业微信账号' => '',
                '账户确认状态' => '脱敏夹具', '重点提醒' => '绿地店店长；底薪只在所属门店',
                '资料状态' => '缺：手机、身份证号', '来源文件' => '夹具',
            ],
            [
                '人员编号' => 'YM-TEST0002', '真实姓名' => '贝小满', '所属门店' => '东部店',
                '岗位' => '兼职老师', '人员状态' => '有效', '所有显示名/昵称' => '小满',
                '手机' => '', '身份证号' => '', '基本底薪' => '0', '绩效' => '0',
                '60分钟课时费' => '120', '45分钟课时费' => '', '小班课时费' => '',
                '团课课时费' => '', '企业课课时费' => '', '收款户名' => '', '银行卡号' => '',
                '开户行/网点' => '', '收款银行' => '', '联行号' => '', '转账类型' => '',
                '企业微信账号' => '', '账户确认状态' => '', '重点提醒' => '兼职老师；底薪必须为0',
                '资料状态' => '缺：银行卡号', '来源文件' => '夹具',
            ],
        ];
    }

    private function seedFrom(string $path): void
    {
        $before = getenv(PayrollProfileFromMasterSeeder::ENV_PATH);
        putenv(PayrollProfileFromMasterSeeder::ENV_PATH.'='.$path);
        $_ENV[PayrollProfileFromMasterSeeder::ENV_PATH] = $path;
        $_SERVER[PayrollProfileFromMasterSeeder::ENV_PATH] = $path;

        try {
            $this->seed(PayrollProfileFromMasterSeeder::class);
        } finally {
            if ($before === false) {
                putenv(PayrollProfileFromMasterSeeder::ENV_PATH);
                unset($_ENV[PayrollProfileFromMasterSeeder::ENV_PATH], $_SERVER[PayrollProfileFromMasterSeeder::ENV_PATH]);
            } else {
                putenv(PayrollProfileFromMasterSeeder::ENV_PATH.'='.$before);
                $_ENV[PayrollProfileFromMasterSeeder::ENV_PATH] = $before;
                $_SERVER[PayrollProfileFromMasterSeeder::ENV_PATH] = $before;
            }
        }
    }

    // ==================================================================
    // 1. 新版结构：sheet 名 + 列名 + 别名单列
    // ==================================================================

    /**
     * 导入器必须吃下**新版结构**而不是旧格式。
     *
     * 旧 `PayrollProfileSeeder` 期望 sheet「人员主档」/「姓名别名」/「特殊规则」，
     * 喂新版文件会抛「人员主档缺少 sheet」—— 这条断言直接钉住那个不兼容点。
     */
    public function test_新版结构可被导入且旧格式会显式报错(): void
    {
        $path = $this->fixture($this->samplePeople());
        try {
            $importer = new PayrollMasterImporter;
            $read = $importer->read($path);

            $this->assertCount(2, $read['rows'], '两个人两行');
            $this->assertSame([], $read['stats']['problems'], '不该有异常行');
            $this->assertSame(2, $read['stats']['total']);
            $this->assertSame(2, $read['stats']['valid']);

            // 列名映射对了才可能有这些值（旧列名会读成 0）
            $zhang = $read['rows'][0];
            $this->assertSame('张芷晴', $zhang['name']);
            $this->assertSame('绿地店', $zhang['venue']);
            $this->assertSame('店长', $zhang['role']);
            $this->assertSame('5000.00', $zhang['base_salary'], '「基本底薪」列必须映射到 base_salary');
            $this->assertSame('110.00', $zhang['fee_private60'], '「60分钟课时费」列必须映射到 fee_private60');
            $this->assertSame('110.00', $zhang['fee_small'], '「小班课时费」列必须映射到 fee_small');
            $this->assertSame('110.00', $zhang['fee_group'], '「团课课时费」列必须映射到 fee_group');
            $this->assertSame('110.00', $zhang['fee_enterprise'], '「企业课课时费」列必须映射到 fee_enterprise');
        } finally {
            @unlink($path);
        }
    }

    /** sheet 名不对时必须**显式报错并说清怎么改**，不得静默导入 0 行 */
    public function test_旧格式文件被显式拒绝且提示改用旧seeder(): void
    {
        require_once __DIR__.'/support/mkxlsx.php';
        $path = tempnam(sys_get_temp_dir(), 'oldmaster').'.xlsx';
        mkXlsx($path, '人员主档', [['人员编号', '真实姓名'], ['YM-OLD', '旧人']]);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/薪酬人员主档.*PayrollProfileSeeder/s');
            (new PayrollMasterImporter)->read($path);
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** 缺少金额列时必须报错 —— 「金额全部读成 0」是静默失败，工资表照出但每人都是 0 */
    public function test_缺少金额列时显式报错而不是读成零(): void
    {
        require_once __DIR__.'/support/mkxlsx.php';
        $path = tempnam(sys_get_temp_dir(), 'badmaster').'.xlsx';
        // 只有身份列、没有金额列
        mkXlsx($path, PayrollMasterImporter::SHEET, [
            ['人员编号', '真实姓名', '所属门店', '岗位', '人员状态', '所有显示名/昵称'],
            ['YM-X', '某人', '绿地店', '全职老师', '有效', ''],
        ]);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/缺少必需列.*基本底薪/s');
            (new PayrollMasterImporter)->read($path);
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    // ==================================================================
    // 2. 岗位归一化：`东部店:顾问` → `顾问`，且不丢行
    // ==================================================================

    /**
     * `东部店:顾问` 这类带门店前缀的岗位必须归一化为 `顾问`，**且该行不能丢**。
     *
     * 真实主档实测有 1 行是这种写法（蒙天乐）。归一化靠 `PayrollRoles::normalizeRole()`
     * （按 `:` 取末段），不在这里另写一份 explode。
     */
    public function test_带门店前缀的岗位被归一化且不丢行(): void
    {
        $people = $this->samplePeople();
        $people[] = [
            '人员编号' => 'YM-TEST0003', '真实姓名' => '前缀岗位', '所属门店' => '东部店',
            '岗位' => '东部店:顾问', '人员状态' => '有效', '所有显示名/昵称' => '',
            '基本底薪' => '4000', '绩效' => '0', '60分钟课时费' => '0',
            '45分钟课时费' => '0', '小班课时费' => '0', '团课课时费' => '0',
            '企业课课时费' => '0', '重点提醒' => '',
        ];
        $path = $this->fixture($people);

        try {
            $read = (new PayrollMasterImporter)->read($path);
            $this->assertCount(3, $read['rows'], '带前缀岗位的行**不得被丢掉**');
            $hit = collect($read['rows'])->firstWhere('name', '前缀岗位');
            $this->assertNotNull($hit, '归一化后的行必须在结果里');
            $this->assertSame('顾问', $hit['role'], '「东部店:顾问」必须归一化为「顾问」');
            $this->assertTrue(PayrollRoles::isValidRole($hit['role']));
        } finally {
            @unlink($path);
        }
    }

    /** 全角冒号同样要归一化（主档清洗过，但用户手改后可能写成全角） */
    public function test_全角冒号的岗位也被归一化(): void
    {
        $this->assertSame('顾问', PayrollRoles::normalizeRole('东部店：顾问'));
        $importer = new PayrollMasterImporter;
        $path = $this->fixture([[
            '人员编号' => 'YM-TEST0004', '真实姓名' => '全角', '所属门店' => '东部店',
            '岗位' => '绿地店：新媒体', '人员状态' => '有效',
            '基本底薪' => '0', '绩效' => '0', '60分钟课时费' => '0',
            '45分钟课时费' => '0', '小班课时费' => '0', '团课课时费' => '0', '企业课课时费' => '0',
        ]]);
        try {
            $read = $importer->read($path);
            $this->assertSame('新媒体', $read['rows'][0]['role']);
            $this->assertSame([], $read['stats']['problems']);
        } finally {
            @unlink($path);
        }
    }

    /**
     * 无法识别的岗位必须**显式上报**，不能被静默塞进某个默认值。
     *
     * 静默塞成「全职老师」最危险：`allowsBaseReward('全职老师')` 会按实际课时
     * 发 200~1000 元底薪奖励（完全不看档案金额），工资就这么错了。
     */
    public function test_无法识别的岗位被上报而非静默丢弃(): void
    {
        $people = $this->samplePeople();
        $people[] = [
            '人员编号' => 'YM-TEST0005', '真实姓名' => '怪岗位', '所属门店' => '绿地店',
            '岗位' => '神秘职务', '人员状态' => '有效', '基本底薪' => '100',
            '绩效' => '0', '60分钟课时费' => '0', '45分钟课时费' => '0',
            '小班课时费' => '0', '团课课时费' => '0', '企业课课时费' => '0',
        ];
        $path = $this->fixture($people);

        try {
            $read = (new PayrollMasterImporter)->read($path);
            $this->assertCount(2, $read['rows'], '无法识别的岗位不建档');
            $this->assertCount(1, $read['stats']['problems'], '必须上报，不得静默');
            $this->assertStringContainsString('怪岗位', $read['stats']['problems'][0]);
            $this->assertStringContainsString('神秘职务', $read['stats']['problems'][0]);
            $this->assertStringContainsString('不在薪酬身份枚举内', $read['stats']['problems'][0]);
        } finally {
            @unlink($path);
        }
    }

    /** 门店不在枚举内同样要上报（否则会造出一张永远算不出来的工资表） */
    public function test_非法门店被上报(): void
    {
        $people = $this->samplePeople();
        $people[] = [
            '人员编号' => 'YM-TEST0006', '真实姓名' => '错门店', '所属门店' => '江北店',
            '岗位' => '全职老师', '人员状态' => '有效', '基本底薪' => '100',
            '绩效' => '0', '60分钟课时费' => '0', '45分钟课时费' => '0',
            '小班课时费' => '0', '团课课时费' => '0', '企业课课时费' => '0',
        ];
        $path = $this->fixture($people);

        try {
            $read = (new PayrollMasterImporter)->read($path);
            $this->assertCount(2, $read['rows']);
            $this->assertCount(1, $read['stats']['problems']);
            $this->assertStringContainsString('江北店', $read['stats']['problems'][0]);
        } finally {
            @unlink($path);
        }
    }

    // ==================================================================
    // 3. 别名真正生效
    // ==================================================================

    /**
     * 别名必须生效：`张芷晴` 与 `芷晴` 都要命中同一个人。
     *
     * 业绩表的列名有 9 个不是本名，别名丢了不会报错，但会让这 9 个人在业绩导入时归零。
     */
    public function test_别名两个显示名都命中同一人(): void
    {
        $path = $this->fixture($this->samplePeople());
        try {
            $this->seedFrom($path);
        } finally {
            @unlink($path);
        }

        $profile = PayrollProfile::where('name', '张芷晴')->firstOrFail();
        // 夹具里「所有显示名/昵称」= `张芷晴, 芷晴`：与本名重复的那项**被去掉**
        // （本名恒可解析，登记成别名只多一条冗余路径），所以只剩「芷晴」
        $this->assertSame(['芷晴'], $profile->aliases, '逗号分隔的别名要拆开，与本名重复的项去掉');

        $resolver = app(PayrollNameResolver::class)->warm();
        $this->assertSame($profile->id, $resolver->resolve('张芷晴')?->id, '本名必须命中');
        $this->assertSame($profile->id, $resolver->resolve('芷晴')?->id, '别名必须命中同一个人');
        $this->assertFalse($resolver->isAmbiguous('芷晴'), '别名不得被判为歧义');
    }

    /** 别名列里的占位符（`-`）不能变成一个叫「-」的别名 */
    public function test_别名占位符不会被登记为别名(): void
    {
        $this->assertSame([], PayrollMasterImporter::aliases('-'));
        $this->assertSame([], PayrollMasterImporter::aliases('—'));
        $this->assertSame([], PayrollMasterImporter::aliases('', '张芷晴'));
        // 与本名重复的项去掉（本名恒可解析，无需登记）
        $this->assertSame(['芷晴'], PayrollMasterImporter::aliases('张芷晴, 芷晴', '张芷晴'));
        $this->assertSame(['Nico', 'nico'], PayrollMasterImporter::aliases('Nico, nico'));
    }

    /** 别名已被别人占用时跳过并上报，**不覆盖**（覆盖会让上一次的归属静默改人） */
    public function test_别名冲突时跳过并上报(): void
    {
        PayrollProfile::create([
            'external_id' => 'YM-OWNER', 'name' => '先来者', 'venue' => '绿地店',
            'role' => '全职老师', 'aliases' => ['小满'],
        ]);

        $path = $this->fixture([[
            '人员编号' => 'YM-TEST0002', '真实姓名' => '贝小满', '所属门店' => '东部店',
            '岗位' => '兼职老师', '人员状态' => '有效', '所有显示名/昵称' => '小满',
            '基本底薪' => '0', '绩效' => '0', '60分钟课时费' => '120',
            '45分钟课时费' => '0', '小班课时费' => '0', '团课课时费' => '0', '企业课课时费' => '0',
        ]]);
        try {
            $this->seedFrom($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(['小满'], PayrollProfile::where('name', '先来者')->firstOrFail()->aliases, '既有的归属不得被改写');
        $this->assertSame([], PayrollProfile::where('name', '贝小满')->firstOrFail()->aliases);
    }

    // ==================================================================
    // 4. 缺失字段留空，绝不写 0
    // ==================================================================

    /**
     * 未知字段必须是「空」而不是 `0` —— `0` 是一个**具体的值**，
     * 会把「不知道」写成「确定的零」，用户再也分不清该不该补录。
     *
     * 主档里 `基本底薪` 有 29 人是**显式 0**（兼职老师「底薪强制为 0」），
     * 那是真实的 0，必须原样落 0；两种情形在夹具里都覆盖到。
     */
    public function test_缺失的金额留空而显式零落零(): void
    {
        $path = $this->fixture([
            [
                '人员编号' => 'YM-Z1', '真实姓名' => '显式零', '所属门店' => '绿地店',
                '岗位' => '兼职老师', '人员状态' => '有效', '所有显示名/昵称' => '',
                '基本底薪' => '0', '绩效' => '0', '60分钟课时费' => '0',
                '45分钟课时费' => '0', '小班课时费' => '0', '团课课时费' => '0', '企业课课时费' => '0',
            ],
            [
                '人员编号' => 'YM-Z2', '真实姓名' => '主档没给', '所属门店' => '绿地店',
                '岗位' => '全职老师', '人员状态' => '有效', '所有显示名/昵称' => '',
                '基本底薪' => '', '绩效' => '', '60分钟课时费' => '',
                '45分钟课时费' => '', '小班课时费' => '', '团课课时费' => '', '企业课课时费' => '',
            ],
        ]);

        try {
            $read = (new PayrollMasterImporter)->read($path);
            $zero = collect($read['rows'])->firstWhere('name', '显式零');
            $missing = collect($read['rows'])->firstWhere('name', '主档没给');

            // 显式 0 落 "0.00"（真实的值）
            $this->assertSame('0.00', $zero['base_salary'], '主档显式 0 必须原样落 0');
            // 主档留空落 null（未知），**不是** "0.00"
            $this->assertNull($missing['base_salary'], '主档留空的金额必须是 null，不得写 0');
            $this->assertNull($missing['fee_private60']);

            $this->assertCount(7, PayrollMasterImporter::moneyFields(), '金额字段应为 7 项');
            $this->assertCount(7, PayrollMasterImporter::unknownMoneyFields($missing), '7 项金额全部未知');
            $this->assertCount(0, PayrollMasterImporter::unknownMoneyFields($zero), '显式 0 不算未知');
        } finally {
            @unlink($path);
        }
    }

    /** 缺失字段的**计数**要能对得上（用户据此去补资料） */
    public function test_逐列缺失计数可对账(): void
    {
        $people = [];
        for ($i = 1; $i <= 3; $i++) {
            $people[] = [
                '人员编号' => "YM-C{$i}", '真实姓名' => "计数{$i}", '所属门店' => '绿地店',
                '岗位' => '兼职老师', '人员状态' => '有效', '所有显示名/昵称' => '',
                '手机' => $i === 1 ? 'SENTINEL-PHONE' : '',
                '身份证号' => '', '银行卡号' => $i === 3 ? 'SENTINEL-BANKCARD' : '',
                '基本底薪' => '0', '绩效' => '0', '60分钟课时费' => '0',
                '45分钟课时费' => '0', '小班课时费' => '0', '团课课时费' => '0', '企业课课时费' => '0',
                '企业微信账号' => $i === 2 ? 'wxid_demo' : '',
            ];
        }
        $path = $this->fixture($people);

        try {
            $blanks = (new PayrollMasterImporter)->read($path)['stats']['blanks'];
            $this->assertSame(2, $blanks['手机'], '3 人里 2 人缺手机');
            $this->assertSame(3, $blanks['身份证号'], '3 人全缺身份证号');
            $this->assertSame(2, $blanks['银行卡号'], '3 人里 2 人缺银行卡号');
            $this->assertSame(2, $blanks['企业微信账号'], '3 人里 2 人缺企业微信账号');
        } finally {
            @unlink($path);
        }
    }

    // ==================================================================
    // 5. PII 不入库
    // ==================================================================

    /**
     * PII 不得进入档案行：导入器只取**薪酬计算所需字段**。
     *
     * 这是**机制**保证（取值白名单 `FIELDS` 里没有 PII 列），不是靠注释约定：
     * 断言解析出的行里不存在 `手机`/`身份证号`/`银行卡号`/`开户行` 等键，
     * 也断言它们的**值**没有以任何形式混进来。
     */
    public function test_导入器不产生任何PII字段(): void
    {
        // ⚠️ 这些哨兵值**故意做成不像真 PII**（不是 11 位手机号、不是 18 位证件号）：
        // 本用例只需要「足够独特、能证明没被带进档案行」的值，而把 PII 形状的
        // 数字串写进仓库文件本身就违反本文件禁止的那条规则（见 `test_测试文件自身不含真实PII`）。
        // 真正的「真库里不出现 PII 形状」由 `test_真实主档可完整落库且不含PII` 的
        // 长度正则兜底 —— 那条不含任何字面量。
        $pii = [
            '手机' => 'SENTINEL-PHONE-NOT-REAL',
            '身份证号' => 'SENTINEL-IDCARD-NOT-REAL',
            '银行卡号' => 'SENTINEL-BANKCARD-NOT-REAL',
            '开户行/网点' => 'SENTINEL-BANK-BRANCH',
            '收款户名' => 'SENTINEL-PAYEE',
            '收款银行' => 'SENTINEL-BANK',
            '联行号' => 'SENTINEL-BANK-CODE',
            '转账类型' => 'SENTINEL-TRANSFER-TYPE',
            '企业微信账号' => 'SENTINEL-WECOM',
        ];
        $path = $this->fixture([array_merge($this->samplePeople()[0], $pii, ['人员编号' => 'YM-PII', '真实姓名' => '有敏感列'])]);
        try {
            $read = (new PayrollMasterImporter)->read($path);
            $row = $read['rows'][0];

            // ① 行里不得有这些键
            foreach (array_keys($pii) as $col) {
                $this->assertArrayNotHasKey($col, $row, "档案行不得含 PII 列「{$col}」");
            }
            // ② 值不得以任何形式出现在行里（防止被顺手塞进 note/alert）
            $flat = json_encode($row, JSON_UNESCAPED_UNICODE);
            foreach ($pii as $col => $value) {
                $this->assertStringNotContainsString($value, $flat, "PII 值「{$col}」不得出现在档案行里");
            }
            // ③ 导入器必须**知道**这些列存在但不读（否则「没读到」可能只是列名拼错）
            $this->assertContains('身份证号', $read['stats']['skippedColumnsPresent']);
            $this->assertContains('银行卡号', $read['stats']['skippedColumnsPresent']);

            // ④ 关键：档案行的**键集合必须恰好等于允许清单**。
            //
            // 只断言「没有 `手机` 这个键」是不够的 —— 把 PII 列改成映射到英文键
            // （`'手机' => 'phone'`）就能绕过中文键断言。钉住整个键集合，
            // 任何新增字段（含 PII、含把 `alert` 换成别的名字）都会立刻失败。
            $allowed = [
                'external_id', 'name', 'venue', 'role', 'status', 'aliases', 'alert',
                'account_status', 'dual_base_salary', 'status_note', 'row_number',
                'base_salary', 'performance', 'fee_private60', 'fee_private45',
                'fee_small', 'fee_group', 'fee_enterprise', 'missing',
            ];
            $this->assertEqualsCanonicalizing(
                $allowed,
                array_keys($row),
                '档案行的键必须恰好是薪酬所需字段，多一个都不行（PII 可能换英文键混进来）'
            );

            // ⑤ 反向：允许清单里也不得出现任何 PII 语义的字段名
            foreach (['phone', 'mobile', 'id_card', 'idcard', 'bank_card', 'bank',
                'bank_account', 'account_no', 'id_number', 'wecom', 'id_no'] as $forbidden) {
                $this->assertNotContains($forbidden, array_keys($row), "字段名「{$forbidden}」属 PII，不得出现在档案行");
                $this->assertNotContains($forbidden, $read['stats']['fieldsImported'], "允许清单不得含 PII 字段「{$forbidden}」");
            }
        } finally {
            @unlink($path);
        }
    }

    /** 落库后表里不得出现 PII：`payroll_profiles` 没有这些列，且任何列的值都不含它们 */
    public function test_落库结果中不出现PII(): void
    {
        $path = $this->fixture([array_merge($this->samplePeople()[0], [
            '人员编号' => 'YM-PII2', '真实姓名' => '落库检查',
            '手机' => 'SENTINEL-PHONE-NOT-REAL',
            '身份证号' => 'SENTINEL-IDCARD-NOT-REAL',
            '银行卡号' => 'SENTINEL-BANKCARD-NOT-REAL',
        ])]);
        try {
            $this->seedFrom($path);
        } finally {
            @unlink($path);
        }

        // 表结构层面：不存在这些列
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('payroll_profiles');
        foreach (['手机', '身份证号', '银行卡号', '开户行', 'phone', 'id_card', 'bank_card'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "payroll_profiles 不得有 PII 列「{$forbidden}」");
        }

        // 数据层面：整行任何字段都不得含 PII 字面量
        $profile = PayrollProfile::where('external_id', 'YM-PII2')->firstOrFail();
        $flat = json_encode($profile->toApiArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('SENTINEL-PHONE-NOT-REAL', $flat);
        $this->assertStringNotContainsString('SENTINEL-IDCARD-NOT-REAL', $flat);
        $this->assertStringNotContainsString('SENTINEL-BANKCARD-NOT-REAL', $flat);
    }

    /**
     * 本测试文件自身不得含真实 PII（夹具被真实数据污染是很容易发生的事）。
     *
     * 自检 `__FILE__`：出现身份证/银行卡/手机号字面量即失败。
     */
    public function test_测试文件自身不含真实PII(): void
    {
        $source = (string) file_get_contents(__FILE__);

        // 18/19 位连续数字 = 身份证或银行卡长度
        $this->assertSame(
            0,
            preg_match('/(?<!\d)\d{17,19}(?!\d)/', $source),
            '本测试文件不得出现身份证/银行卡长度的数字串'
        );
        // 11 位手机号
        $this->assertSame(
            0,
            preg_match('/(?<!\d)1[3-9]\d{9}(?!\d)/', $source),
            '本测试文件不得出现手机号'
        );
        // 夹具里必须留空 PII 列
        foreach ($this->samplePeople() as $p) {
            $this->assertSame('', $p['手机'] ?? '', '夹具不得写手机号');
            $this->assertSame('', $p['身份证号'] ?? '', '夹具不得写身份证号');
            $this->assertSame('', $p['银行卡号'] ?? '', '夹具不得写银行卡号');
        }
        // 真实文件的**文件名**允许出现在读取路径里（`realMasterPath()`），
        // 员工**姓名**也允许（验收明确要求断言「蒙澍南/谭婷婷/张卫玉 被识别」，
        // 且这些姓名在 `PayrollRoles::DUAL_BASE_SALARY_WHITELIST` 里本来就有）。
        //
        // 真正必须为零的是 **PII**：上面两条长度正则已覆盖身份证/银行卡/手机。
        // 再确认夹具里没有任何「看起来像账号/证件」的值被误当哨兵写进来。
        preg_match_all('/SENTINEL-[A-Z-]+/', $source, $m);
        foreach (array_unique($m[0]) as $sentinel) {
            $this->assertMatchesRegularExpression(
                '/^SENTINEL-[A-Z-]+$/',
                $sentinel,
                '哨兵值必须是明显非真实的字面量'
            );
        }
    }

    // ==================================================================
    // 6. 双底薪白名单不硬编码
    // ==================================================================

    /**
     * 双底薪例外必须由**「重点提醒」列内容**驱动，不硬编码姓名。
     *
     * 验收点名的 4 人里，蒙澍南/谭婷婷/张卫玉的提醒写着「两店分别固定底薪…」；
     * 李芯萍的提醒写的是「两店Nico为同一人」—— 那是**别名归属**的说明，
     * **不是**双底薪。宽规则（「含两店即算」）会把她误判成两店各发一份底薪 ⇒ 多发工资。
     */
    public function test_双底薪由重点提醒驱动而不是硬编码(): void
    {
        // 真实三类提醒口吻（脱敏改写，但保留判别特征）
        $this->assertTrue(PayrollMasterImporter::isDualBase('两店分别固定底薪5000，社保扣在绿地'));
        $this->assertTrue(PayrollMasterImporter::isDualBase('两店分别固定底薪3000，社保扣在绿地'));
        $this->assertTrue(PayrollMasterImporter::isDualBase('两店分别固定底薪2500，社保扣在绿地'));
        // 带「两店」但**不是**双底薪的两种真实写法
        $this->assertFalse(
            PayrollMasterImporter::isDualBase('2026-07-23入职，7月实出勤8天；南哥确认底薪2000元；两店Nico为同一人'),
            '「两店Nico为同一人」说的是别名归属，不是双底薪'
        );
        $this->assertFalse(
            PayrollMasterImporter::isDualBase('兼职老师；底薪、绩效、社保强制为0；两店均有课时'),
            '「两店均有课时」说的是课时分布，兼职老师底薪就是 0'
        );
        // 常见无关写法
        $this->assertFalse(PayrollMasterImporter::isDualBase('绿地店店长；底薪只在所属门店'));
        $this->assertFalse(PayrollMasterImporter::isDualBase('绩效只在所属门店'));
        $this->assertFalse(PayrollMasterImporter::isDualBase(''));
        $this->assertFalse(PayrollMasterImporter::isDualBase('东部店店长'));
    }

    /** 落库后：声明了双底薪的行置真，没声明的**不得**被误判 */
    public function test_双底薪标记正确落库且不误判(): void
    {
        $base = [
            '所属门店' => '绿地店', '岗位' => '全职老师', '人员状态' => '有效',
            '所有显示名/昵称' => '', '基本底薪' => '100', '绩效' => '0',
            '60分钟课时费' => '0', '45分钟课时费' => '0', '小班课时费' => '0',
            '团课课时费' => '0', '企业课课时费' => '0',
        ];
        $path = $this->fixture([
            array_merge($base, ['人员编号' => 'YM-D1', '真实姓名' => '双底薪甲', '重点提醒' => '两店分别固定底薪5000，社保扣在绿地']),
            array_merge($base, ['人员编号' => 'YM-D2', '真实姓名' => '双底薪乙', '重点提醒' => '两店分别固定底薪3000，社保扣在绿地']),
            array_merge($base, ['人员编号' => 'YM-D3', '真实姓名' => '仅提两店', '重点提醒' => '两店Nico为同一人']),
            array_merge($base, ['人员编号' => 'YM-D4', '真实姓名' => '仅本店', '重点提醒' => '底薪只在所属门店']),
            array_merge($base, ['人员编号' => 'YM-D5', '真实姓名' => '无提醒', '重点提醒' => '']),
        ]);

        try {
            $this->seedFrom($path);
        } finally {
            @unlink($path);
        }

        $this->assertTrue((bool) PayrollProfile::where('name', '双底薪甲')->firstOrFail()->dual_base_salary);
        $this->assertTrue((bool) PayrollProfile::where('name', '双底薪乙')->firstOrFail()->dual_base_salary);
        $this->assertFalse((bool) PayrollProfile::where('name', '仅提两店')->firstOrFail()->dual_base_salary, '「两店Nico为同一人」不得被当成双底薪');
        $this->assertFalse((bool) PayrollProfile::where('name', '仅本店')->firstOrFail()->dual_base_salary);
        $this->assertFalse((bool) PayrollProfile::where('name', '无提醒')->firstOrFail()->dual_base_salary);

        // 提醒原文要落进 alert（它是判定依据，必须可追溯）
        $this->assertStringContainsString('固定底薪', (string) PayrollProfile::where('name', '双底薪甲')->firstOrFail()->alert);
    }

    // ==================================================================
    // 7. 幂等
    // ==================================================================

    /**
     * 幂等：同一份文件导入两次，行数不翻倍、`user_id` 不被改写、**逐字相同**。
     *
     * `db:seed` 在发版流程里会被反复执行，这条必须成立。
     */
    public function test_重复导入逐字相同(): void
    {
        $path = $this->fixture($this->samplePeople());
        try {
            $this->seedFrom($path);
            $this->assertSame(2, PayrollProfile::count());

            $before = PayrollProfile::orderBy('id')->get()->map(fn ($p) => $p->toApiArray())->all();
            $this->seedFrom($path); // 第二次
            $after = PayrollProfile::orderBy('id')->get()->map(fn ($p) => $p->toApiArray())->all();

            $this->assertSame($before, $after, '第二次导入必须逐字相同（含 note 与 aliases）');
            $this->assertSame(2, PayrollProfile::count(), '行数不得翻倍');
        } finally {
            @unlink($path);
        }
    }

    /**
     * 🔴 用户补录的值**不得被清空** —— 但只针对主档**留空**的字段。
     *
     * 两种情形必须分开，混为一谈会做出错误的产品行为：
     *
     * | 主档 | 正确行为 | 理由 |
     * |---|---|---|
     * | 该字段**留空** | **保留**用户补录的值 | 主档说的是「我不知道」，不是「它是 0」。清掉就是把人补的资料删了 |
     * | 该字段**有值** | 用主档的值覆盖 | 主档是这批数据的权威来源，重跑应「收敛到文件」 |
     *
     * 夹具里 `贝小满` 的 45分钟/小班/团课/企业课四项主档留空，`张芷晴` 的企业课有显式值 ——
     * 正好分别覆盖这两种情形。
     */
    public function test_用户补录的值不被清空而主档有值的字段以主档为准(): void
    {
        $path = $this->fixture($this->samplePeople());
        try {
            $this->seedFrom($path);

            // 情形一：主档留空的字段，用户补录 ⇒ 重跑必须保留
            $bei = PayrollProfile::where('name', '贝小满')->firstOrFail();
            $bei->fee_enterprise = '999.00';
            $bei->note = '用户补录：企业课按次结';
            $bei->save();

            // 情形二：主档有值的字段，用户改成别的 ⇒ 重跑应回到主档值
            $zhang = PayrollProfile::where('name', '张芷晴')->firstOrFail();
            $zhang->fee_enterprise = '777.00';
            $zhang->save();

            $this->seedFrom($path); // 第二次

            $bei = PayrollProfile::where('name', '贝小满')->firstOrFail();
            $this->assertSame('999.00', (string) $bei->fee_enterprise, '主档留空的字段，用户补录的值不得被清空');
            $this->assertStringContainsString('用户补录：企业课按次结', (string) $bei->note, '用户手写的备注不得被清空');

            $zhang = PayrollProfile::where('name', '张芷晴')->firstOrFail();
            $this->assertSame('110.00', (string) $zhang->fee_enterprise, '主档有值的字段，重跑应收敛到主档值');

            // 主档留空这件事本身要让用户看得见（否则他不知道自己该补哪一项）
            $this->assertStringContainsString('主档未提供', (string) $bei->note, '主档留空的字段必须在备注里说明');

            // 备注里的机器段不得重复堆叠（200 字符列，堆两次就把用户备注挤没了）
            $count = mb_substr_count((string) PayrollProfile::where('name', '贝小满')->firstOrFail()->note, '主档未提供');
            $this->assertSame(1, $count, '机器段只应出现一次（composeNote 先摘后接）');
        } finally {
            @unlink($path);
        }
    }

    /**
     * `user_id` 在重复导入时不得被改写（账号绑定是人工决定，导入不该动它）。
     */
    public function test_重复导入不改写既有账号绑定(): void
    {
        $path = $this->fixture($this->samplePeople());
        try {
            $this->seedFrom($path);

            $user = User::factory()->create([
                'username' => 'bindcheck', 'name' => '张芷晴', 'role' => 'R_TEACHER',
                'roles' => ['R_TEACHER'], 'venue' => '绿地店', 'venues' => ['绿地店'], 'status' => '启用',
            ]);
            $zhang = PayrollProfile::where('name', '张芷晴')->firstOrFail();
            $zhang->user_id = $user->id;
            $zhang->save();
            $userId = $zhang->user_id;

            $this->seedFrom($path); // 第二次

            $this->assertSame($userId, PayrollProfile::where('name', '张芷晴')->firstOrFail()->user_id, 'user_id 不得被改写');
        } finally {
            @unlink($path);
        }
    }

    /** 主档新增一行时只新增那一行，既有行不动 */
    public function test_增量导入只新增新行(): void
    {
        $path = $this->fixture($this->samplePeople());
        try {
            $this->seedFrom($path);
            $ids = PayrollProfile::orderBy('id')->pluck('id')->all();

            $people = $this->samplePeople();
            $people[] = array_merge($this->samplePeople()[1], ['人员编号' => 'YM-TEST0009', '真实姓名' => '新人']);
            $path2 = $this->fixture($people, $path);
            $this->seedFrom($path2);

            $this->assertSame(3, PayrollProfile::count());
            $this->assertSame($ids, array_slice(PayrollProfile::orderBy('id')->pluck('id')->all(), 0, 2), '既有行的 id 不得变');
            $this->assertTrue(PayrollProfile::where('name', '新人')->exists());
        } finally {
            @unlink($path);
        }
    }

    /** 没有主档文件时不报错、不阻断（否则 CI / 新装环境整个 db:seed 失败） */
    public function test_文件缺失或未配置时跳过而不报错(): void
    {
        $before = getenv(PayrollProfileFromMasterSeeder::ENV_PATH);
        try {
            putenv(PayrollProfileFromMasterSeeder::ENV_PATH);
            unset($_ENV[PayrollProfileFromMasterSeeder::ENV_PATH], $_SERVER[PayrollProfileFromMasterSeeder::ENV_PATH]);
            $this->seed(PayrollProfileFromMasterSeeder::class);
            $this->assertSame(0, PayrollProfile::count());

            putenv(PayrollProfileFromMasterSeeder::ENV_PATH.'=/nonexistent/v2.xlsx');
            $_ENV[PayrollProfileFromMasterSeeder::ENV_PATH] = '/nonexistent/v2.xlsx';
            $_SERVER[PayrollProfileFromMasterSeeder::ENV_PATH] = '/nonexistent/v2.xlsx';
            $this->seed(PayrollProfileFromMasterSeeder::class);
            $this->assertSame(0, PayrollProfile::count());
        } finally {
            if ($before === false) {
                putenv(PayrollProfileFromMasterSeeder::ENV_PATH);
                unset($_ENV[PayrollProfileFromMasterSeeder::ENV_PATH], $_SERVER[PayrollProfileFromMasterSeeder::ENV_PATH]);
            } else {
                putenv(PayrollProfileFromMasterSeeder::ENV_PATH.'='.$before);
                $_ENV[PayrollProfileFromMasterSeeder::ENV_PATH] = $before;
                $_SERVER[PayrollProfileFromMasterSeeder::ENV_PATH] = $before;
            }
        }
    }

    // ==================================================================
    // 8. 真实文件结构核对（文件不在时才 skip；只断言统计，不打印 PII）
    // ==================================================================

    /**
     * 对新版真实主档跑一次：必须读到 **55 行、其中有效 48**，零异常。
     *
     * 只断言行数与统计，**不打印也不落库任何 PII**；文件不存在时跳过
     * （与 `PayrollProfileSeederTest` 的既有约定一致 —— 高敏文件不随仓库发布）。
     */
    public function test_真实主档读到55行其中有效48(): void
    {
        $path = $this->realMasterPath();
        if (! is_file($path)) {
            $this->markTestSkipped("新版人员主档不存在（{$path}），跳过真实结构核对。可用 PAYROLL_MASTER_XLSX_V2 指定。");
        }

        $read = (new PayrollMasterImporter)->read($path);
        $stats = $read['stats'];

        $this->assertSame(55, $stats['total'], '新版主档应为 55 行');
        $this->assertSame(48, $stats['valid'], '其中有效 48 人');
        $this->assertSame([], $stats['problems'], '零异常：所有 55 行都要能建出档案');

        // 门店分布（取证档案实测：绿地店 32 / 东部店 23）
        $this->assertSame(['绿地店' => 32, '东部店' => 23], $stats['byVenue']);

        // 双底薪由提醒驱动，实测命中同一批 3 人（与旧硬编码白名单一致）
        $dual = $stats['dualBase'];
        sort($dual, SORT_STRING);
        $expected = ['蒙澍南', '谭婷婷', '张卫玉'];
        sort($expected, SORT_STRING);
        $this->assertSame($expected, $dual);

        // 缺失计数（取证档案实测：手机 41 / 身份证 40 / 银行卡 5 / 企业微信 26）
        $this->assertSame(41, $stats['blanks']['手机'] ?? 0);
        $this->assertSame(40, $stats['blanks']['身份证号'] ?? 0);
        $this->assertSame(5, $stats['blanks']['银行卡号'] ?? 0);
        $this->assertSame(26, $stats['blanks']['企业微信账号'] ?? 0);

        // 带前缀岗位必须被归一化（实测 1 行「东部店:顾问」）
        $this->assertContains('顾问', array_keys($stats['byRole']));
        foreach (array_keys($stats['byRole']) as $role) {
            $this->assertTrue(PayrollRoles::isValidRole($role), "岗位「{$role}」必须是合法枚举");
            $this->assertStringNotContainsString(':', $role, '归一化后不得残留门店前缀');
        }

        // 「停用」必须被归入「已离职」而不是丢掉（实测 6 行）
        $this->assertSame(7, $stats['total'] - $stats['valid'], '7 行非有效（停用 6 + 已离职 1）');
        $this->assertNotEmpty($stats['statusNotes'], '状态同义写法必须上报');
    }

    /**
     * 真实主档 + 映射文件一起跑：映射里的别名不得有主档漏登记的
     * （漏了就是业绩表某列会归零，而且不报错）。
     */
    public function test_真实映射文件的别名覆盖无漏项(): void
    {
        $master = $this->realMasterPath();
        $mapping = $this->realMappingPath();
        if (! is_file($master) || ! is_file($mapping)) {
            $this->markTestSkipped('真实主档或映射文件不存在，跳过别名覆盖核对。');
        }

        $importer = new PayrollMasterImporter;
        $masterRows = $importer->read($master)['rows'];
        $mappingRead = $importer->readMapping($mapping);

        $masterAliases = [];
        foreach ($masterRows as $row) {
            foreach ($row['aliases'] as $alias) {
                $masterAliases[$alias][] = $row['name'];
            }
        }

        $missing = [];
        foreach ($mappingRead['stats']['aliasMap'] as $alias => $owners) {
            foreach ($owners as $owner) {
                if (! isset($masterAliases[$alias]) || ! in_array($owner, $masterAliases[$alias], true)) {
                    $missing[] = "{$alias}→{$owner}";
                }
            }
        }

        $this->assertSame([], $missing, '映射文件里有、主档没登记的别名（会让业绩表该列归零）：'.implode('、', $missing));
        $this->assertSame(53, $mappingRead['stats']['total'], '映射文件应为 53 行');
    }

    /**
     * 真实主档落库：55 行档案、别名生效、PII 不落库。
     *
     * 这是端到端的一条 —— 前面各条都在测解析，这条测「真文件进真库」。
     */
    public function test_真实主档可完整落库且不含PII(): void
    {
        $path = $this->realMasterPath();
        if (! is_file($path)) {
            $this->markTestSkipped("新版人员主档不存在（{$path}），跳过真实落库核对。");
        }

        $this->seedFrom($path);

        $this->assertSame(55, PayrollProfile::count(), '55 行主档 → 55 条档案');
        $this->assertSame(48, PayrollProfile::where('status', '有效')->count());
        $this->assertSame(0, PayrollProfile::whereNull('external_id')->count(), '每行都要有人员编号');

        // 别名生效：业绩表 9 个别名必须都能解析
        $resolver = app(PayrollNameResolver::class)->warm();
        foreach (['苏米', '娟子', '芷晴', '阿玉', '婷婷', 'Nico', 'CC', 'Lily', '小鹏'] as $alias) {
            $this->assertNotNull($resolver->resolve($alias), "业绩表别名「{$alias}」必须能解析到档案");
        }

        // 双底薪 3 人（由提醒驱动）
        $dual = PayrollProfile::where('dual_base_salary', true)->pluck('name')->all();
        sort($dual, SORT_STRING);
        $expected = ['蒙澍南', '谭婷婷', '张卫玉'];
        sort($expected, SORT_STRING);
        $this->assertSame($expected, $dual);

        // PII 不落库：整库序列化后不得出现任何 18/19 位数字串（银行卡/身份证长度）
        // —— 这是「万一有列偷偷存了」的兜底断言
        $dump = PayrollProfile::all()->map(fn ($p) => json_encode($p->toApiArray(), JSON_UNESCAPED_UNICODE))->implode('');
        $this->assertSame(0, preg_match('/(?<!\d)\d{15,19}(?!\d)/', $dump), '档案里不得出现身份证/银行卡长度的数字串');

        // 幂等：再跑一次逐字相同
        $before = PayrollProfile::orderBy('id')->get()->map(fn ($p) => $p->toApiArray())->all();
        $this->seedFrom($path);
        $after = PayrollProfile::orderBy('id')->get()->map(fn ($p) => $p->toApiArray())->all();
        $this->assertSame($before, $after, '真实主档重复导入必须逐字相同');
    }

    // ==================================================================
    // 9. 账号绑定接线（回归保护 · 补齐 t7 复核的 M6 变异存活缺口）
    // ==================================================================
    //
    // ## 这一节为什么必须存在
    //
    // 新 seeder 的绑定规则**不重写**，直接复用
    // `PayrollController::distributeUserAccounts()`（见 seeder 的 planBindings 注释）。
    // 规则本身有 t1 的用例守着，但**「plan → 写库」这段接线**原先没有测试：
    // t7 把这行接线改成「忽略 `$plan`、逐行无条件绑定」（变异 M6），
    // 结果当时全部用例**仍然全绿**（50 passed / exit 0）—— 变异存活。
    //
    // 为什么「恰好一行绑上」这种断言抓不住它：`saveProfileGuardingUserId()` 在撞
    // `user_id` 唯一约束时会把**后写那行**的 `user_id` 置空并写原因（t1 的修复）。
    // 所以逐行无条件绑定**也会**留下「恰好一行有 user_id」——只是**可能是错的那一行**。
    //
    // ⇒ 本节所有断言都必须钉住**绑到了哪个 venue 的行**，而不是「绑了几行」。
    //   并且必须让**文件行序**与**账号所属门店**指向**不同的行**，
    //   否则「按行序撞对」与「按同店规则算对」结果相同，变异仍会存活。

    /** 建一个登录账号（脱敏：合成姓名 + 合成用户名） */
    private function account(string $name, string $venue): User
    {
        return User::factory()->create([
            'username' => 'sentinel-'.mb_strtolower($name).'-'.mb_substr(md5($name.$venue), 0, 6),
            'name' => $name,
            'role' => 'R_TEACHER',
            'roles' => ['R_TEACHER'],
            'venue' => $venue,
            'venues' => [$venue],
            'status' => '启用',
        ]);
    }

    /**
     * 跨店同一个人的两行主档（脱敏）。
     *
     * `$first` 决定**文件行序**，而账号门店固定 —— 两者故意解耦，
     * 这样「按行序绑」与「按同店规则绑」会落到**不同的行**上，变异无处可藏。
     *
     * @return array<int, array<string, string>>
     */
    private function crossStoreRows(string $name, string $firstVenue): array
    {
        $second = $firstVenue === '绿地店' ? '东部店' : '绿地店';
        $base = [
            '岗位' => '全职老师', '人员状态' => '有效', '所有显示名/昵称' => '',
            '手机' => '', '身份证号' => '', '银行卡号' => '',
            '基本底薪' => '2000', '绩效' => '0', '60分钟课时费' => '120',
            '45分钟课时费' => '0', '小班课时费' => '0', '团课课时费' => '0',
            '企业课课时费' => '0', '重点提醒' => '',
        ];
        $out = [];
        foreach ([$firstVenue, $second] as $n => $venue) {
            $out[] = $base + [
                '人员编号' => 'YM-XS-'.$name.'-'.$n,
                '真实姓名' => $name,
                '所属门店' => $venue,
            ];
        }

        return $out;
    }

    /** 该姓名各 venue 行绑到的 `user_id`（venue => user_id|null） */
    private function boundVenue(string $name): array
    {
        return PayrollProfile::where('name', $name)->orderBy('venue')->get()
            ->mapWithKeys(fn ($p) => [$p->venue => $p->user_id === null ? null : (int) $p->user_id])
            ->all();
    }

    /**
     * 🔴 用例 1（跨店同名）：恰好一行带 `user_id`，且**绑到账号所属门店那行**。
     *
     * 文件行序刻意把**非**账号门店的行放在前面 ⇒ 「按行序绑」会绑错行。
     */
    public function test_跨店同名时恰好绑定一行且绑到账号所属门店(): void
    {
        $name = '跨店同名甲';
        // 账号属于「绿地店」，但文件里「东部店」那行**排在前面**
        $u = $this->account($name, '绿地店');
        $path = $this->fixture($this->crossStoreRows($name, '东部店'));

        try {
            $this->seedFrom($path);
        } finally {
            @unlink($path);
        }

        $bound = $this->boundVenue($name);
        $this->assertCount(2, $bound, '两行都要建出来');

        // ★ 决定性断言：绑的是「绿地店」那行（= 账号门店），不是文件里排第一的「东部店」
        $this->assertSame(
            ['东部店' => null, '绿地店' => (int) $u->id],
            $bound,
            '账号属于绿地店 ⇒ 必须绑到「绿地店」那行；绑到文件里排第一的「东部店」行就是 M6 那种错绑'
        );

        // 恰好一行 ⇒ 不重复绑定（t1 的 unique 约束根因）
        $this->assertSame(1, PayrollProfile::where('name', $name)->whereNotNull('user_id')->count());
        $this->assertSame(
            0,
            PayrollProfile::where('name', $name)->where('user_id', $u->id)->count() - 1,
            '同一个 user_id 不得出现在两行上'
        );

        // 没绑上的那行：原因**可见**（不静默）
        $unbound = PayrollProfile::where('name', $name)->whereNull('user_id')->firstOrFail();
        $this->assertSame('东部店', $unbound->venue);
        $this->assertStringContainsString('账号绑定', (string) $unbound->note, '未绑定原因必须落在 note 里');
        $this->assertStringContainsString('绿地店', (string) $unbound->note, '原因里要写明账号归了哪一行');
    }

    /**
     * 🔴 用例 2（同店优先 + 与行序解耦的第二个方向）：账号门店那行**排在后面**时，
     * 仍然绑它 ⇒ 说明不是「先到先得」，而是真的按门店匹配。
     *
     * 与用例 1 合起来构成双向：无论账号门店那行在文件里排前还是排后，
     * 绑的都是**同一行（按门店确定的那行）** ⇒ 行序不影响结果。
     */
    public function test_同店优先时账号门店行排在后面也仍被绑定(): void
    {
        $name = '跨店同名乙';
        // 账号属于「东部店」，文件里「绿地店」排前面 ⇒ 先到先得会绑错
        $u = $this->account($name, '东部店');
        $path = $this->fixture($this->crossStoreRows($name, '绿地店'));

        try {
            $this->seedFrom($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(
            ['东部店' => (int) $u->id, '绿地店' => null],
            $this->boundVenue($name),
            '账号属于东部店 ⇒ 必须绑到「东部店」那行，哪怕它在文件里排在后面（先到先得会绑错）'
        );
    }

    /**
     * 🔴 用例 3（行序无关）：同一份数据、**只调换两行在文件里的顺序**，
     * 绑定结果（venue → user_id）必须**逐字相同**。
     *
     * 这是对 M6 的**直接反证**：逐行无条件绑定会让结果随行序改变。
     */
    public function test_行序调换后绑定结果不变(): void
    {
        $name = '跨店同名丙';
        $u = $this->account($name, '绿地店');

        // 第一遍：东部店在前
        $pathA = $this->fixture($this->crossStoreRows($name, '东部店'));
        try {
            $this->seedFrom($pathA);
        } finally {
            @unlink($pathA);
        }
        $a = $this->boundVenue($name);
        $this->assertSame(['东部店' => null, '绿地店' => (int) $u->id], $a, '前提：账号属于绿地店');

        // 清库重来：同一数据，两行顺序对调（绿地店在前）
        PayrollProfile::query()->delete();
        $pathB = $this->fixture($this->crossStoreRows($name, '绿地店'));
        try {
            $this->seedFrom($pathB);
        } finally {
            @unlink($pathB);
        }
        $b = $this->boundVenue($name);

        // ★ 「按 venue 的绑定结果」与行序无关
        $this->assertSame($a, $b, '两行在文件里调换顺序后，绑定结果必须不变（逐行无条件绑定会随行序改变）');
        $this->assertSame(['东部店' => null, '绿地店' => (int) $u->id], $b, '对调后仍是「绿地店」那行被绑');
    }

    /**
     * 🔴 用例 4（同名多账号不猜）：同名有 2 个账号时**全组留空**，且原因写明「有 2 个」。
     *
     * 这条同时封住「随便挑一个绑」的实现 —— 猜错就是把钱记到别人头上。
     * 断言 `user_id` 为 `null`（而非「恰好一行绑定」），因为**猜对也是错的**。
     */
    public function test_同名多个账号时不猜而全部留空(): void
    {
        $name = '跨店同名丁';
        $this->account($name, '绿地店');
        $this->account($name, '东部店'); // 同名第二个账号
        $path = $this->fixture($this->crossStoreRows($name, '绿地店'));

        try {
            $this->seedFrom($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(
            ['东部店' => null, '绿地店' => null],
            $this->boundVenue($name),
            '同名 2 个账号 ⇒ 两个门店的行都必须留空（猜哪个都是错的）'
        );

        foreach (PayrollProfile::where('name', $name)->get() as $p) {
            $this->assertStringContainsString('2', (string) $p->note, '原因里要写明同名账号有几个');
            $this->assertStringContainsString('账号绑定', (string) $p->note);
        }
    }

    /**
     * 🔴 用例 5（防串号）：账号已绑给**别姓名**的档案时，本组不得抢过来。
     *
     * 与「跨店同名」互补 —— 前者是同一姓名的两行分一个账号，
     * 后者是不同姓名之间不能互相夺账号。
     */
    public function test_账号已属他人时不被抢走(): void
    {
        $name = '跨店同名戊';
        $u = $this->account($name, '绿地店');

        // 先建一条别姓名但已占用该账号的档案（模拟人工改绑留下的状态）
        PayrollProfile::create([
            'external_id' => 'YM-OTHER-HOLDER', 'name' => '先来的别姓名', 'venue' => '绿地店',
            'role' => '全职老师', 'user_id' => $u->id,
        ]);

        $path = $this->fixture($this->crossStoreRows($name, '绿地店'));
        try {
            $this->seedFrom($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(
            ['东部店' => null, '绿地店' => null],
            $this->boundVenue($name),
            '账号已被别姓名的档案占用 ⇒ 本组两行都不得抢占'
        );
        $this->assertSame(
            (int) $u->id,
            (int) PayrollProfile::where('external_id', 'YM-OTHER-HOLDER')->firstOrFail()->user_id,
            '原持有者的绑定不得被夺走'
        );
        foreach (PayrollProfile::where('name', $name)->get() as $p) {
            $this->assertStringContainsString('账号绑定', (string) $p->note, '未绑定原因必须可见');
        }
    }

    /**
     * 🔴 用例 6（接线完整性）：`plan` 必须**真的**被用于写库。
     *
     * 直接断言「写进库的 `user_id` 等于 `distributeUserAccounts()` 对同一输入的判定」——
     * 即把「规则」与「接线」对齐。任何「忽略 plan 自己算」的实现都会在这里露出来
     * （哪怕它凑巧绑对了行，只要 `reason` 或分配结果与规则不符即失败）。
     */
    public function test_落库结果与共享规则的判定一致(): void
    {
        $name = '跨店同名己';
        $u = $this->account($name, '绿地店');
        $path = $this->fixture($this->crossStoreRows($name, '东部店'));

        try {
            $this->seedFrom($path);
        } finally {
            @unlink($path);
        }

        // 按 seeder 同样的输入形态独立调一次共享规则（行键 = 行下标，与 seeder 一致）
        $rows = PayrollProfile::where('name', $name)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $group = [];
        foreach ($rows as $i => $p) {
            $group[$i] = [
                'venue' => (string) $p->venue,
                'currentUserId' => null, // 首轮导入：都是新建
                'profileId' => (int) $p->id,
            ];
        }
        $decision = \App\Http\Controllers\PayrollController::distributeUserAccounts(
            $name,
            $group,
            [['id' => (int) $u->id, 'venue' => '绿地店']]
        );

        // 规则说哪一行该绑 ⇒ 库里就必须是那一行，且 user_id 一致
        $granted = collect($decision)->filter(fn ($d) => $d['userId'] !== null);
        $this->assertCount(1, $granted, '规则应恰好分配一行');
        $grantedKey = $granted->keys()->first();
        $this->assertSame(
            $rows[$grantedKey]->venue,
            PayrollProfile::where('user_id', $u->id)->firstOrFail()->venue,
            '库里被绑那行的 venue 必须与共享规则判定的一致（接线不得自己另算一套）'
        );
        $this->assertSame('绿地店', PayrollProfile::where('user_id', $u->id)->firstOrFail()->venue);
    }
}
