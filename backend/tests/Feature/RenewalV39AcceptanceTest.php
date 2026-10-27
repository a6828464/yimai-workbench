<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Customer;
use App\Services\KyMemberSyncService;
use App\Support\CardLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「待续费」v3.9 独立验收（t6）。
 *
 * 这份文件**从规范反推**，不复用实现者的夹具（`CardLifecycleRenewalTest`）：
 *  - 上游行默认带齐实例身份三件套（`id` / `card_no` / `card_id` / `create_time`），
 *    因为 R05/R11/R13 要求的「理由含卡号与发卡日」只有在有身份时才可验证；
 *  - 期望值逐条抄自 `docs/口径/待续费判定规范-v3.9.md` §8.1（R01–R15）与
 *    `docs/口径/待续费-v3.9-任务简报.md` §7，冲突处按规范 §0.3 的裁决（C5/C7）执行；
 *  - 链路固定为 **真实三段**：`KyMemberSyncService::summarizeCards`
 *    → `customerDecision` → `computeMemberLists`（不手搓 cards_list 绕过同步聚合；
 *    只有 R12/B6 两个「旧版数据」用例必须直接落库，那正是它们的被测对象）。
 *
 * 夹具公共基线（规范 §8.1）：`attend_m1=m2=m3=6`、`last_visit=TODAY-3`、
 * `in_revive=false`、`card_paid_amount=0`、规则取 `rules()` 默认值
 * （threshold=10、countPercent=20、expireDays=30、expirePercent=0、
 *  expiredBalanceWindowDays=90、renewalStoredValueThreshold=0）。
 */
