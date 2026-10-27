<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\User;
use App\Services\KyMemberSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 「待续费」卡项实例生命周期（v3.9）验收测试。
 *
 * 覆盖 `docs/口径/待续费判定规范-v3.9.md` §8.1 的 R01–R15（逐条对应），
 * 以及 §8.2 中风险最高的 A01/A03/A04/A05/A06/A08/A09/A10/A11/A13/A16/A17/A18。
 *
 * 夹具约定（同 §8.0 公共基线）：`attend_m1 = attend_m2 = attend_m3 = 6`、
 * `last_visit = TODAY-3`、`in_revive = false`、`card_paid_amount = 0`、规则默认值。
 *
 * 这些用例测的是**真实链路**：`KyMemberSyncService::summarizeCards`
 * → `cards_list`/`historyCards`/`entitlement` → `customerDecision` → `computeMemberLists`。
 */
class CardLifecycleRenewalTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────── 夹具

    /**
     * 上游卡项原始行（与 KeepEasy 字段同名，直接喂 summarizeCards）。
     *
     * @param  array<string,mixed>  $extra
     */
    private function card(
        string $title,
        string $type,
        string $status,
        ?int $residue,
        ?string $deadline,
        array $extra = []
    ): array {
        return array_filter(array_merge([
            'card_title' => $title,
            'type' => $type,
            'status' => $status,
            'is_taste' => '0',
            'residue_amount' => $residue,
            'usage_total' => 0,
            'curr_unit_cash_value' => 1,
            'deadline' => $deadline,
            'expiry_days' => $type === '2' ? 365 : 0,
        ], $extra), fn ($v) => $v !== null);
    }

    private function inDays(int $n): string
    {
        return (string) now()->addDays($n)->timestamp;
    }

    private function ymd(int $n): string
    {
        return now()->addDays($n)->toDateString();
    }

    private function summarize(array $cards): array
    {
        $method = new \ReflectionMethod(KyMemberSyncService::class, 'summarizeCards');
        $method->setAccessible(true);

        return $method->invoke(null, $cards);
    }

    /** 建会员：卡项经真实 summarizeCards 聚合后落库（与生产同链路） */
    private function member(string $name, array $cards, int $m3 = 6, array $extra = []): Customer
    {
        $sum = $this->summarize($cards);

        return Customer::create(array_merge([
            'name' => $name,
            'phone' => '1380000'.random_int(1000, 9999),
            'phone_tail' => '0000',
            'venue' => '绿地店',
            'source' => 'KeepYoga',
            'owner' => '店长',
            'consultant' => '店长',
            'status' => '在籍',
            'layer' => 'P4',
            'main_card' => $sum['main_card'],
            'remain_times' => $sum['remain_times'],
            'expire_date' => $sum['expire_date'],
            'card_stats' => $sum['card_stats'],
            'cards_list' => $sum['cards_list'],
            'attend_m1' => $m3,
            'attend_m2' => $m3,
            'attend_m3' => $m3,
            'in_revive' => false,
            'last_visit' => now()->subDays(3)->toDateString(),
        ], $extra));
    }

    /** 直接落库（用于构造旧版 cards_list / card_stats） */
    private function rawMember(string $name, array $attrs): Customer
    {
        return Customer::create(array_merge([
            'name' => $name, 'phone' => '1380000'.random_int(1000, 9999), 'phone_tail' => '0000',
            'venue' => '绿地店', 'source' => 'KeepYoga', 'owner' => '店长', 'consultant' => '店长',
            'status' => '在籍', 'layer' => 'P4', 'attend_m1' => 6, 'attend_m2' => 6, 'attend_m3' => 6,
            'last_visit' => now()->subDays(3)->toDateString(), 'in_revive' => false,
        ], $attrs));
    }

    private function setRules(array $patch): void
    {
        $setting = AppSetting::oldest('id')->first();
        if ($setting) {
            $setting->update(['rules' => array_merge((array) ($setting->rules ?? []), $patch)]);
        } else {
            AppSetting::create(['rules' => $patch]);
        }
        invalidateBusinessCaches('member_lists');
    }

    private function decision(Customer $c, ?array $rules = null): array
    {
        return customerDecision($c->fresh(), $rules);
    }

    private function renewalIds(): array
    {
        return memberListIds()['待续课'] ?? [];
    }

    // ─────────────────────────────────────────────────── R01–R15

    /** R01：旧年卡已过期 + 新年卡有效 → 不命中；expire_date 取新卡；旧卡进 historyCards */
    public function test_r01_expired_old_card_never_shadows_new_card(): void
    {
        $c = $this->member('R01 旧年卡+新年卡', [
            $this->card('精品年卡', '2', '5', 30, $this->inDays(-30), ['id' => 'old-1']),
            $this->card('精品年卡', '2', '5', 0, $this->inDays(300), ['id' => 'new-1', 'residue_days' => 300]),
        ]);

        $d = $this->decision($c);
        $this->assertFalse($d['renewal']['in'], '历史卡永不进待续费');
        $this->assertSame([], $d['renewal']['why'], 'why 必须为空（历史卡不产生命中）');
        $this->assertSame('—', $d['renewal']['bucket']);
        $this->assertSame('P4', $d['layer']);
        $this->assertSame($this->ymd(300), $c->fresh()->expire_date, 'expire_date 只取当前实例');
        $this->assertSame('current', $d['display']['expireDateState']);
        $this->assertSame('current', $d['display']['mainCardState']);
        $this->assertContains('time_card_residue_ignored', $d['renewal']['degradedCodes'],
            '旧卡 residue_amount=30 被忽略必须显式降级');
        $this->assertNotContains('deadline_expired', $d['renewal']['whyCodes'], 'deadline_expired 已废弃（I1）');

        $history = $c->fresh()->card_stats['historyCards'];
        $this->assertCount(1, $history);
        $this->assertSame('expired_by_clock', $history[0]['historyReason']);
        $this->assertSame('old-1', $history[0]['id']);
        $this->assertNotContains($c->id, $this->renewalIds());
    }

    /** R02：旧年卡 status=6（上游已回写）→ 同上，historyReason=expired */
    public function test_r02_upstream_expired_old_card_is_history(): void
    {
        $c = $this->member('R02 旧卡status6', [
            $this->card('精品年卡', '2', '6', 30, $this->inDays(-30), ['id' => 'old-2']),
            $this->card('精品年卡', '2', '5', 0, $this->inDays(300), ['id' => 'new-2', 'residue_days' => 300]),
        ]);

        $d = $this->decision($c);
        $this->assertFalse($d['renewal']['in']);
        $this->assertSame([], $d['renewal']['why']);
        $this->assertSame('expired', $c->fresh()->card_stats['historyCards'][0]['historyReason']);
        $this->assertSame($this->ymd(300), $c->fresh()->expire_date);
    }

    /** R03：旧年卡过期 + 新年卡未开卡 → 待开卡（K1），why 说明另有过期卡 */
    public function test_r03_expired_plus_unactivated_buckets_pending_start(): void
    {
        $c = $this->member('R03 过期+未开卡', [
            $this->card('精品年卡', '2', '5', 30, $this->inDays(-30), ['id' => 'old-3']),
            $this->card('精品年卡', '2', '7', 0, null, ['id' => 'unact-3']),
        ]);

        $d = $this->decision($c);
        $this->assertFalse($d['renewal']['in']);
        $this->assertSame('待开卡', $d['renewal']['bucket']);
        $this->assertSame('P4', $d['layer']);
        $why = implode('；', $d['renewal']['why']);
        $this->assertStringContainsString('尚未开卡', $why);
        $this->assertStringContainsString('另有 1 张已过期卡（不计入待续费）', $why);
        $this->assertContains('unactivated_with_history', $d['renewal']['whyCodes']);
        $this->assertArrayHasKey($c->id, memberListWatch()['待开卡']);
    }

    /** R04（C5）：只有一张已过期年卡 → P4（期限卡无「过期有余额」），绝不出现「仍余 30 天」 */
    public function test_r04_expired_time_card_is_p4_not_p3(): void
    {
        $c = $this->member('R04 只有过期年卡', [
            $this->card('精品年卡', '2', '5', 30, $this->inDays(-30), ['id' => 'only-4']),
        ]);

        $d = $this->decision($c);
        $this->assertFalse($d['renewal']['in']);
        $this->assertSame('P4', $d['layer'], '期限卡过期即 remaining=0 → 不构成过期有余额');
        $this->assertFalse($d['expiredWithBalance']);
        $this->assertStringNotContainsString('仍余 30 天', implode('；', $d['expiredBalance']['why']));
        $history = $c->fresh()->card_stats['historyCards'];
        $this->assertCount(1, $history);
        $this->assertStringContainsString('期限卡到期即失效', $d['renewal']['instances'][0]['note']);
        $this->assertSame(0, $d['renewal']['instances'][0]['remaining']);
    }

    /** R04b（C5）：同形态换成次卡 → P3（过期有余额 30 节） */
    public function test_r04b_expired_count_card_is_p3_with_balance(): void
    {
        $c = $this->member('R04b 只有过期次卡', [
            $this->card('私教30次', '1', '6', 30, $this->inDays(-30), ['id' => 'only-4b']),
        ]);

        $d = $this->decision($c);
        $this->assertFalse($d['renewal']['in']);
        $this->assertTrue($d['expiredWithBalance']);
        $this->assertSame('P3', $d['layer']);
        $this->assertStringContainsString('已过期 30 天，余 30 节', implode('；', $d['expiredBalance']['why']));
        $this->assertContains($c->id, memberListIds()['过期有余额'] ?? []);
    }

    /** R05：单张年卡 20 天后到期 → 紧急/P0，文案带身份后缀 */
    public function test_r05_time_card_near_deadline_is_urgent(): void
    {
        $c = $this->member('R05 年卡临期', [
            $this->card('精品年卡', '2', '5', 0, $this->inDays(20), [
                'id' => 'r05', 'card_no' => '202509010004', 'create_time_format' => '2025-09-01',
                'residue_days' => 20, 'expiry_days' => 365,
            ]),
        ]);

        $d = $this->decision($c);
        $this->assertTrue($d['renewal']['in']);
        $this->assertSame('待续费·紧急', $d['renewal']['bucket']);
        $this->assertTrue($d['renewal']['urgent']);
        $this->assertTrue($d['renewal']['deadlineHit']);
        $this->assertSame(['deadline_near'], $d['renewal']['whyCodes']);
        $why = implode('；', $d['renewal']['why']);
        $this->assertStringContainsString('「精品年卡」20 天后到期（剩余 20 天）', $why);
        $this->assertStringContainsString('卡号 202509010004', $why, '必须带实例身份（I8）');
        $this->assertSame('P0', $d['layer']);
        $this->assertContains($c->id, $this->renewalIds());
    }

    /** R06a：次卡剩 8 节 → 逐卡尾段命中，紧急（m3>0） */
    public function test_r06a_count_card_tail_is_flagged(): void
    {
        $c = $this->member('R06a 次卡尾段', [
            $this->card('私教20次', '1', '5', 8, $this->inDays(200), ['id' => 'r06a']),
        ], m3: 6);

        $d = $this->decision($c);
        $this->assertTrue($d['renewal']['in']);
        $this->assertSame('待续费·紧急', $d['renewal']['bucket']);
        $this->assertSame(['count_card_low'], $d['renewal']['whyCodes']);
        $this->assertStringContainsString('仅余 8 节 ≤ 10 节', implode('；', $d['renewal']['why']));
    }

    /** R06b：同样剩 8 节但近 30 天无出勤 → 观察态（urgent=false） */
    public function test_r06b_no_attendance_makes_it_observation(): void
    {
        $c = $this->member('R06b 次卡尾段无出勤', [
            $this->card('私教20次', '1', '5', 8, $this->inDays(200), ['id' => 'r06b']),
        ], m3: 0);

        $d = $this->decision($c);
        $this->assertTrue($d['renewal']['in']);
        $this->assertSame('待续费·观察', $d['renewal']['bucket']);
        $this->assertFalse($d['renewal']['urgent']);
        $this->assertSame('P0', $d['layer']);
    }

    /** R07：次卡尾段 + 年卡远期 → why 只指向次卡 */
    public function test_r07_why_only_points_at_the_offending_card(): void
    {
        $c = $this->member('R07 次卡+年卡', [
            $this->card('私教20次', '1', '5', 2, $this->inDays(200), ['id' => 'r07-a']),
            $this->card('精品年卡', '2', '5', 0, $this->inDays(300), ['id' => 'r07-b', 'residue_days' => 300]),
        ]);

        $d = $this->decision($c);
        $this->assertTrue($d['renewal']['in']);
        $this->assertSame(['count_card_low'], $d['renewal']['whyCodes']);
        $why = implode('；', $d['renewal']['why']);
        $this->assertStringContainsString('私教20次', $why);
        $this->assertStringNotContainsString('精品年卡', $why, '年卡不产生 why');
    }

    /** R08：空次卡临期 + 可用年卡 → 不命中（deadlineEligible 要求次卡有余量） */
    public function test_r08_empty_count_card_near_deadline_is_noise(): void
    {
        $c = $this->member('R08 空次卡临期', [
            $this->card('私教50次', '1', '5', 0, $this->inDays(5), ['id' => 'r08-a']),
            $this->card('精品年卡', '2', '5', 0, $this->inDays(300), ['id' => 'r08-b', 'residue_days' => 300]),
        ]);

        $d = $this->decision($c);
        $this->assertFalse($d['renewal']['in']);
        $this->assertSame([], $d['renewal']['why']);
        $this->assertSame('P4', $d['layer']);
    }

    /** R09：储值卡单位=元、独立统计、不参与节/天阈值 */
    public function test_r09_stored_value_card_uses_yuan(): void
    {
        $this->setRules(['renewalStoredValueThreshold' => 0]);
        $c = $this->member('R09 储值卡', [
            $this->card('储值卡', '3', '5', 200, $this->inDays(300), ['id' => 'r09']),
        ]);

        $d = $this->decision($c);
        $this->assertFalse($d['renewal']['in']);
        $this->assertSame('P4', $d['layer']);
        $card = $c->fresh()->cards_list[0];
        $this->assertSame('元', $card['unit'], '储值卡单位不得写成「节」（D05）');
        $this->assertSame(200, $card['residue']);
        $this->assertEqualsWithDelta(200, $d['display']['storedValue'], 0.001);
        $this->assertSame($this->ymd(300), $c->fresh()->expire_date, '唯一的当前实例决定到期日');
    }

    /** R10：只有退卡+停卡 → P4（不是 P5），main_card 回退历史，炸弹与过期余额都不成立 */
    public function test_r10_refunded_and_suspended_only_is_p4(): void
    {
        $c = $this->member('R10 退卡+停卡', [
            $this->card('退掉的私教', '1', '29', 30, $this->inDays(-400), ['id' => 'r10-a']),
            $this->card('停卡的私教', '1', '39', 20, $this->inDays(-400), ['id' => 'r10-b']),
        ]);

        $d = $this->decision($c);
        $this->assertFalse($d['renewal']['in']);
        $this->assertSame('P4', $d['layer'], '有卡历史不得落 P5（S8）');
        $this->assertSame('退掉的私教', $c->fresh()->main_card, '历史回退按输入顺序（createTime 缺失时）');
        $this->assertSame('history', $d['display']['mainCardState']);
        $this->assertFalse($d['bomb']['in'], '29/39 永不参与炸弹');
        $this->assertFalse($d['expiredWithBalance'], '29/39 永不参与过期余额');
        $this->assertTrue($d['hasAnyCard']);
    }

    /** R11：同名两张年卡 → 文案可区分「同名第 1 张 · 卡号 · 发卡日」 */
    public function test_r11_same_title_cards_are_attributed(): void
    {
        $c = $this->member('R11 同名两张年卡', [
            $this->card('精品年卡', '2', '5', 0, $this->inDays(20), [
                'id' => 'r11-old', 'card_no' => '202509010004', 'create_time_format' => '2025-09-01', 'residue_days' => 20,
            ]),
            $this->card('精品年卡', '2', '5', 0, $this->inDays(300), [
                'id' => 'r11-new', 'card_no' => '202608010007', 'create_time_format' => '2026-08-01', 'residue_days' => 300,
            ]),
        ]);

        $d = $this->decision($c);
        $this->assertTrue($d['renewal']['in']);
        $this->assertSame('待续费·紧急', $d['renewal']['bucket']);
        $why = implode('；', $d['renewal']['why']);
        $this->assertStringContainsString('同名第 1 张', $why);
        $this->assertStringContainsString('卡号 202509010004', $why);
        $this->assertStringContainsString('发卡 2025-09-01', $why);
        $this->assertSame($this->ymd(20), $c->fresh()->expire_date, '当前实例里最早的那个');
        $this->assertSame(20, $d['renewal']['hits'][0]['values']['daysUntil'], '第一条命中最贴近到期日的那张');
    }

    /** R12：旧 cards_list（无身份字段）→ 不抛错，中文状态映射，remaining 由 deadline 算出 */
    public function test_r12_legacy_cards_list_degrades_but_works(): void
    {
        $c = $this->rawMember('R12 旧cards_list', [
            'main_card' => '精品年卡',
            'cards_list' => [[
                'title' => '精品年卡', 'type' => '2', 'unit' => '天', 'residue' => 0,
                'deadline' => $this->ymd(20), 'status' => '正常',
            ]],
            'card_stats' => ['dataVersion' => 1],
        ]);

        $d = $this->decision($c);
        $this->assertTrue($d['renewal']['in'], '旧数据同样必须按 deadline 判到期（I9）');
        $this->assertSame('待续费·紧急', $d['renewal']['bucket']);
        $this->assertSame(20, $d['renewal']['instances'][0]['remaining'], 'remaining 由 deadline 推导');
        $this->assertContains('status_label_only', $d['renewal']['degradedCodes']);
        $this->assertContains('identity_missing', $d['renewal']['degradedCodes']);
    }

    /** R13：期限卡 deadline -60 且 residue_amount=200 → 不出现「已过期 60 天，仍余 200 天」 */
    public function test_r13_time_card_residue_is_never_counted(): void
    {
        $c = $this->member('R13 期限卡脏余额', [
            $this->card('年卡', '2', '5', 200, $this->inDays(-60), ['id' => 'r13']),
        ]);

        $d = $this->decision($c);
        $this->assertFalse($d['renewal']['in']);
        $this->assertSame('P4', $d['layer']);
        $this->assertSame(0, $d['renewal']['instances'][0]['remaining']);
        $this->assertStringNotContainsString('仍余 200 天', implode('；', $d['expiredBalance']['why']));
        $this->assertContains('time_card_residue_ignored', $d['renewal']['degradedCodes']);
    }

    /** R14：唯一未开卡卡项 → 待开卡，不进待续费 */
    public function test_r14_only_unactivated_is_pending_start(): void
    {
        $c = $this->member('R14 只有未开卡', [
            $this->card('私教20次', '1', '7', 20, null, ['id' => 'r14']),
        ]);

        $d = $this->decision($c);
        $this->assertFalse($d['renewal']['in']);
        $this->assertSame('待开卡', $d['renewal']['bucket']);
        $this->assertSame('P4', $d['layer']);
        $this->assertContains('unactivated_open', $d['renewal']['whyCodes']);
        $this->assertArrayHasKey($c->id, memberListWatch()['待开卡']);
    }

    /** R15：m3=0 但次卡只剩 8 节 → 观察态（出勤不是门槛） */
    public function test_r15_attendance_is_not_a_gate(): void
    {
        $c = $this->member('R15 无出勤尾段', [
            $this->card('私教20次', '1', '5', 8, $this->inDays(200), ['id' => 'r15']),
        ], m3: 0);

        $d = $this->decision($c);
        $this->assertTrue($d['renewal']['in']);
        $this->assertSame('待续费·观察', $d['renewal']['bucket']);
        $this->assertFalse($d['renewal']['urgent']);
    }

    // ─────────────────────────────────────────── 扩充用例（A 系列，高风险面）

    /** A01：期限卡 residue_amount=0 + residue_days>0（真实主形态）→ deadline 推导剩余 20 天 */
    public function test_a01_time_card_residue_days_drives_remaining(): void
    {
        $c = $this->member('A01 主形态', [
            $this->card('年卡', '2', '5', 0, $this->inDays(20), ['id' => 'a01', 'residue_days' => 20]),
        ]);

        $d = $this->decision($c);
        $this->assertTrue($d['renewal']['in']);
        $this->assertSame(20, $d['renewal']['instances'][0]['remaining']);
        $this->assertSame('deadline', $d['renewal']['instances'][0]['remainingSource']);
        $this->assertSame(20, $c->fresh()->card_stats['daysLeft'], 'daysLeft 也必须由 deadline 累加');
        $this->assertContains('deadline_near', $d['renewal']['whyCodes']);
    }

    /** A03：旧 cards_list 无 residueDays → 仍能算出 20 天（表达式 c） */
    public function test_a03_legacy_cards_list_without_residue_days(): void
    {
        $c = $this->rawMember('A03 无 residueDays', [
            'main_card' => '年卡',
            'cards_list' => [[
                'id' => 'a03', 'title' => '年卡', 'type' => '2', 'residue' => 0,
                'deadline' => $this->ymd(20), 'statusCode' => '5', 'status' => '正常',
            ]],
            'card_stats' => ['dataVersion' => 2],
        ]);

        $d = $this->decision($c);
        $this->assertTrue($d['renewal']['in']);
        $this->assertSame(20, $d['renewal']['instances'][0]['remaining']);
        $this->assertSame('deadline', $d['renewal']['instances'][0]['remainingSource']);
    }

    /** A04：历史卡不得产生命中（S-H2 回归），但过期余额成立 */
    public function test_a04_history_cards_never_produce_hits(): void
    {
        $c = $this->member('A04 旧次卡+新次卡', [
            $this->card('旧私教30次', '1', '6', 30, $this->inDays(-20), ['id' => 'a04-old']),
            $this->card('新私教100次', '1', '5', 100, $this->inDays(300), [
                'id' => 'a04-new', 'curr_unit_cash_value' => 0, 'usage_total' => 0,
            ]),
        ]);

        $d = $this->decision($c);
        $this->assertFalse($d['renewal']['in']);
        $this->assertSame([], $d['renewal']['why'], '历史卡结构上不在命中域');
        $this->assertTrue($d['expiredWithBalance']);
        $this->assertSame(30, $d['expiredBalance']['sections']);
        $this->assertSame(100, $d['display']['usableCount']);
        $this->assertContains('count_bound_underivable', $d['renewal']['degradedCodes']);
    }

    /** A05/A06：过期有余额只看窗内（-60 在窗内 → P3；-200 出窗 → P4） */
    public function test_a05_a06_expired_balance_window(): void
    {
        $this->setRules(['expiredBalanceWindowDays' => 90]);

        $inWindow = $this->member('A05 窗内', [
            $this->card('私教30次', '1', '6', 12, $this->inDays(-60), ['id' => 'a05']),
        ]);
        $outWindow = $this->member('A06 窗外', [
            $this->card('私教30次', '1', '6', 12, $this->inDays(-200), ['id' => 'a06']),
        ]);

        $di = $this->decision($inWindow);
        $do = $this->decision($outWindow);
        $this->assertTrue($di['expiredWithBalance']);
        $this->assertSame('P3', $di['layer']);
        $this->assertTrue($di['hasAnyCard'], '有历史卡不得落 P5');
        $this->assertFalse($do['expiredWithBalance']);
        $this->assertSame('P4', $do['layer']);
        $this->assertNotContains($outWindow->id, memberListIds()['过期有余额'] ?? []);
    }

    /** A08/A09：储值卡默认不参与阈值；开启后按「元」独立提醒 */
    public function test_a08_a09_stored_value_threshold(): void
    {
        $this->setRules(['renewalStoredValueThreshold' => 0]);
        $cards = [
            $this->card('储值卡', '3', '5', 5, $this->inDays(300), ['id' => 'a08-stored']),
            $this->card('私教20次', '1', '5', 8, $this->inDays(200), ['id' => 'a08-count']),
        ];
        $off = $this->member('A08 储值卡默认关', $cards);

        $offDecision = $this->decision($off);
        $this->assertTrue($offDecision['renewal']['in']);
        $this->assertSame(['count_card_low'], $offDecision['renewal']['whyCodes'], '储值卡不得参与节阈值');
        $this->assertStringNotContainsString('仅余 5 节', implode('；', $offDecision['renewal']['why']));
        $this->assertSame(8, $offDecision['display']['usableCount'], '元不进 usableCount');

        $this->setRules(['renewalStoredValueThreshold' => 300]);
        $on = $this->member('A09 储值卡阈值开', $cards);
        $onDecision = $this->decision($on);
        $this->assertContains('stored_value_low', $onDecision['renewal']['whyCodes']);
        $why = implode('；', $onDecision['renewal']['why']);
        $this->assertStringContainsString('余额 5.00 元 ≤ 300 元', $why);
    }

    /** A10：退卡/停卡永不进炸弹，且停卡未过期不参与 */
    public function test_a10_refunded_suspended_never_bomb(): void
    {
        $c = $this->member('A10 29+39', [
            $this->card('退卡', '1', '29', 30, $this->inDays(-400), ['id' => 'a10-a']),
            $this->card('停卡未过期', '1', '39', 20, $this->inDays(30), ['id' => 'a10-b']),
        ], m3: 0, extra: ['last_visit' => null]);

        $d = $this->decision($c);
        $this->assertFalse($d['bomb']['in']);
        $this->assertSame(0, $d['bomb']['sections']);
        $this->assertFalse($d['expiredWithBalance']);
        $this->assertSame('P4', $d['layer']);
    }

    /**
     * A11：标题命中 tasteTitlePattern（is_taste=0）→ excluded，不进任何判定集合。
     *
     * ⚠️ 与规范 §8.2 A11 的 `layer` 列有出入（该行写 P4）：§3.6 明确 `hasAnyCard`
     * 「排除体验/测试」，快照复算也只把「没有任何非体验卡」的 765 人判为 P5。
     * 因此这里按 §3.6 + 参考实现断言 **P5**，并保留「不进待续费 + 保留降级线索」两条核心。
     */
    public function test_a11_taste_card_by_pattern_is_excluded(): void
    {
        $this->setRules(['tasteTitlePattern' => '体验|员工|测试|礼包|赠送']);
        $c = $this->member('A11 礼包卡', [
            $this->card('新人礼包2次', '1', '5', 2, $this->inDays(300), ['id' => 'a11']),
        ]);

        $d = $this->decision($c);
        $this->assertFalse($d['renewal']['in'], '礼包卡不得进待续费（旧硬编码漏掉「礼包」时曾进 P0）');
        $this->assertSame('P5', $d['layer'], '§3.6：体验/赠品卡不计入「有卡」，只有礼包卡 → 无资产');
        $this->assertFalse($d['hasAnyCard']);
        $this->assertContains('taste_excluded_by_pattern', $d['renewal']['degradedCodes']);
        $this->assertSame([], $c->fresh()->cards_list, '被排除的卡不得进入判定输入');
    }

    /** A13：status=7 但已过期（脏数据）→ history + 过期余额 + unactivated_expired */
    public function test_a13_expired_unactivated_is_history(): void
    {
        $c = $this->member('A13 未开卡但过期', [
            $this->card('私教20次', '1', '7', 20, $this->inDays(-5), ['id' => 'a13']),
        ]);

        $d = $this->decision($c);
        $this->assertFalse($d['renewal']['in']);
        $this->assertSame('history', $d['renewal']['instances'][0]['state']);
        $this->assertSame('expired_by_clock', $d['renewal']['instances'][0]['historyReason']);
        $this->assertTrue($d['expiredWithBalance']);
        $this->assertSame('P3', $d['layer']);
        $this->assertContains('unactivated_expired', $d['renewal']['degradedCodes']);
    }

    /** A16：expire_date 不被历史卡拉早；main_card 取命中项 */
    public function test_a16_expire_date_is_not_pulled_earlier_by_history(): void
    {
        $c = $this->member('A16 当前两张+历史一张', [
            $this->card('年卡', '2', '5', 0, $this->inDays(30), ['id' => 'a16-time', 'residue_days' => 30]),
            $this->card('私教20次', '1', '5', 100, $this->inDays(100), ['id' => 'a16-count']),
            $this->card('历史年卡', '2', '5', 0, $this->inDays(-30), ['id' => 'a16-old', 'residue_days' => 0]),
        ]);

        $d = $this->decision($c);
        $this->assertSame($this->ymd(30), $c->fresh()->expire_date);
        $this->assertSame('current', $d['display']['expireDateState']);
        $this->assertSame('年卡', $c->fresh()->main_card, '命中项（期限卡 30 天 ≤ 窗口）为主卡');
        $this->assertTrue($d['renewal']['in']);
    }

    /** A17：期限卡最后一天（daysUntil=0）必须提醒 */
    public function test_a17_last_day_of_time_card_still_alerts(): void
    {
        $c = $this->member('A17 最后一天', [
            $this->card('月卡', '2', '5', 0, (string) now()->timestamp, ['id' => 'a17', 'residue_days' => 0]),
        ]);

        $d = $this->decision($c);
        $this->assertTrue($d['renewal']['in']);
        $this->assertSame('待续费·紧急', $d['renewal']['bucket']);
        $this->assertSame(0, $d['renewal']['instances'][0]['daysUntil']);
        $this->assertSame(0, $d['renewal']['instances'][0]['remaining']);
        $this->assertStringContainsString('今日到期（剩余 0 天）', implode('；', $d['renewal']['why']));
    }

    /** A18：空次卡临期不提醒（与 R08 同源） */
    public function test_a18_empty_count_card_is_not_eligible(): void
    {
        $c = $this->member('A18 空卡临期', [
            $this->card('私教50次', '1', '5', 0, $this->inDays(5), ['id' => 'a18-a']),
            $this->card('年卡', '2', '5', 0, $this->inDays(300), ['id' => 'a18-b', 'residue_days' => 300]),
        ]);

        $d = $this->decision($c);
        $this->assertFalse($d['renewal']['in']);
        $this->assertSame([], $d['renewal']['why']);
    }

    /** `/today/todo` 的 renewals[] 必须带上判定依据字段（前端「今日到期」文案依赖 expireDateState） */
    public function test_today_todo_renewals_expose_lifecycle_fields(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'username' => uniqid('s'), 'role' => 'R_SUPER', 'status' => '启用',
        ]));

        $c = $this->member('今日待办-临期', [
            $this->card('年卡', '2', '5', 0, $this->inDays(20), ['id' => 'todo-1', 'residue_days' => 20]),
            $this->card('私教20次', '1', '5', 8, $this->inDays(200), ['id' => 'todo-2']),
        ]);
        // renewals 新口径：只展示「待续课 && 今天约课」的会员，补一节今天的课
        KyBooking::create([
            'source_key' => uniqid('t-'),
            'venue' => '绿地店',
            'booking_type' => '私教',
            'member_id' => 'm-'.md5('今日待办-临期'),
            'member_name' => '今日待办-临期',
            'phone' => $c->phone,
            'start_at' => now()->startOfDay()->addHours(9),
            'course_name' => '普拉提私教',
            'teacher_name' => '小周',
            'status_raw' => '',
            'status' => 'booked',
            'is_trial' => false,
            'raw' => ['course_type' => '2'],
        ]);

        $rows = $this->getJson('/api/today/todo')->assertOk()->json('data.renewals');
        $row = collect($rows)->firstWhere('id', $c->id);
        $this->assertNotNull($row, '命中待续费的会员必须出现在今日待办 renewals 里');

        $this->assertSame('current', $row['expireDateState']);
        $this->assertSame(20, $row['nextExpireDays']);
        $this->assertSame('current', $row['mainCardState']);
        $this->assertSame(8, $row['usableCount'], 'usableCount 只算当前可用次卡（节）');
        $this->assertSame(8, $row['displayCount']);
        $this->assertNotEmpty($row['why']);
        $this->assertContains('deadline_near', $row['whyCodes']);
        $this->assertContains('count_card_low', $row['whyCodes']);
        $this->assertNotEmpty($row['hits'], 'renewals 必须与 renewalReasons 同构地带命中明细');
        $this->assertIsArray($row['entitlement']);
        $this->assertSame('current', $row['entitlement']['expireDateState']);
        $this->assertTrue($row['urgent']);
    }

    // ─────────────────────────────────────────────── 数据契约（§1.4）

    /** cards_list / historyCards / entitlement 的键契约：新键齐全且旧键一个不少 */
    public function test_cards_list_and_card_stats_contract(): void
    {
        $sum = $this->summarize([
            $this->card('精品年卡', '2', '5', 0, $this->inDays(300), [
                'id' => 'k1', 'card_no' => '202509010004', 'card_id' => '95',
                'create_time_format' => '2025-09-01', 'activate_time_format' => '2025-09-02',
                'residue_days' => 300, 'expiry_days' => 365,
            ]),
            $this->card('私教20次', '1', '7', 10, null, ['id' => 'k2', 'card_no' => '202601010001', 'card_id' => '96']),
            $this->card('旧私教30次', '1', '6', 12, $this->inDays(-30), ['id' => 'k3']),
            $this->card('储值卡', '3', '5', 200, $this->inDays(200), ['id' => 'k4']),
            $this->card('新人礼包2次', '1', '5', 2, $this->inDays(100), ['id' => 'k5']),
        ]);

        $oldKeys = ['title', 'unit', 'residue', 'bound', 'deadline', 'status', 'unactivated', 'type', 'validDays'];
        $newKeys = [
            'id', 'cardNo', 'cardId', 'createTime', 'soldAt', 'activateTime', 'activatedAt',
            'statusCode', 'residueDays', 'residueSource', 'expired', 'daysLeft', 'valueType',
            'isTaste', 'tasteReason', 'state', 'seriesIndex', 'seriesCount', 'degraded',
        ];
        $this->assertCount(3, $sum['cards_list'], 'cards_list = current ∪ unactivated（历史与被排除卡不在内）');
        foreach ($sum['cards_list'] as $entry) {
            foreach (array_merge($oldKeys, $newKeys) as $key) {
                $this->assertArrayHasKey($key, $entry, "cards_list 缺键 {$key}（旧消费方/判定层会读到 null）");
            }
        }

        $units = array_column($sum['cards_list'], 'unit', 'id');
        $this->assertSame('天', $units['k1']);
        $this->assertSame('节', $units['k2']);
        $this->assertSame('元', $units['k4'], '储值卡单位必须是元');

        // historyCards：全类型、带身份、带 historyReason
        $this->assertCount(1, $sum['card_stats']['historyCards']);
        $history = $sum['card_stats']['historyCards'][0];
        foreach (['id', 'cardNo', 'cardId', 'title', 'type', 'unit', 'residue', 'deadline', 'statusCode', 'state', 'historyReason', 'createTime', 'isTaste'] as $key) {
            $this->assertArrayHasKey($key, $history, "historyCards 缺键 {$key}");
        }
        $this->assertSame('k3', $history['id']);
        $this->assertSame('history', $history['state']);

        // 兼容区 expiredCards 仍在（炸弹会员消费）
        $this->assertNotEmpty($sum['card_stats']['expiredCards']);
        foreach (['title', 'residue', 'deadline', 'status', 'type'] as $key) {
            $this->assertArrayHasKey($key, $sum['card_stats']['expiredCards'][0], "expiredCards 缺键 {$key}");
        }

        // entitlement + 版本号 + 三个布尔
        $stats = $sum['card_stats'];
        $this->assertSame(2, $stats['dataVersion']);
        $this->assertArrayHasKey('entitlement', $stats);
        $this->assertArrayHasKey('hasAnyCard', $stats);
        $this->assertArrayHasKey('hasActiveEntitlement', $stats);
        $this->assertArrayHasKey('expiredBalance', $stats);
        $ent = $stats['entitlement'];
        foreach (['mainCard', 'mainCardState', 'expireDate', 'expireDateState', 'nextExpireDays', 'usableCount', 'displayCount', 'storedValue', 'historyBalance', 'counts'] as $key) {
            $this->assertArrayHasKey($key, $ent, "entitlement 缺键 {$key}");
        }
        $this->assertSame('current', $ent['mainCardState']);
        $this->assertEqualsWithDelta(200, $ent['storedValue'], 0.001, '储值卡余额按元汇总');
        // 储值卡的 deadline 仍进入「当前实例最早到期日」的展示（§2.6），但不产生任何命中
        $this->assertSame(200, $ent['nextExpireDays']);
        $this->assertSame(['current' => 2, 'unactivated' => 1, 'history' => 1, 'excluded' => 1,
            'usable' => 2, 'exhausted' => 0, 'unknown' => 0], $ent['counts']);
    }

    /** customerDecision 既有键一个都不能删（前端/今日待办仍在读） */
    public function test_decision_keeps_legacy_keys(): void
    {
        $c = $this->member('契约-旧键', [
            $this->card('私教20次', '1', '5', 2, $this->inDays(300), ['id' => 'legacy-1']),
        ]);
        $d = $this->decision($c);

        foreach (['hasAsset', 'm1', 'm2', 'm3', 'lastVisitDays', 'expireDays', 'revive', 'preLoss',
            'declining', 'vip', 'hasBalance', 'expiredWithBalance', 'bomb', 'layer', 'renewal'] as $key) {
            $this->assertArrayHasKey($key, $d, "customerDecision 缺旧键 {$key}");
        }
        foreach (['in', 'why', 'whyCodes', 'deadlineHit', 'bucket', 'urgent', 'revive', 'degraded'] as $key) {
            $this->assertArrayHasKey($key, $d['renewal'], "renewal 缺旧键 {$key}");
        }
        // 新键（只增不改）
        foreach (['hasAnyCard', 'hasActiveEntitlement', 'hasUsableEntitlement', 'expiredBalance', 'display', 'dataVersion'] as $key) {
            $this->assertArrayHasKey($key, $d, "customerDecision 缺新键 {$key}");
        }
        foreach (['degradedCodes', 'hits', 'instances', 'expiredBalance', 'flags', 'suppressedCodes', 'whyTruncated'] as $key) {
            $this->assertArrayHasKey($key, $d['renewal'], "renewal 缺新键 {$key}");
        }
        // hasAsset 保留为 hasAnyCard 的别名（旧消费方语义不变）
        $this->assertSame($d['hasAnyCard'], $d['hasAsset']);
    }

    /** 跨馆镜像行（同 id 两行）：can_op=1 优先，且记 mirror_row_deduped */
    public function test_mirror_rows_are_deduped_by_instance_id(): void
    {
        $sum = $this->summarize([
            $this->card('私教20次', '1', '5', 8, $this->inDays(200), ['id' => 'dup-1', 'can_op' => '0']),
            $this->card('私教20次', '1', '5', 8, $this->inDays(200), ['id' => 'dup-1', 'can_op' => '1']),
        ]);

        $this->assertCount(1, $sum['cards_list'], '同一 (venue,id) 只能保留一条实例');
        $this->assertContains('mirror_row_deduped', $sum['card_stats']['syncDegraded']);
    }

    /** 储值卡过期且仍有余额 → P3；单位仍是元，不混入节/天 */
    public function test_expired_stored_value_card_is_p3_in_yuan(): void
    {
        $c = $this->member('过期储值卡', [
            $this->card('储值卡', '3', '6', 658, $this->inDays(-20), ['id' => 'stored-exp']),
        ]);

        $d = $this->decision($c);
        $this->assertFalse($d['renewal']['in']);
        $this->assertTrue($d['expiredWithBalance']);
        $this->assertSame('P3', $d['layer']);
        $this->assertSame(0, $d['expiredBalance']['sections'], '储值卡不得进「节」合计');
        $this->assertEqualsWithDelta(658, $d['expiredBalance']['amount'], 0.001, '储值卡余额按元统计');
        $this->assertStringContainsString('658.00 元', implode('；', $d['expiredBalance']['why']));
    }

    /** 旧版 cards_list（无 historyCards）：过期实例仍由本地时钟判 history，不进命中 */
    public function test_legacy_cards_list_with_past_deadline_is_history(): void
    {
        $c = $this->rawMember('旧版 cards_list 过期', [
            'main_card' => '私教50次',
            'cards_list' => [[
                'id' => 'legacy-exp', 'title' => '私教50次', 'type' => '1', 'unit' => '节',
                'residue' => 50, 'deadline' => $this->ymd(-30), 'statusCode' => '5',
                'status' => '正常', 'bound' => 50,
            ]],
            'card_stats' => ['dataVersion' => 1],
        ]);

        $d = $this->decision($c);
        $this->assertFalse($d['renewal']['in'], '本地时钟已过 → history，永不命中（I1 抗同步滞后）');
        $this->assertSame([], $d['renewal']['why']);
        $this->assertTrue($d['expiredWithBalance'], '旧数据用 expiredCards/汇总兜底也要能看到过期余额');
        $this->assertSame('P3', $d['layer']);
    }

    // ─────────────────────────────────────────────── 配置项（I11 / §6）

    public function test_new_rule_keys_have_defaults_validation_and_effects(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => uniqid('s'), 'role' => 'R_SUPER', 'status' => '启用']));

        // 默认值（新增三键必须同时有默认值/校验/消费点，否则就是死配置）
        $defaults = $this->getJson('/api/member-rules')->assertOk()->json('data');
        $this->assertSame(90, $defaults['expiredBalanceWindowDays']);
        $this->assertSame(0, $defaults['renewalStoredValueThreshold']);
        $this->assertSame('体验|员工|测试|礼包|赠送', $defaults['tasteTitlePattern']);

        // 0 是合法值且必须被保留（禁止吞 0）
        $this->putJson('/api/member-rules', [
            'renewalThreshold' => 10,
            'vipAmountThreshold' => 30000,
            'declineMode' => 'strict',
            'predropMin' => 15,
            'predropMax' => 30,
            'reviveDays' => 30,
            'expiredBalanceWindowDays' => 0,
            'renewalStoredValueThreshold' => 0,
        ])->assertOk()
            ->assertJsonPath('data.expiredBalanceWindowDays', 0)
            ->assertJsonPath('data.renewalStoredValueThreshold', 0);
        $this->assertSame(0, rules()['expiredBalanceWindowDays'], '保存后读回仍必须是 0（不能被 ?? 90 吞掉）');

        // 非法 PCRE / 含 ~ → 422
        $this->putJson('/api/member-rules', [
            'renewalThreshold' => 10,
            'vipAmountThreshold' => 30000,
            'declineMode' => 'strict',
            'predropMin' => 15,
            'predropMax' => 30,
            'reviveDays' => 30,
            'tasteTitlePattern' => '([a-z',
        ])->assertStatus(422);
        $this->putJson('/api/member-rules', [
            'renewalThreshold' => 10,
            'vipAmountThreshold' => 30000,
            'declineMode' => 'strict',
            'predropMin' => 15,
            'predropMax' => 30,
            'reviveDays' => 30,
            'tasteTitlePattern' => '体验~员工',
        ])->assertStatus(422);
    }

    /** 旧键 renewalExpiredBackfillDays 双写为新键（§6.3），且它本身不再影响待续费 */
    public function test_legacy_backfill_key_dual_writes_to_window(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => uniqid('s'), 'role' => 'R_SUPER', 'status' => '启用']));

        $this->putJson('/api/member-rules', [
            'renewalThreshold' => 10,
            'vipAmountThreshold' => 30000,
            'declineMode' => 'strict',
            'predropMin' => 15,
            'predropMax' => 30,
            'reviveDays' => 30,
            'renewalExpiredBackfillDays' => 7,
        ])->assertOk()
            ->assertJsonPath('data.renewalExpiredBackfillDays', 7)
            ->assertJsonPath('data.expiredBalanceWindowDays', 7);

        // 只提交新键时反向双写
        $this->putJson('/api/member-rules', [
            'renewalThreshold' => 10,
            'vipAmountThreshold' => 30000,
            'declineMode' => 'strict',
            'predropMin' => 15,
            'predropMax' => 30,
            'reviveDays' => 30,
            'expiredBalanceWindowDays' => 15,
        ])->assertOk()
            ->assertJsonPath('data.expiredBalanceWindowDays', 15)
            ->assertJsonPath('data.renewalExpiredBackfillDays', 15);

        // 行为：窗口=15 → 过期 30 天的余额不再展示（P4）
        $c = $this->member('旧键双写行为', [
            $this->card('私教30次', '1', '6', 12, $this->inDays(-30), ['id' => 'dual']),
        ]);
        $this->assertSame('P4', $this->decision($c)['layer']);
    }

    /** 窗口=0 关闭展示（A07），但**不影响** renewal.in（过期本来就不命中） */
    public function test_window_zero_disables_expired_balance_only(): void
    {
        $this->setRules(['expiredBalanceWindowDays' => 0]);
        $c = $this->member('窗口关闭', [
            $this->card('私教30次', '1', '6', 12, $this->inDays(-30), ['id' => 'win0']),
        ]);

        $d = $this->decision($c);
        $this->assertFalse($d['expiredWithBalance']);
        $this->assertSame('P4', $d['layer']);
        $this->assertFalse($d['renewal']['in']);
    }

    // ─────────────────────────────────────────────── 命令（只读 + 逐卡证据）

    public function test_explain_command_prints_per_instance_evidence(): void
    {
        $c = $this->member('命令-逐卡证据', [
            $this->card('精品年卡', '2', '5', 0, $this->inDays(20), [
                'id' => 'cmd-1', 'card_no' => '202509010004', 'create_time_format' => '2025-09-01', 'residue_days' => 20,
            ]),
            $this->card('精品年卡', '2', '5', 30, $this->inDays(-30), ['id' => 'cmd-2']),
        ]);

        $exit = Artisan::call('members:explain-cards', ['customer' => (string) $c->id]);
        $output = Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('实例', $output);
        $this->assertStringContainsString('R-T1 deadline_near', $output, '必须打印布尔表达式而不是只有文案');
        $this->assertStringContainsString('R-T2 validity_percent', $output);
        $this->assertStringContainsString('历史实例，不在命中域', $output);
        $this->assertStringContainsString('cmd-1', $output);
        $this->assertStringContainsString('同名第', $output, '同名卡必须可区分');

        // JSON 模式：命中项带实参
        $exit = Artisan::call('members:explain-cards', ['customer' => (string) $c->id, '--json' => true]);
        $json = json_decode(Artisan::output(), true);
        $this->assertSame(0, $exit);
        $this->assertTrue($json['in']);
        $this->assertSame('deadline_near', $json['hits'][0]['code']);
        $this->assertSame(20, $json['hits'][0]['values']['daysUntil']);
        $this->assertSame(['current', 'history'], array_values(array_unique(array_column($json['instances'], 'state'))));
    }

    public function test_audit_command_is_read_only_and_reports_guard_metrics(): void
    {
        $this->member('审计-临期', [
            $this->card('年卡', '2', '5', 0, $this->inDays(10), ['id' => 'audit-1', 'residue_days' => 10]),
        ]);
        $this->member('审计-旧卡', [
            // status=5 但 deadline 已过 = HEAD 的 C8 命中形态（status=6 的卡 HEAD 根本不收）
            $this->card('旧私教30次', '1', '5', 12, $this->inDays(-30), ['id' => 'audit-2']),
        ]);
        $this->member('审计-储值卡', [
            $this->card('储值卡', '3', '5', 5, $this->inDays(300), ['id' => 'audit-3']),
        ]);

        $before = DB::table('customers')->orderBy('id')->get(['id', 'layer', 'updated_at', 'card_stats', 'cards_list'])->toArray();

        $exit = Artisan::call('members:audit-renewal', ['--format' => 'json', '--diff-legacy' => true]);
        $output = json_decode(Artisan::output(), true);
        $this->assertSame(0, $exit);
        $this->assertIsArray($output);
        $this->assertSame(3, $output['total']);
        $this->assertSame(1, $output['renewal']['in'], '只有临期年卡命中');
        $this->assertSame(0, $output['historyOnlyHits'], '守门指标：命中不得由历史卡造成');
        $this->assertSame(0, $output['timeCardMissed'], '守门指标：期限卡临期不得漏判');
        $this->assertSame(0, $output['storedValueMisjudged'], '守门指标：储值卡不得被当节');
        $this->assertSame(0, $output['dataVersionStale']);
        $this->assertSame(1, $output['expiredBalance']['in'], '旧卡过期有余额 → P3 口径可见');
        $this->assertArrayHasKey('diffLegacy', $output);

        // A-04：对账字段语义 —— layerChanged 与 in 变化同条件且误导，已改名 inChanged；
        // movedOut.why 必须是真实 legacy 命中码（旧实现输出常量 'legacy-in'）。
        $diff = $output['diffLegacy'];
        $this->assertArrayHasKey('inChanged', $diff);
        $this->assertArrayNotHasKey('layerChanged', $diff, '同条件的 layerChanged 数组不得再输出');
        $this->assertSame($diff['movedOutCount'] + $diff['movedInCount'], $diff['inChangedCount'], 'inChanged 必须恰好是移出+新增');
        // 旧口径移出 2 人：过期次卡（C8 deadline_expired）+ 储值卡（被当「节」命中 card 规则，D05）
        $this->assertSame(2, $diff['movedOutCount']);
        $movedOutByName = collect($diff['movedOut'])->keyBy('name');
        $this->assertSame(['deadline_expired'], $movedOutByName['审计-旧卡']['why'], 'why 必须是真实 legacy 命中码');
        $this->assertSame(['total', 'card'], $movedOutByName['审计-储值卡']['why'], '储值卡在旧口径被当次卡命中（合计+逐卡）');
        $this->assertNotSame('legacy-in', $movedOutByName['审计-旧卡']['why']);
        $this->assertSame(1, $diff['movedInCount'], '期限卡临期在旧口径被 residue_amount=0 丢弃 → 新口径新增');
        $this->assertSame('审计-临期', $diff['movedIn'][0]['name']);
        $this->assertSame(['deadline_near'], $diff['movedIn'][0]['why'], 'movedIn.why 是新口径命中码');
        $this->assertSame([
            'inChanged' => '入清单状态变化（legacyIn !== in）；P 层不在本对账范围内',
            'layerChanged' => 'deprecated/removed：与 inChanged 同条件，v3.9-t8 起不再输出',
        ], $diff['schema']);

        // 只读：数据库快照必须完全一致
        $after = DB::table('customers')->orderBy('id')->get(['id', 'layer', 'updated_at', 'card_stats', 'cards_list'])->toArray();
        $this->assertEquals($before, $after, 'audit/explain 命令不得写库');
    }

    /** A-02：同步侧保留 validDays=null；占比开启时判定层必须给出 valid_days_missing */
    public function test_a02_valid_days_null_is_preserved_and_degraded(): void
    {
        // 上游没给 expiry_months / expiry_days → 有效期未知（不是 0 天）
        $card = $this->card('缺有效期的年卡', '2', '5', 0, null, [
            'id' => 'a02', 'residue_days' => 10, 'expiry_days' => 0, 'expiry_months' => 0,
            'deadline' => $this->inDays(200),
        ]);
        $sum = $this->summarize([$card]);
        $this->assertNull($sum['cards_list'][0]['validDays'], 'cards_list.validDays 必须保留 null（不得写成 0）');

        $this->setRules(['renewalExpireDays' => 1, 'renewalExpirePercent' => 50]);
        $c = $this->member('A02 缺有效期', [$card]);
        $d = $this->decision($c);
        $this->assertNull($d['renewal']['instances'][0]['validDays']);
        $this->assertContains('valid_days_missing', $d['renewal']['degradedCodes'],
            '占比阈值开启但缺 validDays 时必须降级，否则用户不知道阈值为何没反应');
    }
}
