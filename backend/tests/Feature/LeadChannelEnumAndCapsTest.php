<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S15 / S16 / S17 的后端契约（渠道枚举收口 + 卡项限额配置化 + 小额课包判定）。
 *
 * 本文件锁的是**行为边界**，不是实现细节。四条最容易被后人改坏的地方：
 *
 *  1. **枚举清单是唯一来源** —— 校验规则必须派生于 `LEAD_SOURCES`/`ORDER_PLATFORMS`
 *     常量。若有人图省事在控制器里再写一份字面量数组，这组用例仍会绿，
 *     但「加渠道漏改一处」的老问题就回来了。为此 `test_enum_constants_are_the_single_source`
 *     断言的是**常量与前端既有值域的并集关系**，而不是某个具体数组。
 *  2. **只校验写入，不锁死历史** —— 这是本次改动最大的风险面。历史行必须始终
 *     读得出；且「原样带回」不得 422（否则枚举会变成单向闸门，见下）。
 *  3. **超限额绝不阻断** —— 硬要求。金额超 `capLessonAmount` 必须仍 200。
 *  4. **迁移幂等 + 可回滚** —— `up()` 重跑不报错、`down()` 后列消失。
 */
class LeadChannelEnumAndCapsTest extends TestCase
{
    use RefreshDatabase;

    private function superUser(): User
    {
        return User::factory()->create([
            'username' => 'enum-super', 'name' => '枚举超管', 'role' => 'R_SUPER', 'status' => '启用',
        ]);
    }

    // ───────────────────────── S15：枚举是唯一来源 ─────────────────────────

    /**
     * 枚举常量必须覆盖「前端既有值域」的**每一项**（只增不减纪律的机器化版本）。
     *
     * 这里把前端 `SOURCE_OPTIONS` / `PLATFORM_OPTIONS` 的值**硬编码进测试**，是刻意的：
     * 测试要能独立于前端文件存在（前端不在本任务的断言范围内），而这份清单正是
     * 「不得收窄」的下界。将来前端若删项，本用例不会误报（断言的是"枚举至少要有这些"），
     * 但枚举若删项会立刻红 —— 正是想要的保护方向。
     */
    public function test_enum_constants_are_the_single_source_and_never_narrower(): void
    {
        // 前端 leads/index.vue 的 SOURCE_OPTIONS（12 项）+ 既有测试/历史数据在用的值
        $frontendSources = [
            '大众点评', '美团', '抖音', '抖音直播', '抖音私信', '视频号',
            '小红书', '电话咨询', '转介绍', '会员转介绍', '自然到店', '潜客激活',
        ];
        foreach ($frontendSources as $v) {
            $this->assertContains($v, LEAD_SOURCES, "前端既有来源「{$v}」不得从枚举中消失");
        }

        // 「老会员转介绍」是规范 ②-6 的新增口径；「会员转介绍」是既有值域，两者并存
        $this->assertContains('老会员转介绍', LEAD_SOURCES);
        $this->assertContains('会员转介绍', LEAD_SOURCES);

        // 既有测试与真实历史数据在用的值（实测 database.sqlite 有「抖音周年庆直播」）
        $this->assertContains('到店', LEAD_SOURCES);
        $this->assertContains('抖音周年庆直播', LEAD_SOURCES);

        // 平台清单：既有 7 项，且与来源清单**刻意不同**（不要合并成一张表）
        $this->assertSame(
            ['大众点评', '美团', '抖音', '抖音直播', '小红书', '视频号', '其他'],
            ORDER_PLATFORMS
        );
    }

    /** 合法值写入成功，且新值「老会员转介绍」可落库（②-6 的核心） */
    public function test_store_accepts_enum_values_including_new_referral_source(): void
    {
        Sanctum::actingAs($this->superUser());

        $id = $this->postJson('/api/leads', [
            'name' => '老会员带来的', 'source' => '老会员转介绍', 'venue' => '绿地店',
            'orderPlatform' => '美团', 'referrer' => '张芷晴',
        ])->assertOk()->json('data.id');

        $this->assertDatabaseHas('leads', [
            'id' => $id, 'source' => '老会员转介绍', 'referrer' => '张芷晴',
        ]);

        // 前端既有值域同样必须能写（这条锁的是「改名不换值」的回归）
        $this->postJson('/api/leads', [
            'name' => '会员带来的', 'source' => '会员转介绍', 'venue' => '绿地店',
        ])->assertOk();
    }