class RenewalV39AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    /** 卡行自增序号：保证每个实例都有唯一 id / card_no（同名多卡才可区分） */
    private int $cardSeq = 0;

    /** 会员自增序号：phone 唯一约束 */
    private static int $memberSeq = 0;

    /** 已收集的 why 文案（供收尾的反例扫描断言） */
    private array $collectedWhy = [];

    // ─────────────────────────────────────────────────────────── 夹具

    private function ymd(int $offsetDays): string
    {
        return now()->startOfDay()->addDays($offsetDays)->toDateString();
    }

    /**
     * 一行上游 mcard 数据。
     *
     * @param  int  $type  1=次卡 2=期限卡 3=储值卡
     * @param  string  $status  5=正常 6=过期 7=未开卡 4=请假 29=退卡 39=停卡
     * @param  array  $more  覆盖任意上游字段（id/card_no/card_id/create_time/usage_total/residue_days/expiry_days/is_taste…）
     */
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
            // 单次折算价 = 1 ⇒ usage_total 的数值就是「已耗次数」，走真实的金额口径
            'curr_unit_cash_value' => '1',
            'usage_total' => 0,
            'create_time' => $this->ymd(-180),
            'deadline' => $deadline,
            'expiry_days' => 0,
        ], $more);
    }

    /** 真实同步聚合（summarizeCards 是 private：生产由 syncMember 调用，测试用反射等价） */
    private function aggregate(array $rows, ?array $rules = null): array
    {
        $m = new \ReflectionMethod(KyMemberSyncService::class, 'summarizeCards');
        $m->setAccessible(true);

        return $m->invoke(null, $rows, $rules ?? rules());
    }

    /** 建一个会员：卡项经真实 summarizeCards 聚合后落库（与生产同链路） */
    private function member(string $name, array $rows, array $extra = []): Customer
    {
        $sum = $this->aggregate($rows);
        self::$memberSeq++;

        return Customer::create(array_merge([
            'name' => $name,
            'phone' => '139'.str_pad((string) self::$memberSeq, 8, '0', STR_PAD_LEFT),
            'phone_tail' => '0000',
            'external_id' => 'ky:'.(100000 + self::$memberSeq),
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

    /** 直接落库（只用于「旧版数据」用例：旧 cards_list / 旧 card_stats 正是被测对象） */
    private function legacyMember(string $name, array $attrs): Customer
    {
        self::$memberSeq++;

        return Customer::create(array_merge([
            'name' => $name,
            'phone' => '138'.str_pad((string) self::$memberSeq, 8, '0', STR_PAD_LEFT),
            'phone_tail' => '0000',
            'external_id' => 'ky:'.(200000 + self::$memberSeq),
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

    /** 写阈值（合并写入，与生产 PUT /member-rules 同路径），并让清单缓存失效 */
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

    /**
     * 判定 + 文案反例扫描。
     *
     * 扫描的不是「文案好不好看」，而是用户报的那个症状：
     * `已过期 N 天，仍余 M 天` —— 一旦出现就说明历史实例进了命中域。
     */
    private function decide(Customer $c, ?array $rules = null): array
    {
        $d = customerDecision($c->fresh(), $rules ?? rules());
        foreach ($d['renewal']['why'] as $text) {
            $this->collectedWhy[] = $text;
            $this->assertStringNotContainsString('仍余', $text, 'renewal.why 不得出现历史卡口径「仍余」：'.$text);
            $this->assertDoesNotMatchRegularExpression(
                '/已过期[^。；]{0,20}(还有|仍)[^。；]{0,6}[0-9]+/u',
                $text,
                'renewal.why 不得出现「已过期却仍余 N」：'.$text
            );
        }

        return $d;
    }

    /** 清单（真实 computeMemberLists，不经 120s 缓存，测试里结果确定） */
    private function lists(?array $rules = null): array
    {
        return computeMemberLists($rules ?? rules());
    }

    /** 判定快照里按卡号取实例 */
    private function instanceOf(array $d, string $cardNo): array
    {
        foreach ($d['renewal']['instances'] as $i) {
            if (($i['cardNo'] ?? null) === $cardNo) {
                return $i;
            }
        }
        $this->fail("判定快照里找不到卡号 {$cardNo}（说明该实例没有进入判定域）");
    }

    private function assertDate(int $offset, ?string $actual, string $message = ''): void
    {
        $this->assertSame($this->ymd($offset), substr((string) $actual, 0, 10), $message);
    }

    private function codes(array $d): array
    {
        return $d['renewal']['degradedCodes'];
    }

    // ═══════════════════════════════════════════════════════ R01–R15

    /**
     * R01（用户三症状本体）：旧年卡 status=5 但 deadline 已过 30 天 + 新年卡 +300 天。
     * 期望：不在待续费、why=[]、expire_date=新卡、旧卡只作历史且 remaining=0。
     */
    public function test_r01_old_expired_annual_card_must_not_shadow_new_one(): void
    {
        $old = $this->card('精品年卡', 2, '5', $this->ymd(-30), 30, [
            'card_id' => 'prod-annual', 'card_no' => 'A-OLD', 'create_time' => $this->ymd(-395),
        ]);
        $new = $this->card('精品年卡', 2, '5', $this->ymd(300), 0, [
            'card_id' => 'prod-annual', 'card_no' => 'A-NEW', 'create_time' => $this->ymd(-20),
            'expiry_days' => 365,
        ]);

        $sum = $this->aggregate([$old, $new]);
        $c = $this->member('R01 旧年卡已过期', [$old, $new]);
        $d = $this->decide($c);

        // ① 不在待续费，理由为空（旧卡不得产生任何命中）
        $this->assertFalse($d['renewal']['in'], 'R01：旧年卡已过期，不得进待续费');
        $this->assertSame([], $d['renewal']['why'], 'R01：不得有任何为什么');
        $this->assertSame('—', $d['renewal']['bucket']);
        $this->assertSame('P4', $d['layer'], 'R01：非 P0');

        // ② expire_date 不得被旧卡拉早 + 新卡必须真的被计算
        $this->assertDate(300, $d['display']['expireDate'], 'R01：expire_date 必须是新卡的到期日');
        $this->assertSame('current', $d['display']['expireDateState']);
        $this->assertSame('current', $d['display']['mainCardState']);
        $this->assertDate(300, (string) $c->fresh()->expire_date, 'R01：落库列同样不得被拉早');

        $this->assertCount(1, $sum['cards_list'], 'R01：cards_list 只应含当前实例（新卡）');
        $this->assertSame('A-NEW', $sum['cards_list'][0]['cardNo']);
        $this->assertSame(300, $sum['cards_list'][0]['residue'], 'R01：新卡剩余天数必须被算出（旧实现=0）');
        $this->assertSame(300, $sum['card_stats']['daysLeft']);

        // ③ 旧卡降级为历史，且期限卡余量按 deadline 计为 0
        $this->assertCount(1, $sum['card_stats']['historyCards']);
        $this->assertSame('expired_by_clock', $sum['card_stats']['historyCards'][0]['historyReason']);
        $this->assertSame(0, $sum['card_stats']['historyCards'][0]['residue']);
        $this->assertSame(0, $this->instanceOf($d, 'A-OLD')['remaining']);
        $this->assertSame(['time_card_residue_ignored'], $this->codes($d), 'R01：唯一下降级码是「期限卡脏 residue 被忽略」');
    }

    /** R02：同上但旧卡 status=6（回归） */
    public function test_r02_old_expired_annual_card_status6_is_regression_clean(): void
    {
        $old = $this->card('精品年卡', 2, '6', $this->ymd(-30), 30, ['card_no' => 'B-OLD']);
        $new = $this->card('精品年卡', 2, '5', $this->ymd(300), 0, ['card_no' => 'B-NEW', 'expiry_days' => 365]);

        $c = $this->member('R02 旧年卡 status6', [$old, $new]);
        $d = $this->decide($c);

        $this->assertFalse($d['renewal']['in']);
        $this->assertSame([], $d['renewal']['why']);
        $this->assertSame('P4', $d['layer']);
        $this->assertDate(300, $d['display']['expireDate']);
        $this->assertSame('expired', $this->instanceOf($d, 'B-OLD')['historyReason']);
    }

    /**
     * R03：旧年卡已过期 + 新年卡 status=7 未开卡。
     * 期望：待开卡（不是待续费），why 只讲未开卡 + 历史卡不计入。
     */
    public function test_r03_expired_old_card_plus_unactivated_new_card_is_pending_activation(): void
    {
        $old = $this->card('精品年卡', 2, '5', $this->ymd(-30), 30, [
            'card_id' => 'prod-annual', 'card_no' => 'C-OLD', 'create_time' => $this->ymd(-395),
        ]);
        $new = $this->card('精品年卡', 2, '7', null, 0, [
            'card_id' => 'prod-annual', 'card_no' => 'C-NEW', 'create_time' => $this->ymd(-2),
        ]);

        $c = $this->member('R03 旧卡过期新卡未开', [$old, $new]);
        $d = $this->decide($c);

        $this->assertFalse($d['renewal']['in'], 'R03：待开卡不得进待续费');
        $this->assertSame('待开卡', $d['renewal']['bucket']);
        $this->assertSame('P4', $d['layer']);

        $why = implode(' | ', $d['renewal']['why']);
        $this->assertStringContainsString('尚未开卡', $why);
        $this->assertStringContainsString('已过期卡', $why, 'R03：必须说明那张过期卡不计入');
        $this->assertStringContainsString('同名第 2 张', $why, 'R03：同 card_id 的两张卡必须可归因');
        $this->assertNotContains('count_exhausted', $d['renewal']['whyCodes'], 'R03：待开卡不得出现「课时已耗尽」');
        $this->assertContains('unactivated_with_history', $d['renewal']['whyCodes']);

        $watch = $this->lists()['watch']['待开卡'];
        $this->assertArrayHasKey($c->id, $watch, 'R03：待开卡池必须能看见他');
        $this->assertSame('unactivated', $d['display']['mainCardState']);
        // 无当前实例：到期日回退到最近历史（显式标注 state，不冒充 current）
        $this->assertSame('history', $d['display']['expireDateState']);
    }

    /**
     * R04（规范 C5，与简报 §7 的 P3 不同）：只剩一张已过期「期限卡」。
     * 期限卡到期即失效 → 过期余额恒 0 → P4，不是 P3。
     */
    public function test_r04_expired_time_card_alone_is_p4_and_has_no_expired_balance(): void
    {
        $row = $this->card('精品年卡', 2, '5', $this->ymd(-30), 30, ['card_no' => 'D-1']);
        $c = $this->member('R04 只剩过期年卡', [$row]);
        $d = $this->decide($c);

        $this->assertFalse($d['renewal']['in'], 'R04：过期期限卡永不进待续费');
        $this->assertSame([], $d['renewal']['why']);
        $this->assertSame('P4', $d['layer'], 'R04/C5：期限卡过期不产生过期余额 → P4');
        $this->assertFalse($d['expiredBalance']['in']);
        $this->assertSame(0, $this->instanceOf($d, 'D-1')['remaining']);

        $note = (string) ($d['renewal']['instances'][0]['note'] ?? '');
        $this->assertStringContainsString('到期', $note);
        $this->assertStringContainsString('剩余天数计为 0', $note);
        $this->assertStringNotContainsString('仍余', $note);
        $this->assertStringNotContainsString('30 天', $note, 'R04：不得把脏 residue 当成剩余天数');
    }

    /** R04b：同形态换成「次卡」→ 过期有余额 P3（这才是简报 R04 的场景） */
    public function test_r04b_expired_count_card_alone_is_p3_and_filterable(): void
    {
        $row = $this->card('私教10次', 1, '6', $this->ymd(-30), 30, ['card_no' => 'E-1']);
        $c = $this->member('R04b 只剩过期次卡', [$row]);
        $d = $this->decide($c);

        $this->assertFalse($d['renewal']['in']);
        $this->assertSame([], $d['renewal']['why']);
        $this->assertSame('P3', $d['layer'], 'R04b：有过期实例且仍有余额 → P3');
        $this->assertTrue($d['expiredBalance']['in']);
        $this->assertSame(30, $d['expiredBalance']['sections']);

        $note = implode(' | ', $d['expiredBalance']['why']);
        $this->assertStringContainsString('已过期 30 天', $note);
        $this->assertStringContainsString('余 30 节', $note);
        $this->assertStringContainsString('不触发待续费', $note);
        $this->assertStringContainsString('E-1', $note, 'R04b：历史卡也要带身份，便于核对');

        $ids = memberListIds();
        $this->assertContains($c->id, $ids['过期有余额'], 'R04b：会员管理筛选「过期有余额」必须命中');
        $this->assertNotContains($c->id, $ids['待续课'], 'R04b：不得进待续费清单');
    }

    /**
     * R05：单张年卡 20 天后到期（F1 修复后的主场景：residue_days=20、residue_amount=0）。
     * 理由必须含「20 天后到期 / 剩余 20 天」+ 卡号 + 发卡日，且与出勤无关地判紧急。
     */
    public function test_r05_time_card_due_in_20_days_is_urgent_with_identity(): void
    {
        $row = $this->card('精品年卡', 2, '5', $this->ymd(20), 0, [
            'card_no' => 'F-2025', 'create_time' => '2025-09-01', 'residue_days' => 20, 'expiry_days' => 365,
        ]);
        $c = $this->member('R05 年卡 20 天后到期', [$row], ['attend_m3' => 0, 'attend_m2' => 0, 'attend_m1' => 0]);
        $d = $this->decide($c);

        $this->assertTrue($d['renewal']['in']);
        $this->assertSame('待续费·紧急', $d['renewal']['bucket']);
        $this->assertTrue($d['renewal']['urgent'], 'R05：临期命中即使 m3=0 也必须紧急');
        $this->assertSame('P0', $d['layer']);
        $this->assertSame(['deadline_near'], $d['renewal']['whyCodes']);

        $why = $d['renewal']['why'][0];
        $this->assertStringContainsString('20 天后到期', $why);
        $this->assertStringContainsString('剩余 20 天', $why);
        $this->assertStringContainsString('F-2025', $why, 'R05：理由必须含卡号');
        $this->assertStringContainsString('2025-09-01', $why, 'R05：理由必须含发卡日');
        $this->assertSame([], $this->codes($d), 'R05：本月卡场景不应有任何降级噪音');
        $this->assertSame(20.0, $d['renewal']['hits'][0]['values']['remaining']);
    }

    /** R06a：单张次卡剩 8 节（阈值 10）→ 命中，紧急因 m3>0 */
    public function test_r06a_count_card_residue_below_threshold_is_hit(): void
    {
        $row = $this->card('私教20次', 1, '5', $this->ymd(200), 8, ['card_no' => 'G-8']);
        $c = $this->member('R06a 次卡剩 8 节', [$row]);
        $d = $this->decide($c);

        $this->assertTrue($d['renewal']['in']);
        $this->assertSame('待续费·紧急', $d['renewal']['bucket']);
        $this->assertSame(['count_card_low'], $d['renewal']['whyCodes']);
        $why = $d['renewal']['why'][0];
        $this->assertStringContainsString('仅余 8 节', $why);
        $this->assertStringContainsString('≤ 10 节', $why);
        $this->assertStringContainsString('G-8', $why);
    }

    /** R06b：同卡但 m3=0 → 观察态（回归 C4：出勤只决定紧急度） */
    public function test_r06b_same_tail_card_without_attendance_is_observe(): void
    {
        $row = $this->card('私教20次', 1, '5', $this->ymd(200), 8);
        $c = $this->member('R06b 次卡剩 8 节无出勤', [$row], ['attend_m1' => 0, 'attend_m2' => 0, 'attend_m3' => 0]);
        $d = $this->decide($c);

        $this->assertTrue($d['renewal']['in'], 'R06b：出勤不再是入册门槛');
        $this->assertFalse($d['renewal']['urgent']);
        $this->assertSame('待续费·观察', $d['renewal']['bucket']);
        $this->assertSame('P0', $d['layer']);
    }

    /** R07：次卡尾段 + 年卡 300 天 → 逐卡判定，理由只指向次卡 */
    public function test_r07_count_tail_plus_ample_annual_card_only_blames_count_card(): void
    {
        $count = $this->card('私教10次', 1, '5', $this->ymd(200), 2, ['card_no' => 'H-2']);
        $annual = $this->card('精品年卡', 2, '5', $this->ymd(300), 0, ['card_no' => 'H-Y', 'expiry_days' => 365]);
        $c = $this->member('R07 次卡尾段+年卡充裕', [$count, $annual]);
        $d = $this->decide($c);

        $this->assertTrue($d['renewal']['in']);
        $this->assertSame('待续费·紧急', $d['renewal']['bucket']);
        $this->assertSame(['count_card_low'], $d['renewal']['whyCodes']);
        $this->assertCount(1, $d['renewal']['why'], 'R07：年卡不产生任何 why');
        $this->assertStringContainsString('私教10次', $d['renewal']['why'][0]);
        $this->assertStringContainsString('H-2', $d['renewal']['why'][0]);
        $this->assertStringNotContainsString('精品年卡', $d['renewal']['why'][0]);
    }

    /** R08：次卡用完 + 年卡 300 天 → 不得因「已耗尽」误报（R-X1 守卫是整库 USABLE=∅） */
    public function test_r08_exhausted_count_card_plus_usable_annual_card_does_not_hit(): void
    {
        $count = $this->card('私教10次', 1, '5', $this->ymd(5), 0, ['card_no' => 'I-0']);
        $annual = $this->card('精品年卡', 2, '5', $this->ymd(300), 0, ['card_no' => 'I-Y', 'expiry_days' => 365]);
        $c = $this->member('R08 次卡用完+年卡', [$count, $annual]);
        $d = $this->decide($c);

        $this->assertFalse($d['renewal']['in'], 'R08：还有可用年卡时不得判「课时已耗尽」');
        $this->assertSame([], $d['renewal']['why']);
        $this->assertNotContains('count_exhausted', $d['renewal']['whyCodes']);
        $this->assertSame('P4', $d['layer']);
    }

    /** R09：储值卡 200 元 → 单位是元、不进待续费、不显示成 200 节 */
    public function test_r09_stored_value_card_keeps_yuan_unit(): void
    {
        $row = $this->card('储值卡200', 3, '5', $this->ymd(300), 200, ['card_no' => 'J-200']);
        $sum = $this->aggregate([$row]);
        $c = $this->member('R09 储值卡 200 元', [$row]);
        $d = $this->decide($c);

        $this->assertSame('元', $sum['cards_list'][0]['unit'], 'R09/I5：储值卡单位必须是元');
        $this->assertEqualsWithDelta(200.0, (float) $sum['cards_list'][0]['residue'], 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $sum['card_stats']['entitlement']['storedValue'], 0.001);
        $this->assertNull($sum['remain_times'], 'R09：储值卡不得被当成次卡计入 remain_times');

        $this->assertFalse($d['renewal']['in'], 'R09：阈值 0=关闭，储值卡不参与续费判定');
        $this->assertSame([], $d['renewal']['why']);
        $this->assertSame('P4', $d['layer']);
        $this->assertSame(0, $d['display']['displayCount'], 'R09：200 元不得进入「次卡合计」');
        $this->assertEqualsWithDelta(200.0, $d['display']['storedValue'], 0.001);
        $this->assertStringNotContainsString('200 节', json_encode($d, JSON_UNESCAPED_UNICODE));

        // 唯一的当前实例允许作为到期日（规范 R09 明确允许）
        $this->assertDate(300, $d['display']['expireDate']);
    }

    /** R10：只有退卡(29) + 停卡(39) → 不是 P5、不是炸弹、没有过期余额 */
    public function test_r10_refunded_and_suspended_only_are_not_p5_and_not_bomb(): void
    {
        $refund = $this->card('私教30次', 1, '29', $this->ymd(-400), 30, [
            'card_no' => 'K-29', 'create_time' => $this->ymd(-600),
        ]);
        $suspend = $this->card('私教20次', 1, '39', $this->ymd(-400), 20, [
            'card_no' => 'K-39', 'create_time' => $this->ymd(-500),
        ]);
        $sum = $this->aggregate([$refund, $suspend]);
        $c = $this->member('R10 只有退卡停卡', [$refund, $suspend]);
        $d = $this->decide($c);

        $this->assertTrue($d['hasAnyCard'], 'R10：退卡/停卡也是「曾经有卡」的证据');
        $this->assertFalse($d['renewal']['in']);
        $this->assertSame([], $d['renewal']['why']);
        $this->assertSame('P4', $d['layer'], 'R10：不得落 P5');
        $this->assertFalse($d['bomb']['in'], 'R10：29/39 永不参与炸弹');
        $this->assertFalse($d['expiredBalance']['in'], 'R10：29/39 永不参与过期有余额');
        $this->assertSame([], $sum['card_stats']['expiredCards'], 'R10/S11：兼容区也不得收 29/39');
        $this->assertSame('history', $d['display']['mainCardState']);
        $this->assertSame('私教20次', $d['display']['mainCard'], 'R10：主卡=发卡时间最新的历史卡（停卡）');
    }

    /** R11：同名两张年卡（+20 / +300）→ 命中归因到「同名第 1 张 · 卡号 · 发卡」 */
    public function test_r11_same_name_two_annual_cards_are_distinguished_by_identity(): void
    {
        $old = $this->card('精品年卡', 2, '5', $this->ymd(20), 0, [
            'card_id' => 'prod-annual', 'card_no' => 'L-OLD', 'create_time' => $this->ymd(-345), 'expiry_days' => 365,
        ]);
        $new = $this->card('精品年卡', 2, '5', $this->ymd(300), 0, [
            'card_id' => 'prod-annual', 'card_no' => 'L-NEW', 'create_time' => $this->ymd(-65), 'expiry_days' => 365,
        ]);
        $c = $this->member('R11 同名两张年卡', [$old, $new]);
        $d = $this->decide($c);

        $this->assertTrue($d['renewal']['in']);
        $this->assertSame('待续费·紧急', $d['renewal']['bucket']);
        $this->assertSame(['deadline_near'], $d['renewal']['whyCodes']);
        $why = $d['renewal']['why'][0];
        $this->assertStringContainsString('同名第 1 张', $why, 'R11/I8：必须标明是第几张');
        $this->assertStringContainsString('L-OLD', $why);
        $this->assertStringContainsString($this->ymd(-345), $why, 'R11/I8：必须含发卡日');
        $this->assertStringNotContainsString('L-NEW', $why, 'R11：未命中的新卡不得出现在理由里');
        $this->assertSame('L-OLD', $d['renewal']['hits'][0]['cardNo']);

        // 当前实例最早到期日 = 旧卡 +20（两张都是 current，谁更早谁决定展示）
        $this->assertDate(20, $d['display']['expireDate']);
        $this->assertSame('current', $d['display']['expireDateState']);
    }

    /**
     * R12：旧 cards_list（无 id/card_no/soldAt/statusCode/residueDays）→ 不报错，按 deadline 判临期。
     */
    public function test_r12_legacy_cards_list_without_identity_still_judged_by_deadline(): void
    {
        $c = $this->legacyMember('R12 旧版 cards_list', [
            'main_card' => '精品年卡',
            'remain_times' => 0,
            'expire_date' => $this->ymd(20),
            'cards_list' => [[
                'title' => '精品年卡', 'type' => '2', 'unit' => '天',
                'residue' => 0, 'deadline' => $this->ymd(20), 'status' => '正常',
            ]],
            'card_stats' => ['countResidue' => 0],
        ]);
        $d = $this->decide($c);

        $this->assertTrue($d['renewal']['in'], 'R12/I9：旧 cards_list 也必须能判出临期');
        $this->assertSame('待续费·紧急', $d['renewal']['bucket']);
        $this->assertSame(['deadline_near'], $d['renewal']['whyCodes']);
        $this->assertSame(20, $d['renewal']['instances'][0]['remaining'], 'R12/A03：剩余天数由 deadline 算出，不依赖 residueDays');
        $this->assertSame(20, $d['display']['nextExpireDays']);
        $this->assertContains('status_label_only', $this->codes($d), 'R12：中文状态必须被映射并登记降级');
        $this->assertContains('identity_missing', $this->codes($d), 'R12：缺身份必须登记降级');
        $this->assertStringContainsString('20 天后到期', $d['renewal']['why'][0]);
    }

    /** R13：期限卡 deadline 已过 60 天但 residue_amount=200（脏数据）→ 以 deadline 为准 */
    public function test_r13_dirty_time_card_residue_never_claims_remaining_days(): void
    {
        $row = $this->card('精品年卡', 2, '5', $this->ymd(-60), 200, ['card_no' => 'M-DIRTY']);
        $sum = $this->aggregate([$row]);
        $c = $this->member('R13 期限卡脏 residue', [$row]);
        $d = $this->decide($c);

        $this->assertFalse($d['renewal']['in']);
        $this->assertSame([], $d['renewal']['why'], 'R13：历史卡不得产生命中');
        $this->assertSame('P4', $d['layer']);
        $this->assertSame(0, $this->instanceOf($d, 'M-DIRTY')['remaining'], 'R13/I4：期限卡余量只由 deadline 推导');
        $this->assertSame(0, $sum['card_stats']['historyCards'][0]['residue']);
        $this->assertNull($sum['card_stats']['daysLeft'], 'R13/F1：无在用期限卡时 daysLeft=null（绝不是脏 residue 200）');
        $this->assertContains('time_card_residue_ignored', $this->codes($d));

        $all = json_encode($d, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('仍余 200', $all);
        $this->assertStringNotContainsString('200 天', $all, 'R13：用户症状文案必须消失');
    }

    /** R14：唯一卡是未开卡 → 待开卡（回归 F07） */
    public function test_r14_unactivated_only_is_pending_activation(): void
    {
        $row = $this->card('私教20次', 1, '7', null, 20);
        $c = $this->member('R14 唯一未开卡卡项', [$row]);
        $d = $this->decide($c);

        $this->assertFalse($d['renewal']['in']);
        $this->assertSame('待开卡', $d['renewal']['bucket']);
        $this->assertSame('P4', $d['layer']);
        $this->assertContains('unactivated_open', $d['renewal']['whyCodes']);
        $this->assertArrayHasKey($c->id, $this->lists()['watch']['待开卡']);
    }

    /** R15：出勤 m3=0 + 次卡尾段 → 待续费·观察（回归 C4） */
    public function test_r15_zero_attendance_with_tail_card_is_observe_not_excluded(): void
    {
        $row = $this->card('私教20次', 1, '5', $this->ymd(200), 8);
        $c = $this->member('R15 零出勤次卡尾段', [$row], ['attend_m1' => 0, 'attend_m2' => 0, 'attend_m3' => 0]);
        $d = $this->decide($c);

        $this->assertTrue($d['renewal']['in']);
        $this->assertSame('待续费·观察', $d['renewal']['bucket']);
        $this->assertFalse($d['renewal']['urgent']);
        $this->assertSame('P0', $d['layer']);
    }

    // ═══════════════════════════════════════════════ 任务要求的八类边界

    /** 边界①：同 card_id 两张次卡（旧卡已过期 + 新卡在用）→ 新卡不被旧卡压制 */
    public function test_boundary_same_product_expired_plus_current_count_card(): void
    {
        $old = $this->card('私教30次', 1, '5', $this->ymd(-40), 5, [
            'card_id' => 'prod-pt', 'card_no' => 'N-OLD', 'create_time' => $this->ymd(-400),
        ]);
        $new = $this->card('私教30次', 1, '5', $this->ymd(200), 60, [
            'card_id' => 'prod-pt', 'card_no' => 'N-NEW', 'create_time' => $this->ymd(-40),
        ]);
        $sum = $this->aggregate([$old, $new]);
        $c = $this->member('边界 同卡种旧过期新在用', [$old, $new]);
        $d = $this->decide($c);

        $this->assertCount(1, $sum['cards_list'], '同 card_id 只应把「当前」那张放进 cards_list');
        $this->assertSame('N-NEW', $sum['cards_list'][0]['cardNo']);
        $this->assertSame(60, $sum['cards_list'][0]['residue']);
        $this->assertFalse($d['renewal']['in'], '旧卡过期不得触发续费；新卡 60 节远高于阈值');
        $this->assertSame([], $d['renewal']['why']);
        $this->assertDate(200, $d['display']['expireDate']);
        $this->assertSame('current', $d['display']['mainCardState']);
        // 旧卡的 5 节属于「过期有余额」，只能进 P3 与筛选，不进 why
        $this->assertTrue($d['expiredBalance']['in']);
        $this->assertSame('P3', $d['layer']);
        $this->assertSame(60, $d['display']['usableCount'], 'usableCount 只算当前实例');
    }

    /** 边界①b：同 card_id（旧卡过期 + 新卡未开卡）→ 待开卡，且不得说「课时已耗尽」 */
    public function test_boundary_same_product_expired_plus_unactivated(): void
    {
        $old = $this->card('私教30次', 1, '5', $this->ymd(-40), 5, [
            'card_id' => 'prod-pt2', 'card_no' => 'O-OLD', 'create_time' => $this->ymd(-400),
        ]);
        $new = $this->card('私教30次', 1, '7', null, 30, [
            'card_id' => 'prod-pt2', 'card_no' => 'O-NEW', 'create_time' => $this->ymd(-3),
        ]);
        $c = $this->member('边界 同卡种旧过期新未开', [$old, $new]);
        $d = $this->decide($c);

        $this->assertFalse($d['renewal']['in']);
        $this->assertSame('待开卡', $d['renewal']['bucket']);
        $this->assertNotContains('count_exhausted', $d['renewal']['whyCodes']);
        $why = implode(' | ', $d['renewal']['why']);
        $this->assertStringContainsString('尚未开卡', $why);
        $this->assertStringContainsString('同名第 2 张', $why);
        $this->assertStringContainsString('O-NEW', $why);
    }

    /** 边界②：期限卡 deadline 已过但 residue_days>0 的脏数据 → 仍以 deadline 为准，不泄露天数 */
    public function test_boundary_time_card_past_deadline_but_positive_residue_days(): void
    {
        $row = $this->card('精品年卡', 2, '5', $this->ymd(-10), 0, [
            'card_no' => 'P-DIRTY', 'residue_days' => 25,
        ]);
        $sum = $this->aggregate([$row]);
        $c = $this->member('边界 期限卡 residue_days 脏', [$row]);
        $d = $this->decide($c);

        $this->assertFalse($d['renewal']['in']);
        $this->assertSame([], $d['renewal']['why']);
        $this->assertSame(0, $this->instanceOf($d, 'P-DIRTY')['remaining']);
        $this->assertCount(0, $sum['cards_list'], '边界②：已过期的期限卡不得进 cards_list');
        $this->assertStringNotContainsString('25 天', json_encode($d, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('仍余', json_encode($d, JSON_UNESCAPED_UNICODE));
    }

    /** 边界③：储值卡与次卡混用 → 单位分组，why 只由次卡触发（元不与节比较） */
    public function test_boundary_stored_and_count_cards_never_cross_units(): void
    {
        $stored = $this->card('储值卡', 3, '5', $this->ymd(300), 5, ['card_no' => 'Q-5']);
        $count = $this->card('私教10次', 1, '5', $this->ymd(200), 8, ['card_no' => 'Q-8']);
        $sum = $this->aggregate([$stored, $count]);
        $c = $this->member('边界 储值+次卡', [$stored, $count]);
        $d = $this->decide($c);

        $units = array_column($sum['cards_list'], 'unit');
        sort($units);
        $this->assertSame(['元', '节'], $units, '边界③：单位按卡分组，不得混算');
        $this->assertSame(8, $sum['card_stats']['entitlement']['usableCount'], 'usableCount 不含储值卡余额');

        $this->assertTrue($d['renewal']['in']);
        $this->assertSame(['count_card_low'], $d['renewal']['whyCodes']);
        $why = implode(' | ', $d['renewal']['why']);
        $this->assertStringContainsString('仅余 8 节', $why);
        $this->assertStringNotContainsString('仅余 5 节', $why, '边界③：5 元不得被读成 5 节');
        $this->assertEqualsWithDelta(5.0, $d['display']['storedValue'], 0.001);
        $this->assertSame(8, $d['display']['displayCount']);
    }

    /** 边界④：只有停卡/退卡/体验卡 → 不是 P5、不炸弹、不进待续费 */
    public function test_boundary_only_suspended_refunded_and_taste_cards(): void
    {
        $refund = $this->card('私教30次', 1, '29', $this->ymd(-400), 30, ['card_no' => 'R-29']);
        $suspend = $this->card('私教20次', 1, '39', $this->ymd(-400), 20, ['card_no' => 'R-39']);
        $taste = $this->card('新人体验课2次', 1, '5', $this->ymd(300), 2, ['card_no' => 'R-T']);
        $sum = $this->aggregate([$refund, $suspend, $taste]);
        $c = $this->member('边界 只有停卡退卡体验卡', [$refund, $suspend, $taste]);
        $d = $this->decide($c);

        $this->assertTrue($d['hasAnyCard'], '边界④：29/39 是「曾经有卡」的证据');
        $this->assertFalse($d['renewal']['in']);
        $this->assertSame('P4', $d['layer'], '边界④：有卡但无可用权益 → P4，不是 P5');
        $this->assertFalse($d['bomb']['in']);
        $this->assertSame([], $sum['cards_list'], '边界④：体验卡与历史卡都不得进 cards_list');
        $this->assertContains('taste_excluded_by_pattern', $this->codes($d));
        // 体验卡若被当成当前卡会直接命中「仅余 2 节」，这里必须没有
        $this->assertNotContains('count_card_low', $d['renewal']['whyCodes']);
    }

    /** 边界④b：只有体验卡 → 无资产 P5（§3.6；规范 §8.2 A11 的 layer 列与本条冲突，按 §3.6 判） */
    public function test_boundary_taste_only_member_is_p5_with_exclusion_note(): void
    {
        $taste = $this->card('新人礼包2次', 1, '5', $this->ymd(300), 2, ['is_taste' => '0']);
        $c = $this->member('边界 只有礼包卡', [$taste]);
        $d = $this->decide($c);

        $this->assertFalse($d['hasAnyCard'], '§3.6：体验/赠品卡不计入「有卡」');
        $this->assertFalse($d['renewal']['in'], '边界④b：礼包卡不得进 P0（D10-④）');
        $this->assertSame('P5', $d['layer']);
        $this->assertContains('taste_excluded_by_pattern', $this->codes($d));
    }

    /** 边界⑤：旧版 cards_list 里已过期的行 → 不报错，按 deadline 正确判过期 */
    public function test_boundary_legacy_cards_list_expired_row_is_history(): void
    {
        $c = $this->legacyMember('边界 旧 cards_list 过期行', [
            'main_card' => '历史私教',
            'expire_date' => $this->ymd(-20),
            'cards_list' => [[
                'title' => '历史私教', 'type' => '1', 'unit' => '节',
                'residue' => 30, 'deadline' => $this->ymd(-20), 'status' => '过期',
            ]],
            'card_stats' => ['countResidue' => 0],
        ]);
        $d = $this->decide($c);

        $this->assertFalse($d['renewal']['in'], '边界⑤：旧版数据里的过期卡不得进待续费');
        $this->assertSame([], $d['renewal']['why']);
        $this->assertSame('P3', $d['layer']);
        $this->assertTrue($d['expiredBalance']['in']);
        $this->assertSame('expired', $d['renewal']['instances'][0]['historyReason'], '边界⑤：中文「过期」必须映射为 6 并按 deadline 归入历史');
        $this->assertContains('history_not_synced', $this->codes($d));
    }

    /** 边界⑥：跨店同名会员不合并（回归） */
    public function test_boundary_cross_venue_same_name_members_are_not_merged(): void
    {
        $a = $this->member('林小满', [$this->card('私教A', 1, '5', $this->ymd(200), 8, ['card_no' => 'S-A'])]);
        $b = $this->member('林小满', [$this->card('私教B', 1, '5', $this->ymd(200), 8, ['card_no' => 'S-B'])], [
            'venue' => '滨江店',
        ]);

        $this->assertNotSame($a->id, $b->id);
        $lists = $this->lists();
        $this->assertContains($a->id, $lists['lists']['待续课']);
        $this->assertContains($b->id, $lists['lists']['待续课']);
        $this->assertCount(2, $lists['lists']['待续课'], '边界⑥：同名不同店必须是两个独立会员');

        $whyA = implode(' | ', $lists['watch']['待续费'][$a->id]['why']);
        $whyB = implode(' | ', $lists['watch']['待续费'][$b->id]['why']);
        $this->assertStringContainsString('私教A', $whyA);
        $this->assertStringNotContainsString('私教B', $whyA);
        $this->assertStringContainsString('私教B', $whyB);
        $this->assertStringNotContainsString('私教A', $whyB);
    }

    // ─────────────────────────────────────────────── 阈值与窗口边界

    /** 边界⑦a：次卡绝对阈值 = / −1 / +1 */
    public function test_boundary_count_threshold_equality_and_plus_minus_one(): void
    {
        $eq = $this->member('阈值 =10', [$this->card('私教A', 1, '5', $this->ymd(200), 10)]);
        $minus = $this->member('阈值 9', [$this->card('私教B', 1, '5', $this->ymd(200), 9)]);
        $plus = $this->member('阈值 11', [$this->card('私教C', 1, '5', $this->ymd(200), 11)]);

        $this->assertTrue($this->decide($eq)['renewal']['in'], '阈值 =10 必须命中（<= 语义）');
        $this->assertTrue($this->decide($minus)['renewal']['in']);
        $this->assertFalse($this->decide($plus)['renewal']['in'], '阈值 11 不得命中（占比 100% 也不该触发）');
        $this->assertSame(11, $this->decide($plus)['renewal']['instances'][0]['remaining']);
    }

    /** 边界⑦b：次卡占比刚好等于阈值 / 刚超过 */
    public function test_boundary_count_percent_exactly_equal_and_just_above(): void
    {
        // 11/(11+44)=20.0% → 命中；11/(11+43)=20.37% → 不命中
        $equal = $this->member('占比=20%', [
            $this->card('私教55次', 1, '5', $this->ymd(300), 11, ['usage_total' => 44]),
        ]);
        $above = $this->member('占比20.4%', [
            $this->card('私教54次', 1, '5', $this->ymd(300), 11, ['usage_total' => 43]),
        ]);

        $dEqual = $this->decide($equal);
        $this->assertTrue($dEqual['renewal']['in'], '边界⑦b：占比 =20% 必须命中（<= 语义）');
        $this->assertSame(['count_card_percent_low'], $dEqual['renewal']['whyCodes']);
        $this->assertStringContainsString('20%', $dEqual['renewal']['why'][0]);

        $dAbove = $this->decide($above);
        $this->assertFalse($dAbove['renewal']['in'], '边界⑦b：20.37% 不得命中');
        $this->assertSame(54, $dAbove['renewal']['instances'][0]['bound'], '分母=剩余+已耗次数（金额口径）');
    }

    /** 边界⑦c：期限卡到期日阈值 30 / 31 */
    public function test_boundary_expire_days_30_and_31(): void
    {
        $in30 = $this->member('到期30', [$this->card('年卡A', 2, '5', $this->ymd(30), 0, ['expiry_days' => 365])]);
        $in31 = $this->member('到期31', [$this->card('年卡B', 2, '5', $this->ymd(31), 0, ['expiry_days' => 365])]);

        $this->assertTrue($this->decide($in30)['renewal']['in'], '边界⑦c：daysUntil=30 必须命中');
        $this->assertFalse($this->decide($in31)['renewal']['in'], '边界⑦c：daysUntil=31 不得命中');
    }

    /** 边界⑦d：期限卡有效期占比 20% / 21% */
    public function test_boundary_expire_percent_exactly_equal_and_just_above(): void
    {
        $this->withRules(['renewalExpireDays' => 0, 'renewalExpirePercent' => 20]);

        $equal = $this->member('有效期占比20%', [
            $this->card('年卡C', 2, '5', $this->ymd(20), 0, ['expiry_days' => 100]),
        ]);
        $above = $this->member('有效期占比21%', [
            $this->card('年卡D', 2, '5', $this->ymd(21), 0, ['expiry_days' => 100]),
        ]);

        $dEqual = $this->decide($equal);
        $this->assertTrue($dEqual['renewal']['in'], '边界⑦d：20/100=20% 必须命中');
        $this->assertSame(['validity_percent'], $dEqual['renewal']['whyCodes']);
        $this->assertFalse($this->decide($above)['renewal']['in'], '边界⑦d：21/100=21% 不得命中');
    }

    /** 边界⑦e：储值卡阈值 300（=阈值命中 / +1 不命中） */
    public function test_boundary_stored_value_threshold(): void
    {
        $this->withRules(['renewalStoredValueThreshold' => 300]);

        $eq = $this->member('储值300', [$this->card('储值卡A', 3, '5', $this->ymd(300), 300)]);
        $plus = $this->member('储值301', [$this->card('储值卡B', 3, '5', $this->ymd(300), 301)]);

        $dEq = $this->decide($eq);
        $this->assertTrue($dEq['renewal']['in'], '边界⑦e：余额=300 必须命中（<= 语义）');
        $this->assertContains('stored_value_low', $dEq['renewal']['whyCodes']);
        $this->assertStringContainsString('300.00 元 ≤ 300 元', $dEq['renewal']['why'][0]);
        $this->assertSame('元', $dEq['renewal']['hits'][0]['unit']);

        $this->assertFalse($this->decide($plus)['renewal']['in'], '边界⑦e：余额=301 不得命中');
    }

    /** 边界⑦f：过期有余额展示窗 90 / 91 */
    public function test_boundary_expired_balance_window_90_and_91(): void
    {
        $in90 = $this->member('过期90天', [$this->card('私教A', 1, '6', $this->ymd(-90), 30)]);
        $out91 = $this->member('过期91天', [$this->card('私教B', 1, '6', $this->ymd(-91), 30)]);

        $dIn = $this->decide($in90);
        $this->assertTrue($dIn['expiredBalance']['in'], '边界⑦f：过期第 90 天仍在展示窗内');
        $this->assertSame('P3', $dIn['layer']);

        $dOut = $this->decide($out91);
        $this->assertFalse($dOut['expiredBalance']['in'], '边界⑦f：过期第 91 天出窗');
        $this->assertSame('P4', $dOut['layer'], '边界⑦f：出窗后必须是 P4，不是 P3');

        $lists = $this->lists();
        $this->assertContains($in90->id, $lists['lists']['过期有余额']);
        $this->assertNotContains($out91->id, $lists['lists']['过期有余额']);
    }

    // ─────────────────────────────────────────────── 最后一天 / 文案反例

    /** 边界⑧（A17）：期限卡最后一天 → 必须仍然提醒（不能整条消失） */
    public function test_boundary_time_card_last_day_is_still_reported(): void
    {
        $row = $this->card('年卡今天到期', 2, '5', $this->ymd(0), 0, ['card_no' => 'T-0', 'residue_days' => 0]);
        $c = $this->member('边界 期限卡最后一天', [$row]);
        $d = $this->decide($c);

        $this->assertTrue($d['renewal']['in'], '边界⑧：最后一天必须命中');
        $this->assertSame(['deadline_near'], $d['renewal']['whyCodes']);
        $this->assertSame(0, $this->instanceOf($d, 'T-0')['daysUntil']);
        $this->assertSame(0, $this->instanceOf($d, 'T-0')['remaining']);
        $this->assertStringContainsString('今日到期', $d['renewal']['why'][0]);
    }

    /**
     * 反例扫描：所有 why 文案里不得出现「已过期却仍余 N」这一类自相矛盾口径。
     *
     * 这是用户症状的字符串形态；结构化断言（why=[] / whyCodes）已在各条用例里，
     * 这里再对「混合形态」做一次地毯式扫描，防止某条新规则把历史卡文案漏进 why。
     */
    public function test_why_texts_never_claim_expired_cards_still_have_balance(): void
    {        $cases = [
            $this->member('扫描1', [
                $this->card('年卡旧', 2, '5', $this->ymd(-30), 30),
                $this->card('年卡新', 2, '5', $this->ymd(300), 0, ['expiry_days' => 365]),
            ]),
            $this->member('扫描2', [$this->card('次卡旧', 1, '6', $this->ymd(-40), 12)]),
            $this->member('扫描3', [$this->card('年卡脏', 2, '5', $this->ymd(-60), 200)]),
            $this->member('扫描4', [
                $this->card('次卡尾', 1, '5', $this->ymd(200), 7),
                $this->card('次卡旧', 1, '6', $this->ymd(-50), 20),
            ]),
            $this->member('扫描5', [$this->card('年卡临期', 2, '5', $this->ymd(10), 0, ['expiry_days' => 365])]),
        ];

        foreach ($cases as $c) {
            $this->decide($c);
        }

        $this->assertNotSame([], $this->collectedWhy, '扫描应至少收集到一条 why，否则等于空转');

        foreach ($this->collectedWhy as $text) {
            $this->assertStringNotContainsString('仍余', $text);
            $this->assertDoesNotMatchRegularExpression('/已过期/u', $text, '历史卡口径不得进入 renewal.why：'.$text);
        }
    }

    /**
     * 边界②b：期限卡处于历史态但**没有 deadline**（上游脏/缺字段）。
     *
     * 关键不变量：期限卡残余量恒 0（定义，不是缺失），所以它既不能产生过期余额，
     * 也不能进待续费；缺 deadline 必须显式降级（否则运营无法解释「为什么这张卡不提醒」）。
     * 文案层面的瑕疵（note 里出现空到期日）已作为 finding 记入验收报告，不在断言里固化。
     */
    public function test_history_time_card_without_deadline_yields_no_expired_balance(): void
    {
        $row = $this->card('年卡无到期日', 2, '6', null, 0, ['card_no' => 'W-NODATE', 'residue_days' => 10]);
        $sum = $this->aggregate([$row]);
        $c = $this->member('边界 期限卡缺到期日', [$row]);
        $d = $this->decide($c);

        $this->assertFalse($d['renewal']['in']);
        $this->assertSame([], $d['renewal']['why']);
        $this->assertFalse($d['expiredBalance']['in'], 'residualBalance(期限卡) ≡ 0，缺 deadline 也不例外');
        $this->assertSame(0, $sum['card_stats']['historyCards'][0]['residue']);
        $this->assertContains('time_card_no_deadline', $this->codes($d), '缺 deadline 必须显式降级');
        $this->assertContains('history_without_deadline', $this->codes($d));
        $this->assertSame('P4', $d['layer']);
    }

    /**
     * R04b 强化：过期次卡只剩 3 节（落在阈值 10 内）→ 依然不得进待续费。
     *
     * 这是用户症状的「残留余额型」形态：旧实现会把「已过期但还有余量」直接送进待续费。
     * 阈值内的残余量让它成为最容易被误判的一张卡，必须由「历史不在命中域」挡住。
     */
    public function test_expired_count_card_with_residue_inside_threshold_is_still_not_renewal(): void
    {
        $row = $this->card('私教10次', 1, '6', $this->ymd(-15), 3, ['card_no' => 'V-3']);
        $c = $this->member('R04b 强化 过期次卡仅余 3 节', [$row]);
        $d = $this->decide($c);

        $this->assertFalse($d['renewal']['in'], '过期实例即使余量落在阈值内也不得命中');
        $this->assertSame([], $d['renewal']['why']);
        $this->assertNotContains('count_card_low', $d['renewal']['whyCodes']);
        $this->assertSame('P3', $d['layer']);
        $this->assertTrue($d['expiredBalance']['in']);
        $this->assertSame(3, $d['expiredBalance']['sections']);
    }

    /**
     * 结构性不变量（规范 §4.3）：历史实例**永远不是**可用权益，即使它还有余额。
     *
     * 这条不是端到端用例，而是把「排除历史」的守卫本身钉住：
     * 判定层现在靠 `foreach ($current)` 做结构性排除，`isUsable()` 里的
     * `state === current` 是第二道防线。只改后者不改前者时端到端用例全绿
     * （等价变异），所以必须单独断言它，否则将来有人把循环改成 `$all`
     * 就没有任何测试能拦住「过期卡带余额 → 命中尾段」这个原始 bug 的回归。
     */
    public function test_history_instance_is_never_a_usable_entitlement(): void
    {
        $row = $this->card('私教30次', 1, '6', $this->ymd(-30), 30, ['card_no' => 'U-HIST']);
        $i = CardLifecycle::normalize($row);

        $this->assertSame('history', $i['state']);
        $this->assertSame(30.0, (float) $i['remaining'], '历史次卡仍保留残余量（供过期有余额/炸弹使用）');
        $this->assertFalse(CardLifecycle::isUsable($i), '§4.3：历史实例永远不是可用权益');
        $this->assertFalse(CardLifecycle::isExhausted($i), '历史实例也不能被当成「已耗尽」');
        $this->assertFalse(CardLifecycle::deadlineEligible($i, 30), '§2.4：历史实例不得参与到期判定');

        $c = $this->member('结构性不变量', [$row]);
        $d = $this->decide($c);
        $this->assertFalse($d['renewal']['in']);
        $this->assertSame([], $d['renewal']['why']);
    }
}
