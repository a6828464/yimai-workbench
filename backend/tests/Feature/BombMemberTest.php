<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\KyMemberSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 「炸弹会员」清单（v3.3.4 / t11）。
 *
 * 语义：**钱收了、课没上、卡过期很久、人也很久没来**。五条口径必须同时成立：
 *   1. 正式会员 —— 沿用既有 `scopeMemberCustomers()` 谓词，**不得**新写第二份判定
 *   2. 持有次卡（`type='1'`，排除体验卡/员工卡/测试卡）
 *   3. 该卡有余额（`residue > 0`）
 *   4. 该卡已过期且**过期天数严格大于 183 天**（deadline 早于「今天 − 183 天」）
 *   5. **超过 183 天没有出勤记录**（2026-09-25 用户补充口径）：
 *      `last_visit` 为 null（从未到店）视为满足；有 `last_visit` 但距今
 *      未超过 183 天的排除（近 6 个月来过的人不是炸弹）
 *
 * ─────────────────────────────────────────────────────────────────────────
 * ⚠️ 边界必须用**严格不等号**（captain 已冻结该取法，请勿「修正」）：
 *   严格 `days > 183` ⇒ 302 人 / **393** 卡 / **2509** 节   ← 基线
 *   含等号 `days >= 183` ⇒ 302 人 / 394 卡 / 2512 节
 * 差异就是「恰好过期 183 天」的那一张卡（到期 2026-03-25 · 精品小班 · 余3节 · status=6）。
 * **不要**改成 `>=`，那会让卡数从 393 变 394，并被误判为 bug。
 * ─────────────────────────────────────────────────────────────────────────
 *
 * 判定唯一口径来源是后端 `helpers.php`（`customerDecision()` 的 `$bomb` +
 * `computeMemberLists()` 的 `lists['炸弹会员']`）；前端只消费 `memberListIds`。
 * 夹具为**脱敏最小数据**（无手机号/身份证/银行卡）。
 */
class BombMemberTest extends TestCase
{
    use RefreshDatabase;

    /** 冻结基线（captain 实测，严格 > 183） */
    private const BASELINE_MEMBERS = 302;
    private const BASELINE_CARDS = 393;
    private const BASELINE_SECTIONS = 2509;

    /** 严格「超过」6 个月：deadline 必须早于「今天 − 183 天」 */
    private const SIX_MONTHS_DAYS = 183;

    // ─────────────────────────────────────────────────────────── 夹具

    /**
     * 生成一个「经 `KyMemberSyncService::date()` 归一化后恰好等于 $days 天前」的上游时间戳。
     *
     * `deadline` 在上游**是 Unix 时间戳**（不是日期字符串，`deadline_format` 才可读），
     * 而 `date()` 用 `createFromTimestamp()`（**UTC** 语义）解释 ——
     * 直接传上海时区当日 00:00 会得到**前一天**，把 183 天测成 184 天。
     * 取目标日的 UTC 12:00（= 上海 20:00，同日）即可稳定还原该日期。
     */
    private function tsForDaysAgo(int $days): string
    {
        $date = CarbonImmutable::today()->subDays($days)->toDateString();

        return (string) CarbonImmutable::parse($date.' 12:00:00', 'UTC')->timestamp;
    }

    /** 上游卡项原始行 */
    private function card(array $overrides = []): array
    {
        return array_merge([
            'card_title' => '定制私教30节',
            'status' => '6',                          // 6 = 过期
            'type' => '1',                            // 1 = 次卡（单位=节）
            'is_taste' => '0',
            'residue_amount' => '12',
            'usage_total' => '8400.00',
            'curr_unit_cash_value' => '466.6666666667',
            'consume_amount_format' => '18次',
            'deal_price' => '13999',
            'expiry_days' => '0',
            'deadline' => $this->tsForDaysAgo(400),   // 超过 6 个月
        ], $overrides);
    }

    private function summarize(array $cards): array
    {
        return (new \ReflectionMethod(KyMemberSyncService::class, 'summarizeCards'))->invoke(null, $cards);
    }