    /** 非法值 422，且**错误信息列出允许值**（验收明确要求） */
    public function test_illegal_channel_value_is_rejected_with_allowed_values_listed(): void
    {
        Sanctum::actingAs($this->superUser());

        $res = $this->postJson('/api/leads', [
            'name' => '非法来源', 'source' => '某个没听说过的渠道', 'venue' => '绿地店',
        ])->assertStatus(422);

        $msg = (string) $res->json('errors.source.0');
        $this->assertNotSame('', $msg, '错误信息必须可读，不能是默认的 The source field is invalid.');
        foreach (LEAD_SOURCES as $allowed) {
            $this->assertStringContainsString($allowed, $msg, "错误信息应列出允许值「{$allowed}」");
        }

        // 平台字段同理
        $res2 = $this->postJson('/api/leads', [
            'name' => '非法平台', 'source' => '美团', 'venue' => '绿地店',
            'orderPlatform' => '不存在的平台',
        ])->assertStatus(422);
        foreach (ORDER_PLATFORMS as $allowed) {
            $this->assertStringContainsString($allowed, (string) $res2->json('errors.orderPlatform.0'));
        }

        // 非法值不得落库
        $this->assertDatabaseMissing('leads', ['name' => '非法来源']);
    }

    // ───────────── S15 兼容性：读取永不报错 + 历史值可原样回流 ─────────────

    /**
     * 枚举外的历史值：**读得到、也存得回**（本次最容易改坏的边界）。
     *
     * 「只校验写入」的准确含义是「拦新增/改写，不锁存量」。若 update 对 source 严格
     * 校验，一条来源为 `到店` 的历史留资只要被打开、改个备注再保存（前端提交整个
     * 表单）就会 422，且店长**无法自救**——下拉框里没有这个选项。所以断言两条：
     *   a) 列表/详情读取不因枚举外的值而失败；
     *   b) 提交与库内现值**相同**的历史值时放行（原样带回）。
     */
    public function test_out_of_enum_legacy_value_is_readable_and_keeps_flowing_back(): void
    {
        Sanctum::actingAs($this->superUser());

        // 构造一条枚举外的历史行（直接落库，模拟升级前遗留数据）
        $legacy = Lead::create([
            'lead_date' => now()->toDateString(),
            'name' => '历史留资', 'phone' => '13800000091',
            'source' => '微信朋友圈（老渠道）',
            'venue' => '绿地店', 'status' => '新留资', 'created_by' => '枚举超管',
        ]);

        // a) 读取路径不得拒绝
        $records = $this->getJson('/api/leads')->assertOk()->json('data.records');
        $this->assertContains(
            '微信朋友圈（老渠道）',
            array_column($records, 'source'),
            '枚举外的历史值必须照常读出（读取路径不得做枚举校验）'
        );

        // b) 原样带回 → 放行；且这条记录仍能被正常编辑
        $this->patchJson("/api/leads/{$legacy->id}", [
            'name' => '历史留资', 'source' => '微信朋友圈（老渠道）',
            'venue' => '绿地店', 'remark' => '改了备注',
        ])->assertOk();

        $legacy->refresh();
        $this->assertSame('微信朋友圈（老渠道）', $legacy->source, '历史来源不得被枚举改写');
        $this->assertSame('改了备注', $legacy->remark);

        // c) 但**改成另一个枚举外的值**仍必须 422（放行的是存量，不是任意写入）
        $this->patchJson("/api/leads/{$legacy->id}", ['source' => '又一个没听说过的渠道'])
            ->assertStatus(422);
        $this->assertSame('微信朋友圈（老渠道）', $legacy->fresh()->source);
    }

    // ───────────────────── S16：卡项限额配置化 + 不阻断 ─────────────────────

    /** 四个限额键默认值按上海「三限」，且可保存、可回读、未提交的键保留原值 */
    public function test_cap_rules_defaults_and_round_trip(): void
    {
        Sanctum::actingAs($this->superUser());

        $this->getJson('/api/member-rules')->assertOk()
            ->assertJsonPath('data.capMembershipAmount', 5000)
            ->assertJsonPath('data.capMembershipMonths', 24)
            ->assertJsonPath('data.capLessonAmount', 20000)
            ->assertJsonPath('data.capLessonTimes', 60);

        $base = [
            'renewalThreshold' => 10, 'vipAmountThreshold' => 30000, 'declineMode' => 'strict',
            'predropMin' => 15, 'predropMax' => 30, 'reviveDays' => 30,
        ];

        // 写：非默认值
        $this->putJson('/api/member-rules', $base + [
            'capMembershipAmount' => 8000, 'capMembershipMonths' => 12,
            'capLessonAmount' => 50000, 'capLessonTimes' => 120,
        ])->assertOk()
            ->assertJsonPath('data.capMembershipAmount', 8000)
            ->assertJsonPath('data.capLessonTimes', 120);

        // 读：重读仍是新值
        $this->getJson('/api/member-rules')->assertOk()
            ->assertJsonPath('data.capMembershipAmount', 8000)
            ->assertJsonPath('data.capMembershipMonths', 12);

        // 未提交的限额键必须保留原值（不得被打回默认 —— 那会让店长「改了就丢」）
        $this->putJson('/api/member-rules', $base + ['capLessonAmount' => 30000])->assertOk()
            ->assertJsonPath('data.capLessonAmount', 30000)
            ->assertJsonPath('data.capMembershipAmount', 8000, '未提交的限额键必须保留原值');

        // 0 = 不限制，必须可保存（min:0 而非 min:1）
        $this->putJson('/api/member-rules', $base + ['capLessonAmount' => 0])->assertOk()
            ->assertJsonPath('data.capLessonAmount', 0);

        // 负数非法
        $this->putJson('/api/member-rules', $base + ['capLessonAmount' => -1])->assertStatus(422);
    }

