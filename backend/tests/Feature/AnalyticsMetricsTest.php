<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\KyCard;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 战略规划象限② 五个经营指标的后端回归：
 *
 *   S10 收入结构（summary.revenueMix）
 *   S11 到店频次 × 续费率曲线（attend_m3 / 30 天窗口 / 按人算）
 *   S12 未耗课余额分桶（卡数口径，分类守恒）
 *   S13 渠道四列聚合（channels 加键且向后兼容）
 *   S14 体验卡 → 会员卡转化率（按 service_teacher 分组）
 *
 * 每个端点覆盖三件事：正常结构、门店过滤、空库不 500。
 */
class AnalyticsMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 端点是 Cache::remember 60 秒的，测试之间不隔离会让断言依赖执行顺序
        Cache::flush();
    }

    private function superUser(): User
    {
        return User::factory()->create([
            'username' => 'metrics-super', 'name' => '超管', 'role' => 'R_SUPER',
            'roles' => ['R_SUPER'], 'venue' => '绿地店', 'venues' => ['绿地店', '东部店'],
            'status' => '启用',
        ]);
    }

    private function manager(string $venue): User
    {
        return User::factory()->create([
            'username' => 'metrics-mgr-'.md5($venue), 'name' => $venue.'店长',
            'role' => 'R_MANAGER', 'roles' => ['R_MANAGER'],
            'venue' => $venue, 'venues' => [$venue], 'status' => '启用',
        ]);
    }

    private function booking(string $key, string $venue, string $status, string $kind, array $extra = []): KyBooking
    {
        return KyBooking::create(array_merge([
            'source_key' => $key,
            'venue' => $venue,
            'booking_type' => '私教',
            'course_kind' => $kind,
            'member_name' => '学员',
            'phone' => '1380000'.substr(md5($key), 0, 4),
            'start_at' => '2026-09-10 10:00:00',
            'course_name' => '课',
            'status_raw' => $status,
            'status' => $status,
            'is_trial' => false,
            'raw' => [],
        ], $extra));
    }

    // ==================== S10 收入结构 ====================

    public function test_summary_revenue_mix_groups_by_course_kind_and_divides_by_card_sales(): void
    {
        Sanctum::actingAs($this->superUser());

        // 三节课，每节 single_charge=1 次、单次现金价值不同 ⇒ 耗卡金额可手算
        $this->booking('rv-private', '绿地店', 'signed', 'private', [
            'raw' => ['m_card_unit_cash_value' => '200', 'single_charge' => '1'],
        ]);
        $this->booking('rv-small', '绿地店', 'signed', 'small', [
            'raw' => ['m_card_unit_cash_value' => '100', 'single_charge' => '1'],
        ]);
        $this->booking('rv-group', '绿地店', 'signed', 'group', [
            // single_charge 的历史形态可能是 "1次" 文本 ⇒ 必须能解析出数字
            'raw' => ['m_card_unit_cash_value' => '50', 'single_charge' => '1次'],
        ]);
        // 未签到不算耗课
        $this->booking('rv-cancelled', '绿地店', 'cancelled', 'private', [
            'raw' => ['m_card_unit_cash_value' => '999', 'single_charge' => '1'],
        ]);
        // charge 回退路径（上游没给单次现金价值时）
        $this->booking('rv-charge-fallback', '绿地店', 'signed', 'group', [
            'raw' => ['charge' => '30'],
        ]);
        // 两个金额都取不到 ⇒ 计 0 并进 degraded，不猜
        $this->booking('rv-no-amount', '绿地店', 'signed', 'group', ['raw' => []]);

        KyCard::create([
            'source_key' => '1:rv-card', 'venue' => '绿地店', 'external_id' => 'rv-card',
            'card_title' => '私教卡', 'deal_price' => 1000, 'is_taste' => false,
            'status_format' => '正常', 'sold_at' => '2026-09-01',
        ]);
        // 体验卡与退卡都不进售卡金额分母
        KyCard::create([
            'source_key' => '1:rv-taste', 'venue' => '绿地店', 'external_id' => 'rv-taste',
            'card_title' => '体验卡', 'deal_price' => 8888, 'is_taste' => true,
            'status_format' => '正常', 'sold_at' => '2026-09-01',
        ]);
        KyCard::create([
            'source_key' => '1:rv-refund', 'venue' => '绿地店', 'external_id' => 'rv-refund',
            'card_title' => '退掉的卡', 'deal_price' => 7777, 'is_taste' => false,
            'status_format' => '退卡', 'sold_at' => '2026-09-01',
        ]);

        $data = $this->getJson('/api/analytics/summary')->assertOk()->json('data');
        $mix = $data['revenueMix'];

        $this->assertSame(1000.0, (float) $mix['cardSales'], '售卡金额只算非体验、非退卡');
        $this->assertSame(200.0, (float) $mix['private']['amount']);
        $this->assertSame(100.0, (float) $mix['small']['amount']);
        // group = 50（single_charge 文本能解析）+ 30（charge 回退）+ 0（无金额行）
        $this->assertSame(80.0, (float) $mix['group']['amount']);
        $this->assertSame(380.0, (float) $mix['consumptionTotal']);
        // ratio 分母 = 售卡金额
        $this->assertSame(20.0, (float) $mix['private']['ratio']);
        $this->assertSame(10.0, (float) $mix['small']['ratio']);
        $this->assertSame(8.0, (float) $mix['group']['ratio']);
        // share 是耗卡内部结构，三类相加 = 100
        $this->assertSame(100.0, round(
            (float) $mix['private']['share'] + (float) $mix['small']['share'] + (float) $mix['group']['share'],
            2
        ));
        // 取不到金额的行必须显式暴露，不能静默变小
        $this->assertSame(1, $mix['degraded']['bookingsWithoutAmount']);
    }

    public function test_revenue_mix_respects_venue_scope_for_manager(): void
    {
        Sanctum::actingAs($this->manager('绿地店'));

        $this->booking('rv-scope-green', '绿地店', 'signed', 'private', [
            'raw' => ['m_card_unit_cash_value' => '100', 'single_charge' => '1'],
        ]);
        $this->booking('rv-scope-east', '东部店', 'signed', 'private', [
            'raw' => ['m_card_unit_cash_value' => '555', 'single_charge' => '1'],
        ]);
        KyCard::create([
            'source_key' => '1:rv-scope-green-card', 'venue' => '绿地店', 'external_id' => 'rv-scope-green-card',
            'card_title' => '私教卡', 'deal_price' => 500, 'is_taste' => false,
            'status_format' => '正常', 'sold_at' => '2026-09-01',
        ]);
        KyCard::create([
            'source_key' => '1:rv-scope-east-card', 'venue' => '东部店', 'external_id' => 'rv-scope-east-card',
            'card_title' => '私教卡', 'deal_price' => 999, 'is_taste' => false,
            'status_format' => '正常', 'sold_at' => '2026-09-01',
        ]);

        $mix = $this->getJson('/api/analytics/summary')->assertOk()->json('data.revenueMix');
        $this->assertSame(500.0, (float) $mix['cardSales'], '店长看不到东部店的售卡金额');
        $this->assertSame(100.0, (float) $mix['private']['amount'], '店长看不到东部店的耗卡');
    }

    public function test_revenue_mix_on_empty_database_returns_zeros_not_500(): void
    {
        Sanctum::actingAs($this->superUser());

        $mix = $this->getJson('/api/analytics/summary')->assertOk()->json('data.revenueMix');
        $this->assertSame(0.0, (float) $mix['cardSales']);
        $this->assertSame(0.0, (float) $mix['consumptionTotal']);
        $this->assertSame(0, (int) $mix['private']['ratio']);
        $this->assertSame(0, (int) $mix['private']['amount']);
        $this->assertSame(0, (int) $mix['group']['classCount']);
        $this->assertSame(0, (int) $mix['degraded']['bookingsWithoutAmount']);
    }

    /**
     * S10 必须走 chunk 流式聚合，不得一次性 `->get()` 全表。
     *
     * ## 为什么要钉这一条（2026-09-28 测试服事故的回归锁）
     *
     * 首版实现写的是 `foreach ($bookingQ->get() as $booking)`。本机测试库为空、
     * 该写法一路全绿；但测试服 `ky_bookings` 实测 **4 万余行**（团课 35453 +
     * 私教 6123），且每行带一个大 `raw` JSON 列（`bookingConsumeAmount()` 要从里面读
     * `m_card_unit_cash_value` / `single_charge`，故不能 select 掉省内存）。
     * 一次性实例化全部模型直接撑爆 PHP 的 128MB 上限，`/analytics/summary` 返回 500：
     *
     *     Allowed memory size of 134217728 bytes exhausted
     *     (tried to allocate 20480 bytes)  at Connection.php:427
     *
     * 因 `analyticsError` 由 summary 的 catch 设置，整页顶部弹出「看板数据加载失败」，
     * 而 trends/channels 等其它请求都 200 —— 症状正是用户报的「有数据、但部分没显示」。
     *
     * ## 本测试如何守住它
     *
     * 只断言「结果正确 + 不 OOM」不够（空库下 `->get()` 也不会 OOM）。故这里**造出足以
     * 区分两种写法的数据量**：3000 行 × ~2KB raw。旧写法需一次性持有约 6MB raw 字符串
     * 加 3000 个 Eloquent 模型（实测峰值 30MB+）；chunk 写法峰值只与批大小有关。
     * 判定用**峰值内存增量**而非计时，与机器性能无关。
     *
     * 若有人改回 `->get()`，峰值断言会失败 —— 那正是它存在的意义。
     */
    public function test_revenue_mix_streams_bookings_instead_of_loading_all_at_once(): void
    {
        Sanctum::actingAs($this->superUser());

        $pad = str_repeat('x', 2000);
        $now = now();
        for ($chunk = 0; $chunk < 3; $chunk++) {
            $rows = [];
            for ($i = 0; $i < 1000; $i++) {
                $n = $chunk * 1000 + $i;
                $rows[] = [
                    'source_key' => "stream-probe:{$n}", 'venue' => '绿地店',
                    'booking_type' => '私教', 'course_kind' => 'private',
                    'member_id' => 'm'.($n % 500), 'member_name' => '流式探针',
                    'phone' => '13900000000', 'start_at' => $now->copy()->subDays($n % 100),
                    'teacher_name' => '王教练', 'status' => 'signed', 'is_trial' => false,
                    'raw' => json_encode([
                        'm_card_unit_cash_value' => '128.5',
                        'single_charge' => '1次',
                        'pad' => $pad,
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            KyBooking::insert($rows);
        }
        $this->assertSame(3000, KyBooking::count(), '夹具必须真的落库（否则本测试失去意义）');

        $before = memory_get_peak_usage(true);
        $mix = $this->getJson('/api/analytics/summary')->assertOk()->json('data.revenueMix');
        $peakGrowthMb = (memory_get_peak_usage(true) - $before) / 1048576;

        // 聚合结果正确（不是靠少算换来的低内存）：3000 行 × 128.5 = 385500
        $this->assertSame(385500.0, (float) $mix['private']['amount']);
        $this->assertSame(3000, (int) $mix['private']['classCount']);

        // 阈值 12MB：本夹具下 chunk(1000) 实测峰值增量 **6.0MB**，旧写法（一次性
        // `->get()`）实测 **16.0MB** —— 取中间偏上，既能拦住回归又不随机器抖动误报。
        // （阈值不能拍脑袋定：本测试最初写 20MB，结果把旧写法也放过了 —— 已用
        //  「临时改回 ->get() 看它是否变红」的方式验证过判据有效，别再放宽。）
        $this->assertLessThan(
            12,
            $peakGrowthMb,
            "S10 一次性加载了全部预约（峰值增长 {$peakGrowthMb}MB；chunk 版应为 6MB 左右）——"
            .'必须用 chunkById 流式聚合，否则生产 4 万行会 OOM（见方法注释）'
        );
    }

    // ==================== S11 到店频次 × 续费率曲线 ====================

    public function test_attendance_renewal_curve_buckets_by_attend_m3_and_counts_renewal_by_person(): void
    {
        Sanctum::actingAs($this->superUser());

        // 4 档各放会员，务必让每档分母 > 1 才能验「按人」而不是恒等 0/1
        $specs = [
            // [name, attend_m3, 是否已续费]
            ['c0-a', 0, false], ['c0-b', 0, false], ['c0-c', 0, true],
            ['c13-a', 1, false], ['c13-b', 3, true], ['c13-c', 3, false],
            ['c47-a', 4, true], ['c47-b', 7, true],
            ['c8-a', 8, true], ['c8-b', 20, false], ['c8-c', 99, false],
        ];
        foreach ($specs as [$name, $attend, $renewed]) {
            Customer::create([
                'name' => $name, 'phone' => '139'.substr(md5($name), 0, 8),
                'venue' => '绿地店', 'external_id' => 'ky:'.$name,
                'layer' => 'P2', 'attend_m3' => $attend, 'attend_m1' => 999, 'attend_m2' => 888,
                'renewal_plan' => $renewed ? ['goal' => '体态改善'] : null,
            ]);
        }
        // P5 且非 ky: 的纯客资不算会员，且它的 attend_m3 很大 —— 绝不能被算进 8+ 档
        Customer::create([
            'name' => '纯客资', 'phone' => '13900009999', 'venue' => '绿地店',
            'external_id' => 'local-1', 'layer' => 'P5', 'attend_m3' => 50,
        ]);

        $data = $this->getJson('/api/analytics/attendance-renewal-curve')->assertOk()->json('data');
        $rows = collect($data['buckets'])->keyBy('bucket');

        $this->assertSame(['0', '1-3', '4-7', '8+'], array_column($data['buckets'], 'bucket'));
        $this->assertSame(3, (int) $rows['0']['memberCount']);
        $this->assertSame(1, (int) $rows['0']['renewedCount'], '0 档：3 人里 1 人已续费');
        $this->assertSame(33.3, (float) $rows['0']['renewalRate']);
        $this->assertSame(3, (int) $rows['1-3']['memberCount']);
        $this->assertSame(1, (int) $rows['1-3']['renewedCount']);
        $this->assertSame(33.3, (float) $rows['1-3']['renewalRate']);
        $this->assertSame(2, (int) $rows['4-7']['memberCount']);
        $this->assertSame(2, (int) $rows['4-7']['renewedCount']);
        $this->assertSame(100.0, (float) $rows['4-7']['renewalRate']);
        $this->assertSame(3, (int) $rows['8+']['memberCount'], 'P5 纯客资不算会员，不进 8+');
        $this->assertSame(1, (int) $rows['8+']['renewedCount']);
        $this->assertSame(33.3, (float) $rows['8+']['renewalRate']);

        $this->assertSame(11, (int) $data['totalMembers'], 'Σ 各档人数 = 会员总数（分类守恒）');
        $this->assertSame(5, (int) $data['totalRenewed']);
        $this->assertSame('person', $data['unit']);
        $this->assertSame('attend_m3', $data['window']['attendField'], '窗口字段必须是 attend_m3（近 30 天）');
        $this->assertSame(30, (int) $data['window']['windowDays']);
        $this->assertSame(0, (int) $data['unbucketed']);
    }

    public function test_attendance_renewal_curve_venue_filter_isolates_stores(): void
    {
        Sanctum::actingAs($this->superUser());
        foreach ([
            ['g1', '绿地店', 0], ['g2', '绿地店', 5],
            ['e1', '东部店', 99], ['e2', '东部店', 9],
        ] as [$name, $venue, $attend]) {
            Customer::create([
                'name' => $name, 'phone' => '138'.substr(md5($name), 0, 8), 'venue' => $venue,
                'external_id' => 'ky:'.$name, 'layer' => 'P2', 'attend_m3' => $attend,
            ]);
        }

        $all = $this->getJson('/api/analytics/attendance-renewal-curve')->assertOk()->json('data');
        $this->assertSame(4, (int) $all['totalMembers']);

        $green = $this->getJson('/api/analytics/attendance-renewal-curve?venue='.urlencode('绿地店'))
            ->assertOk()->json('data');
        $this->assertSame(2, (int) $green['totalMembers'], '绿地店看不到东部店会员');
        $greenRows = collect($green['buckets'])->keyBy('bucket');
        $this->assertSame(1, (int) $greenRows['0']['memberCount'], '绿地店只有 g1 是 0 档');
        $this->assertSame(1, (int) $greenRows['4-7']['memberCount'], '绿地店 g2 在 4-7 档');
        $this->assertSame(0, (int) $greenRows['8+']['memberCount'], '东部店的 8+ 会员不能出现');

        $east = $this->getJson('/api/analytics/attendance-renewal-curve?venue='.urlencode('东部店'))
            ->assertOk()->json('data');
        $this->assertSame(2, (int) $east['totalMembers']);
        $this->assertSame(0, (int) collect($east['buckets'])->keyBy('bucket')['0']['memberCount']);

        // 店长被锁定本店，传他店参数也不会放大范围
        Sanctum::actingAs($this->manager('绿地店'));
        Cache::flush();
        $mgr = $this->getJson('/api/analytics/attendance-renewal-curve')->assertOk()->json('data');
        $this->assertSame(2, (int) $mgr['totalMembers'], '店长只看本店');
    }

    public function test_attendance_renewal_curve_empty_database_returns_zeroed_buckets(): void
    {
        Sanctum::actingAs($this->superUser());

        $data = $this->getJson('/api/analytics/attendance-renewal-curve')->assertOk()->json('data');
        $this->assertCount(4, $data['buckets']);
        $this->assertSame(0, (int) $data['totalMembers']);
        $this->assertSame(0, (int) $data['totalRenewed']);
        $this->assertSame(0, (int) $data['overallRenewalRate']);
        foreach ($data['buckets'] as $row) {
            $this->assertSame(0, (int) $row['memberCount']);
            $this->assertSame(0, (int) $row['renewalRate'], '空档不能出现 0/0 的除零异常');
        }
    }

    // ==================== S12 未耗课余额分桶 ====================

    /** 逐卡夹具的公共形状（字段与 KyMemberSyncService::cardSummary 写 cards_list 时一致） */
    private function card(string $title, string $type, ?int $residue, ?string $deadline): array
    {
        return [
            'title' => $title, 'unit' => $type === '2' ? '天' : '节',
            'residue' => $residue, 'bound' => null, 'deadline' => $deadline,
            'status' => '正常', 'unactivated' => false, 'type' => $type, 'validDays' => 365,
        ];
    }

    public function test_asset_buckets_conserve_every_card_and_hand_expect_the_distribution(): void
    {
        Sanctum::actingAs($this->superUser());
        $today = now()->startOfDay();

        // 6 张卡，逐张手工指定期望桶（不写「Σ=Σ」这种循环恒等式，全部写死数值）
        $cards = [
            $this->card('次卡30天内', '1', 5, $today->copy()->addDays(10)->toDateString()),   // within30 | count
            $this->card('期限卡31-90', '2', 20, $today->copy()->addDays(60)->toDateString()), // 31to90  | time
            $this->card('储值卡90以上', '3', 500, $today->copy()->addDays(200)->toDateString()), // over90 | stored
            $this->card('次卡余额未知', '1', null, $today->copy()->addDays(45)->toDateString()), // 31to90 | count | 未知
            $this->card('次卡到期日未知', '1', 3, null),                                       // unknown | count
            $this->card('套餐卡其它', '4', 1, $today->copy()->addDays(5)->toDateString()),      // within30 | other
        ];
        Customer::create([
            'name' => '资产会员', 'phone' => '13700000001', 'venue' => '绿地店',
            'external_id' => 'ky:asset-1', 'layer' => 'P2', 'cards_list' => $cards,
        ]);

        $data = $this->getJson('/api/analytics/asset-buckets')->assertOk()->json('data');

        $this->assertSame(6, (int) $data['totalCards']);
        $this->assertSame('card_count', $data['unit'], '本端点单位必须是卡数，不是金额');
        $this->assertNotEmpty($data['note'], '口径说明不可省（与上游 remainingAssets 不同源）');
        $this->assertStringContainsString('remaining_assets_total', $data['note']);

        // ── 期限桶 ──
        $byDeadline = collect($data['byDeadline'])->keyBy('key');
        $this->assertSame(2, (int) $byDeadline['within30']['cardCount'], '30 天内：次卡 + 套餐卡');
        $this->assertSame(2, (int) $byDeadline['31to90']['cardCount'], '31-90：期限卡 + 余额未知次卡');
        $this->assertSame(1, (int) $byDeadline['over90']['cardCount']);
        $this->assertSame(1, (int) $byDeadline['unknown']['cardCount'], 'deadline 为 null 进「到期日未知」');

        // ── 卡种桶 ──
        $byType = collect($data['byType'])->keyBy('key');
        $this->assertSame(3, (int) $byType['count']['cardCount'], 'type=1 次卡 3 张');
        $this->assertSame(1, (int) $byType['time']['cardCount']);
        $this->assertSame(1, (int) $byType['stored']['cardCount']);
        $this->assertSame(1, (int) $byType['other']['cardCount'], 'type=4 套餐卡不能丢');

        // ── 余额状态桶：residue=null 必须是「未知」，不是「已耗尽」 ──
        $byResidue = collect($data['byResidue'])->keyBy('key');
        $this->assertSame(5, (int) $byResidue['positive']['cardCount']);
        $this->assertSame(0, (int) $byResidue['zero']['cardCount']);
        $this->assertSame(1, (int) $byResidue['unknown']['cardCount'], '余额未知单独一桶，不能被静默丢掉');

        // ── 交叉桶：逐格写死期望值 ──
        $cells = collect($data['buckets'])->keyBy(fn ($r) => $r['deadlineBucket'].'|'.$r['cardType']);
        $this->assertSame(1, (int) $cells['within30|count']['cardCount']);
        $this->assertSame(0, (int) $cells['within30|time']['cardCount']);
        $this->assertSame(1, (int) $cells['within30|other']['cardCount']);
        $this->assertSame(1, (int) $cells['31to90|count']['cardCount']);
        $this->assertSame(1, (int) $cells['31to90|time']['cardCount']);
        $this->assertSame(1, (int) $cells['over90|stored']['cardCount']);
        $this->assertSame(1, (int) $cells['unknown|count']['cardCount']);

        // ── 分类守恒（核心断言）：一张不漏、一张不重 ──
        $cellSum = array_sum(array_column($data['buckets'], 'cardCount'));
        $this->assertSame(6, $cellSum);
        $this->assertSame(6, array_sum(array_column($data['byDeadline'], 'cardCount')));
        $this->assertSame(6, array_sum(array_column($data['byType'], 'cardCount')));
        $this->assertSame(6, array_sum(array_column($data['byResidue'], 'cardCount')));
        $this->assertTrue($data['integrity']['balanced']);
        $this->assertSame($data['integrity']['totalCards'], $data['integrity']['cellSum']);
        $this->assertSame($data['integrity']['bucketsSum'], $data['integrity']['cellSum'], '别名与规范键同值');
    }

    public function test_asset_buckets_counts_expired_cards_separately_and_respects_venue(): void
    {
        Sanctum::actingAs($this->superUser());
        $today = now()->startOfDay();

        // 已过期但仍有余额：归入 ≤30 天档，并在 expiredCardsIncluded 单列（不丢、也不算成未来）
        Customer::create([
            'name' => '过期卡会员', 'phone' => '13700000002', 'venue' => '绿地店',
            'external_id' => 'ky:asset-2', 'layer' => 'P2',
            'cards_list' => [$this->card('已过期次卡', '1', 7, $today->copy()->subDays(90)->toDateString())],
        ]);
        Customer::create([
            'name' => '东部会员', 'phone' => '13700000003', 'venue' => '东部店',
            'external_id' => 'ky:asset-3', 'layer' => 'P2',
            'cards_list' => [
                $this->card('东部次卡A', '1', 1, $today->copy()->addDays(5)->toDateString()),
                $this->card('东部次卡B', '1', 1, $today->copy()->addDays(5)->toDateString()),
            ],
        ]);

        $all = $this->getJson('/api/analytics/asset-buckets')->assertOk()->json('data');
        $this->assertSame(3, (int) $all['totalCards']);
        $this->assertSame(1, (int) $all['expiredCardsIncluded'], '已过期卡单列计数，不静默丢失');

        $green = $this->getJson('/api/analytics/asset-buckets?venue='.urlencode('绿地店'))
            ->assertOk()->json('data');
        $this->assertSame(1, (int) $green['totalCards']);
        $this->assertSame(1, (int) $green['expiredCardsIncluded']);

        $east = $this->getJson('/api/analytics/asset-buckets?venue='.urlencode('东部店'))
            ->assertOk()->json('data');
        $this->assertSame(2, (int) $east['totalCards']);

        // 店长锁定本店
        Sanctum::actingAs($this->manager('东部店'));
        Cache::flush();
        $mgr = $this->getJson('/api/analytics/asset-buckets')->assertOk()->json('data');
        $this->assertSame(2, (int) $mgr['totalCards']);
    }

    public function test_asset_buckets_empty_database_returns_zeroed_buckets(): void
    {
        Sanctum::actingAs($this->superUser());

        $data = $this->getJson('/api/analytics/asset-buckets')->assertOk()->json('data');
        $this->assertSame(0, (int) $data['totalCards']);
        $this->assertTrue($data['integrity']['balanced'], '空库也必须自洽（0 === 0）');
        // 4 期限 × 4 卡种 = 16 格：卡种必须含「其它」一格，否则 type=4（上游字典里的套餐卡）
        // 会无处可去，分类守恒被破坏。空库也要给满格子。
        $this->assertCount(16, $data['buckets']);
        foreach ($data['buckets'] as $row) {
            $this->assertSame(0, (int) $row['cardCount']);
            $this->assertSame(0, (int) $row['ratio']);
        }
        // cards_list 为 NULL 的会员（老数据）不能让端点炸掉
        Customer::create([
            'name' => '无卡会员', 'phone' => '13700000009', 'venue' => '绿地店',
            'external_id' => 'ky:asset-none', 'layer' => 'P2', 'cards_list' => null,
        ]);
        Cache::flush();
        $this->assertSame(0, (int) $this->getJson('/api/analytics/asset-buckets')->assertOk()->json('data.totalCards'));
    }

    // ==================== S13 渠道四列聚合 ====================

    public function test_channels_keeps_legacy_keys_and_adds_four_metric_columns(): void
    {
        Sanctum::actingAs($this->superUser());

        // 美团：3 条留资，其中 2 条核销、1 条成交 1000 元
        $this->lead('美团客A', '美团', '2026-09-05', ['status' => '已成交', 'deal_amount' => 1000, 'deal_at' => '2026-09-06 10:00:00']);
        $this->lead('美团客B', '美团', '2026-09-05', ['redeem_amount' => 99, 'redeemed_at' => '2026-09-06 11:00:00']);
        $this->lead('美团客C', '美团', '2026-09-05');
        // 抖音：1 条留资，0 成交
        $this->lead('抖音客', '抖音', '2026-09-07', ['redeem_amount' => 59, 'redeemed_at' => '2026-09-07 12:00:00']);

        $data = $this->getJson('/api/analytics/channels?start=2026-09-01&end=2026-09-30')->assertOk()->json('data');

        // ── 向后兼容：既有键原样保留 ──
        $this->assertSame(4, (int) $data['total']);
        $this->assertArrayHasKey('rows', $data);
        $this->assertArrayHasKey('channel', $data['rows'][0]);
        $this->assertArrayHasKey('leads', $data['rows'][0]);
        // 既有排序口径：按留资数倒序 ⇒ 美团在前
        $this->assertSame('美团', $data['rows'][0]['channel']);
        $this->assertSame(3, (int) $data['rows'][0]['leads']);
        $this->assertSame('抖音', $data['rows'][1]['channel']);

        $meituan = $data['rows'][0];
        $this->assertSame(1, (int) $meituan['redeemCount'], '美团 3 条留资里只有 1 条有核销金额');
        $this->assertSame(99.0, (float) $meituan['redeemAmount']);
        $this->assertSame(33.3, (float) $meituan['redeemRate'], '核销 1 / 留资 3');
        $this->assertSame(1, (int) $meituan['deals']);
        $this->assertSame(1000.0, (float) $meituan['dealAmount']);
        $this->assertSame(33.3, (float) $meituan['dealRate'], '成交 1 / 留资 3');
        $this->assertSame(1000.0, (float) $meituan['avgDealAmount'], '客单 = 金额 ÷ 成交条数');

        $douyin = $data['rows'][1];
        $this->assertSame(0, (int) $douyin['deals']);
        $this->assertSame(0, (int) $douyin['dealRate']);
        $this->assertSame(0, (int) $douyin['avgDealAmount'], '没有成交时客单为 0，不做 0/0');
        $this->assertSame(100.0, (float) $douyin['redeemRate'], '抖音：核销 1 / 留资 1');

        // 合计行与逐行相加一致
        $this->assertSame(2, (int) $data['summary']['redeemCount'], '美团 1 + 抖音 1');
        $this->assertSame(1, (int) $data['summary']['deals']);
        $this->assertSame(1000.0, (float) $data['summary']['dealAmount']);
        $this->assertSame(25.0, (float) $data['summary']['dealRate'], '成交 1 / 留资 4');
    }

    public function test_channels_venue_filter_and_empty_database(): void
    {
        Sanctum::actingAs($this->superUser());
        $this->lead('绿地客', '美团', '2026-09-05', ['venue' => '绿地店']);
        $this->lead('东部客', '抖音', '2026-09-05', ['venue' => '东部店']);

        $green = $this->getJson('/api/analytics/channels?start=2026-09-01&end=2026-09-30&venue='.urlencode('绿地店'))
            ->assertOk()->json('data');
        $this->assertSame(1, (int) $green['total']);
        $this->assertSame('美团', $green['rows'][0]['channel'], '绿地店看不到东部店的留资');

        $east = $this->getJson('/api/analytics/channels?start=2026-09-01&end=2026-09-30&venue='.urlencode('东部店'))
            ->assertOk()->json('data');
        $this->assertSame('抖音', $east['rows'][0]['channel']);

        // 空窗口：不是空库，而是区间内没有数据
        $empty = $this->getJson('/api/analytics/channels?start=2020-01-01&end=2020-01-31')->assertOk()->json('data');
        $this->assertSame([], $empty['rows']);
        $this->assertSame(0, (int) $empty['total']);
        $this->assertSame(0, (int) $empty['summary']['avgDealAmount']);
    }

    public function test_channels_on_truly_empty_database_is_not_500(): void
    {
        Sanctum::actingAs($this->superUser());

        $data = $this->getJson('/api/analytics/channels')->assertOk()->json('data');
        $this->assertSame([], $data['rows']);
        $this->assertSame(0, (int) $data['total']);
        $this->assertSame(0, (int) $data['summary']['dealRate']);
        $this->assertSame(0, (int) $data['summary']['redeemRate']);
    }

    // ==================== S14 体验卡 → 会员卡转化率 ====================

    public function test_trial_conversion_groups_by_service_teacher_and_ranks_by_rate(): void
    {
        Sanctum::actingAs($this->superUser());

        // 李老师：2 条留资、3 张体验卡、其中 2 张 attended（同手机号已成会员）
        $this->lead('李的客A', '', '2026-09-01', [
            'venue' => '绿地店', 'service_teacher' => '李老师', 'phone' => '13600000001',
            'trial_cards' => [
                ['date' => '2026-09-02', 'topic' => '内观流', 'teacher' => '王教练', 'attended' => true],
                ['date' => '2026-09-03', 'topic' => '核心床', 'attended' => true],
            ],
        ]);
        // 历史卡片缺 session 键：靠 Lead::trialCards() 访问器兼容，不能被丢掉
        $this->lead('李的客B', '', '2026-09-01', [
            'venue' => '绿地店', 'service_teacher' => '李老师', 'phone' => '13600000002',
            'trial_cards' => [['date' => '2026-09-04', 'attended' => true]],
        ]);
        // 王老师：1 张体验卡，attended 但手机号不在 customers ⇒ 未转化
        $this->lead('王的客', '', '2026-09-01', [
            'venue' => '绿地店', 'service_teacher' => '王老师', 'phone' => '13600000003',
            'trial_cards' => [['date' => '2026-09-05', 'attended' => true]],
        ]);
        // 未归属留资：进「未分配」档，不丢
        $this->lead('没归属的客', '', '2026-09-01', [
            'venue' => '绿地店', 'service_teacher' => '', 'phone' => '13600000004',
            'trial_cards' => [['date' => '2026-09-06', 'attended' => false]],
        ]);

        // 会员：A、B 是正式会员；C 是 P5 客资（存在但不算转化）；D 是带分隔符的手机号也要能匹配
        Customer::create(['name' => '李客A', 'phone' => '13600000001', 'venue' => '绿地店', 'external_id' => 'ky:tc-a', 'layer' => 'P2']);
        Customer::create(['name' => '李客B', 'phone' => '13600000002', 'venue' => '绿地店', 'external_id' => 'ky:tc-b', 'layer' => 'P1']);
        Customer::create(['name' => '王的客', 'phone' => '13600000003', 'venue' => '绿地店', 'external_id' => 'local-c', 'layer' => 'P5']);

        $data = $this->getJson('/api/analytics/trial-conversion')->assertOk()->json('data');
        $rows = collect($data['rows'])->keyBy('teacher');

        $this->assertSame(5, (int) $data['totalTrialCards'], '分母合计 = 全部体验卡张数，一张不漏');
        // 已转化 = 李A 2 张 + 李B 1 张 = 3；王老师那张手机号存在但是 P5 ⇒ 不算；未归属那张 attended=false ⇒ 不算
        $this->assertSame(3, (int) $data['totalConvertedCards']);

        $li = $rows['李老师'];
        $this->assertSame(2, (int) $li['leads']);
        $this->assertSame(3, (int) $li['trialCards']);
        $this->assertSame(3, (int) $li['attendedCards']);
        $this->assertSame(3, (int) $li['convertedCards']);
        $this->assertSame(100.0, (float) $li['conversionRateByCard']);
        // 人口径 = 主指标：2 人有体验卡、2 人已转化
        $this->assertSame(2, (int) $li['trialPeople']);
        $this->assertSame(2, (int) $li['convertedPeople']);
        $this->assertSame(100.0, (float) $li['conversionRate'], '主指标按人算');
        $this->assertSame(100.0, (float) $li['conversionRateByPerson'], '同值别名');

        $wang = $rows['王老师'];
        $this->assertSame(1, (int) $wang['trialCards']);
        $this->assertSame(0, (int) $wang['convertedCards'], 'P5 客资不算已转化会员');
        $this->assertSame(1, (int) $wang['trialPeople']);
        $this->assertSame(0, (int) $wang['convertedPeople']);
        $this->assertSame(0, (int) $wang['conversionRate']);

        $unassigned = $rows['未分配'];
        $this->assertSame(1, (int) $unassigned['trialCards'], '未归属不能静默丢弃');
        $this->assertSame(0, (int) $unassigned['convertedCards'], 'attended=false 不算转化');
        $this->assertSame(1, (int) $unassigned['trialPeople'], '有体验卡就算进人口径分母');

        // 整体值按手机号全局去重：4 个人有体验卡、2 个人已转化
        $this->assertSame(4, (int) $data['totalTrialPeople']);
        $this->assertSame(2, (int) $data['totalConvertedPeople']);
        $this->assertSame(50.0, (float) $data['overallConversionRate'], '整体主指标按人算');
        $this->assertSame('person', $data['unit']);

        // 排行：主指标（人口径）倒序（100% 的李老师在前）
        $this->assertSame('李老师', $data['rows'][0]['teacher']);
    }

    /**
     * 「一人多节体验课」时，按人与按卡必须给出**不同**的答案 —— 这正是队长裁定
     * 主指标按人的理由（按卡会被一人多课放大，转化率虚高）。
     */
    public function test_trial_conversion_by_person_differs_from_card_metric_when_one_person_has_many_trials(): void
    {
        Sanctum::actingAs($this->superUser());
        // 同一人上 4 节体验课，只有 1 节 attended，且已是会员
        $this->lead('多节体验客', '', '2026-09-01', [
            'venue' => '绿地店', 'service_teacher' => '赵老师', 'phone' => '13300000001',
            'trial_cards' => [
                ['date' => '2026-09-02', 'attended' => true],
                ['date' => '2026-09-03', 'attended' => false],
                ['date' => '2026-09-04', 'attended' => false],
                ['date' => '2026-09-05', 'attended' => false],
            ],
        ]);
        Customer::create([
            'name' => '多节体验客', 'phone' => '13300000001', 'venue' => '绿地店',
            'external_id' => 'ky:tc-many', 'layer' => 'P2',
        ]);

        $row = $this->getJson('/api/analytics/trial-conversion')->assertOk()->json('data.rows.0');

        $this->assertSame(4, (int) $row['trialCards']);
        $this->assertSame(1, (int) $row['trialPeople'], '同一个人只算一次');
        $this->assertSame(1, (int) $row['convertedPeople']);
        // 按人：1/1 = 100%；按卡：1/4 = 25% —— 两者必须不同，否则这组夹具没有区分力
        $this->assertSame(100.0, (float) $row['conversionRate'], '主指标按人');
        $this->assertSame(25.0, (float) $row['conversionRateByCard'], '卡口径仅作参考');
        $this->assertNotSame($row['conversionRate'], $row['conversionRateByCard']);
    }

    public function test_trial_conversion_respects_venue_scope(): void
    {
        Sanctum::actingAs($this->superUser());
        $this->lead('绿地体验客', '', '2026-09-01', [
            'venue' => '绿地店', 'service_teacher' => '绿地老师', 'phone' => '13500000001',
            'trial_cards' => [['date' => '2026-09-02', 'attended' => true]],
        ]);
        $this->lead('东部体验客', '', '2026-09-01', [
            'venue' => '东部店', 'service_teacher' => '东部老师', 'phone' => '13500000002',
            'trial_cards' => [['date' => '2026-09-02', 'attended' => true]],
        ]);

        $green = $this->getJson('/api/analytics/trial-conversion?venue='.urlencode('绿地店'))
            ->assertOk()->json('data');
        $this->assertSame(1, (int) $green['totalTrialCards']);
        $this->assertCount(1, $green['rows']);
        $this->assertSame('绿地老师', $green['rows'][0]['teacher']);

        $east = $this->getJson('/api/analytics/trial-conversion?venue='.urlencode('东部店'))
            ->assertOk()->json('data');
        $this->assertSame('东部老师', $east['rows'][0]['teacher']);
    }

    public function test_trial_conversion_empty_database_returns_empty_rows(): void
    {
        Sanctum::actingAs($this->superUser());

        $data = $this->getJson('/api/analytics/trial-conversion')->assertOk()->json('data');
        $this->assertSame([], $data['rows']);
        $this->assertSame(0, (int) $data['totalTrialCards']);
        $this->assertSame(0, (int) $data['overallConversionRate']);
        $this->assertSame(0, (int) $data['memberPhones']);
    }

    // ==================== 权限与参数校验 ====================

    public function test_all_new_endpoints_require_authentication(): void
    {
        foreach ([
            '/api/analytics/attendance-renewal-curve',
            '/api/analytics/asset-buckets',
            '/api/analytics/trial-conversion',
        ] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }

    public function test_new_endpoints_reject_invalid_venue_parameter(): void
    {
        Sanctum::actingAs($this->superUser());
        foreach ([
            '/api/analytics/attendance-renewal-curve?venue='.urlencode('不存在的店'),
            '/api/analytics/asset-buckets?venue='.urlencode('不存在的店'),
            '/api/analytics/trial-conversion?venue='.urlencode('不存在的店'),
        ] as $url) {
            $this->getJson($url)->assertStatus(422);
        }
    }

    /** 留资夹具 */
    private function lead(string $name, string $source, string $date, array $extra = []): Lead
    {
        return Lead::create(array_merge([
            'lead_date' => $date,
            'name' => $name,
            'phone' => $extra['phone'] ?? '134'.substr(md5($name), 0, 8),
            'venue' => '绿地店',
            'source' => $source,
            'order_platform' => $extra['order_platform'] ?? $source,
            'status' => '新留资',
        ], $extra));
    }
}
