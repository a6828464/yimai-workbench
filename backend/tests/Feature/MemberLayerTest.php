<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\KyMemberSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 会员经营分层（P0-P5）落库（v3.3.0）。
 *
 * 修复的缺陷：同步把新会员写死成 `layer='P4'`（KyMemberSyncService 原 225/241 行），
 * 于是「经营池分层」里 P0/P1/P2/P3 **恒为空**——分层列有值、但永远只有 P4 和 P5，
 * 店长看到的经营池没有任何分层意义。
 *
 * 修法：分层改为**派生数据**，由 recalculateMemberLayers() 按真实卡项/出勤统一算并落库，
 * 唯一口径来源是 customerDecision()（与五清单、续费评估同一份结论）。
 *
 * 本测试锁住三件事：
 *  1. 同步之后 P0-P4 真的有行（缺陷回归）；
 *  2. P5 仍严格等于「无卡项资产」——它是新媒体账号的**授权条件**，漂移等于越权或漏看；
 *  3. 重算幂等且不写 updated_at（否则每轮同步都会击穿五清单缓存）。
 */
class MemberLayerTest extends TestCase
{
    use RefreshDatabase;

    private function super(): User
    {
        $u = User::factory()->create(['username' => uniqid('s'), 'role' => 'R_SUPER', 'status' => '启用']);
        Sanctum::actingAs($u);

        return $u;
    }

    /** 会员卡项经真实 summarizeCards 聚合后落库 */
    private function member(string $name, array $cards, array $extra = []): Customer
    {
        $m = new \ReflectionMethod(KyMemberSyncService::class, 'summarizeCards');
        $m->setAccessible(true);
        $sum = $m->invoke(null, $cards);

        return Customer::create(array_merge([
            'name' => $name, 'phone' => '1380000'.random_int(1000, 9999), 'phone_tail' => '0000',
            'venue' => '绿地店', 'source' => 'KeepYoga', 'owner' => '店长', 'consultant' => '店长',
            'status' => '在籍',
            'main_card' => $sum['main_card'], 'remain_times' => $sum['remain_times'],
            'expire_date' => $sum['expire_date'], 'card_stats' => $sum['card_stats'],
            'cards_list' => $sum['cards_list'],
            'attend_m1' => 6, 'attend_m2' => 6, 'attend_m3' => 6,
            'last_visit' => now()->subDays(3)->toDateString(),
        ], $extra));
    }

    /**
     * 上游卡项原始行。`$usage` 语义是「已耗**次数**」（调用点按次数传值）。
     * 上游 `usage_total` 是**金额**，故补 `curr_unit_cash_value = 1`
     * 使 `usage_total / 1 == $usage`：保持夹具语义，同时让分母走真实金额口径，
     * 而不是退化成「推导不出 → 分母只剩剩余节数」的降级路径
     * （否则本文件涉及占比的断言会空转，绿得没有意义）。
     */
    private function card(string $title, string $status, string $type, int $residue, int $usage, ?string $deadline): array
    {
        return array_filter([
            'card_title' => $title, 'status' => $status, 'type' => $type,
            'residue_amount' => $residue, 'usage_total' => $usage,
            'curr_unit_cash_value' => '1',
            'deadline' => $deadline,
        ], fn ($v) => $v !== null);
    }

    private function distribution(): array
    {
        return DB::table('customers')->selectRaw('layer, COUNT(*) c')->groupBy('layer')->pluck('c', 'layer')->all();
    }

    // ------------------------------------------------------------ 迁移产物

    public function test_migration_adds_venue_layer_index_and_backfill_is_idempotent(): void
    {
        // 迁移的验收：索引真的建出来了，且回填是幂等的。
        // 本测试跑在 RefreshDatabase 之后——即该迁移已在建库阶段真实执行过一次，
        // 这里断言的是它的**产物**（因此迁移本身没有被跳过）。
        $indexes = collect(Schema::getIndexes('customers'))->pluck('name');
        $this->assertTrue(
            $indexes->contains('customers_venue_layer_idx'),
            '缺少 customers(venue, layer) 联合索引：本店分层筛选会退回全表扫描'
        );

        // 迁移里调用的回填与运行时同源，跑第二次必须零写入（否则每次部署都全表重写）
        $this->assertSame(0, recalculateMemberLayers());
    }

    // ------------------------------------------------------------ 缺陷回归

    public function test_layers_are_not_all_p4_after_recalculation(): void
    {
        // 四个会员分别命中 P0/P2/P3/P4，外加一个无资产 P5。
        // 修复前：全部落 P4（新会员写死），P0/P2/P3 恒为空。
        $this->member('回归-P0', [$this->card('私教20次', '4', '1', 2, 18, now()->addDays(300)->toDateString())]);
        $this->member('回归-P2', [$this->card('私教200次', '4', '1', 180, 20, now()->addDays(500)->toDateString())],
            ['attend_m1' => 9, 'attend_m2' => 6, 'attend_m3' => 3]);
        $this->member('回归-P3', [$this->card('私教50次', '4', '1', 50, 0, now()->subDays(100)->toDateString())]);
        $this->member('回归-P4', [$this->card('私教200次', '4', '1', 180, 20, now()->addDays(500)->toDateString())]);
        $this->member('回归-P5', [], ['main_card' => '—']);

        $changed = recalculateMemberLayers();
        $this->assertGreaterThan(0, $changed);

        $dist = $this->distribution();
        foreach (['P0', 'P2', 'P3', 'P4', 'P5'] as $layer) {
            $this->assertGreaterThanOrEqual(1, $dist[$layer] ?? 0, "{$layer} 层不应为空——这正是「经营池分层恒为空」的缺陷");
        }
        $this->assertSame(1, $dist['P0'] ?? 0);
    }