    /**
     * **硬要求**：成交金额超 `capLessonAmount` 必须仍 200（可带 warning），绝不 422。
     *
     * 限额是「提示」不是「闸门」——库里本来就有历史大卡，硬拦会让真实成交录不进来，
     * 店长只能把金额改小（数据失真）或干脆不录（更糟）。
     */
    public function test_over_cap_deal_amount_is_warned_not_blocked(): void
    {
        Sanctum::actingAs($this->superUser());

        // 先把限额调到很低，确保下面的金额必然超限
        $this->putJson('/api/member-rules', [
            'renewalThreshold' => 10, 'vipAmountThreshold' => 30000, 'declineMode' => 'strict',
            'predropMin' => 15, 'predropMax' => 30, 'reviveDays' => 30,
            'capLessonAmount' => 1000,
        ])->assertOk();

        // store：超限仍 200，金额原样落库，附 warning
        $res = $this->postJson('/api/leads', [
            'name' => '大额成交', 'source' => '美团', 'venue' => '绿地店',
            'status' => '已成交', 'dealAmount' => 99999,
        ])->assertOk();

        $warnings = $res->json('data.warnings');
        $this->assertIsArray($warnings);
        $this->assertNotEmpty($warnings, '超限必须给出 warning，否则提示形同虚设');
        $this->assertSame('capLessonAmount', $warnings[0]['code']);
        $this->assertSame(99999.0, (float) $warnings[0]['actual']);
        $this->assertSame(1000.0, (float) $warnings[0]['limit']);

        $id = $res->json('data.id');
        $this->assertSame(99999.0, (float) Lead::findOrFail($id)->deal_amount, '超限不得改写金额');
        $this->assertDatabaseHas('audit_logs', ['action' => '提示', 'module' => '前端客资', 'target_id' => (string) $id]);

        // update：同样不阻断
        $res2 = $this->patchJson("/api/leads/{$id}", ['dealAmount' => 88888])->assertOk();
        $this->assertNotEmpty($res2->json('data.warnings'));
        $this->assertSame(88888.0, (float) Lead::findOrFail($id)->deal_amount);
    }

    /** 限额为 0 时不限制：多大金额都不提示（「0=不限制」是四键统一语义） */
    public function test_zero_cap_means_unlimited(): void
    {
        Sanctum::actingAs($this->superUser());

        $this->putJson('/api/member-rules', [
            'renewalThreshold' => 10, 'vipAmountThreshold' => 30000, 'declineMode' => 'strict',
            'predropMin' => 15, 'predropMax' => 30, 'reviveDays' => 30,
            'capLessonAmount' => 0,
        ])->assertOk();

        $res = $this->postJson('/api/leads', [
            'name' => '无上限成交', 'source' => '美团', 'venue' => '绿地店',
            'status' => '已成交', 'dealAmount' => 999999,
        ])->assertOk();

        $this->assertSame([], $res->json('data.warnings'), '0=不限制时不应产生告警');
    }

    // ───────────────────── S17：小额课包判定（纯函数） ─────────────────────

    public function test_small_package_card_detection_and_ratio(): void
    {
        // 命中类
        foreach (['VIP私教月卡', '月度课包', '包月卡', '月付制会籍', '季卡', '单次体验课', '1次体验', '次卡10次', '小课包'] as $hit) {
            $this->assertTrue(isSmallPackageCard($hit), "「{$hit}」应判为小额/月度课包");
        }
        // 不命中：大额长周期卡
        foreach (['VIP私教50节', '全能小班36节半年卡', '精品白领年卡', '—', ''] as $miss) {
            $this->assertFalse(isSmallPackageCard($miss), "「{$miss}」不应判为小额课包");
        }
        $this->assertFalse(isSmallPackageCard(null));

        // 占比：分子分母同一把尺，空值不计入分母
        $stats = smallPackageStats(['VIP私教月卡', '精品白领年卡', '体验课', '', null, '季卡']);
        $this->assertSame(4, $stats['total'], '空/无卡项名称的行不进分母');
        $this->assertSame(3, $stats['small']);
        $this->assertSame(0.75, $stats['ratio']);

        // 分母为 0 时返回 0.0 而不是 NaN（NaN 会让前端图表崩）
        $empty = smallPackageStats([]);
        $this->assertSame(0, $empty['total']);
        $this->assertSame(0.0, $empty['ratio']);
    }

