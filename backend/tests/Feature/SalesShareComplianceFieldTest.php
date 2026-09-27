<?php

namespace Tests\Feature;

use App\Http\Controllers\ShareController;
use App\Models\PublishedShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S18「谈单工具 H5 分享页 · 合规说明区块」的出网链路。
 *
 * 为什么需要单独一个测试文件：H5 分享页**不读前端 store**，只读服务端快照，
 * 而快照经过 `sanitizeSalesPayload()` 的逐层白名单清洗。合规说明若不在
 * `NESTED_FIELDS['info']` 里，就会在**入库前**被静默丢弃 —— 前端保存成功、
 * 接口返回 200、H5 一片空白。这是「测试绿但功能坏」的典型：既有测试全都只
 * 断言「白名单外的键被丢掉」，没有任何一条断言「白名单内的新键出得来」。
 *
 * 本文件钉住三件事（对应 t6 验收）：
 *  1. 正向：`info.complianceNotice` 发布后能从 `GET /api/public/sales/{token}` 取到；
 *  2. 负向：未知键（`info.__evil` 等）**仍然**被丢弃 —— 加键没有变成放行任意键，
 *     fail-closed 机制未被放宽（这是本任务最容易被做坏的地方）；
 *  3. 约束：类型/空值/超长都被 sanitize 收口，不会产生 `null`/`""`/超长脏值。
 *
 * 注意所有「不该出现」的断言都走解码后的结构（`assertResponseLacks`），
 * 直接搜原始响应体是空洞断言 —— 中文被转义成 \uXXXX，永远搜不到，测试白绿。
 */
class SalesShareComplianceFieldTest extends TestCase
{
    use RefreshDatabase;

    private const NOTICE = '本页展示的会员案例均已取得本人书面授权，仅用于说明训练效果；个案存在差异，不作为效果承诺。';

    private function makeManager(string $name = '张店长'): User
    {
        // username 必须每次唯一：同一条用例里可能建多个账号（如重新发布场景），
        // 而 users.username 有唯一约束 —— 固定串会让第二条 create 直接 23000。
        static $seq = 0;
        $seq++;

        return User::factory()->create([
            'name' => $name,
            'username' => 'u-'.$seq.'-'.md5($name),
            'role' => 'R_MANAGER',
            'venue' => '绿地店',
            'venues' => ['绿地店'],
            'status' => '启用',
        ]);
    }

    /** 基础 payload（与 PublicShareSalesTest 同构，另加合规说明） */
    private function payload(): array
    {
        return [
            'share' => ['enabled' => true, 'code' => 'yimai-lvdi', 'views' => 0],
            'info' => [
                'name' => '一麦瑜伽（绿地店）',
                'industry' => '瑜伽 · 普拉提',
                'slogan' => '让身体回到中立位',
                'intro' => '绿地店门店简介',
                'address' => '绿地中心 3 楼',
                'phone' => '0531-00000000',
                'complianceNotice' => self::NOTICE,
            ],
            'products' => [['id' => 1, 'name' => '精品白领年卡', 'showPrice' => true]],
            'coaches' => [['id' => 1, 'name' => '婷婷']],
            'cases' => [[
                'id' => 1, 'coachId' => 1, 'goal' => '改善骨盆前倾',
                'desc' => '授权案例正文', 'authorized' => true, 'stages' => [['duration' => '第4周']],
            ]],
        ];
    }

    /** 发布销售分享，返回服务端权威 token */
    private function publishSales(?array $payload = null, ?User $user = null): string
    {
        Sanctum::actingAs($user ?? $this->makeManager());

        return (string) $this->postJson('/api/shares/publish', [
            'type' => 'sales',
            'payload' => $payload ?? $this->payload(),
        ])->assertOk()->json('data.token');
    }

    private function assertResponseLacks($res, string $needle): void
    {
        $decoded = json_encode($res->json('data'), JSON_UNESCAPED_UNICODE);
        $this->assertIsString($decoded);
        $this->assertStringNotContainsString($needle, $decoded, "公开响应（解码后）不应包含：{$needle}");
    }

    private function assertResponseContains($res, string $needle): void
    {
        $decoded = json_encode($res->json('data'), JSON_UNESCAPED_UNICODE);
        $this->assertIsString($decoded);
        $this->assertStringContainsString($needle, $decoded, "公开响应（解码后）应包含：{$needle}");
    }

    // ============================================================
    // 1. 正向：合规说明能出网（本任务的核心，修复前必然失败）
    // ============================================================

