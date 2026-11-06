<?php

namespace Tests\Feature;

use App\Models\KyCard;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B3：`revenueMix` / `cardQ` 之外**剩余 4 组金额字段**的收敛（t22）。
 *
 * 背景：馆主拍板「提成按销售金额计算」，所以本店售卡实收、核销金额、客单价都属于薪酬侧信道。
 * B2 只收了 `summary.revenueMix` 与 `trends` 的售卡列，剩余四处仍然裸奔：
 *  1. `trends.summary` 的 dealAmount / redeemAmount / registeredDealAmount，以及 `daily[*].amount|redeem`；
 *  2. `trends.summary.mediaPerformance` 的 visitRewardAmount / commissionAmount，
 *     以及 `formula.visitReward`「× 20 元」、`formula.commission`「× 时效内核销金额」——
 *     这两句**直接写着提成公式**，比数字更敏感；
 *  3. `channels` 的 dealAmount / avgDealAmount / redeemAmount（客单价 × 成交条数 = 营收）；
 *  4. `platforms` 的 deal / redeem / totalDeal / totalRedeem。
 *
 * 本文件钉住两件事：老师侧金额全 0 但**计数照旧**（那是工作抓手），管理层数字不变（功能不能被关掉）。
 * 键名与结构一律不变 —— 断言里逐项 `assertArrayHasKey`，删键/改名都会红。
 */
class AnalyticsMoneyResidualTest extends TestCase
{
    use RefreshDatabase;

    private const CARD_PRICE = 5600.0;
    private const DEAL_AMOUNT = 5000.0;
    private const REDEEM_AMOUNT = 300.0;

    // ====================================================== 1. 老师：金额全 0

    public function test_teacher_gets_zero_money_but_keeps_counts_on_all_four_endpoints(): void
    {
        $this->seedMoneyWorld();
        $teacher = $this->user('b3-teacher', 'B3服务老师', 'R_SERVICE', '绿地店');
        Sanctum::actingAs(User::findOrFail($teacher->id));
        $today = now()->toDateString();

        // ---- ① trends ----
        $trends = $this->getJson("/api/analytics/trends?start={$today}&end={$today}")->assertOk()->json('data');

        foreach (['dealAmount', 'redeemAmount', 'registeredDealAmount', 'cardSalesCount', 'dealCount', 'leadCount'] as $key) {
            $this->assertArrayHasKey($key, $trends['summary'], "trends.summary.{$key} 键不得删");
        }
        $this->assertSame(0.0, (float) $trends['summary']['dealAmount'], '本店售卡实收必须归 0');
        $this->assertSame(0.0, (float) $trends['summary']['redeemAmount'], '核销金额必须归 0');
        $this->assertSame(0.0, (float) $trends['summary']['registeredDealAmount'], '留资登记成交额必须归 0');
        // 计数保留：这才是老师的工作抓手
        $this->assertSame(1, (int) $trends['summary']['registeredDealCount'], '成交条数必须保留');
        $this->assertGreaterThanOrEqual(1, (int) $trends['summary']['leadCount'], '留资条数必须保留');

        $this->assertNotEmpty($trends['daily'], 'daily 不得为空（否则本段断言空转）');
        foreach ($trends['daily'] as $row) {
            foreach ($row as $venue => $cell) {
                if ($venue === 'date') {
                    continue;
                }
                $this->assertArrayHasKey('amount', $cell);
                $this->assertArrayHasKey('redeem', $cell);
                $this->assertSame(0, (int) $cell['amount'], 'daily.amount 必须归 0');
                $this->assertSame(0, (int) $cell['redeem'], 'daily.redeem 必须归 0');
            }
        }

        // ---- ② trends.summary.mediaPerformance ----
        $mp = $trends['summary']['mediaPerformance'];
        $this->assertSame(0.0, (float) $mp['params']['visitReward'], '单人奖励单价必须归 0（它本身就是提成参数）');
        $this->assertSame(0.0, (float) $mp['visitRewardAmount'], '到店奖励金额必须归 0');
        $this->assertSame(0.0, (float) $mp['commissionAmount'], '核销提成金额必须归 0');
        $this->assertSame(0.0, (float) $mp['breakdown']['validRedeemAmount'], '时效内核销金额必须归 0');
        $this->assertSame(0.0, (float) $mp['breakdown']['excludedRedeemAmount'], '被排除的核销金额必须归 0');
        // 计数保留 + 文案去掉金额
        $this->assertArrayHasKey('validVisitCount', $mp['breakdown'], '有效到店人数必须保留（计数）');
        $this->assertStringNotContainsString('元', (string) $mp['formula']['visitReward'], '到店奖励公式不得留金额/单价');
        $this->assertStringNotContainsString('核销金额', (string) $mp['formula']['commission'], '提成公式不得留金额口径');
        $this->assertStringContainsString('成交率', (string) $mp['formula']['commission'], '提成公式应退化为纯成交率描述');

        // ---- ③ channels ----
        $channels = $this->getJson("/api/analytics/channels?start={$today}&end={$today}")->assertOk()->json('data');
        $this->assertNotEmpty($channels['rows'], 'channels 行不得为空');
        $row = $this->firstWhereKey($channels['rows'], 'channel', '美团');
        $this->assertNotNull($row, '夹具里的美团渠道必须出现');
        foreach (['dealAmount', 'avgDealAmount', 'redeemAmount'] as $key) {
            $this->assertArrayHasKey($key, $row);
            $this->assertSame(0, (int) $row[$key], "channels.rows[].{$key} 必须归 0");
            $this->assertArrayHasKey($key, $channels['summary']);
            $this->assertSame(0, (int) $channels['summary'][$key], "channels.summary.{$key} 必须归 0");
        }
        // 计数保留
        $this->assertSame(2, (int) $row['leads'], '该渠道留资数必须保留');
        $this->assertSame(1, (int) $row['deals'], '该渠道成交条数必须保留');
        $this->assertSame(1, (int) $row['redeemCount'], '该渠道核销条数必须保留');
        $this->assertSame(50.0, (float) $row['dealRate'], '成交率是比率，必须保留（1/2）');
        $this->assertSame(50.0, (float) $row['redeemRate'], '核销率必须保留（1/2）');

        // ---- ④ platforms ----
        $platforms = $this->getJson("/api/analytics/platforms?start={$today}&end={$today}")->assertOk()->json('data');
        $this->assertNotEmpty($platforms['rows'], 'platforms 行不得为空');
        $prow = $this->firstWhereKey($platforms['rows'], 'platform', '美团');
        $this->assertNotNull($prow, '夹具里的美团平台必须出现');
        $this->assertSame(0, (int) $prow['deal'], 'platforms.rows[].deal 必须归 0');
        $this->assertSame(0, (int) $prow['redeem'], 'platforms.rows[].redeem 必须归 0');
        $this->assertSame(0, (int) $platforms['totalDeal'], 'totalDeal 必须归 0');
        $this->assertSame(0, (int) $platforms['totalRedeem'], 'totalRedeem 必须归 0');
        $this->assertSame(2, (int) $prow['leads'], '平台留资数必须保留');
        $this->assertSame(1, (int) $platforms['dealCount'], '成交条数必须保留');
    }