    /**
     * 建会员：卡项经**真实** `summarizeCards` 聚合
     * （测的是「同步怎么算 + 判定怎么判」整条链，不是手写 card_stats）。
     *
     * `$layer`/`$externalId` 决定它是不是「正式会员」：
     * `scopeMemberCustomers()` = `layer != 'P5' OR external_id like 'ky:%'`。
     *
     * `$lastVisit`：默认 400 天前（满足第 5 条「超 6 个月无出勤」）；
     * 传 'never' 表示「从未到店」（last_visit 落库为 null）；
     * 传其他日期字符串表示「近期/边界来过」。
     */
    private function member(
        string $name,
        array $cards,
        string $layer = 'P4',
        ?string $externalId = null,
        ?string $lastVisit = null
    ): Customer {
        $sum = $this->summarize($cards);

        return Customer::create([
            'name' => $name,
            'phone' => '1380000'.random_int(1000, 9999),
            'phone_tail' => '0000',
            'venue' => '绿地店',
            'source' => 'KeepYoga',
            'owner' => '店长',
            'consultant' => '店长',
            'status' => '在籍',
            'layer' => $layer,
            'external_id' => $externalId ?? 'ky:bomb-'.$name,
            'main_card' => $sum['main_card'],
            'remain_times' => $sum['remain_times'],
            'expire_date' => $sum['expire_date'],
            'card_stats' => $sum['card_stats'],
            'cards_list' => $sum['cards_list'],
            'attend_m1' => 6, 'attend_m2' => 6, 'attend_m3' => 6,
            // 默认 400 天没来：满足第 5 条口径（超过 183 天无出勤）。
            // 旧值 subDays(3) 在加入出勤口径后会让所有卡项用例的人都不再命中炸弹。
            // 'never' 哨兵 ⇒ last_visit 落库 null（从未到店）——不能直接用 null 传参：
            // ?? 兜底会把显式传的 null 一起吞掉，「从未到店」语义就测不到了。
            'last_visit' => $lastVisit === 'never'
                ? null
                : ($lastVisit ?? now()->subDays(400)->toDateString()),
            'card_paid_amount' => 0,
            'in_revive' => 0,
        ]);
    }

    private function rules(): array
    {
        return [
            'renewalThreshold' => 10, 'renewalCountPercent' => 15,
            'renewalExpireDays' => 30, 'renewalExpirePercent' => 0,
            'vipAmountThreshold' => 30000, 'declineMode' => 'strict',
            'predropMin' => 15, 'predropMax' => 30, 'reviveDays' => 30,
            'renewalExpiredBackfillDays' => 90,
        ];
    }

    /** 炸弹清单 id（与接口同源） */
    private function bombIds(): array
    {
        return memberListIds()['炸弹会员'] ?? [];
    }

    // ─────────────────────────────────────── 1. 清单存在 + 四条口径成立

    public function test_member_list_ids_exposes_bomb_key(): void
    {
        $lists = memberListIds();
        $this->assertArrayHasKey('炸弹会员', $lists, '炸弹会员必须是一个可筛选的清单键');
        $this->assertIsArray($lists['炸弹会员']);
    }

    public function test_member_list_watch_exposes_bomb_detail(): void
    {
        $watch = memberListWatch();
        $this->assertArrayHasKey('炸弹会员', $watch, '炸弹会员必须有 watch 明细（why/sections/cards）');
    }

    /** 四条同时成立 ⇒ 命中 */
    public function test_member_meeting_all_four_conditions_is_a_bomb(): void
    {
        $c = $this->member('四条件会员', [$this->card()]);

        $this->assertContains($c->id, $this->bombIds(), '正式会员+次卡有余额+过期超6个月 必须命中');
    }

    /** 口径 3 反向：余额为 0 的过期卡不算（钱收了但课上完了，不是炸弹） */
    public function test_expired_card_with_zero_residue_is_not_a_bomb(): void
    {
        $c = $this->member('零余额过期卡', [$this->card(['residue_amount' => '0'])]);

        $this->assertNotContains($c->id, $this->bombIds(), 'residue=0 表示课已上完，不是沉睡资产');
    }

    /** 口径 2 反向：时间卡（type=2，单位=天）不算 —— 混入会把「节」算成别的量纲 */
    public function test_time_card_is_not_counted_as_bomb(): void
    {
        $c = $this->member('过期时间卡', [$this->card(['type' => '2', 'residue_amount' => '88'])]);

        $this->assertNotContains($c->id, $this->bombIds(), 'type≠1 的单位不是「节」，不得计入沉睡节数');
    }