    /**
     * 发布 → 公开接口取得到合规说明。
     *
     * 这条是修复前**会红**的用例（键不在白名单 → 入库前被静默剥掉），
     * 也是本文件存在的理由：既有测试没有任何一条覆盖「白名单内的新键出得来」。
     */
    public function test_compliance_notice_published_is_served_on_public_endpoint(): void
    {
        $token = $this->publishSales();

        // 入库侧：快照里就有（不是只有下发侧临时补）
        $stored = PublishedShare::where('token', $token)->firstOrFail();
        $this->assertSame(self::NOTICE, $stored->payload['info']['complianceNotice']);

        // 下发侧
        $this->getJson("/api/public/sales/{$token}")
            ->assertOk()
            ->assertJsonPath('data.info.complianceNotice', self::NOTICE);
    }

    /** 合规说明与既有 6 个 info 字段**并存**，不是二选一 */
    public function test_compliance_notice_coexists_with_existing_info_fields(): void
    {
        $token = $this->publishSales();

        $res = $this->getJson("/api/public/sales/{$token}")->assertOk();
        $this->assertResponseContains($res, self::NOTICE);
        foreach (['一麦瑜伽（绿地店）', '瑜伽 · 普拉提', '让身体回到中立位', '绿地店门店简介', '绿地中心 3 楼', '0531-00000000'] as $existing) {
            $this->assertResponseContains($res, $existing);
        }

        // 白名单只增不减：7 个键都在（顺序即常量声明顺序）
        $this->assertSame(
            ['name', 'industry', 'slogan', 'intro', 'address', 'phone', 'complianceNotice'],
            array_keys($res->json('data.info'))
        );
    }

    /**
     * 存量的历史快照（本次升级前入库、当时没有该键）下发时不出错、不产生脏键。
     *
     * 场景来源：合规说明上线前的已发布链接仍要能打开，且不应因为缺这个键而
     * 在 info 里出现 `complianceNotice: null`。
     */
    public function test_stored_legacy_snapshot_without_notice_is_unaffected(): void
    {
        $payload = $this->payload();
        unset($payload['info']['complianceNotice']);

        PublishedShare::create([
            'type' => 'sales', 'token' => 'aa11bb22cc33dd55', 'created_by' => '张店长',
            'payload' => $payload, 'enabled' => true, 'token_source' => 'server',
        ]);

        $res = $this->getJson('/api/public/sales/aa11bb22cc33dd55')->assertOk();
        $this->assertArrayNotHasKey('complianceNotice', $res->json('data.info'));
        $this->assertResponseContains($res, '一麦瑜伽（绿地店）');
    }

    // ============================================================
    // 2. 负向（关键）：白名单机制未被放宽
    // ============================================================

    /**
     * 未知键必须仍被丢弃 —— 证明「加 complianceNotice」没变成「放行任意键」。
     *
     * 历史教训（见 NESTED_FIELDS 注释）：白名单是 fail-closed 安全设计，
     * 未授权文本换个容器就能出网。本用例同时覆盖两种「跟着新键一起混进来」的写法：
     * 相邻未知键（__evil）与仿冒键名（complianceNoticeExtra）。
     */
    public function test_unknown_keys_are_still_dropped_alongside_new_key(): void
    {
        $payload = $this->payload();
        $payload['info']['__evil'] = 'UNKNOWN-KEY-LEAK';
        $payload['info']['complianceNoticeExtra'] = 'LOOKALIKE-KEY-LEAK';
        $payload['info']['secret'] = 'INFO-INNER-LEAK';

        $token = $this->publishSales($payload);
        $stored = PublishedShare::where('token', $token)->firstOrFail();

        // 入库侧：未知键一个都没留下
        foreach (['__evil', 'complianceNoticeExtra', 'secret'] as $unknown) {
            $this->assertArrayNotHasKey($unknown, $stored->payload['info'], "未知键 {$unknown} 必须被丢弃");
        }
        // 白名单内的键该留的仍留（证明不是「整段 info 被丢」这种假绿）
        $this->assertSame(self::NOTICE, $stored->payload['info']['complianceNotice']);

        // 下发侧：文本不出网
        $res = $this->getJson("/api/public/sales/{$token}")->assertOk();
        $this->assertResponseLacks($res, 'UNKNOWN-KEY-LEAK');
        $this->assertResponseLacks($res, 'LOOKALIKE-KEY-LEAK');
        $this->assertResponseLacks($res, 'INFO-INNER-LEAK');
        $this->assertResponseContains($res, self::NOTICE);
    }