    public function test_sync_no_longer_hardcodes_layer(): void
    {
        // 静态锁：同步服务不得再出现写死分层的赋值。
        // 「恒为空」的根因就是两处 'layer' => 'P4' 硬编码，一旦被改回来缺陷立刻复现。
        $src = file_get_contents(base_path('app/Services/KyMemberSyncService.php'));
        $this->assertStringNotContainsString("'layer' => 'P4'", $src, '同步服务不得再硬编码 layer=P4');
        $this->assertStringNotContainsString("'layer' => 'P0'", $src, '同步服务不得硬编码任何分层');
        $this->assertStringNotContainsString("'layer' => 'P1'", $src);
        $this->assertStringNotContainsString("'layer' => 'P2'", $src);
        $this->assertStringNotContainsString("'layer' => 'P3'", $src);
    }

    // ------------------------------------------------- P2「频次下降」口径修复（T5）

    /**
     * 缺陷回归：P1 抢占把 P2 整层吃空（用户报告「P2 只剩 2 人」）。
     *
     * 根因：旧优先级链是 P0 → P1 → P2，而 $revive = dd > reviveDays(30)。
     * 频次下降（M1>M2>M3）的人 last_visit 越来越久远，只要超 30 天就被 P1 抢走；
     * 「仍在出勤且递减」的会员（P2 的核心画像：正在流失、还来得及拦）反而落不进 P2。
     *
     * 修法：把「仍在出勤的严格递减」（M3>0 且 M1>M2>M3）提到 P1 之前落 P2；
     * 停练（M3=0）的递减者仍归 P1/P3/P4——停练不是「频次下降」，是「已经流失」。
     * 下面四条用例分别钉住 P2 口径的四个象限（在出勤×递减、停练×递减、
     * 待续费抢占、非严格递减），任何一档编排回退都会至少红一条。
     */
    public function test_p2_active_strictly_declining_member_lands_p2_even_if_revive_would_match(): void
    {
        // 象限①：仍在出勤（M3=3>0）且严格递减（9>6>3）、卡余量充足不触待续费。
        // last_visit 5 天前——远小于 reviveDays=30，$revive 本来就不成立；
        // 这条用例的价值在于**锁住优先级编排**：若有人把 P2 挪回 P1 之后，
        // 该会员会因 preLoss 窗口外而仍落 P2（侥幸通过），所以再补一条 dd>30 的变体
        // （见下一条）双保险钉住「P2 在 P1 之前」这件事本身。
        $c = $this->member('P2-在出勤递减', [
            $this->card('私教200次', '4', '1', 180, 20, now()->addDays(500)->toDateString()),
        ], ['attend_m1' => 9, 'attend_m2' => 6, 'attend_m3' => 3, 'last_visit' => now()->subDays(5)->toDateString()]);

        recalculateMemberLayers();
        $this->assertSame('P2', $c->fresh()->layer, '仍在出勤且严格递减的会员必须落 P2（频次下降）');
    }

    public function test_p2_priority_precedes_p1_in_the_chain_itself(): void
    {
        // 象限①的编排锁：构造「$decliningActive 与 $revive 同时为真」的会员。
        // m=9/6/3 严格递减、M3=3>0（仍在出勤），但 last_visit 设在 35 天前（> reviveDays=30）
        // ——注意这在本库是**边界数据形态**（出勤窗口与 last_visit 字段不一致），
        // 用它正是为了证明优先级链里 P2 排在 P1 前面：若编排回退成 P1 在前，
        // 该会员会落 P1，本用例立刻红。
        $c = $this->member('P2-编排优先级', [
            $this->card('私教200次', '4', '1', 180, 20, now()->addDays(500)->toDateString()),
        ], ['attend_m1' => 9, 'attend_m2' => 6, 'attend_m3' => 3, 'last_visit' => now()->subDays(35)->toDateString()]);

        $d = customerDecision($c->fresh());
        $this->assertTrue($d['revive'], '前置：dd=35>30 应命中 revive（用于证明两者重叠时的编排顺序）');
        $this->assertSame('P2', $d['layer'], '仍在出勤（M3>0）的严格递减者必须赢过 P1——否则 P2 又被抢空');
        recalculateMemberLayers();
        $this->assertSame('P2', $c->fresh()->layer);
    }

    public function test_p2_stopped_training_declining_member_lands_p1_not_p2(): void
    {
        // 象限②：停练的递减者（m=6/3/0，last_visit 60 天前）→ P1。
        // M3=0 说明本月一次没来——他不是「正在流失」，是「已经流失」，
        // 属于 P1 待复活的画像。新 P2（要求 M3>0）不得把他抢走。
        $c = $this->member('P1-停练递减', [
            $this->card('私教200次', '4', '1', 180, 20, now()->addDays(500)->toDateString()),
        ], ['attend_m1' => 6, 'attend_m2' => 3, 'attend_m3' => 0, 'last_visit' => now()->subDays(60)->toDateString()]);

        recalculateMemberLayers();
        $this->assertSame('P1', $c->fresh()->layer, '停练（M3=0）的递减者是待复活画像，必须落 P1 而非 P2');
    }