    /** 口径 2 反向：体验卡/员工卡/测试卡排除 */
    public function test_taste_and_staff_cards_are_excluded(): void
    {
        foreach ([
            '体验卡' => ['is_taste' => '1'],
            '员工卡' => ['card_title' => '员工福利私教30节'],
            '测试卡' => ['card_title' => '测试专用次卡'],
        ] as $label => $override) {
            $c = $this->member('排除'.$label, [$this->card($override)]);
            $this->assertNotContains($c->id, $this->bombIds(), "{$label} 必须从炸弹清单排除");
        }
    }

    /** 口径 4 反向：尚未过期（远期到期）不算 */
    public function test_not_yet_expired_card_is_not_a_bomb(): void
    {
        $c = $this->member('未过期卡', [$this->card(['deadline' => $this->tsForDaysAgo(-30)])]);

        $this->assertNotContains($c->id, $this->bombIds(), '还没过期的卡不是炸弹');
    }

    // ─────────────────────────────────────── 2. 边界（必须严格 > 183）

    /**
     * 边界：恰好 183 天**不算**、184 天**算**。
     *
     * 断言按**严格 `> 183`** 写死。captain 已用真实样本冻结该取法：
     * 严格 ⇒ 393 卡/2509 节；含等号 ⇒ 394/2512。
     * 本用例是防「后人把它改成 >=」的闸门 —— 一旦改成 `>=`，这里立刻红。
     */
    public function test_boundary_is_strictly_greater_than_183_days(): void
    {
        $exact183 = $this->member('恰好183天', [$this->card(['deadline' => $this->tsForDaysAgo(183)])]);
        $day184 = $this->member('过期184天', [$this->card(['deadline' => $this->tsForDaysAgo(184)])]);
        $ids = $this->bombIds();

        $this->assertNotContains($exact183->id, $ids, '恰好 183 天 = 未「超过」6 个月，必须排除（严格 >）');
        $this->assertContains($day184->id, $ids, '184 天必须命中（说明门限不是被设成了 184 天）');
    }

    /** 刚过期（100 天）不算：保留可查 ≠ 立刻算炸弹 */
    public function test_recent_expiry_is_retained_but_not_a_bomb(): void
    {
        $c = $this->member('近期过期', [$this->card(['deadline' => $this->tsForDaysAgo(100)])]);

        $this->assertNotEmpty($c->fresh()->card_stats['expiredCards'] ?? [], '过期卡数据仍须保留可查');
        $this->assertNotContains($c->id, $this->bombIds(), '只过期 100 天（<183）不算炸弹');
    }

    // ─────────────────────────────────────── 2.5 第 5 条口径：超 6 个月无出勤

    /**
     * 口径 5（2026-09-25 用户补充）：近 30 天有出勤记录的**不是**炸弹。
     *
     * 卡项口径（次卡+余额+过期超 183 天）全部命中也不行 ——
     * 人来过就说明还在练，不是「钱收了、课没上、人没来」。
     */
    public function test_recent_visitor_is_not_a_bomb(): void
    {
        $c = $this->member('近期到店', [$this->card()], lastVisit: now()->subDays(30)->toDateString());

        $this->assertNotContains($c->id, $this->bombIds(), '30 天前来过的人不是炸弹（有出勤记录的不能放炸弹清单）');
    }

    /** 口径 5：last_visit 为 null（从未到店）也算——比「183 天前来过」更久没来 */
    public function test_never_visited_member_is_a_bomb(): void
    {
        $c = $this->member('从未到店', [$this->card()], lastVisit: 'never');

        // 双保险：先证明 last_visit 确实落库 null（防止哨兵失效让用例静默退化），
        // 再断言清单命中。若夹具退回 ?? 兜底吞掉 null，第一条断言就会红。
        $this->assertNull($c->fresh()->last_visit, '哨兵验证：从未到店的会员 last_visit 必须是 null');
        $this->assertContains($c->id, $this->bombIds(), '从未到店的人满足「超过 6 个月没有出勤记录」');
    }