    /**
     * 历史脏快照：库里已存着未知键（绕过发布路径写入）时，**下发侧**同样剥掉。
     *
     * 入库与下发共用 sanitizeSalesPayload，这条证明两侧口径没有因新增键而漂移 ——
     * 是「入库过滤了、下发没过滤」那类静默缺口的直接护栏。
     */
    public function test_stored_snapshot_unknown_info_keys_stripped_on_read(): void
    {
        $payload = $this->payload();
        $payload['info']['__evil'] = 'STORED-LEAK';
        $payload['info']['leak'] = 'STORED-LEAK-2';

        PublishedShare::create([
            'type' => 'sales', 'token' => 'bb11cc22dd33ee55', 'created_by' => '张店长',
            'payload' => $payload, 'enabled' => true, 'token_source' => 'server',
        ]);

        $res = $this->getJson('/api/public/sales/bb11cc22dd33ee55')->assertOk();
        $this->assertResponseLacks($res, 'STORED-LEAK');
        $this->assertResponseLacks($res, 'STORED-LEAK-2');
        $this->assertSame(
            ['name', 'industry', 'slogan', 'intro', 'address', 'phone', 'complianceNotice'],
            array_keys($res->json('data.info'))
        );
    }

    /** 白名单常量本身：新增一个键，既有 6 键与顺序原样保留 */
    public function test_whitelist_gained_exactly_one_key(): void
    {
        $info = ShareController::NESTED_FIELDS['info'];

        $this->assertContains('complianceNotice', $info);
        $this->assertSame(
            ['name', 'industry', 'slogan', 'intro', 'address', 'phone'],
            array_slice($info, 0, 6),
            '既有 6 键必须原样保留（顺序也未动）'
        );
        $this->assertCount(7, $info, '只应有新增这一个键');
        $this->assertSame(['enabled', 'code', 'views'], ShareController::NESTED_FIELDS['share']);
    }

    // ============================================================
    // 3. 约束：类型 / 空值 / 超长
    // ============================================================

    /** 超长合规说明被截断到上限，且截断后仍是合法 UTF-8（中文不被切半） */
    public function test_overlong_notice_is_truncated(): void
    {
        $max = ShareController::COMPLIANCE_NOTICE_MAX_LENGTH;
        // 用多字节字符构造，专门验证按「字符数」而不是「字节数」截断
        $payload = $this->payload();
        $payload['info']['complianceNotice'] = str_repeat('合规说明', $max).'TAIL-OVERFLOW';

        $token = $this->publishSales($payload);
        $stored = PublishedShare::where('token', $token)->firstOrFail();
        $kept = $stored->payload['info']['complianceNotice'];

        $this->assertSame($max, mb_strlen($kept), "必须截断到 {$max} 字符");
        $this->assertStringNotContainsString('TAIL-OVERFLOW', $kept);
        $this->assertTrue(mb_check_encoding($kept, 'UTF-8'), '截断后必须仍是合法 UTF-8（不能按字节切）');

        // 下发侧同样是截断后的值，顺序稳定（截断取值可预测：前 max 个字符）
        $this->getJson("/api/public/sales/{$token}")
            ->assertOk()
            ->assertJsonPath('data.info.complianceNotice', mb_substr(str_repeat('合规说明', $max), 0, $max));
    }

    /** 恰好等于上限的值**不**被截断（边界另一侧，避免 off-by-one 把正常内容切掉） */
    public function test_notice_exactly_at_limit_is_kept_intact(): void
    {
        $max = ShareController::COMPLIANCE_NOTICE_MAX_LENGTH;
        $exact = str_repeat('甲', $max);

        $payload = $this->payload();
        $payload['info']['complianceNotice'] = $exact;

        $token = $this->publishSales($payload);
        $this->assertSame(
            $exact,
            PublishedShare::where('token', $token)->firstOrFail()->payload['info']['complianceNotice']
        );
        $this->getJson("/api/public/sales/{$token}")
            ->assertOk()
            ->assertJsonPath('data.info.complianceNotice', $exact);
    }

    /**
     * 非字符串值一律**不写入该键**（而不是转型成 "Array" / "1" 之类的脏值）。
     *
     * `(string) $array` 会抛 Array-to-string 警告并把字面量 "Array" 写进快照；
     * 对 null/false/数字同样没有保留的理由 —— 与 sanitize 里其余「类型不符即丢弃」
     * 同一规则，不为这个键开例外。
     */
    public function test_non_string_notice_values_are_dropped(): void
    {
        foreach ([['a' => 'b'], null, false, 123, 12.5, true] as $i => $bad) {
            $payload = $this->payload();
            $payload['info']['complianceNotice'] = $bad;

            $out = ShareController::sanitizeSalesPayload($payload, 'abc123', null);

            $this->assertArrayNotHasKey(
                'complianceNotice',
                $out['info'],
                '非法类型（'.gettype($bad)."）不得写入快照 [case {$i}]"
            );
            // info 里的既有字段不受影响
            $this->assertSame('绿地店门店简介', $out['info']['intro']);
        }
    }