    public function test_p2_declining_member_hitting_renewal_still_lands_p0(): void
    {
        // 象限③：递减但命中待续费（次卡仅余 2 节 ≤ 阈值 10）→ P0。
        // P0 续费窗口是打钱的池子，优先级必须保持最高——P2 的提前不能殃及 P0。
        $c = $this->member('P0-递减且待续费', [
            $this->card('私教20次', '4', '1', 2, 18, now()->addDays(300)->toDateString()),
        ], ['attend_m1' => 9, 'attend_m2' => 6, 'attend_m3' => 3, 'last_visit' => now()->subDays(5)->toDateString()]);

        recalculateMemberLayers();
        $this->assertSame('P0', $c->fresh()->layer, '命中待续费的递减者必须落 P0（续费窗口优先级不被破坏）');
    }

    public function test_p2_non_strict_declining_member_does_not_land_p2(): void
    {
        // 象限④：m2=m3（6>3=3，非严格递减）→ 不落 P2。
        // 分层 P2 固定用 strict（M1>M2>M3），「近两档持平」不算趋势下降。
        // 该会员余额充足、近期出勤，应落 P4。
        $c = $this->member('P4-非严格递减', [
            $this->card('私教200次', '4', '1', 180, 20, now()->addDays(500)->toDateString()),
        ], ['attend_m1' => 6, 'attend_m2' => 3, 'attend_m3' => 3, 'last_visit' => now()->subDays(5)->toDateString()]);

        recalculateMemberLayers();
        $this->assertSame('P4', $c->fresh()->layer, 'm2=m3 非严格递减不得落 P2（分层固定 strict 口径）');
    }

    // ------------------------------------------------------------ P5 授权不变

    public function test_p5_still_means_no_card_asset_for_media_scope(): void
    {
        // P5 是「前端客资/留资」= 无卡项资产，同时是 scopeCustomersForUser() 里
        // 新媒体账号（R_MEDIA → where layer='P5'）的**授权条件**。
        // 若把有资产的会员误算成 P5，新媒体就会看到会员手机号（越权）；
        // 若把无资产客资误算成 P4，新媒体就看不到自己录的客资（漏看）。两侧都要锁。
        $lead = $this->member('客资-无资产', [], ['main_card' => '—']);
        $realMember = $this->member('会员-有资产', [
            $this->card('私教200次', '4', '1', 180, 20, now()->addDays(500)->toDateString()),
        ]);

        recalculateMemberLayers();

        $this->assertSame('P5', $lead->fresh()->layer);
        $this->assertSame('P4', $realMember->fresh()->layer);

        // 新媒体可见范围：只看得到 P5
        $media = User::factory()->create(['username' => uniqid('m'), 'role' => 'R_MEDIA', 'status' => '启用']);
        $media->forceFill(['roles' => ['R_MEDIA']])->save();
        Sanctum::actingAs($media);

        $visible = $this->getJson('/api/customers?size=500')->assertOk()->json('data.records');
        $names = array_column($visible, 'name');
        $this->assertContains('客资-无资产', $names, '新媒体必须能看到无资产客资');
        $this->assertNotContains('会员-有资产', $names, '新媒体不得看到有资产的会员（越权）');
    }

    public function test_asset_less_row_with_ky_external_id_is_not_treated_as_lead(): void
    {
        // 边界：同步会员的卡项全部过期时 main_card 会变成 '—'，此时他**仍是会员**，
        // 不能被当成客资（前端客资）——CustomerController 用
        // 「layer='P5' 且 external_id 非 ky:」双条件把这两类区分开。
        // 本测试锁住：ky: 来源的行即使无资产也不落到 P5（否则会从会员列表消失）。
        $expired = $this->member('过期无资产', [
            $this->card('私教50次', '6', '1', 0, 50, now()->subDays(10)->toDateString()),
        ], ['external_id' => 'ky:999001', 'main_card' => '—']);

        recalculateMemberLayers();
        $this->assertSame('P5', $expired->fresh()->layer, '无资产行落 P5 是既定语义');

        // 但 /customers?type=lead 必须排除 ky: 来源，会员列表必须包含他
        $this->super();
        $leads = array_column($this->getJson('/api/customers?type=lead&size=500')->assertOk()->json('data.records'), 'name');
        $this->assertNotContains('过期无资产', $leads, 'ky: 来源的行不得出现在客资列表');

        $members = array_column($this->getJson('/api/customers?type=member&size=500')->assertOk()->json('data.records'), 'name');
        $this->assertContains('过期无资产', $members, 'ky: 来源的行必须留在会员列表');
    }

    // ------------------------------------------------------------ 幂等 / 时间戳