    // ==================================================== 2. 管理层：金额非 0

    public function test_management_sees_the_same_fields_with_real_amounts(): void
    {
        $this->seedMoneyWorld();
        $today = now()->toDateString();

        foreach ([
            'super' => ['R_SUPER', null],
            'manager' => ['R_MANAGER', '绿地店'],
        ] as $label => [$role, $venue]) {
            $u = $this->user("b3-{$label}", "B3{$label}", $role, $venue);
            Sanctum::actingAs(User::findOrFail($u->id));

            // ① trends
            $trends = $this->getJson("/api/analytics/trends?start={$today}&end={$today}")->assertOk()->json('data');
            $this->assertSame(self::CARD_PRICE, (float) $trends['summary']['dealAmount'], "{$label}: 售卡实收必须是真值");
            $this->assertSame(self::REDEEM_AMOUNT, (float) $trends['summary']['redeemAmount'], "{$label}: 核销金额必须是真值");
            $this->assertSame(self::DEAL_AMOUNT, (float) $trends['summary']['registeredDealAmount'], "{$label}: 登记成交额必须是真值");

            // ② mediaPerformance：单价 20 + 公式里带「元」（证明判据是按角色分支，不是把功能关掉）
            $mp = $trends['summary']['mediaPerformance'];
            $this->assertSame(20.0, (float) $mp['params']['visitReward'], "{$label}: 奖励单价必须是真值");
            $this->assertStringContainsString('元', (string) $mp['formula']['visitReward'], "{$label}: 管理层公式应保留金额口径");

            // ③ channels
            $channels = $this->getJson("/api/analytics/channels?start={$today}&end={$today}")->assertOk()->json('data');
            $row = $this->firstWhereKey($channels['rows'], 'channel', '美团');
            $this->assertSame(self::DEAL_AMOUNT, (float) $row['dealAmount'], "{$label}: 渠道成交金额必须是真值");
            $this->assertSame(self::DEAL_AMOUNT, (float) $row['avgDealAmount'], "{$label}: 客单价必须是真值（1 条成交）");
            $this->assertSame(self::REDEEM_AMOUNT, (float) $row['redeemAmount'], "{$label}: 渠道核销金额必须是真值");

            // ④ platforms
            $platforms = $this->getJson("/api/analytics/platforms?start={$today}&end={$today}")->assertOk()->json('data');
            $prow = $this->firstWhereKey($platforms['rows'], 'platform', '美团');
            $this->assertSame(self::DEAL_AMOUNT, (float) $prow['deal'], "{$label}: 平台成交金额必须是真值");
            $this->assertSame(self::REDEEM_AMOUNT, (float) $prow['redeem'], "{$label}: 平台核销金额必须是真值");
            $this->assertSame(self::DEAL_AMOUNT, (float) $platforms['totalDeal'], "{$label}: totalDeal 必须是真值");
            $this->assertSame(self::REDEEM_AMOUNT, (float) $platforms['totalRedeem'], "{$label}: totalRedeem 必须是真值");
        }
    }