    // ───────────────────── S15：referrer 迁移幂等 + 可回滚 ─────────────────────

    public function test_referrer_migration_is_idempotent_and_reversible(): void
    {
        $migration = require database_path('migrations/2026_09_28_000001_add_referrer_to_leads.php');

        // up 重跑不报错（RefreshDatabase 已跑过一次）
        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasColumn('leads', 'referrer'));

        // down 可回滚 + 重跑不报错
        $migration->down();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('leads', 'referrer'));

        // 回到终态，避免影响同进程的其它用例
        $migration->up();
        $this->assertTrue(Schema::hasColumn('leads', 'referrer'));

        // 列宽与 referrerRules() 的 max:50 必须同源。**不在这里解析 schema**：
        // `Schema::getColumns()` 的形态随驱动而变（MySQL 的 `type` 是 `varchar(50)`、
        // SQLite 只是 `varchar`，长度信息在 `length` 键上且不一定存在），按 schema
        // 断言等于把测试绑到驱动实现上。真正的约束（50 能存、51 被拦、不得截断）
        // 由下一个用例用**行为**锁定，两种驱动下都成立且更有意义。
        $columns = collect(Schema::getColumns('leads'))->keyBy('name');
        $this->assertFalse($columns['referrer']['nullable'] === false, 'referrer 必须可空');
    }

    /**
     * 列宽 50 与校验 max:50 的**行为**一致性：50 字能存、51 字被拦。
     *
     * 这条比解析 schema 更有意义：两处若不同步（例如校验放到 80 而列还是 50），
     * MySQL 非严格模式会**静默截断** —— 校验通过但数据已残。用行为断言把这种
     * 「看起来没问题」的情况钉死。
     */
    public function test_referrer_length_limit_matches_between_validation_and_column(): void
    {
        Sanctum::actingAs($this->superUser());

        $fifty = str_repeat('长', 50);
        $id = $this->postJson('/api/leads', [
            'name' => '边界50', 'source' => '老会员转介绍', 'venue' => '绿地店', 'referrer' => $fifty,
        ])->assertOk()->json('data.id');
        $this->assertSame($fifty, Lead::findOrFail($id)->referrer, '50 字必须原样入库（不得被截断）');

        $this->postJson('/api/leads', [
            'name' => '边界51', 'source' => '老会员转介绍', 'venue' => '绿地店',
            'referrer' => str_repeat('长', 51),
        ])->assertStatus(422);
    }

    /** referrer 是手填姓名，不做「必须是系统会员」的校验；但长度受约束 */
    public function test_referrer_is_free_text_with_length_limit(): void
    {
        Sanctum::actingAs($this->superUser());

        $this->postJson('/api/leads', [
            'name' => '介绍人非会员', 'source' => '老会员转介绍', 'venue' => '绿地店',
            'referrer' => '路过的熟人（未入会）',
        ])->assertOk();

        $this->postJson('/api/leads', [
            'name' => '介绍人超长', 'source' => '老会员转介绍', 'venue' => '绿地店',
            'referrer' => str_repeat('很长', 40),
        ])->assertStatus(422);
    }

    /** 枚举随列表下发，供前端消费（前后端不各写一份） */
    public function test_enum_is_exposed_to_frontend_via_lead_list(): void
    {
        Sanctum::actingAs($this->superUser());

        $res = $this->getJson('/api/leads')->assertOk();
        $this->assertSame(LEAD_SOURCES, $res->json('data.enums.sources'));
        $this->assertSame(ORDER_PLATFORMS, $res->json('data.enums.orderPlatforms'));
    }

    /** 审计兜底：超限提示必须留痕（否则「提示过」随响应消失，无法复盘） */
    public function test_over_cap_warning_is_audited(): void
    {
        Sanctum::actingAs($this->superUser());

        $this->putJson('/api/member-rules', [
            'renewalThreshold' => 10, 'vipAmountThreshold' => 30000, 'declineMode' => 'strict',
            'predropMin' => 15, 'predropMax' => 30, 'reviveDays' => 30, 'capLessonAmount' => 100,
        ])->assertOk();

        $this->postJson('/api/leads', [
            'name' => '留痕校验', 'source' => '美团', 'venue' => '绿地店',
            'status' => '已成交', 'dealAmount' => 5000,
        ])->assertOk();

        $log = AuditLog::where('action', '提示')->where('module', '前端客资')->first();
        $this->assertNotNull($log, '超限提示必须写审计');
        $this->assertStringContainsString('超过课时包限额', (string) $log->detail);
    }
}
