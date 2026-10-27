<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Customer;
use App\Services\KyMemberSyncService;
use App\Support\CardLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * t7 对抗复核：**规范未写**的脏数据 / 矛盾形态探测。
 *
 * 与 t6 的验收矩阵互补：t6 验证「规范写了什么」，这里验证「规范没写、但上游一定会
 * 吐出来的东西」。每个用例都把观察到的行为打印成一行 PROBE 日志（phpunit --debug 可见），
 * 断言只锁「不得静默 / 不得误报 / 不得崩溃」这三条底线，具体取值以日志为准。
 *
 * 运行：php artisan test --filter=AdversaryProbeV39Test
 *
 * 原先两个 `test_defect_*` 用例（A-01 hash 折叠 / A-02 valid_days_missing）在 t8 已修复，
 * 现在改为**常规回归**（不再需要 V39_DEFECT_PROBES=1 跳过守卫）；它们断言的就是修复后的
 * 正确行为：无身份多实例不得折叠、占比开启且缺 validDays 必须降级。
 */
class AdversaryProbeV39Test extends TestCase
{
    use RefreshDatabase;

    private int $cardSeq = 0;

    private static int $memberSeq = 0;

    // ─────────────────────────────────────────────────────── 夹具

    private function ymd(int $offsetDays): string
    {
        return now()->startOfDay()->addDays($offsetDays)->toDateString();
    }

    private function card(
        string $title,
        int $type,
        string $status = '5',
        ?string $deadline = null,
        int|float|null $residue = null,
        array $more = []
    ): array {
        $this->cardSeq++;

        return array_merge([
            'id' => 'mc'.$this->cardSeq,
            'card_no' => 'CN'.str_pad((string) $this->cardSeq, 4, '0', STR_PAD_LEFT),
            'card_id' => 'prod'.$this->cardSeq,
            'card_title' => $title,
            'type' => (string) $type,
            'status' => $status,
            'is_taste' => '0',
            'can_op' => '1',
            'residue_amount' => $residue,
            'residue_days' => null,
            'curr_unit_cash_value' => '1',
            'usage_total' => 0,
            'create_time' => $this->ymd(-180),
            'deadline' => $deadline,
            'expiry_days' => 0,
        ], $more);
    }

    private function aggregate(array $rows, ?array $rules = null): array
    {
        $m = new \ReflectionMethod(KyMemberSyncService::class, 'summarizeCards');
        $m->setAccessible(true);

        return $m->invoke(null, $rows, $rules ?? rules());
    }

    private function member(string $name, array $rows, array $extra = []): Customer
    {
        $sum = $this->aggregate($rows);
        self::$memberSeq++;

        return Customer::create(array_merge([
            'name' => $name,
            'phone' => '137'.str_pad((string) self::$memberSeq, 8, '0', STR_PAD_LEFT),
            'phone_tail' => '0000',
            'external_id' => 'ky:'.(300000 + self::$memberSeq),
            'venue' => '绿地店',
            'source' => 'KeepYoga',
            'owner' => '店长',
            'consultant' => '店长',
            'status' => '在籍',
            'layer' => 'P4',
            'main_card' => $sum['main_card'],
            'remain_times' => $sum['remain_times'],
            'expire_date' => $sum['expire_date'],
            'card_paid_amount' => 0,
            'card_stats' => $sum['card_stats'],
            'cards_list' => $sum['cards_list'],
            'attend_m1' => 6,
            'attend_m2' => 6,
            'attend_m3' => 6,
            'in_revive' => false,
            'last_visit' => $this->ymd(-3),
        ], $extra));
    }

    private function legacyMember(string $name, array $attrs): Customer
    {
        self::$memberSeq++;

        return Customer::create(array_merge([
            'name' => $name,
            'phone' => '136'.str_pad((string) self::$memberSeq, 8, '0', STR_PAD_LEFT),
            'phone_tail' => '0000',
            'external_id' => 'ky:'.(400000 + self::$memberSeq),
            'venue' => '绿地店',
            'source' => 'KeepYoga',
            'owner' => '店长',
            'consultant' => '店长',
            'status' => '在籍',
            'layer' => 'P4',
            'attend_m1' => 6,
            'attend_m2' => 6,
            'attend_m3' => 6,
            'in_revive' => false,
            'last_visit' => $this->ymd(-3),
        ], $attrs));
    }