    public function test_recalculation_is_idempotent(): void
    {
        $this->member('幂等A', [$this->card('私教20次', '4', '1', 2, 18, now()->addDays(300)->toDateString())]);
        $this->member('幂等B', [$this->card('私教200次', '4', '1', 180, 20, now()->addDays(500)->toDateString())]);

        $first = recalculateMemberLayers();
        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, recalculateMemberLayers(), '第二次重算不应再写入任何行');
    }

    public function test_recalculation_does_not_bump_updated_at(): void
    {
        // 五清单缓存键含 MAX(updated_at)。分层每次同步后都重算，
        // 若写 updated_at 就等于每轮同步都击穿清单缓存（清单接口等于没缓存）。
        $c = $this->member('时间戳不变', [
            $this->card('私教200次', '4', '1', 180, 20, now()->addDays(500)->toDateString()),
        ], ['layer' => 'P5']);
        $before = $c->fresh()->updated_at;

        $this->travel(2)->seconds();
        recalculateMemberLayers();

        $this->assertSame('P4', $c->fresh()->layer);
        $this->assertEquals($before, $c->fresh()->updated_at, '分层重算不得改 updated_at');
    }

    // ------------------------------------------------------------ 命令

    public function test_rebuild_layers_command_backfills_and_reports(): void
    {
        $this->member('命令-A', [$this->card('私教20次', '4', '1', 2, 18, now()->addDays(300)->toDateString())],
            ['layer' => 'P5']);
        $this->member('命令-B', [$this->card('私教200次', '4', '1', 180, 20, now()->addDays(500)->toDateString())],
            ['layer' => 'P5']);

        $this->artisan('rebuild:layers')
            ->expectsOutputToContain('实际更新 2 行')
            ->assertSuccessful();

        $dist = $this->distribution();
        $this->assertGreaterThanOrEqual(1, $dist['P0'] ?? 0);
    }

    public function test_rebuild_layers_dry_run_does_not_write(): void
    {
        $this->member('预演-A', [$this->card('私教20次', '4', '1', 2, 18, now()->addDays(300)->toDateString())],
            ['layer' => 'P5']);

        $this->artisan('rebuild:layers', ['--dry-run' => true])
            ->expectsOutputToContain('预演模式')
            ->expectsOutputToContain('预计变化 1 行')
            ->assertSuccessful();

        $this->assertSame('P5', Customer::where('name', '预演-A')->first()->layer, '预演不得写库');
    }

    // ------------------------------------------------------------ 分层定义单一来源

    public function test_layer_definitions_cover_exactly_p0_to_p5(): void
    {
        $defs = layerDefinitions();
        $this->assertSame(['P0', 'P1', 'P2', 'P3', 'P4', 'P5'], array_keys($defs));
        foreach ($defs as $key => $def) {
            $this->assertArrayHasKey('label', $def, "{$key} 缺少 label");
            $this->assertArrayHasKey('desc', $def, "{$key} 缺少 desc");
        }
        // 标签必须与前端 LAYER_LABELS 一致，否则同一分层在两处叫不同名字
        $this->assertSame('续费窗口', $defs['P0']['label']);
        $this->assertSame('高资产低活跃', $defs['P1']['label']);
        $this->assertSame('频次下降', $defs['P2']['label']);
        $this->assertSame('过期有余额', $defs['P3']['label']);
        $this->assertSame('可升级', $defs['P4']['label']);
        $this->assertSame('新客转化', $defs['P5']['label']);
    }

    public function test_layer_filter_returns_only_that_layer(): void
    {
        $this->member('筛选-P0', [$this->card('私教20次', '4', '1', 2, 18, now()->addDays(300)->toDateString())]);
        $this->member('筛选-P4', [$this->card('私教200次', '4', '1', 180, 20, now()->addDays(500)->toDateString())]);
        recalculateMemberLayers();

        $this->super();
        $names = array_column(
            $this->getJson('/api/customers?layer=P0&size=500')->assertOk()->json('data.records'),
            'name'
        );

        $this->assertContains('筛选-P0', $names);
        $this->assertNotContains('筛选-P4', $names, 'layer 筛选不得串层');
    }

    public function test_lead_type_never_shows_real_members(): void
    {
        // 「不隐藏人」的另一面：客资与会员必须互斥，否则店长会把会员当客资重复跟进
        $this->member('互斥-会员', [$this->card('私教200次', '4', '1', 180, 20, now()->addDays(500)->toDateString())]);
        $this->member('互斥-客资', [], ['main_card' => '—']);
        recalculateMemberLayers();

        $this->super();
        $leads = array_column($this->getJson('/api/customers?type=lead&size=500')->assertOk()->json('data.records'), 'name');
        $members = array_column($this->getJson('/api/customers?type=member&size=500')->assertOk()->json('data.records'), 'name');

        $this->assertContains('互斥-客资', $leads);
        $this->assertNotContains('互斥-会员', $leads);
        $this->assertContains('互斥-会员', $members);
        $this->assertNotContains('互斥-客资', $members);
    }

    public function test_layer_distribution_endpoint_is_consistent_with_db(): void
    {
        // 分层接口/统计与实际落库值必须一致（避免「页面显示 P0 有 3 个、库里 0 个」）
        $this->member('统计-A', [$this->card('私教20次', '4', '1', 2, 18, now()->addDays(300)->toDateString())]);
        $this->member('统计-B', [], ['main_card' => '—']);
        recalculateMemberLayers();

        $this->super();
        $counts = $this->getJson('/api/customers?size=500')->assertOk()->json('data.records');
        $byLayer = [];
        foreach ($counts as $row) {
            $byLayer[$row['layer']] = ($byLayer[$row['layer']] ?? 0) + 1;
        }

        $dist = $this->distribution();
        $this->assertSame((int) ($dist['P0'] ?? 0), $byLayer['P0'] ?? 0);
        $this->assertSame((int) ($dist['P5'] ?? 0), $byLayer['P5'] ?? 0);
    }

    // ------------------------------------------------- 徽标口径 ≡ 页签口径（t16）

    /**
     * 徽标数与页签行数必须同源。
     *
     * 缺陷形态：`/customers/list-counts` 只做角色范围过滤、不接受 `type`，
     * 而 `/customers` 带 `type=member` → 徽标按「全部客户」算、页签按「仅会员」算。
     * 实测预流失徽标显示 4、点进去只有 3 行，多的那条是 layer=P5 的留资客资。
     *
     * 这条断言对**五个清单逐个**比对，而不是只看预流失——同一个漏配 type 的 bug
     * 在任何清单上都可能复发，只盯一个清单等于给其余四个留了后门。
     */
    public function test_list_counts_badge_equals_tab_rows_for_every_list(): void
    {
        // 造数据：3 个会员（P0 续费窗口 / P4 / 待复活）+ 2 个 P5 客资。
        // 客资必须**确实命中某个清单**，否则测不出「徽标把客资算进去」这件事。
        $this->member('徽标-会员A', [$this->card('私教20次', '4', '1', 2, 18, now()->addDays(300)->toDateString())]);
        $this->member('徽标-会员B', [$this->card('私教200次', '4', '1', 180, 20, now()->addDays(500)->toDateString())],
            ['last_visit' => now()->subDays(60)->toDateString()]);
        // 客资（无资产 → P5）：让它命中预流失（停练落在 15~30 天窗口且无近期出勤）
        $this->member('徽标-客资C', [], [
            'main_card' => '—', 'attend_m1' => 5, 'attend_m2' => 3, 'attend_m3' => 0,
            'last_visit' => now()->subDays(20)->toDateString(),
        ]);
        recalculateMemberLayers();

        $this->super();

        // 全量（不带 type）：客资会被算进徽标 —— 这正是缺陷场景
        $all = $this->getJson('/api/customers/list-counts')->assertOk()->json('data.counts');
        $memberOnly = $this->getJson('/api/customers/list-counts?type=member')->assertOk()->json('data.counts');

        foreach (['待续课', '出勤降低', 'VIP', '预流失', '待复活'] as $list) {
            // 页签行数 = /customers?list=X&type=member 的 total（会员管理页真实走的接口）
            $total = $this->getJson('/api/customers?list='.urlencode($list).'&type=member&size=500')
                ->assertOk()->json('data.total');

            $this->assertSame(
                $total,
                $memberOnly[$list],
                "清单「{$list}」徽标数({$memberOnly[$list]})与页签行数({$total})不一致——徽标与列表口径分裂"
            );
        }

        // 反向：不带 type 的旧口径确实比 type=member 多算了客资（证明这条测试有牙）
        $this->assertGreaterThan(
            $memberOnly['预流失'],
            $all['预流失'],
            '全量口径应比会员口径多出客资；若相等说明测试数据没造出污染场景，测试失去意义'
        );
    }

    /** `type` 缺省时行为与改动前逐字一致（向后兼容：老调用方不带 type）。 */
    public function test_list_counts_without_type_is_unchanged(): void
    {
        $this->member('兼容-会员', [$this->card('私教20次', '4', '1', 2, 18, now()->addDays(300)->toDateString())]);
        $this->member('兼容-客资', [], ['main_card' => '—', 'attend_m3' => 6]);
        recalculateMemberLayers();

        $this->super();

        // 不带 type：直接返回清单原始计数（含客资），与 memberListIds() 逐项相等
        $counts = $this->getJson('/api/customers/list-counts')->assertOk()->json('data.counts');
        $lists = memberListIds();
        foreach (['待续课', '出勤降低', 'VIP', '预流失', '待复活'] as $list) {
            $this->assertSame(count($lists[$list]), $counts[$list], "{$list} 的缺省口径变了");
        }

        // 未知 type 也必须等价于缺省（不能误当成 member 过滤掉客资）
        $unknown = $this->getJson('/api/customers/list-counts?type=whatever')->assertOk()->json('data.counts');
        $this->assertSame($counts, $unknown, '未知 type 不得改变缺省行为');
    }

    /** `/customers` 的既有 type 语义不得被改动（member/lead 互补且不含对方）。 */
    public function test_customers_type_semantics_unchanged(): void
    {
        $this->member('语义-会员', [$this->card('私教200次', '4', '1', 180, 20, now()->addDays(500)->toDateString())]);
        $this->member('语义-客资', [], ['main_card' => '—']);
        // ky: 来源的无资产行：layer 落 P5，但业务上仍是会员
        $this->member('语义-同步无资产', [], ['main_card' => '—', 'external_id' => 'ky:88001']);
        recalculateMemberLayers();

        $this->super();

        $members = array_column($this->getJson('/api/customers?type=member&size=500')->assertOk()->json('data.records'), 'name');
        $leads = array_column($this->getJson('/api/customers?type=lead&size=500')->assertOk()->json('data.records'), 'name');

        // member：非 P5 或 ky: 来源
        $this->assertContains('语义-会员', $members);
        $this->assertContains('语义-同步无资产', $members, 'ky: 来源的无资产行必须仍算会员');
        $this->assertNotContains('语义-客资', $members);

        // lead：P5 且非 ky: 来源
        $this->assertContains('语义-客资', $leads);
        $this->assertNotContains('语义-会员', $leads);
        $this->assertNotContains('语义-同步无资产', $leads, 'ky: 来源不得算客资');

        // 两者互补不重叠
        $this->assertSame([], array_intersect($members, $leads));
    }

    /**
     * 多角色账号（R_MANAGER + R_MEDIA）的徽标也必须与页签同源。
     *
     * 这是同一个 bug 的第二处：`scopeCustomersForUser()` 对 R_MEDIA 之外的角色会**提前 return**，
     * 所以「店长 + 新媒体」这类账号不会在那边被收窄成 P5；新媒体收窄是在控制器里额外补的。
     * 若只把 `type` 抽出去共用、漏掉这层收窄，多角色账号的徽标仍会与页签分裂。
     * （本仓库 TaskVisibilityScopeTest 明确覆盖了这类多角色组合，不是假想场景。）
     */
    public function test_list_counts_matches_tab_rows_for_multi_role_media_account(): void
    {
        $this->member('多角色-会员', [$this->card('私教20次', '4', '1', 2, 18, now()->addDays(300)->toDateString())]);
        $this->member('多角色-客资', [], ['main_card' => '—']);

        $u = User::factory()->create(['username' => uniqid('mr'), 'role' => 'R_MANAGER', 'status' => '启用']);
        $u->forceFill(['roles' => ['R_MANAGER', 'R_MEDIA'], 'venue' => '绿地店'])->save();
        Sanctum::actingAs($u);

        $badge = $this->getJson('/api/customers/list-counts?type=member')->assertOk()->json('data.counts');
        foreach (['待续课', '出勤降低', 'VIP', '预流失', '待复活'] as $list) {
            $total = $this->getJson('/api/customers?list='.urlencode($list).'&type=member&size=500')
                ->assertOk()->json('data.total');
            $this->assertSame($total, $badge[$list], "多角色账号下清单「{$list}」徽标与页签不一致");
        }
    }

    /** 非超管角色的徽标同样要带 type 收窄（不能只在超管快路径上修）。 */
    public function test_list_counts_type_filter_applies_to_scoped_roles(): void
    {
        $this->member('角色-会员', [$this->card('私教20次', '4', '1', 2, 18, now()->addDays(300)->toDateString())]);
        $this->member('角色-客资', [], [
            'main_card' => '—', 'attend_m1' => 5, 'attend_m2' => 3, 'attend_m3' => 0,
            'last_visit' => now()->subDays(20)->toDateString(),
        ]);
        recalculateMemberLayers();

        $manager = User::factory()->create(['username' => uniqid('m'), 'role' => 'R_MANAGER', 'status' => '启用']);
        $manager->forceFill(['roles' => ['R_MANAGER'], 'venue' => '绿地店'])->save();
        Sanctum::actingAs($manager);

        $all = $this->getJson('/api/customers/list-counts')->assertOk()->json('data.counts');
        $memberOnly = $this->getJson('/api/customers/list-counts?type=member')->assertOk()->json('data.counts');

        // 店长走的是「求交集」分支：带 type 后客资被排除
        $this->assertGreaterThan($memberOnly['预流失'], $all['预流失'], '店长口径下 type=member 也应排除客资');

        foreach (['待续课', '出勤降低', 'VIP', '预流失', '待复活'] as $list) {
            $total = $this->getJson('/api/customers?list='.urlencode($list).'&type=member&size=500')
                ->assertOk()->json('data.total');
            $this->assertSame($total, $memberOnly[$list], "店长视角下清单「{$list}」徽标与页签不一致");
        }
    }

    // ============================================================ t11 审查修复回归

    /**
     * R1：写入判定输入后，派生列 `layer` 必须跟着重算。
     *
     * 缺陷形态：t6 只在「同步」与「改阈值」两处触发分层重算，
     * 漏掉了 `PATCH /customers/{id}` 写 `in_revive` 这条路径。
     * 店长点「标记待复活」后 layer 不变，而经营池分层页读的正是 layer 列，
     * 详情页算的是 customerLayerFor() —— 同一会员两个答案，且两边都「看起来对」。
     *
     * 断言方式：PATCH 之后**直接查库**取 layer，与 customerLayerFor() 比对。
     * 这样即使将来换实现（observer / 全量重算 / 按 id 重算），只要派生列与判定函数一致就通过。
     */
    public function test_patch_in_revive_recomputes_layer_column(): void
    {
        // 造一个「有资产、近期出勤、余额充足」的会员：初始不是 P1
        $c = $this->member('待复活重算', [
            $this->card('私教200次', '4', '1', 180, 20, now()->addDays(500)->toDateString()),
        ]);
        // member() 不写 layer，落库是列默认值 P5；先重算一次得到真实分层
        recalculateMemberLayers();
        $this->assertSame('P4', $c->fresh()->layer);

        $this->super();
        $this->patchJson("/api/customers/{$c->id}", ['inRevive' => true])->assertOk();

        // 派生列必须等于判定函数的结果（P1 = 待复活）
        $fresh = $c->fresh();
        $this->assertSame(
            customerLayerFor($fresh),
            (string) $fresh->layer,
            'PATCH in_revive 后 layer 列与 customerLayerFor() 分叉——派生列没跟着判定输入重算'
        );
        $this->assertSame('P1', (string) $fresh->layer, '标记待复活后应落 P1 高资产低活跃');

        // 再取消，必须跟着回落（双向都成立）
        $this->patchJson("/api/customers/{$c->id}", ['inRevive' => false])->assertOk();
        $back = $c->fresh();
        $this->assertSame(customerLayerFor($back), (string) $back->layer);
        $this->assertSame('P4', (string) $back->layer, '取消待复活后应回落到 P4');
    }

    /**
     * R1（四路一致性）：同一会员经**真实控制器路径**写入后，四个消费方必须给出同一个分层。
     *
     * 四个消费方各有各的读法，任一处的分叉都是真实事故：
     *   1. `GET /customers` 的 layer 列        —— 列表/经营池分层页读的是**列**
     *   2. `GET /customers/{id}/renewal-evaluation` 的 layer —— 详情页读的是**实时判定**
     *   3. `memberListWatch()['经营分层']`      —— 清单明细读的是**缓存快照**
     *   4. `GET /customers?layer=P1`            —— 下拉/筛选走的是**SQL 条件**
     *
     * 第 4 项最容易漏：即使前三者都对，若 layer 列没写进去，
     * 店长按 P1 筛选会「筛不到人」，而详情页明明显示 P1 —— 就是用户报的那类现象。
     */
    public function test_four_consumers_agree_after_real_controller_write(): void
    {
        $c = $this->member('四路一致', [
            $this->card('私教200次', '4', '1', 180, 20, now()->addDays(500)->toDateString()),
        ]);
        recalculateMemberLayers();

        $this->super();
        $this->patchJson("/api/customers/{$c->id}", ['inRevive' => true])->assertOk();

        // ① GET /customers 的 layer 列
        $rows = $this->getJson("/api/customers?size=500&ids={$c->id}")->assertOk()->json('data.records');
        $rowLayer = collect($rows)->firstWhere('id', $c->id)['layer'] ?? null;
        $this->assertSame('P1', $rowLayer, '① GET /customers 的 layer 列未跟上');

        // ② renewal-evaluation 的 layer（实时判定）
        $evalLayer = $this->getJson("/api/customers/{$c->id}/renewal-evaluation")
            ->assertOk()->json('data.layer');
        $this->assertSame('P1', $evalLayer, '② renewal-evaluation 的 layer 未跟上');

        // ③ memberListWatch()['经营分层']（缓存快照）
        $watchLayer = memberListWatch()['经营分层'][$c->id] ?? null;
        $this->assertSame('P1', $watchLayer, '③ 清单明细的经营分层快照未跟上');

        // ④ GET /customers?layer=P1（SQL 条件——「筛不到人」的现场）
        $filtered = $this->getJson('/api/customers?layer=P1&size=500')->assertOk()->json('data.records');
        $this->assertContains(
            $c->id,
            array_column($filtered, 'id'),
            '④ 按 P1 筛选筛不到该会员——layer 列没落库，下拉会「筛不到人」'
        );

        // 四者字面相等
        $this->assertSame([$rowLayer, $evalLayer, $watchLayer], ['P1', 'P1', 'P1']);
        $this->assertSame(customerLayerFor($c->fresh()), $rowLayer, '派生列与判定函数必须一致');
    }

    /**
     * R2：`layer='P5'` 不等于「前端客资」——必须排除 ky: 来源。
     *
     * 缺陷形态：`scopeCustomersForUser()` 的 R_MEDIA 分支是裸的 `where('layer','P5')`。
     * 而「卡项全部过期的正式会员」也会落 P5（分层语义「无资产」本身没错），
     * 于是新媒体账号能看到这些**会员**的姓名与手机号 —— 那是越权，不是留资。
     *
     * 断言：R_MEDIA 看不到 ky: 来源会员的姓名/手机号/实收金额，但看得到真客资。
     */
    public function test_media_role_cannot_see_asset_less_synced_member(): void
    {
        // ① 真客资：P5 且非 ky:
        $lead = $this->member('客资-真留资', [], ['main_card' => '—']);
        // ② 同步会员，卡项全部过期 → 无资产 → 落 P5，但 external_id 是 ky:
        $syncedMember = $this->member('会员-卡项全过期', [
            $this->card('私教50次', '6', '1', 0, 50, now()->subDays(10)->toDateString()),
        ], ['external_id' => 'ky:77001', 'main_card' => '—', 'card_paid_amount' => 12345.67]);
        recalculateMemberLayers();

        // 两者都落 P5 —— 分层语义没错
        $this->assertSame('P5', $lead->fresh()->layer);
        $this->assertSame('P5', $syncedMember->fresh()->layer);

        $media = User::factory()->create(['username' => uniqid('m'), 'role' => 'R_MEDIA', 'status' => '启用']);
        $media->forceFill(['roles' => ['R_MEDIA']])->save();
        Sanctum::actingAs($media);

        $rows = $this->getJson('/api/customers?size=500')->assertOk()->json('data.records');
        $names = array_column($rows, 'name');
        $payload = json_encode($rows, JSON_UNESCAPED_UNICODE);

        // 真客资可见
        $this->assertContains('客资-真留资', $names, '新媒体必须能看到前端客资');
        // 同步会员不可见（这是越权面）
        $this->assertNotContains('会员-卡项全过期', $names, '新媒体不得看到 ky: 来源的无资产会员');
        $this->assertStringNotContainsString('会员-卡项全过期', $payload);
        // 姓名/手机号/实收金额都不得泄露
        $this->assertStringNotContainsString((string) $syncedMember->phone, $payload, '不得泄露会员手机号');
        $this->assertStringNotContainsString('12345.67', $payload, '不得泄露会员实收金额');
    }

    /**
     * R2（第二处泄漏点）：控制器里的 R_MEDIA 收窄也必须是「P5 且非 ky:」。
     *
     * `scopedCustomerQuery()` 为了兼容多角色账号，额外补了一层 R_MEDIA 收窄。
     * 这层此前也是裸 `where('layer','P5')` —— 与 helpers 里的授权分支是**同一个缺陷的第二份拷贝**。
     * 只修 helpers 那一处、漏掉这里，新媒体照样能看到 ky: 来源会员的手机号。
     * 这条测试专门盯控制器这一层（用多角色账号绕过 helpers 的提前 return）。
     */
    public function test_controller_media_narrowing_also_excludes_synced_member(): void
    {
        $this->member('控制器-真客资', [], ['main_card' => '—']);
        $synced = $this->member('控制器-同步无资产', [
            $this->card('私教50次', '6', '1', 0, 50, now()->subDays(10)->toDateString()),
        ], ['external_id' => 'ky:77004', 'main_card' => '—', 'card_paid_amount' => 88888.88]);
        recalculateMemberLayers();

        // 多角色：scopeCustomersForUser 走 R_MANAGER 分支提前 return，收窄只能由控制器补
        $u = User::factory()->create(['username' => uniqid('mr'), 'role' => 'R_MANAGER', 'status' => '启用']);
        $u->forceFill(['roles' => ['R_MANAGER', 'R_MEDIA'], 'venue' => '绿地店'])->save();
        Sanctum::actingAs($u);

        $payload = json_encode(
            $this->getJson('/api/customers?size=500')->assertOk()->json('data.records'),
            JSON_UNESCAPED_UNICODE
        );

        $this->assertStringContainsString('控制器-真客资', $payload, '新媒体必须看得到真客资');
        $this->assertStringNotContainsString('控制器-同步无资产', $payload, '控制器的 R_MEDIA 收窄必须排除 ky: 来源会员');
        $this->assertStringNotContainsString((string) $synced->phone, $payload, '不得泄露会员手机号');
        $this->assertStringNotContainsString('88888.88', $payload, '不得泄露会员实收金额');
    }

    /** R2：今日待办的 newLeads 不得把 ky: 来源的无资产会员算成新客资。 */    public function test_today_new_leads_excludes_synced_asset_less_member(): void
    {
        $this->member('待办-真客资', [], ['main_card' => '—']);
        $this->member('待办-同步无资产', [
            $this->card('私教50次', '6', '1', 0, 50, now()->subDays(10)->toDateString()),
        ], ['external_id' => 'ky:77002', 'main_card' => '—']);
        recalculateMemberLayers();

        $this->super();
        // newLeads 计数在 /today/summary（/today/todo 返回的是列表，不是计数）
        $newLeads = $this->getJson('/api/today/summary')->assertOk()->json('data.newLeads');

        // 只有 1 个真客资（ky: 会员不算）；留资记录表里没有数据
        $this->assertSame(1, $newLeads, 'newLeads 只能数前端客资，ky: 来源的无资产会员不算');
    }

    /** R2：留资查重的 kind 判定不得把 ky: 来源的无资产会员标成「留资」。 */
    public function test_lead_phone_check_marks_synced_member_as_member_not_lead(): void
    {
        $synced = $this->member('查重-同步无资产', [
            $this->card('私教50次', '6', '1', 0, 50, now()->subDays(10)->toDateString()),
        ], ['external_id' => 'ky:77003', 'main_card' => '—']);
        recalculateMemberLayers();

        $this->super();
        $matches = $this->getJson('/api/leads/check?phone='.urlencode((string) $synced->phone))
            ->assertOk()->json('data.matches');

        $kinds = array_column($matches, 'kind');
        $this->assertContains('会员', $kinds, 'ky: 来源的无资产会员必须标成「会员」');
        $this->assertNotContains('留资', $kinds, '不得标成「留资」——否则录入时会误判此人不是会员');
    }

    /**
     * R7：缓存指纹加入 MAX(id)，让「行数与时间戳都不变」的增删也能换键。
     *
     * 为什么不能只测「新增一行」：那会让 COUNT(*) 变化，**没有 MAX(id) 也会换键**——
     * 测试会通过但根本没测到 MAX(id)。只有「删一行 + 加一行、总数不变、同秒」这个组合
     * 才需要 MAX(id) 才能区分。
     *
     * 注意 id 显式指定：SQLite（测试库）的 `INTEGER PRIMARY KEY` 在删掉最大行后会**复用** id，
     * 而生产 MySQL 的 AUTO_INCREMENT 不会。若让 id 自增，这条断言就变成驱动相关——
     * 在 SQLite 上即使有 MAX(id) 也测不出差异。显式给一个更大的 id，等价于 MySQL 的行为，
     * 测的是「MAX(id) 是否参与键」这件事本身，而不是某个驱动的 id 分配策略。
     */
    public function test_cache_fingerprint_changes_when_count_and_timestamp_are_unchanged(): void
    {
        $a = $this->member('指纹-会员A', [
            $this->card('私教20次', '4', '1', 2, 18, now()->addDays(300)->toDateString()),
        ]);
        $this->assertContains($a->id, memberListIds()['待续课']);

        // 同秒内：删掉 A，插入 B（显式更大 id）→ 行数不变、MAX(updated_at) 不变、只有 MAX(id) 变
        Customer::whereKey($a->id)->delete();
        $b = $this->member('指纹-会员B', [
            $this->card('私教20次', '4', '1', 3, 17, now()->addDays(300)->toDateString()),
        ]);
        Customer::whereKey($b->id)->update(['id' => $a->id + 500]);
        $newId = $a->id + 500;

        $second = memberListIds()['待续课'];
        $this->assertNotContains($a->id, $second, '被删除的会员不得仍在清单里（指纹必须换键）');
        $this->assertContains($newId, $second, '同秒新增的会员必须立刻出现');
    }

    /** R7：指纹的粒度限制必须写在注释里（避免后人误以为它能兜住同秒更新）。 */
    public function test_fingerprint_granularity_limit_is_documented(): void
    {
        $src = file_get_contents(base_path('app/Support/helpers.php'));
        $this->assertStringContainsString('粒度限制', $src, '指纹的时间精度限制必须写明，否则后人会误以为它万能');
        $this->assertStringContainsString('MAX(id)', $src);
    }
}