    /** 结构不变：4 个接口的键集合在两种角色下完全一致（只变值，前端零改动）。 */
    public function test_key_sets_are_identical_for_both_roles(): void
    {
        $this->seedMoneyWorld();
        $today = now()->toDateString();
        $urls = [
            "/api/analytics/trends?start={$today}&end={$today}",
            "/api/analytics/channels?start={$today}&end={$today}",
            "/api/analytics/platforms?start={$today}&end={$today}",
        ];

        $collect = function (string $url): array {
            $data = $this->getJson($url)->assertOk()->json('data');

            return [
                'top' => array_keys($data),
                'summary' => isset($data['summary']) ? array_keys($data['summary']) : [],
                'row' => isset($data['rows'][0]) ? array_keys($data['rows'][0]) : [],
                'media' => isset($data['summary']['mediaPerformance'])
                    ? [
                        'keys' => array_keys($data['summary']['mediaPerformance']),
                        'params' => array_keys($data['summary']['mediaPerformance']['params']),
                        'breakdown' => array_keys($data['summary']['mediaPerformance']['breakdown']),
                        'formula' => array_keys($data['summary']['mediaPerformance']['formula']),
                    ]
                    : [],
            ];
        };

        Sanctum::actingAs(User::findOrFail($this->user('b3-ks-teacher', 'B3键位老师', 'R_SERVICE', '绿地店')->id));
        $teacher = array_map($collect, $urls);

        Sanctum::actingAs(User::findOrFail($this->user('b3-ks-super', 'B3键位超管', 'R_SUPER', null)->id));
        $super = array_map($collect, $urls);

        foreach ($urls as $i => $url) {
            $this->assertSame($super[$i]['top'], $teacher[$i]['top'], "{$url}: 顶层键集合必须一致");
            $this->assertSame($super[$i]['summary'], $teacher[$i]['summary'], "{$url}: summary 键集合必须一致");
            $this->assertSame($super[$i]['row'], $teacher[$i]['row'], "{$url}: rows[].键集合必须一致");
            $this->assertSame($super[$i]['media'], $teacher[$i]['media'], "{$url}: mediaPerformance 嵌套键集合必须一致");
        }
    }

    // ------------------------------------------------------------- 数据准备

    /**
     * 一位「已成交 + 有核销」的线上留资 + 一条普通留资 + 一张当天售卡。
     * 数字刻意固定（5000 / 300 / 5600），断言里只差角色。
     */
    private function seedMoneyWorld(): void
    {
        KyCard::create([
            'source_key' => 'b3-card-1',
            'venue' => '绿地店',
            'external_id' => 'b3-ext-1',
            'card_title' => '私教50次',
            'member_id' => '880301',
            'member_name' => 'B3会员',
            'phone' => '13900000301',
            'deal_price' => self::CARD_PRICE,
            'price' => self::CARD_PRICE,
            'status' => '正常',
            'status_format' => '正常',
            'is_taste' => false,
            'sold_at' => now()->toDateString(),
        ]);

        Lead::create([
            'lead_date' => now()->toDateString(), 'name' => 'B3成交',
            'phone' => '13900000302', 'demand' => '私教', 'source' => '美团',
            'order_platform' => '美团', 'venue' => '绿地店', 'service_teacher' => 'B3服务老师',
            'status' => '已成交', 'deal_amount' => self::DEAL_AMOUNT,
            'deal_at' => now(), 'redeem_amount' => self::REDEEM_AMOUNT, 'redeemed_at' => now(),
        ]);

        Lead::create([
            'lead_date' => now()->toDateString(), 'name' => 'B3留资',
            'phone' => '13900000303', 'demand' => '体验课', 'source' => '美团',
            'order_platform' => '美团', 'venue' => '绿地店', 'service_teacher' => 'B3服务老师',
            'status' => '新留资',
        ]);
    }

    /** @param  array<int,array<string,mixed>>  $rows */
    private function firstWhereKey(array $rows, string $key, string $value): ?array
    {
        foreach ($rows as $row) {
            if ((string) ($row[$key] ?? '') === $value) {
                return $row;
            }
        }

        return null;
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