    /**
     * 口径 5 边界：与卡过期口径同用严格不等号。
     * 恰好 183 天前来过 → 不算（未「超过」6 个月）；184 天前 → 算。
     */
    public function test_visit_boundary_is_strictly_greater_than_183_days(): void
    {
        $exact183 = $this->member(
            '恰好183天到店',
            [$this->card()],
            lastVisit: now()->subDays(183)->toDateString()
        );
        $day184 = $this->member(
            '184天前到店',
            [$this->card()],
            lastVisit: now()->subDays(184)->toDateString()
        );
        $ids = $this->bombIds();

        $this->assertNotContains($exact183->id, $ids, '183 天前来过 = 未「超过」6 个月，必须排除（严格 >）');
        $this->assertContains($day184->id, $ids, '184 天前到店且卡过期超 6 个月必须命中');
    }

    /** 口径 5 反向：近期来过（3 天前）即便卡过期超 6 个月也不算 */
    public function test_member_who_visited_3_days_ago_is_not_a_bomb(): void
    {
        $c = $this->member('三天前到店', [$this->card()], lastVisit: now()->subDays(3)->toDateString());

        $this->assertNotContains($c->id, $this->bombIds(), '3 天前来过的人绝不能进炸弹清单');
    }

    // ─────────────────────────────────────── 3. 正式会员边界（口径 1）

    /**
     * 「正式会员」边界可证（t11 验收第 5 条）。
     *
     * `scopeMemberCustomers()` = `layer != 'P5' OR external_id like 'ky:%'`。
     * 两件事必须同时成立：
     *  - **纯客资**（layer=P5 且非 ky:）即便名下有沉睡过期卡也不进清单
     *  - **卡项全部过期的正式会员会落 P5**，但因为是 ky: 同步来的，**仍须命中**
     *    （只写 `layer != 'P5'` 会把这类人整批漏掉 —— 仓库内已有 4 处注释警告过这点）
     */
    public function test_member_predicate_boundary_is_provable(): void
    {
        $lead = $this->member('纯客资', [$this->card()], layer: 'P5', externalId: 'lead:12345');
        $expiredFormal = $this->member('全过期正式会员', [$this->card()], layer: 'P5', externalId: 'ky:88771');
        $ids = $this->bombIds();

        $this->assertNotContains($lead->id, $ids,
            '纯客资（layer=P5 且非 ky:）不得进炸弹清单 —— 它不是会员运营对象');
        $this->assertContains($expiredFormal->id, $ids,
            '卡项全过期而落 P5 的**正式**会员必须仍被认作会员（这正是 scopeMemberCustomers 的第二个条件）');
    }

    /** 口径 1 反向：layer≠P5 的正式会员正常命中（谓词第一分支） */
    public function test_non_p5_layer_member_is_included(): void
    {
        $c = $this->member('P4正式会员', [$this->card()], layer: 'P4', externalId: 'ky:99123');

        $this->assertContains($c->id, $this->bombIds());
    }

    // ─────────────────────────────────────── 4. 与待续费互不干扰

    /** 过期超 6 个月的会员：`renewal.in === false`（用户已拍定过期卡不纳入待续费） */
    public function test_bomb_member_is_never_in_renewal(): void
    {
        $c = $this->member('炸弹非待续费', [$this->card()]);
        $decision = customerDecision($c->fresh(), $this->rules());

        $this->assertTrue($decision['bomb']['in'], '必须判为炸弹');
        $this->assertFalse($decision['renewal']['in'], '过期超 6 个月不得进待续费（用户决策）');
        $this->assertNotContains($c->id, memberListIds()['待续课'] ?? [], '清单层同样不得命中待续费');
    }

    /** 炸弹卡在保留区，不进 `cards_list`（判定层输入） */
    public function test_bomb_cards_live_in_retention_not_in_decision_input(): void
    {
        $c = $this->member('保留区取证', [$this->card()]);
        $fresh = $c->fresh();

        $this->assertSame([], $fresh->cards_list, '过期卡不得进 cards_list（进去会经 C8 复活待续费）');
        $this->assertNotEmpty($fresh->card_stats['expiredCards'], '过期卡必须在 card_stats.expiredCards 可查');

        $kept = $fresh->card_stats['expiredCards'][0];
        foreach (['title', 'residue', 'deadline', 'status', 'type'] as $field) {
            $this->assertArrayHasKey($field, $kept, "保留区必须含 {$field}（口径要求卡名/剩余/到期日/类型）");
        }
        $this->assertSame('1', $kept['type'], '必须保留卡类型，供复核「为何排除时间卡」');
    }