    private function withRules(array $patch): void
    {
        $setting = AppSetting::oldest('id')->first();
        if ($setting) {
            $setting->update(['rules' => array_merge((array) ($setting->rules ?? []), $patch)]);
        } else {
            AppSetting::create(['rules' => $patch]);
        }
        invalidateBusinessCaches('member_lists');
    }

    private function decide(Customer $c, ?array $rules = null): array
    {
        return customerDecision($c->fresh(), $rules ?? rules());
    }

    /** 一行可复制的观察日志（phpunit --debug 时可见） */
    private function probe(string $id, array $facts): void
    {
        fwrite(STDERR, "\nPROBE {$id} ".json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }

    private function inst(array $d, ?string $cardNo = null): array
    {
        foreach ($d['renewal']['instances'] as $i) {
            if ($cardNo === null || ($i['cardNo'] ?? null) === $cardNo) {
                return $i;
            }
        }
        $this->fail('实例快照里没有 '.$cardNo);
    }

    // ═══════════════════════════════════════════════════ 探测

    /** P-01 期限卡 deadline 为空但 residue_days=10：不得猜「到期」，也不得静默。 */
    public function test_p01_time_card_without_deadline(): void
    {
        $row = $this->card('无到期日年卡', 2, '5', null, 0, ['residue_days' => 10]);
        $c = $this->member('P01', [$row]);
        $d = $this->decide($c);
        $i = $this->inst($d);

        $this->probe('P01', [
            'state' => $i['state'],
            'remaining' => $i['remaining'],
            'remainingSource' => $i['remainingSource'],
            'in' => $d['renewal']['in'],
            'why' => $d['renewal']['why'],
            'degraded' => $d['renewal']['degradedCodes'],
            'layer' => $d['layer'],
        ]);

        $this->assertSame('current', $i['state']);
        $this->assertSame(10, (int) $i['remaining'], 'residue_days 只作 deadline 缺失时的回退');
        $this->assertContains('time_card_no_deadline', $d['renewal']['degradedCodes'], '缺 deadline 必须降级，不得静默');
        $this->assertFalse($d['renewal']['in'], '缺 deadline 无法判到期，不得由此命中');
    }

    /** P-02 期限卡已过期但 deadline 缺失：historyNote 文案是否会写出空日期（t6 F-4）。 */
    public function test_p02_history_time_card_without_deadline_note(): void
    {
        $row = $this->card('历史无到期年卡', 2, '6', null, 0, ['residue_days' => 10]);
        $c = $this->member('P02', [$row]);
        $d = $this->decide($c);
        $i = $this->inst($d);

        $note = $i['note'] ?? null;
        $this->probe('P02', [
            'state' => $i['state'],
            'historyReason' => $i['historyReason'],
            'remaining' => $i['remaining'],
            'note' => $note,
            'expiredBalance' => $d['expiredBalance']['in'],
            'degraded' => $d['renewal']['degradedCodes'],
        ]);

        $this->assertSame('history', $i['state']);
        $this->assertFalse($d['expiredBalance']['in'], '期限卡过期不产生过期余额');
        // A-10 已修复：缺 deadline 的期限卡不得写出「已于  到期」空日期；
        // 且历史期限卡的 remaining 必须与「剩余天数计为 0」口径一致。
        $this->assertNotNull($note);
        $this->assertStringNotContainsString('已于  到期', (string) $note, '不得出现空日期文案');
        $this->assertStringContainsString('到期日未知（期限卡已失效）', (string) $note);
        $this->assertSame(0.0, (float) $i['remaining'], '历史期限卡不得保留 residue_days 作为余量');
    }

    /** P-03 负数 residue：规范 §2.4 clamp 到 0 → 会被当成「已耗尽」。 */
    public function test_p03_negative_residue_is_clamped_to_zero(): void
    {
        $row = $this->card('脏负数次卡', 1, '5', $this->ymd(30), -5);
        $c = $this->member('P03', [$row]);
        $d = $this->decide($c);

        $this->probe('P03', [
            'remaining' => $this->inst($d)['remaining'],
            'in' => $d['renewal']['in'],
            'why' => $d['renewal']['why'],
            'whyCodes' => $d['renewal']['whyCodes'],
            'degraded' => $d['renewal']['degradedCodes'],
            'layer' => $d['layer'],
        ]);

        $this->assertSame(0.0, (float) $this->inst($d)['remaining'], '规范要求 max(0, residue)');
        // 记录：负数被 clamp 成 0 后语义等同「已确认耗尽」，且规范/实现都没有降级码
        $this->assertSame([], array_values(array_intersect(['negative_residue'], $d['renewal']['degradedCodes'])), '当前没有负数降级码');
    }

    /** P-04 status=6 但 deadline 在未来：状态与日期矛盾，按历史处理且不得进 P3。 */
    public function test_p04_status6_with_future_deadline(): void
    {
        $row = $this->card('上游标过期但日期未到', 1, '6', $this->ymd(30), 10);
        $c = $this->member('P04', [$row]);
        $d = $this->decide($c);

        $this->probe('P04', [
            'state' => $this->inst($d)['state'],
            'in' => $d['renewal']['in'],
            'expiredBalance' => $d['expiredBalance']['in'],
            'layer' => $d['layer'],
            'hasAnyCard' => $d['hasAnyCard'],
        ]);

        $this->assertSame('history', $this->inst($d)['state'], 'status=6 是历史的强信号');
        $this->assertFalse($d['renewal']['in']);
        $this->assertFalse($d['expiredBalance']['in'], '未到期不得算「过期有余额」');
        $this->assertTrue($d['hasAnyCard'], '有卡（已过期）不得落 P5');
    }

    /** P-05 同 card_id 三实例（卡升级/拆卡的常见形态）：系列序号 1/2/3，三条过期余额。 */
    public function test_p05_three_instances_same_card_id(): void
    {
        $rows = [
            $this->card('私教20节', 1, '6', $this->ymd(-30), 30, ['card_id' => 'prod-x', 'create_time' => $this->ymd(-400)]),
            $this->card('私教20节', 1, '6', $this->ymd(-60), 20, ['card_id' => 'prod-x', 'create_time' => $this->ymd(-300)]),
            $this->card('私教20节', 1, '6', $this->ymd(-90), 10, ['card_id' => 'prod-x', 'create_time' => $this->ymd(-200)]),
        ];
        $c = $this->member('P05', $rows);
        $d = $this->decide($c);

        $this->probe('P05', [
            'in' => $d['renewal']['in'],
            'expiredBalanceIn' => $d['expiredBalance']['in'],
            'sections' => $d['expiredBalance']['sections'],
            'cardCount' => count($d['expiredBalance']['cards']),
            'series' => array_map(fn ($i) => [$i['seriesIndex'], $i['seriesCount']], $d['renewal']['instances']),
            'why' => $d['expiredBalance']['why'],
            'layer' => $d['layer'],
        ]);

        $this->assertFalse($d['renewal']['in'], '历史实例结构上不得产生 why');
        $this->assertSame(60, $d['expiredBalance']['sections']);
        $this->assertEqualsCanonicalizing([1, 2, 3], array_column($d['renewal']['instances'], 'seriesIndex'));
        $this->assertStringContainsString('同名第 1 张', implode(' | ', $d['expiredBalance']['why']));
    }

    /** P-06 跨店同人（同手机号两条 customers 行）：不得互相合并/抵消。 */
    public function test_p06_cross_venue_same_person_not_merged(): void
    {
        $a = $this->member('P06 绿地', [$this->card('次卡', 1, '5', $this->ymd(200), 50)]);
        $b = $this->member('P06 万象', [$this->card('次卡', 1, '5', $this->ymd(200), 3)], [
            'phone' => $a->phone, 'venue' => '万象城店',
        ]);
        $da = $this->decide($a);
        $db = $this->decide($b);
        $lists = computeMemberLists(rules());

        $this->probe('P06', [
            'a' => ['in' => $da['renewal']['in'], 'usable' => $da['display']['usableCount'], 'layer' => $da['layer']],
            'b' => ['in' => $db['renewal']['in'], 'usable' => $db['display']['usableCount'], 'layer' => $db['layer']],
            'listHasB' => in_array($b->id, $lists['lists']['待续课'], true),
            'listHasA' => in_array($a->id, $lists['lists']['待续课'], true),
        ]);

        $this->assertFalse($da['renewal']['in'], '50 节不该被另一门店的 3 节拖进待续费');
        $this->assertTrue($db['renewal']['in']);
        $this->assertTrue(in_array($b->id, $lists['lists']['待续课'], true));
        $this->assertFalse(in_array($a->id, $lists['lists']['待续课'], true));
    }

    /** P-07 体验卡混入：被排除的卡不得产生 why，也不得把真实卡片的单位/余量带歪。 */
    public function test_p07_taste_card_mixed_with_real_card(): void
    {
        $taste = $this->card('新人礼包2次', 1, '5', $this->ymd(300), 2, ['is_taste' => '0']);
        $real = $this->card('私教10节', 1, '5', $this->ymd(200), 8);
        $c = $this->member('P07', [$taste, $real]);
        $d = $this->decide($c);

        $this->probe('P07', [
            'in' => $d['renewal']['in'],
            'why' => $d['renewal']['why'],
            'titles' => array_column($d['renewal']['instances'], 'title'),
            'cards_list' => array_column((array) $c->fresh()->cards_list, 'title'),
            'degraded' => $d['renewal']['degradedCodes'],
        ]);

        $this->assertTrue($d['renewal']['in']);
        $this->assertStringNotContainsString('礼包', implode(' | ', $d['renewal']['why']), '体验卡不得进 why');
        $this->assertNotContains('新人礼包2次', array_column((array) $c->fresh()->cards_list, 'title'), '体验卡不进 cards_list');
        $this->assertContains('taste_excluded_by_pattern', $d['renewal']['degradedCodes']);
    }

    /** P-08 储值卡 residue=null：余额未知。规范没有对应 degraded code —— 是否静默？ */
    public function test_p08_stored_value_null_residue(): void
    {
        $row = $this->card('储值卡', 3, '5', $this->ymd(100), null, ['residue_amount' => null]);
        $c = $this->member('P08', [$row]);
        $this->withRules(['renewalStoredValueThreshold' => 300]);
        $d = $this->decide($c);
        $i = $this->inst($d);

        $this->probe('P08', [
            'type' => $i['type'],
            'unit' => $i['unit'],
            'remaining' => $i['remaining'],
            'in' => $d['renewal']['in'],
            'degraded' => $d['renewal']['degradedCodes'],
            'layer' => $d['layer'],
            'storedValue' => $d['display']['storedValue'],
        ]);

        $this->assertSame(3, $i['type']);
        $this->assertSame('元', $i['unit']);
        $this->assertNull($i['remaining']);
        $this->assertFalse($d['renewal']['in'], '余额未知不得猜「低于阈值」');
        // 观察项：degradedCodes 为空 ⇒ 储值卡余额未知对运营完全不可见
        $this->probe('P08-degraded-empty', ['degraded' => $d['renewal']['degradedCodes']]);
    }

    /** P-09 expire_date 取 CURRENT 的 min（含已耗尽次卡）——规范 §2.6 如此，检查副作用。 */
    public function test_p09_expire_date_includes_exhausted_current(): void
    {
        $exhausted = $this->card('空次卡', 1, '5', $this->ymd(10), 0);
        $annual = $this->card('年卡', 2, '5', $this->ymd(200), 0, ['expiry_days' => 365]);
        $c = $this->member('P09', [$exhausted, $annual]);
        $d = $this->decide($c);

        $this->probe('P09', [
            'expireDate' => $d['display']['expireDate'],
            'expireDateState' => $d['display']['expireDateState'],
            'nextExpireDays' => $d['display']['nextExpireDays'],
            'in' => $d['renewal']['in'],
            'why' => $d['renewal']['why'],
            'mainCard' => $d['display']['mainCard'],
            'mainCardState' => $d['display']['mainCardState'],
        ]);

        $this->assertSame($this->ymd(10), substr((string) $d['display']['expireDate'], 0, 10), '规范 §2.6：CURRENT 的最早 deadline');
        $this->assertFalse($d['renewal']['in'], '空次卡临期不得产生命中（deadlineEligible 挡住）');
    }

    /** P-10 任何 deadline<today 的实例都不得进待续费（含汇总字段仍很大的脏数据）。 */
    public function test_p10_expired_instances_never_enter_renewal(): void
    {
        $rows = [
            $this->card('旧年卡', 2, '5', $this->ymd(-60), 60),
            $this->card('旧次卡', 1, '5', $this->ymd(-5), 20),
        ];
        // 汇总列故意留旧值：remain_times 很大、expire_date 在未来（模拟同步写了一半的数据）
        $c = $this->member('P10', $rows, [
            'remain_times' => 99,
            'expire_date' => $this->ymd(200),
        ]);
        $d = $this->decide($c);

        $this->probe('P10', [
            'in' => $d['renewal']['in'],
            'why' => $d['renewal']['why'],
            'expiredBalance' => $d['expiredBalance']['in'],
            'layer' => $d['layer'],
            'states' => array_column($d['renewal']['instances'], 'state'),
        ]);

        $this->assertFalse($d['renewal']['in'], '过期实例（含脏汇总）不得进待续费');
        $this->assertSame([], $d['renewal']['why']);
        $this->assertTrue($d['expiredBalance']['in'], '过期次卡 20 节在窗口内 → P3');
    }

    /** P-11（A-01 回归）三张完全同字段、无 id/card_no 的旧实例：不得被 hash 身份合并成一张。 */
    public function test_defect_p11_identical_rows_without_identity_are_collapsed(): void
    {
        $rows = [];
        for ($n = 0; $n < 3; $n++) {
            $rows[] = [
                'card_title' => '无身份次卡', 'type' => '1', 'status' => '6',
                'residue_amount' => 10, 'deadline' => $this->ymd(-20),
                'is_taste' => '0', 'create_time' => null,
            ];
        }
        // 直接落旧 cards_list（模拟 v1 快照里没有 id/card_no 的行）
        $legacyCards = array_map(fn ($r) => [
            'title' => $r['card_title'], 'unit' => '节', 'residue' => 10, 'bound' => null,
            'deadline' => $r['deadline'], 'status' => '过期', 'unactivated' => false,
            'type' => '1', 'validDays' => 0,
        ], $rows);
        $c = $this->legacyMember('P11', [
            'main_card' => '无身份次卡',
            'remain_times' => 0,
            'expire_date' => $this->ymd(-20),
            'card_paid_amount' => 0,
            'card_stats' => ['dataVersion' => 1],
            'cards_list' => $legacyCards,
        ]);
        $d = $this->decide($c);

        $this->probe('P11', [
            'instanceCount' => count($d['renewal']['instances']),
            'in' => $d['renewal']['in'],
            'expiredBalanceCards' => count($d['expiredBalance']['cards']),
            'sections' => $d['expiredBalance']['sections'],
            'degraded' => $d['renewal']['degradedCodes'],
            'notes' => array_column($d['renewal']['instances'], 'note'),
        ]);

        $this->assertCount(3, $d['renewal']['instances'], '无身份的同字段实例不得被 hash 合并');
        // DEFECT-01：三张 10 节被 identity 做 map key 折叠成 1 张 → 沉睡余额少算 20 节
        $this->assertSame(30, $d['expiredBalance']['sections'], '三张 10 节都算进沉睡余额');
        $this->assertCount(3, $d['expiredBalance']['cards'], '过期余额明细不得丢卡');
        $indexes = array_map(function (array $i) {
            preg_match('/同名第 (\d) 张/u', (string) ($i['note'] ?? ''), $m);

            return isset($m[1]) ? (int) $m[1] : null;
        }, $d['renewal']['instances']);
        $this->assertSame([1, 2, 3], $indexes, '同名三张必须可区分（I8）');
    }

    /** P-12（A-02 回归）有效期占比开启，但期限卡没有 validDays：必须产出 valid_days_missing。 */
    public function test_defect_p12_valid_days_missing_degraded(): void
    {
        $this->withRules(['renewalExpireDays' => 1, 'renewalExpirePercent' => 50]);
        $row = $this->card('缺有效期的年卡', 2, '5', $this->ymd(200), 0, [
            'expiry_days' => 0, 'expiry_months' => 0,
        ]);
        $c = $this->member('P12', [$row]);
        $d = $this->decide($c);
        $i = $this->inst($d);

        $this->probe('P12', [
            'validDays' => $i['validDays'],
            'in' => $d['renewal']['in'],
            'whyCodes' => $d['renewal']['whyCodes'],
            'degraded' => $d['renewal']['degradedCodes'],
        ]);

        $this->assertNull($i['validDays']);
        // 规范 §5.3：validDays=null 且占比阈值开启 → 必须出现 valid_days_missing
        $this->assertContains(
            'valid_days_missing',
            $d['renewal']['degradedCodes'],
            '有效期占比开启但缺 validDays 时必须降级告知（否则用户无法知道阈值为何没反应）'
        );
    }

    /** P-13 老汇总行（cards_list=null）且卡已全部过期：兼容路径会进待续费吗？ */
    public function test_p13_legacy_summary_row_with_expired_cards(): void
    {
        $c = $this->legacyMember('P13', [
            'main_card' => '旧次卡',
            'remain_times' => 5,
            'expire_date' => $this->ymd(-20),
            'card_paid_amount' => 1000,
            'card_stats' => null,
            'cards_list' => null,
        ]);
        $d = $this->decide($c);

        $this->probe('P13', [
            'in' => $d['renewal']['in'],
            'why' => $d['renewal']['why'],
            'whyCodes' => $d['renewal']['whyCodes'],
            'degraded' => $d['renewal']['degradedCodes'],
            'dataVersion' => $d['dataVersion'],
            'layer' => $d['layer'],
            'expiredBalance' => $d['expiredBalance']['in'],
        ]);

        // 记录兼容路径行为：旧行确实仍会进（规范 §7 允许，但必须降级告知）
        $this->assertTrue($d['hasAnyCard']);
        $this->assertContains('legacy_summary_fallback', $d['renewal']['degradedCodes'], '旧汇总口径必须显式降级');
    }

    /** P-14 why 上限 5 条 + 截断标记：命中很多时不崩、且可观测。 */
    public function test_p14_why_truncation(): void
    {
        $this->withRules(['renewalExpirePercent' => 20]);
        $rows = [];
        for ($n = 0; $n < 3; $n++) {
            $rows[] = $this->card('次卡'.$n, 1, '5', $this->ymd(200), 5);
        }
        $rows[] = $this->card('年卡甲', 2, '5', $this->ymd(20), 0, ['expiry_days' => 365]);
        $rows[] = $this->card('年卡乙', 2, '5', $this->ymd(25), 0, ['expiry_days' => 365]);
        $c = $this->member('P14', $rows);
        $d = $this->decide($c);

        $this->probe('P14', [
            'whyCount' => count($d['renewal']['why']),
            'whyTruncated' => $d['renewal']['whyTruncated'],
            'codes' => $d['renewal']['whyCodes'],
            'hits' => count($d['renewal']['hits']),
        ]);

        $this->assertLessThanOrEqual(5, count($d['renewal']['why']));
        $this->assertTrue($d['renewal']['whyTruncated'], '超过 5 条必须可观测');
        $this->assertTrue($d['renewal']['in']);
    }

    /** P-15 status 缺失 + deadline 在未来：按 current 处理，必须降级 status_missing。 */
    public function test_p15_missing_status(): void
    {
        $row = $this->card('无状态次卡', 1, '', $this->ymd(50), 8);
        $row['status'] = null;
        $c = $this->member('P15', [$row]);
        $d = $this->decide($c);

        $this->probe('P15', [
            'state' => $this->inst($d)['state'],
            'in' => $d['renewal']['in'],
            'degraded' => $d['renewal']['degradedCodes'],
        ]);

        $this->assertSame('current', $this->inst($d)['state']);
        $this->assertContains('status_missing', $d['renewal']['degradedCodes']);
    }

    /** P-16 储值卡与次卡同额：单位不得被串（阈值只作用于元，不作用于节）。 */
    public function test_p16_stored_and_count_same_number_units(): void
    {
        $this->withRules(['renewalStoredValueThreshold' => 300, 'renewalThreshold' => 10]);
        $stored = $this->card('储值卡', 3, '5', $this->ymd(100), 300);
        $count = $this->card('次卡', 1, '5', $this->ymd(100), 300);
        $c = $this->member('P16', [$stored, $count]);
        $d = $this->decide($c);

        $this->probe('P16', [
            'in' => $d['renewal']['in'],
            'why' => $d['renewal']['why'],
            'units' => array_map(fn ($h) => [$h['code'], $h['unit']], $d['renewal']['hits']),
            'usableCount' => $d['display']['usableCount'],
        ]);

        // `<=` 语义：300 元 ≤ 300 元 必须命中；300 节 > 10 节 不得命中
        $this->assertCount(1, $d['renewal']['why']);
        $this->assertStringContainsString('300.00 元', $d['renewal']['why'][0]);
        $this->assertSame(300, $d['display']['usableCount'], 'usableCount 只含次卡（节）');
    }

    /** P-17 status × deadline 全组合扫描：deadline<today 的实例在任何 status 下都不得进待续费。 */
    public function test_p17_no_status_can_smuggle_expired_instance_into_renewal(): void
    {
        $matrix = [];
        foreach (['4', '5', '7', '6', '29', '39', '', '99'] as $status) {
            $row = $this->card('扫'.$status, 1, $status, $this->ymd(-1), 8);
            if ($status === '') {
                $row['status'] = null;
            }
            $c = $this->member('P17-'.$status, [$row]);
            $d = $this->decide($c);
            $matrix[$status === '' ? 'null' : $status] = [
                'state' => $this->inst($d)['state'],
                'in' => $d['renewal']['in'],
                'whyCodes' => $d['renewal']['whyCodes'],
                'layer' => $d['layer'],
            ];
            $this->assertFalse($d['renewal']['in'], "status={$status} 且 deadline 已过，不得进待续费");
        }

        $this->probe('P17', $matrix);
    }

    /** P-18 只有历史卡：expire_date 回退到最近历史且必须标注 state=history（不得说「N 天后到期」）。 */
    public function test_p18_history_only_display_state(): void
    {
        $c = $this->member('P18', [
            $this->card('旧次卡', 1, '6', $this->ymd(-40), 12),
            $this->card('旧年卡', 2, '6', $this->ymd(-10), 0),
        ]);
        $d = $this->decide($c);

        $this->probe('P18', [
            'expireDate' => $d['display']['expireDate'],
            'expireDateState' => $d['display']['expireDateState'],
            'nextExpireDays' => $d['display']['nextExpireDays'],
            'mainCard' => $d['display']['mainCard'],
            'mainCardState' => $d['display']['mainCardState'],
            'in' => $d['renewal']['in'],
            'layer' => $d['layer'],
        ]);

        $this->assertSame('history', $d['display']['expireDateState']);
        $this->assertSame($this->ymd(-10), substr((string) $d['display']['expireDate'], 0, 10), '历史回退取最近一次（max）');
        $this->assertLessThan(0, (int) $d['display']['nextExpireDays'], '历史到期日的 nextExpireDays 必须为负，前端据此禁用「今日到期」');
        $this->assertFalse($d['renewal']['in']);
        $this->assertSame('P3', $d['layer']);
    }
}