    /**
     * 空串 / 纯空白 → 不写入该键（不产生「已设置但没内容」的空白区块）。
     *
     * 中间两个 case 是中文场景的真实坑：全角空格 U+3000 与不换行空格 U+00A0
     * 都**不在** PHP `trim()` 的默认字符表里 —— 只判 `trim() === ''` 的实现
     * 会让一串肉眼看不见的空白落进快照，前端渲染成空白区块。
     */
    public function test_blank_notice_is_not_written(): void
    {
        $blanks = [
            'empty string' => '',
            'ascii spaces' => '   ',
            'whitespace chars' => "\t\n\r ",
            'fullwidth space U+3000' => '　　',
            'nbsp U+00A0' => "\u{00A0}\u{00A0}",
            'mixed blanks' => " \u{00A0}　\n",
        ];

        foreach ($blanks as $label => $blank) {
            $payload = $this->payload();
            $payload['info']['complianceNotice'] = $blank;

            $out = ShareController::sanitizeSalesPayload($payload, 'abc123', null);

            $this->assertArrayNotHasKey('complianceNotice', $out['info'], "空白内容不应写入快照：{$label}");
        }
    }

    /** 首尾空白被 trim（自由文本的常见粘贴形态），语义不变 */
    public function test_notice_is_trimmed(): void
    {
        $payload = $this->payload();
        $payload['info']['complianceNotice'] = '  '.self::NOTICE."\n";

        $token = $this->publishSales($payload);

        $this->assertSame(
            self::NOTICE,
            PublishedShare::where('token', $token)->firstOrFail()->payload['info']['complianceNotice']
        );
    }

    /**
     * 回归保护：**不提供** complianceNotice 时，既有字段与白名单行为完全不变。
     *
     * 同时覆盖「info 内层未知键仍被丢」这条既有边界 —— 新增键不得把它带松。
     */
    public function test_absent_notice_does_not_affect_existing_fields(): void
    {
        $payload = $this->payload();
        unset($payload['info']['complianceNotice']);
        $payload['info']['__evil'] = 'ABSENT-CASE-LEAK';

        $token = $this->publishSales($payload);
        $stored = PublishedShare::where('token', $token)->firstOrFail();

        $this->assertSame('一麦瑜伽（绿地店）', $stored->payload['info']['name']);
        $this->assertSame('0531-00000000', $stored->payload['info']['phone']);
        $this->assertArrayNotHasKey('complianceNotice', $stored->payload['info']);
        $this->assertArrayNotHasKey('__evil', $stored->payload['info']);

        $res = $this->getJson("/api/public/sales/{$token}")->assertOk();
        $this->assertResponseLacks($res, 'ABSENT-CASE-LEAK');
        $this->assertSame(
            ['name', 'industry', 'slogan', 'intro', 'address', 'phone'],
            array_keys($res->json('data.info'))
        );
    }

    /**
     * 公开端点的合规说明不是「客户端可任意覆盖」的旁路。
     *
     * 重新发布（沿用同一 token）时走的是同一 sanitize 路径，两次约束一致；
     * 且 views 仍沿用库内值 —— 借新键顺手改 views 的写法同样无效。
     */
    public function test_republish_applies_same_constraints(): void
    {
        // 必须用**同一个账号**发布两次：写路径按 created_by_user_id 选址，
        // 换账号会新开一行（拿到的还是新 token），那就不再是「重新发布」了。
        $user = $this->makeManager();
        $token = $this->publishSales(null, $user);

        $again = $this->payload();
        $again['info']['complianceNotice'] = str_repeat('乙', ShareController::COMPLIANCE_NOTICE_MAX_LENGTH * 2);
        $again['share']['views'] = 99999;

        Sanctum::actingAs($user);
        $this->postJson('/api/shares/publish', ['type' => 'sales', 'payload' => $again])
            ->assertOk()
            ->assertJsonPath('data.token', $token);

        $this->assertSame(1, PublishedShare::where('type', 'sales')->count(), '重新发布应沿用同一行');
        $stored = PublishedShare::where('token', $token)->firstOrFail();
        $this->assertSame(ShareController::COMPLIANCE_NOTICE_MAX_LENGTH, mb_strlen($stored->payload['info']['complianceNotice']));
        $this->assertSame(0, (int) $stored->payload['share']['views'], '借新键顺带改 views 无效');
    }

    /** 合规说明里含 HTML/脚本片段时按**原文**存（转义属于渲染侧，不在快照层做） */
    public function test_notice_keeps_raw_text_not_html_escaped(): void
    {
        $raw = '合规提示：<b>加粗</b> & "引号" 均按原文保存';

        $payload = $this->payload();
        $payload['info']['complianceNotice'] = $raw;

        $token = $this->publishSales($payload);

        $this->assertSame(
            $raw,
            PublishedShare::where('token', $token)->firstOrFail()->payload['info']['complianceNotice'],
            '快照层不做 HTML 转义（否则非 HTML 消费者会看到 &lt; 之类脏数据）'
        );
        $this->getJson("/api/public/sales/{$token}")
            ->assertOk()
            ->assertJsonPath('data.info.complianceNotice', $raw);
    }
}