    // ─────────────────────────────────────── 5. 冻结基线（真实样本逐人比对）

    /**
     * 以 captain 冻结的 `bomb_members_20260924.json` 为基线，逐人逐卡比对。
     *
     * 该文件在**仓库外**（含 PII），按路径读取；不存在时 skip
     * （他人机器/CI 上没有属正常；本地交付环境必然存在）。
     *
     * 三层断言：总数（302/393/2509）→ 名单完全相同 → 逐人卡数/节数/逐卡明细。
     */
    public function test_frozen_baseline_matches_member_by_member(): void
    {
        $snapshot = '/Users/ttt/yimai-master-data/ky_cards_live_20260924.json';
        $baseline = '/Users/ttt/yimai-master-data/bomb_members_20260924.json';
        if (! is_file($snapshot) || ! is_file($baseline)) {
            $this->markTestSkipped('仓库外冻结样本不存在（仅本地交付环境提供），跳过基线比对');
        }

        $cards = json_decode(file_get_contents($snapshot), true);
        $expected = json_decode(file_get_contents($baseline), true);
        $this->assertIsArray($cards, '冻结卡样本必须可解析');
        $this->assertIsArray($expected, '冻结基线必须可解析');

        $today = CarbonImmutable::today();
        $byMember = [];
        foreach ($cards as $card) {
            $byMember[(string) $card['member_id']][] = $card;
        }

        $mine = [];
        foreach ($byMember as $memberId => $memberCards) {
            $sum = $this->summarize($memberCards);
            $keep = [];
            $sections = 0;
            foreach ($sum['card_stats']['expiredCards'] as $ec) {
                $days = (int) $today->diffInDays(CarbonImmutable::parse($ec['deadline']), false);
                // 严格「过期天数 > 183」⇔ $days < -183
                if ($days >= -self::SIX_MONTHS_DAYS) {
                    continue;
                }
                $keep[] = $ec;
                $sections += (int) $ec['residue'];
            }
            if ($keep !== []) {
                $mine[$memberId] = ['cards' => $keep, 'sections' => $sections];
            }
        }

        // ① 总数与冻结基线逐项一致
        $myCards = array_sum(array_map(fn ($x) => count($x['cards']), $mine));
        $mySections = array_sum(array_column($mine, 'sections'));
        $baseCards = array_sum(array_map(fn ($x) => count($x['cards']), $expected));
        $baseSections = array_sum(array_column($expected, 'sections'));

        $this->assertSame(self::BASELINE_MEMBERS, count($mine), '命中人数必须等于冻结基线 302');
        $this->assertSame(self::BASELINE_CARDS, $myCards, '命中卡数必须等于冻结基线 393');
        $this->assertSame(self::BASELINE_SECTIONS, $mySections, '沉睡节数必须等于冻结基线 2509');
        $this->assertSame(count($expected), count($mine), '命中人数必须与基线文件逐人一致');
        $this->assertSame($baseCards, $myCards, '卡数必须与基线文件一致');
        // 基线 JSON 里的 sections 是浮点（如 2509.0），故用 == 比较数值而非 assertSame
        // （assertSame 会因 int/float 类型不同而误报「不一致」）
        $this->assertEqualsWithDelta($baseSections, $mySections, 0.0001, '节数必须与基线文件一致');

        // ② 名单完全相同（不多、不漏）
        $this->assertSame([], array_keys(array_diff_key($mine, $expected)), '不得有基线之外的会员');
        $this->assertSame([], array_keys(array_diff_key($expected, $mine)), '不得漏掉基线中的会员');

        // ③ 逐人卡数/节数 + 逐卡明细（名称|剩余|到期日）
        $norm = function (array $list): array {
            $out = array_map(
                fn ($x) => (string) $x['title'].'|'.(float) $x['residue'].'|'.(string) $x['deadline'],
                $list
            );
            sort($out);

            return $out;
        };
        foreach ($expected as $memberId => $row) {
            $this->assertCount(count($row['cards']), $mine[$memberId]['cards'], "会员 {$memberId} 卡数不一致");
            $this->assertSame((int) $row['sections'], $mine[$memberId]['sections'], "会员 {$memberId} 节数不一致");
            $this->assertSame(
                $norm(array_map(
                    fn ($x) => ['title' => $x['title'], 'residue' => $x['residue'], 'deadline' => $x['deadline']],
                    $row['cards']
                )),
                $norm($mine[$memberId]['cards']),
                "会员 {$memberId} 的卡项明细（名称/剩余/到期日）不一致"
            );
        }
    }

    // ─────────────────────────────────────── 6. watch 明细 + 接口层

    /** watch 明细字段：供前端展示「哪张卡、多久、多少节」 */
    public function test_bomb_watch_payload_carries_detail(): void
    {
        $c = $this->member('明细会员', [$this->card()]);
        $payload = memberListWatch()['炸弹会员'][$c->id] ?? null;

        $this->assertNotNull($payload, '炸弹会员必须有 watch 明细');
        $this->assertSame(12, $payload['sections'], '须给出沉睡节数');
        $this->assertSame(1, $payload['cardCount'], '须给出命中卡数');
        $this->assertSame(['bomb_expired'], $payload['whyCodes'], '须给出结构化命中码');
        $this->assertNotEmpty($payload['why'], '须给出人话理由');
        $this->assertGreaterThan(self::SIX_MONTHS_DAYS, $payload['earliestExpiredDays'], '须给出过期天数');
    }

    public function test_list_endpoint_accepts_bomb_key_without_error(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'username' => uniqid('s'), 'role' => 'R_SUPER', 'status' => '启用',
        ]));
        $rows = $this->getJson('/api/customers?list='.urlencode('炸弹会员').'&size=20')
            ->assertOk()
            ->json('data.records');

        $this->assertIsArray($rows, '炸弹会员清单必须能被 list 参数正常筛选（返回形状与其他清单一致）');
    }

    /** 该会员必须能通过接口按炸弹清单真正筛出来（不只是「参数不报错」） */
    public function test_list_endpoint_actually_filters_bomb_members(): void
    {
        $bomb = $this->member('接口炸弹', [$this->card()]);
        Sanctum::actingAs(User::factory()->create([
            'username' => uniqid('s'), 'role' => 'R_SUPER', 'status' => '启用',
        ]));

        $rows = $this->getJson('/api/customers?list='.urlencode('炸弹会员').'&size=100')
            ->assertOk()->json('data.records');

        $this->assertContains($bomb->id, array_column($rows, 'id'), '该会员必须能按炸弹清单筛出');
    }

    public function test_list_counts_accepts_bomb_key_without_error(): void
    {
        $this->member('计数炸弹', [$this->card()]);
        Sanctum::actingAs(User::factory()->create([
            'username' => uniqid('s'), 'role' => 'R_SUPER', 'status' => '启用',
        ]));
        // 数量挂在 data.counts 下（见 CustomerController::listCounts 的 ok(['counts' => …])）
        $counts = $this->getJson('/api/customers/list-counts')->assertOk()->json('data.counts');

        $this->assertIsArray($counts, 'list-counts 必须正常返回');
        $this->assertArrayHasKey('炸弹会员', $counts, '清单数量接口必须包含炸弹会员（否则页签拿不到数字）');
        $this->assertSame(1, $counts['炸弹会员'], '计数必须与清单同源');
    }

    /** 既有 5 个清单的键与计数不受影响（不得因新增清单而改动旧键） */
    public function test_existing_five_lists_are_unaffected(): void
    {
        $this->member('既有清单会员', [$this->card()]);
        Sanctum::actingAs(User::factory()->create([
            'username' => uniqid('s'), 'role' => 'R_SUPER', 'status' => '启用',
        ]));
        $counts = $this->getJson('/api/customers/list-counts')->assertOk()->json('data.counts');

        foreach (['待续课', '出勤降低', 'VIP', '预流失', '待复活'] as $key) {
            $this->assertArrayHasKey($key, $counts, "既有清单 {$key} 不得消失");
            $this->assertIsInt($counts[$key]);
        }
    }
}
