<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\User;
use App\Services\KyMemberSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 「待续费」判定口径精准化（v3.3.0）。
 *
 * 本测试把诊断文档 `docs/口径/会员卡项判定与待续费口径诊断.md` §5.5 的 26 个夹具
 * 灌进**真实链路**（KyMemberSyncService::summarizeCards → cards_list/card_stats
 * → customerDecision → computeMemberLists），逐条锁住「在/不在清单」与归类。
 *
 * 为什么要端到端而不是只测判定函数：C2/C3 的根因在**上游聚合**
 * （summarizeCards 把已用完卡也算进分母、把用完卡的到期日当成会员到期日），
 * 只测判定层会让「上游算错、下游照单全收」的缺陷重新溜过去。
 *
 * 覆盖的缺陷编号（见文档 §5.2）：
 *  - C2 分母含已用完卡 → 老客被系统性误报
 *  - C3 用完卡的到期日污染 expire_date → 误报主要来源
 *  - C7 未开卡参与续费 → 语义荒谬
 *  - C8 已过期但有余量漏判 → 最该联系的人看不见
 *  - C9 占比规则在无快照时静默失效 → 改为显式降级提示
 *  - C4 出勤门槛（D1 决策）→ 门槛改为紧急度分档
 */
class RenewalPrecisionTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- 夹具构造

    /**
     * 上游卡项原始行（与 KeepYoga 字段同名，直接喂 summarizeCards）。
     *
     * `$usage` 一直表示「已消耗**次数**」（夹具作者的意图，全部调用点都按次数传值：
     * 5/15、2/98、180/20 …）。但 `usage_total` 在上游是**金额**，
     * 真正权威的换算需要 `curr_unit_cash_value`。这里补上 `curr_unit_cash_value = 1`，
     * 于是 `usage_total / 1 = $usage` 恰好等于夹具想要的已耗次数 ——
     * 既保持所有现有用例的语义不变，又让它们继续走**真实的金额口径**，
     * 而不是退化成「推导不出 → 分母只剩剩余节数」的降级路径。
     *
     * ⚠️ 不加这个字段的话，`consumedCount()` 会判定为「推导不出」并降级，
     * 本文件里所有与分母相关的断言都会变成**空转**（分母恒等于剩余节数）——
     * 那种「绿」是假的，等于把断言悄悄弱化掉了。
     */
    private function card(string $title, string $status, string $type, ?int $residue, int $usage, ?string $deadline, ?int $validDays = null): array
    {
        return array_filter([
            'card_title' => $title, 'status' => $status, 'type' => $type,
            'residue_amount' => $residue, 'usage_total' => $usage,
            // 单次折算价 = 1 ⇒ usage_total 数值 == 已耗次数（保持夹具语义）
            'curr_unit_cash_value' => '1',
            'deadline' => $deadline, 'expiry_days' => $validDays,
        ], fn ($v) => $v !== null);
    }

    private function inDays(int $n): string
    {
        return now()->addDays($n)->toDateString();
    }

    /** 写阈值配置（合并写入，与 setRules() 同路径），并让清单缓存失效 */
    private function setRules(array $patch): void
    {
        $s = AppSetting::oldest('id')->first();
        if ($s) {
            $s->update(['rules' => array_merge((array) ($s->rules ?? []), $patch)]);
        } else {
            AppSetting::create(['rules' => $patch]);
        }
        invalidateBusinessCaches('member_lists');
    }

    /**
     * 建一个会员：卡项经真实 summarizeCards 聚合，出勤/待复活按夹具给定。
     * 这样测的是「同步怎么算 + 判定怎么判」的完整链路。
     */
    private function memberFromCards(string $name, array $cards, int $m3 = 6, bool $revive = false, array $extra = []): Customer
    {
        $summary = $this->summarize($cards);

        return Customer::create(array_merge([
            'name' => $name,
            'phone' => '1380000'.random_int(1000, 9999),
            'phone_tail' => '0000',
            'venue' => '绿地店',
            'source' => 'KeepYoga',
            'owner' => '店长',
            'consultant' => '店长',
            'status' => '在籍',
            'main_card' => $summary['main_card'],
            'remain_times' => $summary['remain_times'],
            'expire_date' => $summary['expire_date'],
            'card_stats' => $summary['card_stats'],
            'cards_list' => $summary['cards_list'],
            'attend_m1' => $m3,
            'attend_m2' => $m3,
            'attend_m3' => $m3,
            'in_revive' => $revive,
            // 停练天数要落在 reviveDays 之外，$revive 才由夹具真实控制
            'last_visit' => now()->subDays($revive ? 60 : 3)->toDateString(),
        ], $extra));
    }

    private function summarize(array $cards): array
    {
        $m = new \ReflectionMethod(KyMemberSyncService::class, 'summarizeCards');
        $m->setAccessible(true);

        return $m->invoke(null, $cards);
    }

    /**
     * 指定清单的会员 id（走真实 memberListIds()，与接口同源）。
     * 与 CardStatsRenewalTest::renewalIds() 等价，此处按名字取清单。
     */
    private function listIdsFor(string $list): array
    {
        return memberListIds()[$list] ?? [];
    }

    /** 直接落库（供需要绕过 memberFromCards 聚合的用例使用） */
    private function rawCustomer(array $attrs): Customer
    {
        return Customer::create(array_merge([
            'name' => 'raw', 'phone' => '1380000'.random_int(1000, 9999), 'phone_tail' => '0000',
            'venue' => '绿地店', 'source' => 'KeepYoga', 'owner' => '店长', 'consultant' => '店长',
            'layer' => 'P4', 'status' => '在籍',
        ], $attrs));
    }

    /** 判定明细（同一次缓存扫描，与清单计数同源） */
    private function watchFor(Customer $c): ?array
    {
        return memberListWatch()['待续费'][$c->id] ?? null;
    }

    private function assertRenewal(Customer $c, bool $expected, string $note = ''): void
    {
        $in = array_key_exists($c->id, memberListWatch()['待续费']);
        $msg = "会员「{$c->name}」".($expected ? '应在' : '不应在').'待续费清单'.($note ? "（{$note}）" : '');
        $expected ? $this->assertTrue($in, $msg) : $this->assertFalse($in, $msg);
    }

    // ------------------------------------------------- C3：用完卡的到期日不再污染

    public function test_used_up_card_deadline_no_longer_flags_member(): void
    {
        // 夹具 F03/F04：一张卡 0/50 且 +10 天到期，另一张还有 40/100 节。
        // 旧口径取「全局最早 expire_date」→ 用 +10 天误报；新口径只看有余量的卡。
        $f03 = $this->memberFromCards('F03 用完卡临期+满卡', [
            $this->card('旧私教50次', '4', '1', 0, 50, $this->inDays(10)),
            $this->card('新私教40次', '4', '1', 40, 0, $this->inDays(400)),
        ]);
        $this->assertRenewal($f03, false, '已用完卡的到期日不得触发');
        // v3.9：expire_date = min(deadline) over **CURRENT**（§2.6）。已用完的卡仍是 current
        // 实例，只是不满足命中适配域（deadlineEligible 要求次卡有余量）；历史实例才会被排除。
        $this->assertSame($this->inDays(10), $f03->expire_date,
            'expire_date 取当前实例的最早 deadline（历史实例永不把「当前」拉早）');

        $f04 = $this->memberFromCards('F04 用完卡临期+新卡100节', [
            $this->card('旧私教50次', '4', '1', 0, 50, $this->inDays(10)),
            $this->card('新私教100次', '4', '1', 100, 0, $this->inDays(500)),
        ]);
        $this->assertRenewal($f04, false);
    }

    // ------------------------------------------------- C2：分母不含已用完卡

    public function test_exhausted_card_no_longer_dilutes_percent_denominator(): void
    {
        // 夹具 F16：过期卡被上游过滤，只剩 0/30 的在用卡 → 课时已耗尽，该续课
        $f16 = $this->memberFromCards('F16 在用卡耗尽', [
            $this->card('过期私教', '6', '1', 5, 10, $this->inDays(-10)),
            $this->card('在用私教', '4', '1', 0, 30, $this->inDays(60)),
        ]);
        $this->assertRenewal($f16, true, '次卡课时耗尽应提醒续课');

        // 反向：分母若含已用完卡，40/90 会被算成「占比不高」而被漏掉。
        // 这里锁住分母只统计有余量的卡（40 节 / 40 节 = 100%，远高于 20% 阈值 → 不报）
        $healthy = $this->memberFromCards('健康卡+历史用完卡', [
            $this->card('历史用完卡', '4', '1', 0, 90, $this->inDays(300)),
            $this->card('在用私教40次', '4', '1', 40, 0, $this->inDays(400)),
        ]);
        $this->assertRenewal($healthy, false, '有余量的卡不应因历史用完卡而被计入尾段');
        $this->assertSame(40, $healthy->card_stats['countResidue'] ?? null);
        $this->assertSame(40, $healthy->card_stats['countBound'] ?? null, 'countBound 只应合计有余量的卡');
    }

    // ------------------------------------------------- C7：未开卡不触发续费

    public function test_unactivated_card_is_waiting_to_start_not_renewal(): void
    {
        // 夹具 F07：只有一张未开卡 3 节 → 催他「续费」是荒谬的，他还没开始上课
        $f07 = $this->memberFromCards('F07 未开卡3节', [
            $this->card('私教3次', '7', '1', 3, 0, $this->inDays(365)),
        ], m3: 3);
        $this->assertRenewal($f07, false, '未开卡不判续费');

        // 夹具 F08：未开卡 + 在用 100 节 → 同样不报
        $f08 = $this->memberFromCards('F08 未开卡+在用100节', [
            $this->card('未开卡私教3次', '7', '1', 3, 0, $this->inDays(365)),
            $this->card('在用私教100次', '4', '1', 100, 0, $this->inDays(500)),
        ], m3: 3);
        $this->assertRenewal($f08, false);
    }

    public function test_unactivated_inventory_still_counts_toward_remaining_lessons(): void
    {
        // 决策 C7 的另一半：未开卡不触发续费，但**仍要计入库存/剩余课时展示**，
        // 否则店长看到的「剩余课时」会比实际少，导致重复卖课。
        $c = $this->memberFromCards('未开卡库存展示', [
            $this->card('未开卡私教20次', '7', '1', 20, 0, $this->inDays(365)),
            $this->card('在用私教5次', '4', '1', 5, 15, $this->inDays(300)),
        ], m3: 3);

        $this->assertSame(25, $c->card_stats['countResidue'] ?? null, '剩余课时必须含未开卡库存');
        $this->assertRenewal($c, true, '在用卡只剩 5 节应提醒续费');
    }

    // ------------------------------------------------- 历史卡：只进「过期有余额」，永不进待续费

    public function test_expired_card_with_balance_is_flagged_within_backfill_window(): void
    {
        // 夹具 F17（v3.9 口径翻转，§9.2 #1/#2）：卡已过期 30 天、还剩 50 节。
        // 旧口径把它送进待续费（C8 分支）；新口径**过期实例永不命中**，
        // 它的运营价值迁到独立的「过期有余额」标签（P3 + 筛选）。
        $f17 = $this->memberFromCards('F17 过期30天余50节', [
            $this->card('私教50次', '4', '1', 50, 0, $this->inDays(-30)),
        ]);
        $this->assertRenewal($f17, false, '过期实例永不进待续费');

        $d = customerDecision($f17->fresh());
        $this->assertSame([], $d['renewal']['why'], '历史卡不得产生 renewal.why');
        $this->assertSame('P3', $d['layer'], '窗内过期且有余额 → P3');
        $this->assertTrue($d['expiredWithBalance'], '过期有余额必须独立成立');
        $this->assertStringContainsString('已过期', implode('；', $d['expiredBalance']['why']));
    }

    public function test_expired_card_beyond_backfill_window_is_ignored(): void
    {
        // 夹具 F25：过期 -100 天，超出 90 天展示窗（决策 D4）→ 连「过期有余额」也不显示。
        $f25 = $this->memberFromCards('F25 过期100天余50节', [
            $this->card('私教50次', '4', '1', 50, 0, $this->inDays(-100)),
        ]);
        $this->assertRenewal($f25, false, '过期卡不进待续费');
        $this->assertSame('P4', customerDecision($f25->fresh())['layer'], '出窗的过期余额落 P4');
    }

    public function test_expired_window_boundary_is_inclusive(): void
    {
        // 边界：正好 -90 天在展示窗内（P3），-91 天出窗（P4）；两条都不进待续费。
        $edge = $this->memberFromCards('边界-90天', [
            $this->card('私教50次', '4', '1', 50, 0, $this->inDays(-90)),
        ]);
        $this->assertRenewal($edge, false);
        $de = customerDecision($edge->fresh());
        $this->assertTrue($de['expiredWithBalance'], '-90 天在展示窗内（含边界）');
        $this->assertSame('P3', $de['layer']);

        $past = $this->memberFromCards('边界-91天', [
            $this->card('私教50次', '4', '1', 50, 0, $this->inDays(-91)),
        ]);
        $this->assertRenewal($past, false);
        $dp = customerDecision($past->fresh());
        $this->assertFalse($dp['expiredWithBalance'], '-91 天已出窗');
        $this->assertSame('P4', $dp['layer']);
    }

    // ------------------------------------------------- D2：合计与逐卡并存

    public function test_per_card_tail_is_caught_even_when_total_is_healthy(): void
    {
        // 夹具 F05：一张剩 2 节 + 一张满 50 节（合计 52）→ 合计口径看不出来，逐卡口径必须命中。
        // 决策 D2：两个口径并存（OR），why 要说清是哪条命中。
        $f05 = $this->memberFromCards('F05 单卡剩2节', [
            $this->card('私教100次', '4', '1', 2, 98, $this->inDays(300)),
            $this->card('私教50次', '4', '1', 50, 0, $this->inDays(300)),
        ]);
        $this->assertRenewal($f05, true, '合计 52 节但单卡只剩 2 节');
        $why = implode('；', $this->watchFor($f05)['why']);
        // v3.9 文案（§5.1）：逐卡尾段 = 「「卡名」仅余 2 节 ≤ 10 节（当前可用合计 52 节）（…）」
        // 旧文案的「单卡」「合计仍有」两个词已不再使用（D08-③/D12：展示口径不得叫「合计」）。
        $this->assertStringContainsString('仅余', $why);
        $this->assertStringContainsString('52', $why, 'why 应说明「当前可用合计 52 节」，让店长理解为何合计健康却被提醒');

        // 夹具 F21：两张各剩 3 节 + 一张 100 节（合计 106）
        $f21 = $this->memberFromCards('F21 两张各剩3节', [
            $this->card('私教10次A', '4', '1', 3, 7, $this->inDays(300)),
            $this->card('私教10次B', '4', '1', 3, 7, $this->inDays(300)),
            $this->card('私教100次', '4', '1', 100, 0, $this->inDays(400)),
        ]);
        $this->assertRenewal($f21, true);

        // 夹具 F02：双卡各剩 8 节（合计 16，占比 40%）→ 合计与占比都不报，逐卡必须报
        $f02 = $this->memberFromCards('F02 双卡各剩8节', [
            $this->card('私教20次A', '4', '1', 8, 12, $this->inDays(300)),
            $this->card('私教20次B', '4', '1', 8, 12, $this->inDays(300)),
        ]);
        $this->assertRenewal($f02, true, '合计 16 节 > 阈值 10，占比 40% > 20%，只能靠逐卡口径命中');
    }

    public function test_healthy_multi_card_member_is_not_flagged(): void
    {
        // 夹具 F09/F18 反向：真正健康的会员不能被误报，否则清单失去可信度
        $f09 = $this->memberFromCards('F09 纯期限卡剩200天', [
            $this->card('全能年卡', '4', '2', 200, 0, $this->inDays(200), 365),
        ]);
        $this->assertRenewal($f09, false);

        $f18 = $this->memberFromCards('F18 次卡充足+期限卡临期', [
            $this->card('私教100次', '4', '1', 100, 0, $this->inDays(500)),
            $this->card('团课月卡', '4', '2', 10, 0, $this->inDays(10), 30),
        ]);
        $this->assertRenewal($f18, true, '期限卡 10 天后到期应提醒');
    }

    // ------------------------------------------------- 无资产 / 退卡

    public function test_member_without_any_active_card_has_no_asset(): void
    {
        // 夹具 F15/F20：只有退卡（status 29）≠ 无资产；完全没卡才是无资产。
        // v3.9（§9.2 #11）：退卡实例进 historyCards，main_card 回退到最近历史并带 state=history；
        // 它不落 P5（P5 只留给「从未有卡」）。
        $f15 = $this->memberFromCards('F15 只有退卡', [
            $this->card('退掉的私教', '29', '1', 30, 0, $this->inDays(30)),
        ]);
        $this->assertRenewal($f15, false);
        $d15 = customerDecision($f15->fresh());
        $this->assertSame('退掉的私教', $f15->main_card, '历史回退是唯一确定值，不能写 —');
        $this->assertSame('history', $d15['display']['mainCardState']);
        $this->assertNotSame('P5', $d15['layer'], '有卡历史（退卡）不得落 P5');

        $f20 = $this->memberFromCards('F20 无任何有效卡', [], m3: 6);
        $this->assertRenewal($f20, false);
        $this->assertSame('P5', customerDecision($f20->fresh())['layer'], '从未有卡才是 P5');
    }

    // ------------------------------------------------- C4 / D1：出勤只决定紧急度

    public function test_no_recent_attendance_is_observation_not_excluded(): void
    {
        // 夹具 F11：次卡剩 2 节 + 近 30 天无出勤 → 仍进清单，但标「观察」
        $f11 = $this->memberFromCards('F11 无出勤剩2节', [
            $this->card('私教20次', '4', '1', 2, 18, $this->inDays(300)),
        ], m3: 0);
        $this->assertRenewal($f11, true, '门槛已放开，只是降为观察态');
        $watch = $this->watchFor($f11);
        $this->assertSame('待续费·观察', $watch['bucket']);
        $this->assertFalse($watch['urgent']);
    }

    public function test_expiring_card_stays_urgent_even_without_recent_attendance(): void
    {
        // 夹具 F13：无出勤 + 10 天到期 → 必须是「紧急」。
        // 卡马上作废，等会员自己回来就来不及了，不能因为没出勤而降级。
        $f13 = $this->memberFromCards('F13 无出勤+10天到期', [
            $this->card('私教20次', '4', '1', 2, 18, $this->inDays(10)),
        ], m3: 0);
        $this->assertRenewal($f13, true);
        $this->assertSame('待续费·紧急', $this->watchFor($f13)['bucket']);
    }

    // ------------------------------------------------- D3：待复活不隐藏

    public function test_revive_member_is_still_listed_with_dual_tags(): void
    {
        // 夹具 F12/F26：待复活会员**不隐藏**（决策 D3），只是主标签为「待复活」。
        // 旧口径用 ! $revive 把他从待续费里剔除，结果他既在待复活又在等续费，两边都不处理。
        $f12 = $this->memberFromCards('F12 停练60天剩2节', [
            $this->card('私教20次', '4', '1', 2, 18, $this->inDays(300)),
        ], m3: 0, revive: true);
        $this->assertRenewal($f12, true, '待复活不得把会员从待续费里藏起来');

        $watch = $this->watchFor($f12);
        $this->assertTrue($watch['revive']);
        $this->assertSame('待复活', $watch['primary'], '主标签应是待复活（先唤醒）');
        $this->assertContains('待复活', $watch['coLists'], '共属标记要能让前端显示次要标签');
    }

    // ------------------------------------------------- C9：无快照时显式降级

    public function test_legacy_row_without_card_snapshot_reports_degraded_rules(): void
    {
        // C9：card_stats 为 NULL 的老行，占比规则无法生效。
        // 旧实现是**静默失效**（用户调了阈值没反应，查不到原因），现在必须显式说明。
        $legacy = Customer::create([
            'name' => '老数据无快照', 'phone' => '13800009999', 'phone_tail' => '9999',
            'venue' => '绿地店', 'source' => 'KeepYoga', 'owner' => '店长', 'consultant' => '店长',
            'status' => '在籍', 'main_card' => '私教卡', 'remain_times' => 3,
            'attend_m1' => 0, 'attend_m2' => 4, 'attend_m3' => 0,
            'last_visit' => now()->subDays(3)->toDateString(),
        ]);

        $this->assertNull($legacy->card_stats);
        $this->assertRenewal($legacy, true, '老数据应退回汇总口径（remain_times=3 ≤ 10）');
        $watch = $this->watchFor($legacy);
        $this->assertNotEmpty($watch['degraded'], '占比规则未生效必须显式告知，不能静默');
        $this->assertStringContainsString('占比规则未生效', implode('；', $watch['degraded']));
    }

    // ------------------------------------------------- 配置项：回溯天数可调

    public function test_backfill_days_rule_is_configurable_and_persisted(): void
    {
        // 新增阈值必须能被超管保存（否则前端表单会 422）并能改变判定结果
        $super = User::factory()->create(['username' => uniqid('s'), 'role' => 'R_SUPER', 'status' => '启用']);
        Sanctum::actingAs($super);

        $this->getJson('/api/member-rules')->assertOk()
            ->assertJsonPath('data.renewalExpiredBackfillDays', 90);

        $this->putJson('/api/member-rules', [
            'renewalThreshold' => 10, 'renewalCountPercent' => 20,
            'renewalExpireDays' => 30, 'renewalExpirePercent' => 0,
            'renewalExpiredBackfillDays' => 7,
            'vipAmountThreshold' => 30000,
            'declineMode' => 'strict', 'predropMin' => 15, 'predropMax' => 30,
            'reviveDays' => 30,
        ])->assertOk();

        $this->assertSame(7, rules()['renewalExpiredBackfillDays']);

        // 过期 30 天：默认 90 天窗内应报；收窄到 7 天后应不报
        $c = $this->memberFromCards('过期30天窗收窄', [
            $this->card('私教50次', '4', '1', 50, 0, $this->inDays(-30)),
        ]);
        $this->assertRenewal($c, false, '回溯窗收窄到 7 天后，过期 30 天的卡不再提醒');
    }

    // ------------------------------------------------- 与续费评估同源（C4 治本）

    public function test_renewal_evaluation_window_agrees_with_list(): void
    {
        // C4 的核心：会员管理页签与续费评估曾各算一套，同一会员能得到相反结论。
        // 现在两者都走 customerDecision()，必须一致。
        $super = User::factory()->create(['username' => uniqid('s'), 'role' => 'R_SUPER', 'status' => '启用']);
        Sanctum::actingAs($super);

        $inList = $this->memberFromCards('同源校验-在清单', [
            $this->card('私教20次', '4', '1', 2, 18, $this->inDays(300)),
        ]);
        $notInList = $this->memberFromCards('同源校验-不在清单', [
            $this->card('私教200次', '4', '1', 180, 20, $this->inDays(500)),
        ]);

        foreach ([$inList, $notInList] as $c) {
            $ctx = $this->getJson("/api/customers/{$c->id}/renewal-evaluation")->assertOk()->json('data');
            $inWatch = array_key_exists($c->id, memberListWatch()['待续费']);

            $this->assertSame($inWatch, $ctx['renewalIn'], "会员「{$c->name}」的评估页与清单结论必须一致");
            $this->assertSame($inWatch ? 10 : 0, $ctx['cardWindow'], '续费窗口分应与清单同源（在=10，不在=0）');
        }

        // 在清单者的 why 也要透出，让店长能自查口径
        $ctx = $this->getJson("/api/customers/{$inList->id}/renewal-evaluation")->assertOk()->json('data');
        $this->assertNotEmpty($ctx['renewalWhy']);
        $this->assertSame('P0', $ctx['layer'], '命中续费窗口的会员应落在 P0 层');
    }

    // ------------------------------------------------- 分层落库

    public function test_layer_recalculation_writes_p0_to_p4_and_preserves_p5_semantics(): void
    {
        // 「经营池分层 P0-P4 恒为空」缺陷的回归：重算后必须真的有 P0-P4 行，
        // 且 P5 仍严格等于「无卡项资产」（它是新媒体账号的授权条件，不能漂移）。
        $this->memberFromCards('分层-P0续费窗口', [
            $this->card('私教20次', '4', '1', 2, 18, $this->inDays(300)),
        ]);
        // P1 高资产低活跃：停练超过 reviveDays（30）阈值 → 待复活，且未命中续费判定
        $this->memberFromCards('分层-P1低活跃', [
            $this->card('私教200次', '4', '1', 180, 20, $this->inDays(500)),
        ], extra: ['last_visit' => now()->subDays(60)->toDateString()]);
        $this->memberFromCards('分层-P2频次下降', [
            $this->card('私教200次', '4', '1', 180, 20, $this->inDays(500)),
        ], m3: 0, extra: ['attend_m1' => 9, 'attend_m2' => 6, 'attend_m3' => 3]);
        // P3 的语义（v3.9）：**有过期实例且仍有余额**（未命中待续费），受展示窗约束。
        // 过期 30 天在默认窗（90）内 → 落 P3；过期 200 天出窗 → P4（A06）。
        // 分层互斥，优先级 P0 > P1/P2 > P3 > P4，先命中先落层。
        $this->memberFromCards('分层-P3过期有余额', [
            $this->card('私教50次', '4', '1', 50, 0, $this->inDays(-30)),
        ], extra: ['in_revive' => false]);
        $this->memberFromCards('分层-P4可升级', [
            $this->card('私教200次', '4', '1', 180, 20, $this->inDays(500)),
        ]);
        $lead = $this->memberFromCards('分层-P5客资', [], extra: ['main_card' => '—']);

        $changed = recalculateMemberLayers();
        $this->assertGreaterThan(0, $changed, '重算必须真的写库');

        // 验收条款「客户经营池各分层下拉均有数据」：P0-P5 **六层全部非空**。
        // 缺陷形态是 P0-P4 恒为空（只剩 P4/P5），所以这里逐层断言而不是只看总数。
        $dist = DB::table('customers')->selectRaw('layer, COUNT(*) c')->groupBy('layer')->pluck('c', 'layer')->all();
        foreach (['P0', 'P1', 'P2', 'P3', 'P4', 'P5'] as $layer) {
            $this->assertGreaterThanOrEqual(1, $dist[$layer] ?? 0, "{$layer} 层不应为空（分层恒为空的回归）");
        }

        // 分层必须真的按会员归属落对层，而不是「碰巧每层都有一个」
        $byName = Customer::pluck('layer', 'name')->all();
        $this->assertSame('P0', $byName['分层-P0续费窗口']);
        $this->assertSame('P1', $byName['分层-P1低活跃']);
        $this->assertSame('P2', $byName['分层-P2频次下降']);
        $this->assertSame('P3', $byName['分层-P3过期有余额']);
        $this->assertSame('P4', $byName['分层-P4可升级']);
        $this->assertSame('P5', $byName['分层-P5客资']);

        // P5 语义：无资产者归 P5，且它仍是新媒体的可见集合
        $this->assertSame('P5', $lead->fresh()->layer);
        $this->assertSame('—', $lead->fresh()->main_card);

        // 幂等：再跑一次不应产生任何写入
        $this->assertSame(0, recalculateMemberLayers(), '分层重算必须幂等');
    }

    public function test_layer_recalculation_does_not_touch_updated_at(): void
    {
        // 分层在每次同步后都会重算；若写 updated_at，五清单缓存键（含 MAX(updated_at)）
        // 会被每轮击穿，清单接口等于没缓存。这里把它锁住。
        $c = $this->memberFromCards('分层不碰时间戳', [
            $this->card('私教200次', '4', '1', 180, 20, $this->inDays(500)),
        ], extra: ['layer' => 'P5']);
        $before = $c->fresh()->updated_at;

        $this->travel(2)->seconds();
        $this->assertGreaterThan(0, recalculateMemberLayers());
        $this->assertSame($c->fresh()->layer, 'P4', '应被纠正为真实分层');
        $this->assertEquals($before, $c->fresh()->updated_at, '重算分层不得改 updated_at（会击穿清单缓存）');
    }

    public function test_list_endpoint_exposes_renewal_reasons(): void
    {
        // 清单接口必须能回答「为什么他在清单里」：合计/逐卡并存（D2）+ 共属清单（D3）。
        // 没有这段解释，店长看到「合计还有 52 节却被提醒续费」只会认为系统算错。
        $super = User::factory()->create(['username' => uniqid('s'), 'role' => 'R_SUPER', 'status' => '启用']);
        Sanctum::actingAs($super);

        $c = $this->memberFromCards('接口理由-单卡尾段', [
            $this->card('私教100次', '4', '1', 2, 98, $this->inDays(300)),
            $this->card('私教50次', '4', '1', 50, 0, $this->inDays(300)),
        ]);

        $data = $this->getJson('/api/customers?list='.urlencode('待续课').'&size=500')->assertOk()->json('data');
        $reason = $data['renewalReasons'][$c->id] ?? null;

        $this->assertNotNull($reason, '清单接口必须返回命中理由');
        $this->assertSame('待续费·紧急', $reason['bucket']);
        $this->assertTrue($reason['urgent']);
        $this->assertStringContainsString('仅余', implode('；', $reason['why']));
        $this->assertSame('待续费·紧急', $reason['primary']);

        // 不在清单的会员不应出现在 reasons 里（避免前端渲染出多余提示）
        $outside = $this->memberFromCards('接口理由-不在清单', [
            $this->card('私教200次', '4', '1', 180, 20, $this->inDays(500)),
        ]);
        $data2 = $this->getJson('/api/customers?list='.urlencode('待续课').'&size=500')->assertOk()->json('data');
        $this->assertArrayNotHasKey($outside->id, $data2['renewalReasons']);
    }

    // ------------------------------------------------- 清单计数与明细同源

    public function test_list_count_and_watch_payload_are_same_source(): void
    {
        // 徽标计数说 N 个、列表里却是另一批 —— 这类「同源分裂」在前端最难查。
        // 锁住：计数与明细来自同一次扫描。
        $super = User::factory()->create(['username' => uniqid('s'), 'role' => 'R_SUPER', 'status' => '启用']);
        Sanctum::actingAs($super);

        $this->memberFromCards('同源A', [$this->card('私教20次', '4', '1', 2, 18, $this->inDays(300))]);
        $this->memberFromCards('同源B', [$this->card('私教200次', '4', '1', 180, 20, $this->inDays(500))]);
        $this->memberFromCards('同源C', [$this->card('私教20次', '4', '1', 5, 15, $this->inDays(300))], m3: 0);

        $counts = $this->getJson('/api/customers/list-counts')->assertOk()->json('data.counts');
        $ids = memberListIds()['待续课'];

        $this->assertSame(count($ids), $counts['待续课']);
        $this->assertSame(count($ids), count(memberListWatch()['待续费']));
        $this->assertEqualsCanonicalizing($ids, array_keys(memberListWatch()['待续费']));
    }

    // ============================================================ t11 审查修复回归

    /**
     * R3：declineMode 必须真的影响「出勤降低」清单。
     *
     * 缺陷形态：HEAD 读 `$rules['declineMode']`，本次实现一度硬编码 strict，
     * 而后端仍在校验持久化、前端仍提供单选 → 用户调了没效果。
     * 这正是「可调但无效的阈值」，与用户最初抱怨的是同一类问题。
     */
    public function test_decline_mode_recent_and_strict_give_different_results(): void
    {
        // 夹具：M2 > M3 但不满足 M1 > M2（3/2/1 → 5/3/1 的中间态）。
        // strict 要求 M1>M2>M3；recent 只要求 M2>M3。
        // 取 m1=2, m2=5, m3=1：strict 不成立（2 > 5 为假），recent 成立（5 > 1）。
        $mk = fn (string $name) => $this->memberFromCards($name, [
            $this->card('私教200次', '4', '1', 180, 20, $this->inDays(500)),
        ], m3: 1, extra: ['attend_m1' => 2, 'attend_m2' => 5, 'attend_m3' => 1]);

        $this->setRules(['declineMode' => 'strict']);
        $strictC = $mk('strict 夹具');
        $this->assertNotContains($strictC->id, memberListIds()['出勤降低'], 'strict 下 M1>M2>M3 不成立，不应命中');

        $this->setRules(['declineMode' => 'recent']);
        $recentC = $mk('recent 夹具');
        $this->assertContains($recentC->id, memberListIds()['出勤降低'], 'recent 下 M2>M3 应命中——否则 declineMode 是「可调但无效」');
    }

    /**
     * R3（验收指定的夹具）：declineMode=recent 时 M1=0 / M2=5 / M3=2 必须进「出勤降低」。
     *
     * 这是 t11 验收条款逐字给出的夹具，单独锁一条：
     * strict 要求 M1>M2>M3（0>5 为假 → 不命中），recent 只要求 M2>M3（5>2 → 命中）。
     * 用「同一批数据、只切配置」的方式断言，确保结论差异确实来自 declineMode 而非夹具差异。
     */
    public function test_decline_mode_recent_flags_zero_m1_fixture(): void
    {
        $this->setRules(['declineMode' => 'strict']);
        $c = $this->memberFromCards('M1=0 夹具', [
            $this->card('私教200次', '4', '1', 180, 20, $this->inDays(500)),
        ], m3: 2, extra: ['attend_m1' => 0, 'attend_m2' => 5, 'attend_m3' => 2]);

        $this->assertNotContains(
            $c->id,
            memberListIds()['出勤降低'],
            'strict 下 M1=0 不满足 M1>M2>M3，不应命中'
        );

        // 只切配置，数据不动
        $this->setRules(['declineMode' => 'recent']);
        $this->assertContains(
            $c->id,
            memberListIds()['出勤降低'],
            'recent 下 M2=5 > M3=2 必须命中「出勤降低」'
        );
    }

    /**
     * R4：$urgent 不得靠文案匹配。
     *
     * 缺陷形态：`str_contains($w, '到期')` 遍历的 $why 内嵌卡名，
     * 一张叫「到期提醒卡」的卡即使 deadline 远在窗外，也会把观察态误升为紧急态。
     */
    public function test_card_named_like_deadline_does_not_force_urgent(): void
    {
        // 卡名含「到期」「过期」字样，但 deadline 远在 500 天后、且余额充足。
        // 用 m3=0（近 30 天无出勤）确保「不紧急」的唯一依据就是 deadline 判定。
        $c = $this->memberFromCards('文案匹配夹具', [
            $this->card('到期提醒专用卡', '4', '1', 8, 12, $this->inDays(500)),
        ], m3: 0);

        // 先确认它确实进了清单（剩 8 节 ≤ 10），再看紧急度
        $this->assertRenewal($c, true);
        $watch = $this->watchFor($c);
        $this->assertSame(
            '待续费·观察',
            $watch['bucket'],
            '卡名含「到期」不得让会员变成紧急——$urgent 必须读结构化标记而非文案'
        );
        $this->assertFalse($watch['urgent']);
        $this->assertFalse($watch['deadlineHit'], 'deadline 远在窗外，deadlineHit 应为 false');
    }

    /** R4 反向：真正的临期命中必须置 deadlineHit=true 且为紧急。 */
    public function test_real_deadline_hit_sets_structured_flag(): void
    {
        $c = $this->memberFromCards('真实临期', [
            $this->card('私教100次', '4', '1', 100, 0, $this->inDays(10)),
        ], m3: 0);

        $watch = $this->watchFor($c);
        $this->assertNotNull($watch);
        $this->assertTrue($watch['deadlineHit'], '真实临期必须置结构化标记');
        $this->assertContains('deadline_near', $watch['whyCodes'], 'whyCodes 应带结构化命中类型');
        $this->assertSame('待续费·紧急', $watch['bucket']);
    }

    /**
     * R5：只有 residue === 0（确认数值）才判「课时已耗尽」；
     * residue === null（余额未知）不得据此判紧急，且必须进 degraded 显式告知。
     */
    public function test_unknown_residue_is_not_treated_as_exhausted(): void
    {
        // 次卡：上游没返回 residue_amount（card 工厂传 null 会被 array_filter 剔除）
        $c = $this->memberFromCards('余额未知次卡', [
            ['card_title' => '私教50次', 'status' => '4', 'type' => '1', 'usage_total' => 20],
        ], m3: 6);

        // 余额未知 → 不能断言「课时已耗尽」
        $watch = $this->watchFor($c);
        if ($watch !== null) {
            $this->assertNotContains('count_exhausted', $watch['whyCodes'] ?? [], '余额未知不得判「课时已耗尽」');
            $this->assertNotContains('在用次卡课时已耗尽', implode('；', $watch['why']));
        }
        // 但必须显式降级，不能静默消失
        $decision = customerDecision($c->fresh());
        $this->assertNotEmpty($decision['renewal']['degraded'], '余额未知必须进 degraded，不能静默');
        $this->assertStringContainsString('未返回剩余量', implode('；', $decision['renewal']['degraded']));
    }

    /** R5 对照：residue === 0（确认耗尽）仍应正常判「课时已耗尽」。 */
    public function test_confirmed_zero_residue_still_counts_as_exhausted(): void
    {
        $c = $this->memberFromCards('确认耗尽', [
            $this->card('私教30次', '5', '1', 0, 30, null),
        ], m3: 6);

        $this->assertRenewal($c, true, '确认 0 节应提醒续课');
        $this->assertContains('count_exhausted', $this->watchFor($c)['whyCodes']);
    }

    /**
     * R6：期限卡余额缺失时不得静默漏提醒 —— v3.9 起**不需要 residue 也能算对**：
     * 剩余天数唯一取 `deadline − TODAY`（F1）。因此该卡既命中到期规则，
     * 也不应再产生「未返回剩余量」的降级（那是旧口径读 residue_amount 的症状）。
     */
    public function test_time_card_with_missing_residue_still_judged_by_deadline(): void
    {
        // 期限卡：没有 residue_amount，但 deadline 只有 10 天
        $c = $this->memberFromCards('期限卡余额缺失', [
            ['card_title' => '全能年卡', 'status' => '4', 'type' => '2', 'usage_total' => 0,
                'deadline' => $this->inDays(10), 'expiry_days' => 365],
        ], m3: 0);

        $this->assertRenewal($c, true, '期限卡余额缺失但临近到期，必须仍提醒');
        $watch = $this->watchFor($c);
        $this->assertTrue($watch['deadlineHit']);
        $this->assertSame('待续费·紧急', $watch['bucket']);
        $this->assertStringContainsString('天后到期', implode('；', $watch['why']));
        $this->assertStringContainsString('剩余 10 天', implode('；', $watch['why']),
            '期限卡剩余天数必须由 deadline 推导（不是 residue_amount）');
        $this->assertNotContains('count_residue_unknown', $watch['degradedCodes'],
            '期限卡不需要 residue_amount，不应再报「未返回剩余量」');
    }

    /** R6 边界：期限卡余额缺失且 deadline 远 → 不报，且**不应**凭空造降级（deadline 已知）。 */
    public function test_time_card_with_missing_residue_and_far_deadline_is_degraded_only(): void
    {
        $c = $this->memberFromCards('期限卡余额缺失远期', [
            ['card_title' => '全能年卡', 'status' => '4', 'type' => '2', 'usage_total' => 0,
                'deadline' => $this->inDays(300), 'expiry_days' => 365],
        ], m3: 6);

        $this->assertRenewal($c, false);
        $decision = customerDecision($c->fresh());
        $this->assertNotContains('time_card_no_deadline', $decision['renewal']['degradedCodes'],
            '有 deadline 就不该报「期限卡缺到期日」');
        $this->assertSame(0, $decision['display']['usableCount'],
            '期限卡单位是「天」，绝不能计入 usableCount（节）');
    }

    /**
     * R8：cardWindow 保留次级档 —— 不在清单但接近阈值仍给 5 分。
     *
     * 缺陷形态：t6 一度把「不在清单」一律压成 0，导致刚好在门槛外的会员
     * 评估分整体下降一档（高机会 → 重点培育），店长会读成「数据出错」。
     */
    public function test_card_window_keeps_secondary_tier_for_near_miss(): void
    {
        $super = User::factory()->create(['username' => uniqid('s'), 'role' => 'R_SUPER', 'status' => '启用']);
        Sanctum::actingAs($super);

        // 剩余 15 节：> 阈值 10（不在清单），但 ≤ 2×10（次级档）
        $near = $this->memberFromCards('次级档-临近', [
            $this->card('私教20次', '4', '1', 15, 5, $this->inDays(500)),
        ]);
        // 剩余 180 节：远离阈值 → 0 分
        $far = $this->memberFromCards('次级档-远离', [
            $this->card('私教200次', '4', '1', 180, 20, $this->inDays(500)),
        ]);

        $nearCtx = $this->getJson("/api/customers/{$near->id}/renewal-evaluation")->assertOk()->json('data');
        $farCtx = $this->getJson("/api/customers/{$far->id}/renewal-evaluation")->assertOk()->json('data');

        $this->assertFalse($nearCtx['renewalIn'], '15 节 > 阈值 10，不在清单');
        $this->assertSame(5, $nearCtx['cardWindow'], '不在清单但接近阈值应保留次级档 5 分');
        $this->assertSame(0, $farCtx['cardWindow'], '远离阈值应为 0 分');
    }

    // ============================================================ t23 审查修复回归

    /**
     * t23[0]：待开卡 guard 必须收紧为「**确实只有未开卡卡项**」。
     *
     * 缺陷形态：原 guard 只要求 `$unactivated !== [] && $liveCount === [] && $liveTime === []`，
     * **漏了 `$unknownResidue === []`**。于是「未开卡次卡 + 一张余额未知但 10 天后到期的期限卡」
     * 会被整体判成「待开卡」而退出续费判定 —— 可那张期限卡本就该按到期日提醒。
     * 结论与「只有这张期限卡」时（in=true / P0）**不一致**，属于拿未开卡的卡掩盖了真资产。
     */
    public function test_unactivated_plus_unknown_residue_time_card_still_renews(): void
    {
        // 未开卡次卡 + 期限卡余额未知（上游没给 residue_amount）·10 天到期
        $combo = $this->memberFromCards('未开卡+余额未知期限卡', [
            ['card_title' => '待开卡私教10次', 'status' => '7', 'type' => '1', 'residue_amount' => 10, 'usage_total' => 0],
            ['card_title' => '全能年卡', 'status' => '4', 'type' => '2', 'usage_total' => 0,
                'deadline' => $this->inDays(10), 'expiry_days' => 365],
        ], m3: 0);

        // 基准：只有那张期限卡
        $onlyTime = $this->memberFromCards('仅余额未知期限卡', [
            ['card_title' => '全能年卡', 'status' => '4', 'type' => '2', 'usage_total' => 0,
                'deadline' => $this->inDays(10), 'expiry_days' => 365],
        ], m3: 0);

        $rc = customerDecision($combo->fresh());
        $ro = customerDecision($onlyTime->fresh());

        // 先锁基准：**仅一张年卡（余额未知、10 天后到期）** 本身必须是 in=true / P0 / 紧急。
        // 不先锁基准的话，「两者一致」可能一致地错（例如都变成 false）。
        $this->assertTrue($ro['renewal']['in'], '基准：仅余额未知的临期年卡必须命中');
        $this->assertSame('P0', $ro['layer'], '基准：仅该年卡必须落 P0');
        $this->assertSame('待续费·紧急', $ro['renewal']['bucket'], '基准：必须为紧急');

        $this->assertTrue($rc['renewal']['in'], '未开卡卡项不得掩盖余额未知期限卡的到期提醒');
        $this->assertSame('P0', $rc['layer'], '必须与「仅该期限卡」同为 P0');
        $this->assertSame('待续费·紧急', $rc['renewal']['bucket'], '必须为紧急（加一张未开卡不得把到期整个抹掉）');
        $this->assertContains($combo->id, memberListIds()['待续课'], '必须进待续课清单');
        $this->assertNotSame('待开卡', $rc['renewal']['bucket']);

        // why 必须含到期说明（验收要求）
        $this->assertStringContainsString('到期', implode('；', $rc['renewal']['why']), 'why 必须含到期说明');
        $this->assertContains('deadline_near', $rc['renewal']['whyCodes']);

        // 与基准结论一致（这正是验收要求的「一致」）
        $this->assertSame($ro['renewal']['in'], $rc['renewal']['in'], '与「仅该期限卡」的 in 必须一致');
        $this->assertSame($ro['layer'], $rc['layer'], '与「仅该期限卡」的 layer 必须一致');
        $this->assertSame($ro['renewal']['bucket'], $rc['renewal']['bucket'], 'bucket 也必须一致');
    }

    /** t23[0] 反向：**确实只有**未开卡卡项时，仍须归「待开卡」而非续费（C7 不能被修坏）。 */
    public function test_only_unactivated_cards_still_bucket_as_pending_start(): void
    {
        $c = $this->memberFromCards('仅未开卡', [
            ['card_title' => '待开卡私教10次', 'status' => '7', 'type' => '1', 'residue_amount' => 10, 'usage_total' => 0],
        ], m3: 6);

        $d = customerDecision($c->fresh());
        $this->assertFalse($d['renewal']['in'], '未开卡的卡不应触发续费');
        $this->assertSame('待开卡', $d['renewal']['bucket']);
        $this->assertNotContains($c->id, memberListIds()['待续课']);
    }

    /**
     * t23[1] / v3.9 §3.3：watch['待开卡'] 的 why 必须与 decision 同源。
     *
     * v3.9 **口径再次变化**（这是本测试第二次重写，两次都值得记录）：
     *  - t25 时结论是「待开卡 ⟹ why 为空」（当时 K1 只是「不进待续费」）；
     *  - v3.9 要求 K1 命中时**重建 why**：`unactivated_open` / `unactivated_with_history`。
     *    原因是「待开卡」是独立 bucket，店长需要知道「为什么他在这个池子」以及「另有过期卡」。
     * 因此现在的契约是：why 非空、whyCodes ⊆ {unactivated_open, unactivated_with_history}，
     * 且**不得**出现 count_exhausted（被 K1 压制，只进 diagnostics）。
     */
    public function test_pending_start_watch_is_consistent_with_decision(): void
    {
        $c = $this->memberFromCards('待开卡同源', [
            ['card_title' => '待开卡私教10次', 'status' => '7', 'type' => '1', 'residue_amount' => 10, 'usage_total' => 0],
        ], m3: 6);

        $d = customerDecision($c->fresh());
        $this->assertSame('待开卡', $d['renewal']['bucket'], '前置：仅未开卡应归待开卡');
        $this->assertFalse($d['renewal']['in'], 'K1 不进待续费');
        $this->assertNotEmpty($d['renewal']['why'], '待开卡必须给出「尚未开卡」的理由');
        $this->assertStringContainsString('尚未开卡', implode('；', $d['renewal']['why']));
        $this->assertSame(['unactivated_open'], $d['renewal']['whyCodes'], 'whyCodes 作用域约束（§5.2）');

        $watch = memberListWatch()['待开卡'][$c->id] ?? null;
        $this->assertNotNull($watch, '待开卡明细必须存在');
        $this->assertSame($d['renewal']['why'], $watch['why'], 'why 必须与 decision 同源');
        $this->assertSame($d['renewal']['whyCodes'], $watch['whyCodes'], 'whyCodes 必须透传 decision');
        $this->assertSame($d['renewal']['degraded'], $watch['degraded'], 'degraded 必须透传 decision');
    }

    /**
     * v3.9 §3.3 / C7：待开卡的 whyCodes 只允许 unactivated_*，**绝不含 count_exhausted**。
     *
     * 这是「已耗尽 + 未开卡 → 待开卡」口径翻转的可测试硬约束：
     * 点亮的 count_exhausted 只能出现在 diagnostics（suppressedCodes），
     * 否则「待开卡」成员身上会出现「课时已耗尽」的自相矛盾。
     */
    public function test_pending_start_why_codes_are_scoped_to_unactivated(): void
    {
        $u1 = ['card_title' => '未开卡次卡', 'status' => '7', 'type' => '1', 'residue_amount' => 10, 'usage_total' => 0];
        $u2 = ['card_title' => '未开卡年卡', 'status' => '7', 'type' => '2', 'residue_amount' => 300, 'usage_total' => 0, 'expiry_days' => 365];
        $exhausted = ['card_title' => '已耗尽次卡', 'status' => '5', 'type' => '1', 'residue_amount' => 0, 'usage_total' => 30];

        foreach ([[$u1], [$u2], [$u1, $u2], [$u1, $u1, $u1], [$u1, $exhausted]] as $i => $cards) {
            $d = customerDecision($this->memberFromCards("待开卡作用域-{$i}", $cards, m3: 6)->fresh());
            $this->assertSame('待开卡', $d['renewal']['bucket'], "第 {$i} 组应归待开卡");
            $this->assertFalse($d['renewal']['in'], "第 {$i} 组不进待续费");
            $allowed = ['unactivated_open', 'unactivated_with_history'];
            $this->assertSame([], array_values(array_diff($d['renewal']['whyCodes'], $allowed)),
                "第 {$i} 组 whyCodes 越界：" .implode(',', $d['renewal']['whyCodes']));
            $this->assertNotContains('count_exhausted', $d['renewal']['whyCodes'], 'C7：耗尽信号被 K1 压制');
        }
    }

    /**
     * t23[3]：`hasAsset` 必须同时考虑 cards_list，不能只看 main_card。
     *
     * 缺陷形态：`main_card` 由 `$active[0] ? pick([...]) : '—'` 得到，而 `pick()` 在字段缺失时
     * 返回**空串**（'' 在排除表里）→「有在用卡但上游没给卡标题」的会员被判「无资产」→ 落 P5。
     * 后果不只是分层错：他会从 `type=member` 消失、出现在 `type=lead` 里，
     * 若还不是 ky: 来源，`isLeadOnlyCustomer()` 为 true → **R_MEDIA 能读到他的 PII**。
     */
    public function test_asset_detection_falls_back_to_cards_list(): void
    {
        // 有在用卡、有余额，但**没有 card_title**（上游没给）→ main_card 为空串
        $c = $this->memberFromCards('无卡标题但有资产', [
            ['status' => '4', 'type' => '1', 'residue_amount' => 10, 'usage_total' => 5],
        ], m3: 6);

        $d = customerDecision($c->fresh());
        $this->assertTrue($d['hasAsset'], 'cards_list 有在用卡即视为有资产，不能因缺 card_title 判无资产');
        $this->assertNotSame('P5', $d['layer'], '有资产的会员不得落 P5（否则会被当客资、PII 外泄）');
        $this->assertNotSame('无资产', $d['renewal']['bucket']);
    }

    /**
     * t23[3] 不变量：放宽 hasAsset **不得**把真客资推进/推出 P5。
     *
     * 依据：`main_card === '—'` 只在 `$active` 为空时出现，而 cards_list 同样映射自 `$active`，
     * 故 `main_card='—'` 必然蕴含 cards_list 为空 → 三项明细证据全空 → 仍判无资产 → 仍落 P5。
     * 这条一旦破，`scopeLeadOnlyCustomers()` 的授权面就会变。
     */
    public function test_real_lead_still_lands_p5_after_asset_widening(): void
    {
        $lead = $this->memberFromCards('t23真客资', [], m3: 0, extra: ['main_card' => '—']);
        $d = customerDecision($lead->fresh());

        $this->assertFalse($d['hasAsset'], '无卡的真客资必须仍判无资产');
        $this->assertSame('P5', $d['layer'], '真客资必须仍落 P5——否则 /customers?type=lead 会漏人');
        $this->assertTrue(isLeadOnlyCustomer($lead->fresh()), '真客资必须仍被认作前端客资');
    }

    /**
     * t23[2]：P3 只在**超出**回溯窗时可达（描述必须与行为一致）。
     *
     * 实测依据：窗内过期卡会被逐卡过期分支命中而进 P0（bucket=待续费·紧急），
     * 因此 P3 的实际语义是「过期已超出 renewalExpiredBackfillDays，未命中待续费」。
     */
    public function test_p3_only_reachable_beyond_backfill_window(): void
    {
        $this->setRules(['expiredBalanceWindowDays' => 90]);

        // v3.9 口径翻转（§9.2 #6–#9）：**窗内过期不再命中待续费**，而是落 P3（过期有余额）。
        $inWindow = $this->memberFromCards('窗内过期', [
            $this->card('私教50次', '4', '1', 20, 30, now()->subDays(30)->toDateString()),
        ], m3: 6);
        // 超窗：过期 200 天 → 既不出「过期有余额」也不命中 → P4
        $beyond = $this->memberFromCards('超窗过期', [
            $this->card('私教50次', '4', '1', 20, 30, now()->subDays(200)->toDateString()),
        ], m3: 6);

        $di = customerDecision($inWindow->fresh());
        $db = customerDecision($beyond->fresh());

        $this->assertFalse($di['renewal']['in'], '过期实例永不命中待续费（I1 口径翻转）');
        $this->assertSame([], $di['renewal']['why'], '历史卡不得产生 why');
        $this->assertTrue($di['expiredWithBalance'], '窗内过期 → 过期有余额');
        $this->assertSame('P3', $di['layer'], '窗内过期且有余额 → P3');
        $this->assertFalse($db['renewal']['in'], '超窗过期同样不命中');
        $this->assertFalse($db['expiredWithBalance'], '出窗后不再计入过期有余额');
        $this->assertSame('P4', $db['layer'], '超窗过期 → P4');

        // 描述与行为一致（§9.2 #9）
        $desc = layerDefinitions()['P3']['desc'];
        $this->assertStringContainsString('有过期实例且仍有余额', $desc, 'P3 描述必须与行为一致');
    }

    // ============================================================ t25 结构性修复回归
    //
    // 背景：t19-F1 → t24-F1 → t24-F2 连续三轮都是「列举式证据集漏项」。
    // 共同形态是**组合漏测**：每轮只补了被点名的那一格。
    // 所以这组回归不用「再补几格枚举」，而是测**代数性质** ——
    // 性质成立即对所有组合成立，新增第 6 种卡项状态时也自动覆盖。

    /** 5 种卡项状态的代表卡（与 customerDecision 的分类循环一一对应） */
    private function stateCards(): array
    {
        return [
            'liveCount' => ['card_title' => '在用次卡', 'status' => '4', 'type' => '1',
                'residue_amount' => 50, 'usage_total' => 10],
            'liveTime' => ['card_title' => '在用年卡', 'status' => '4', 'type' => '2',
                'residue_amount' => 200, 'usage_total' => 0,
                'deadline' => $this->inDays(200), 'expiry_days' => 365],
            'unknownResidue' => ['card_title' => '余额未知次卡', 'status' => '4', 'type' => '1',
                'usage_total' => 20],
            'exhaustedCount' => ['card_title' => '已耗尽次卡', 'status' => '5', 'type' => '1',
                'residue_amount' => 0, 'usage_total' => 30, 'deadline' => $this->inDays(5)],
            'unactivated' => ['card_title' => '未开卡次卡', 'status' => '7', 'type' => '1',
                'residue_amount' => 10, 'usage_total' => 0],
        ];
    }

    /**
     * 结构性性质（v3.9 收窄为**有可用权益的基底**）：加一张未开卡卡项，不得改变判定。
     *
     * 这是 T24-F1/T24-F2 的通式：缺陷形态是「加一张未开卡卡项 → 结论翻转」。
     *
     * ⚠️ v3.9 的边界（必须写清楚，否则这条性质会被误读成「永远成立」）：
     *  K1 的口径是 `USABLE=∅ ∧ UNACT≠∅ → 待开卡`，因此当基底**没有任何可用权益**时，
     *  加一张未开卡卡项**应当**把 bucket 翻转成「待开卡」（C7 显式口径翻转）。
     *  所以本性质只在「基底 hasUsableEntitlement=true」时断言不变；
     *  「无可用权益」那一半由 `test_exhausted_plus_unactivated_flips_to_pending_start` 单独锁定。
     *
     * 两个维度都要跑：未开卡卡项有/无标题；基底有/无标题（T24-F2 只在无标题时暴露）。
     */
    public function test_adding_unactivated_card_never_changes_decision(): void
    {
        $states = $this->stateCards();
        $bases = array_diff_key($states, ['unactivated' => true]);
        $this->assertCount(4, $bases, '基底应覆盖除 unactivated 外的 4 种状态');

        $unactivatedForms = [
            '有标题' => $states['unactivated'],
            '无标题' => ['status' => '7', 'type' => '1', 'residue_amount' => 10, 'usage_total' => 0],
        ];

        $compared = 0;
        foreach ($bases as $name => $base) {
            $baseForms = ['有标题' => $base, '无标题' => array_diff_key($base, ['card_title' => true])];

            foreach ($baseForms as $baseForm => $b) {
                foreach ($unactivatedForms as $uForm => $u) {
                    $label = "基底{$name}({$baseForm}) + 未开卡({$uForm})";
                    $without = $this->memberFromCards("性质-{$label}", [$b], m3: 6);
                    $with = $this->memberFromCards("性质-{$label}-加卡", [$b, $u], m3: 6);

                    $a = customerDecision($without->fresh());
                    $c = customerDecision($with->fresh());

                    // hasAsset 永远不得因「加一张未开卡」而翻转（T24-F2 的原始缺陷）
                    $this->assertSame($a['hasAsset'], $c['hasAsset'], "[{$label}] 加未开卡不得改变 hasAsset");

                    if (! $a['hasUsableEntitlement']) {
                        // K1：基底无可用权益 + 未开卡 → 应当翻转成待开卡（C7），不在此断言不变
                        continue;
                    }
                    $compared++;
                    $this->assertSame($a['renewal']['in'], $c['renewal']['in'], "[{$label}] 加未开卡不得改变 in");
                    $this->assertSame($a['renewal']['bucket'], $c['renewal']['bucket'], "[{$label}] 加未开卡不得改变 bucket");
                    $this->assertSame($a['layer'], $c['layer'], "[{$label}] 加未开卡不得改变 layer");
                    $this->assertSame($a['renewal']['why'], $c['renewal']['why'], "[{$label}] 加未开卡不得改变 why");
                    $this->assertSame($a['renewal']['whyCodes'], $c['renewal']['whyCodes'], "[{$label}] 加未开卡不得改变 whyCodes");
                }
            }
        }

        // 防「性质测试空转」：必须有非平凡比较参与，否则全是被 continue 跳过的空循环
        $this->assertGreaterThanOrEqual(8, $compared,
            '应至少比较 8 组「基底有可用权益」的组合（liveCount/liveTime × 标题形态 × 未开卡形态）');

        // 同时确认「无标题」形态确实让汇总证据失效（否则该维度等于没测）
        $noTitleBase = customerDecision(
            $this->memberFromCards('性质-汇总证据失效校验',
                [array_diff_key($states['liveCount'], ['card_title' => true])], m3: 6)->fresh()
        );
        $this->assertTrue($noTitleBase['hasAsset'],
            '无标题形态必须靠 cards_list 证据才判得出资产——否则本测试的「无标题」维度是空转');
    }

    /**
     * T24-F1 / C7：**显式口径翻转**（§9.2 #10）。
     *
     * v3.9 之前：「已耗尽 + 未开卡 → 待续费」（t25 曾把这条锁成不变量）。
     * v3.9 之后：`USABLE=∅ ∧ UNACT≠∅ → 待开卡`（K1 优先于 K2），
     * 耗尽信号被 K1 压制 —— 只允许出现在 diagnostics（suppressedCodes），
     * 绝不允许拼进 why / whyCodes。**这不是回归，是按规范改写。**
     */
    public function test_exhausted_plus_unactivated_flips_to_pending_start(): void
    {
        $exhausted = $this->stateCards()['exhaustedCount'];
        $unactivated = $this->stateCards()['unactivated'];

        $only = $this->memberFromCards('仅已耗尽次卡', [$exhausted], m3: 6);
        $plus = $this->memberFromCards('已耗尽+未开卡', [$exhausted, $unactivated], m3: 6);

        $a = customerDecision($only->fresh());
        $b = customerDecision($plus->fresh());

        // 基准：仅已耗尽次卡 → count_exhausted 命中（真实数据几乎不触发，F4/C3）
        $this->assertTrue($a['renewal']['in'], '基准：仅已耗尽次卡仍命中续费');
        $this->assertContains('count_exhausted', $a['renewal']['whyCodes']);

        // 翻转：加一张未开卡卡项 → 待开卡，且不进待续费
        $this->assertFalse($b['renewal']['in'], 'C7：待开卡不进待续费');
        $this->assertSame('待开卡', $b['renewal']['bucket']);
        $this->assertNotContains('count_exhausted', $b['renewal']['whyCodes'],
            'whyCodes 作用域约束：待开卡只允许 unactivated_*');
        $this->assertContains('count_exhausted', $b['renewal']['suppressedCodes'],
            '被压制的耗尽信号必须保留在 diagnostics 里');
        $this->assertSame('P4', $b['layer'], '无可用权益、无过期余额 → P4');
    }

    /**
     * T24-F2（medium）：`hasAsset` 漏查 `$unactivated`。
     *
     * 修复前实测：同一张未开卡卡项，**仅差 `card_title`** ——
     * 有标题 → hasAsset=true/P4，无标题 → hasAsset=false/**P5**（被当成前端客资）。
     */
    public function test_unactivated_asset_detection_is_title_independent(): void
    {
        $withTitle = ['card_title' => '未开卡次卡', 'status' => '7', 'type' => '1',
            'residue_amount' => 10, 'usage_total' => 0];
        $noTitle = ['status' => '7', 'type' => '1', 'residue_amount' => 10, 'usage_total' => 0];

        $a = customerDecision($this->memberFromCards('未开卡有标题', [$withTitle], m3: 6)->fresh());
        $b = customerDecision($this->memberFromCards('未开卡无标题', [$noTitle], m3: 6)->fresh());

        $this->assertTrue($a['hasAsset'], '未开卡卡项也是资产（会员确实买了卡）');
        $this->assertTrue($b['hasAsset'], '不得因缺 card_title 就判无资产');
        $this->assertSame($a['hasAsset'], $b['hasAsset'], 'hasAsset 不得依赖 card_title');
        $this->assertSame($a['layer'], $b['layer'], '两者 layer 必须一致');
        $this->assertNotSame('P5', $b['layer'], '有卡会员不得落 P5（否则会被当客资、PII 外泄）');
        $this->assertSame('待开卡', $b['renewal']['bucket'], '未开卡仍应归待开卡（C7 不被修坏）');
    }

    /** 正向判定的另一面：**确实只有**未开卡（含多张）时，仍须归「待开卡」。 */
    public function test_only_unactivated_cards_use_positive_count_assertion(): void
    {
        $u = $this->stateCards()['unactivated'];
        $u2 = ['card_title' => '未开卡年卡', 'status' => '7', 'type' => '2',
            'residue_amount' => 300, 'usage_total' => 0, 'expiry_days' => 365];

        foreach ([1 => [$u], 2 => [$u, $u2]] as $n => $cards) {
            $d = customerDecision($this->memberFromCards("仅未开卡x{$n}", $cards, m3: 6)->fresh());
            $this->assertSame('待开卡', $d['renewal']['bucket'], "{$n} 张未开卡应归待开卡");
            $this->assertFalse($d['renewal']['in'], "{$n} 张未开卡不应触发续费");
            $this->assertTrue($d['hasAsset'], "{$n} 张未开卡仍是有资产（不应落 P5）");
        }
    }

    /**
     * 三卡混合：未开卡 + 两种其他状态 —— 计数断言必须仍然正确
     * （`count($unactivated) !== count($cards)` → 不是「只有未开卡」）。
     */
    public function test_mixed_three_card_set_is_not_pending_start(): void
    {
        $s = $this->stateCards();
        $c = $this->memberFromCards('三卡混合', [$s['unactivated'], $s['liveCount'], $s['unknownResidue']], m3: 6);
        $d = customerDecision($c->fresh());

        $this->assertNotSame('待开卡', $d['renewal']['bucket'], '存在其他卡项时不得归待开卡');
        $this->assertTrue($d['hasAsset']);
    }

    /**
     * 授权面不变量（t25 复验）：`main_card='—'` ⟹ `cards_list` 为空。
     *
     * 这条不变量是「cards_list 非空即有资产」安全的**唯一依据**：
     * 两者同源自 KyMemberSyncService 的 `$active`，故 main_card='—' 时明细必空
     * → hasAsset 仍为 false → 真客资仍落 P5。
     * 一旦此不变量被破坏，「cards_list 非空即有资产」就可能把真客资推进 P5。
     */
    public function test_main_card_dash_implies_empty_cards_list(): void
    {
        $inputs = [
            '空卡项' => [],
            '只有退卡' => [['card_title' => '退卡', 'status' => '29', 'type' => '1', 'residue_amount' => 0, 'usage_total' => 10]],
            '只有过期卡' => [['card_title' => '过期卡', 'status' => '6', 'type' => '1', 'residue_amount' => 5, 'usage_total' => 10]],
            '在用卡' => [['card_title' => '在用卡', 'status' => '4', 'type' => '1', 'residue_amount' => 10, 'usage_total' => 5]],
            '在用卡无标题' => [['status' => '4', 'type' => '1', 'residue_amount' => 10, 'usage_total' => 5]],
            '未开卡' => [['card_title' => '未开卡', 'status' => '7', 'type' => '1', 'residue_amount' => 3, 'usage_total' => 0]],
            '未开卡无标题' => [['status' => '7', 'type' => '1', 'residue_amount' => 3, 'usage_total' => 0]],
            '在用+退卡' => [
                ['card_title' => '在用', 'status' => '4', 'type' => '1', 'residue_amount' => 10, 'usage_total' => 5],
                ['card_title' => '退卡', 'status' => '29', 'type' => '1', 'residue_amount' => 0, 'usage_total' => 5],
            ],
            '期限卡' => [['card_title' => '年卡', 'status' => '4', 'type' => '2', 'residue_amount' => 200, 'usage_total' => 0, 'deadline' => $this->inDays(200), 'expiry_days' => 365]],
            '未知status' => [['card_title' => '怪卡', 'status' => '99', 'type' => '1', 'residue_amount' => 10, 'usage_total' => 0]],
        ];

        $m = new \ReflectionMethod(KyMemberSyncService::class, 'summarizeCards');
        $violations = [];
        foreach ($inputs as $label => $cards) {
            $sum = $m->invoke(null, $cards);
            // v3.9（§9.2 #13 的重定义）：main_card='—' ⟹ 没有当前/未开卡实例，也没有历史实例；
            // `hasAnyCard` 可能仍为 true（被排除的体验/赠品卡是「曾经有卡」的证据，见 A11），
            // 因此等价式写成「main_card='—' ⟺ ALL=∅」，而不是与 hasAnyCard 直接等价。
            if ($sum['main_card'] === '—'
                && ($sum['cards_list'] !== [] || $sum['card_stats']['historyCards'] !== [])) {
                $violations[] = $label;
            }
            // 有历史实例时，主卡必须回退到历史（带 state），且历史明细可识别身份
            if ($sum['card_stats']['historyCards'] !== []) {
                if ($sum['main_card'] === '—') {
                    $violations[] = $label.'（有历史实例却无主卡）';
                }
                foreach ($sum['card_stats']['historyCards'] as $history) {
                    if (! array_key_exists('state', $history) || $history['state'] !== 'history'
                        || ! array_key_exists('historyReason', $history)) {
                        $violations[] = $label.'（历史实例不可识别）';

                        break;
                    }
                }
            }
        }
        $this->assertSame([], $violations, '不变量被破坏：main_card/历史实例的结构关系不成立');
    }

    /** 授权面端到端：真客资仍 P5/可见，ky: 无资产会员仍不被当客资。 */
    public function test_authorization_surface_unchanged_after_positive_detection(): void
    {
        $lead = $this->memberFromCards('t25真客资', [], m3: 0, extra: ['main_card' => '—']);
        $ky = $this->memberFromCards('t25ky无资产会员', [], m3: 0,
            extra: ['main_card' => '—', 'external_id' => 'ky:99002']);
        recalculateMemberLayers();

        $this->assertFalse(customerDecision($lead->fresh())['hasAsset'], '真客资必须仍判无资产');
        $this->assertSame('P5', $lead->fresh()->layer, '真客资必须仍落 P5');
        $this->assertTrue(isLeadOnlyCustomer($lead->fresh()), '真客资必须仍被认作客资');

        $this->assertSame('P5', $ky->fresh()->layer, 'ky: 无资产会员仍落 P5（分层语义不变）');
        $this->assertFalse(isLeadOnlyCustomer($ky->fresh()), 'ky: 无资产会员不得被当成客资');
    }


    // ═══════════════════════════════════════════════════════════════════════
    // 待续费分母单位错误（v3.3.4 / t2）：usage_total 是金额不是次数
    //
    // 王素红「定制私教30节」residue=29、usage_total=466.63、curr_unit_cash_value=466.6333：
    //   旧: bound = 29 + 466.63 = 495.63 ⇒ 29/495.63 = 5.85% ⇒ 误判「紧急待续费」
    //   新: 已耗 = 466.63/466.6333 = 1 次 ⇒ bound = 30 ⇒ 29/30 = 96.67% ⇒ 不该提醒
    //
    // 权威规则（详见 KyMemberSyncService::consumedCount 注释与 docs/口径 报告）：
    //   1) usage_total / curr_unit_cash_value  2) 回退 consume_amount_format
    //   3) 都不可用 ⇒ null（显式降级，绝不编造分母）
    // ═══════════════════════════════════════════════════════════════════════

    /** 上游卡项原始行：王素红真实卡数值（脱敏，仅卡名+数值，无 PII） */
    private function amountCard(array $overrides = []): array
    {
        return array_merge([
            'card_title' => '定制私教30节',
            'status' => '5',
            'type' => '1',
            'is_taste' => '0',
            'residue_amount' => '29',
            'usage_total' => '466.63',
            'curr_unit_cash_value' => '466.6333333333',
            'consume_amount_format' => '1次',
            'deal_price' => '13999',
            'expiry_days' => '0',
            'deadline' => '1810396799',       // 2027-05-15
        ], $overrides);
    }

    private function summarizeAmountCards(array $cards): array
    {
        return $this->summarize($cards);
    }

    /** 用「卡项原始行」建会员（RenewalPrecisionTest::memberFromCards 的别名，语义同） */
    private function memberFromAmountCards(string $name, array $cards, int $m3 = 6): Customer
    {
        return $this->memberFromCards($name, $cards, $m3);
    }

    private function enablePercentRule(int $percent = 15): void
    {
        $this->setRules([
            'renewalThreshold' => 10, 'renewalCountPercent' => $percent,
            'renewalExpireDays' => 30, 'renewalExpirePercent' => 0,
            'vipAmountThreshold' => 30000, 'declineMode' => 'strict',
            'predropMin' => 15, 'predropMax' => 30, 'reviveDays' => 30,
        ]);
    }

    public function test_real_card_bound_is_total_sessions_not_amount(): void
    {
        $sum = $this->summarizeAmountCards([$this->amountCard()]);

        $this->assertSame(29, $sum['card_stats']['countResidue'], '剩余节数必须是 29');
        $this->assertSame(30, $sum['card_stats']['countBound'], '分母必须是总次数 30（29 剩余 + 1 已耗）');

        $ratio = $sum['card_stats']['countResidue'] / $sum['card_stats']['countBound'] * 100;
        $this->assertGreaterThanOrEqual(15, $ratio, "占比 {$ratio}% 必须 ≥ 15%：这是「不再命中 5.9%」的验收点");
        $this->assertEqualsWithDelta(96.7, $ratio, 0.1);

        // 逐卡 bound 与汇总同源表达式
        $this->assertSame(30, $sum['cards_list'][0]['bound'], 'cards_list.bound 必须与 countBound 同源');
        $this->assertSame(30, $sum['total_purchased'], 'total_purchased 同源表达式必须一并修正（旧实现会算成 495）');
    }

    public function test_real_card_member_is_not_flagged_as_renewal(): void
    {
        $this->enablePercentRule(15);
        $c = $this->memberFromAmountCards('分母定点会员', [$this->amountCard()]);

        $this->assertNotContains($c->id, $this->listIdsFor('待续课'), '29/30 = 96.7% 远高于 15%，不该进待续费');
    }

    public function test_genuine_tail_card_is_still_flagged(): void
    {
        $this->enablePercentRule(15);
        $c = $this->memberFromAmountCards('真实尾段会员', [$this->amountCard([
            'residue_amount' => '3',
            'usage_total' => '12599.10',      // 27 次 × 466.6333
        ])]);

        $sum = $this->summarizeAmountCards([$this->amountCard(['residue_amount' => '3', 'usage_total' => '12599.10'])]);
        $this->assertSame(30, $sum['card_stats']['countBound'], '3 剩余 + 27 已耗 = 30');
        $this->assertContains($c->id, $this->listIdsFor('待续课'), '3/30 = 10% ≤ 15%，必须命中');
    }

    public function test_bound_is_never_residue_plus_usage_amount(): void
    {
        $sum = $this->summarizeAmountCards([$this->amountCard()]);
        $wrong = (int) floor((float) '29' + (float) '466.63');   // 495

        $this->assertNotSame($wrong, $sum['card_stats']['countBound'],
            'bound 仍是 residue+usage_total（金额混入）——单位错误复现');
        $this->assertLessThan(100, $sum['card_stats']['countBound'],
            '30 节卡的 bound 不该是数百：金额量级（数百元）出现即说明又是金额当次数');
    }

    public function test_mutation_reverting_to_amount_breaks_the_regression(): void
    {
        $sum = $this->summarizeAmountCards([$this->amountCard()]);
        // 旧实现（被修掉的那一行）的字面复刻
        $mutated = (int) floor(max(0.0, (float) '29') + max(0.0, (float) '466.63'));
        $mutatedRatio = (float) '29' / $mutated * 100;

        $this->assertSame(495, $mutated, '变异体必须复刻旧表达式：29 + 466.63 = 495');
        $this->assertLessThan(15, $mutatedRatio, '变异体占比 5.85% < 15% ⇒ 会误报（这正是被修的缺陷）');
        $this->assertNotSame($mutated, $sum['card_stats']['countBound'],
            '实现与变异体必须不同 —— 若相同则说明修复被回退，回归用例会红');
        $this->assertGreaterThanOrEqual(15, $sum['card_stats']['countResidue'] / $sum['card_stats']['countBound'] * 100);
    }

    public function test_no_usage_amount_added_into_bound_expression(): void
    {
        $src = file_get_contents(base_path('app/Services/KyMemberSyncService.php'));

        // 旧实现两处的字面形态：金额被直接加到剩余节数上
        $this->assertStringNotContainsString(
            "+ max(0.0, self::toNum(\$card['usage_total'] ?? 0))",
            $src,
            '分母仍含「剩余节数 + usage_total(金额)」形态——单位错误会复发'
        );

        // 分母累加处必须经 consumedCount() 推导（v3.9 起逐卡 bound 先算、再汇总，
        // 表达式仍必须是「剩余节数 + 已耗次数」，不得再让金额进入分母）
        $this->assertStringContainsString('consumedCount', $src, '必须经 consumedCount() 统一推导已耗次数');
        $this->assertStringContainsString(
            "\$card['bound'] = \$card['remaining'] + \$consumed;",
            $src,
            '逐卡分母必须是「剩余节数 + 已耗次数」'
        );
        $this->assertStringContainsString(
            '$countBound += $card[\'bound\'];',
            $src,
            '汇总分母必须累加逐卡 bound（与逐卡同源）'
        );
    }

    public function test_amount_source_wins_when_sources_disagree(): void
    {
        // 金额推出 1 次，文本说 8 次 —— 必须取金额的 1 次
        $sum = $this->summarizeAmountCards([$this->amountCard(['consume_amount_format' => '8次'])]);

        $this->assertSame(30, $sum['card_stats']['countBound'], '冲突时金额优先：29 + 1 = 30（不是 29 + 8 = 37）');
    }

    public function test_text_source_is_used_when_unit_value_missing(): void
    {
        $sum = $this->summarizeAmountCards([$this->amountCard([
            'curr_unit_cash_value' => '0',
            'usage_total' => '0.00',
            'consume_amount_format' => '4次',
        ])]);

        $this->assertSame(33, $sum['card_stats']['countBound'], '回退文本口径：29 + 4 = 33');
        $this->assertSame(0, $sum['card_stats']['countBoundUnderivable'], '文本可解析 ⇒ 不算降级');
    }

    public function test_zero_usage_with_unit_value_means_zero_consumed(): void
    {
        $sum = $this->summarizeAmountCards([$this->amountCard([
            'usage_total' => '0.00',
            'consume_amount_format' => '5次',    // 与金额口径冲突：金额权威
        ])]);

        $this->assertSame(29, $sum['card_stats']['countBound'], '金额口径为 0 ⇒ bound = 29 + 0');
    }

    public function test_underivable_consumed_is_reported_and_not_fabricated(): void
    {
        $sum = $this->summarizeAmountCards([$this->amountCard([
            'curr_unit_cash_value' => '0',
            'usage_total' => '0.00',
            'consume_amount_format' => '',        // 空文本：两来源皆不可用
        ])]);

        $this->assertSame(1, $sum['card_stats']['countBoundUnderivable'], '推导不出必须计数上报');
        $this->assertSame(29, $sum['card_stats']['countBound'],
            '降级时分母只含剩余节数（保守偏高），绝不凭空补一个已耗次数');

        // 【O1 / t6 复核发现】`cards_list[].bound` 此前全仓只有 1 处断言
        // （主路径 test_real_card_bound_is_total_sessions_not_amount），**降级路径没有断言**
        // ⇒ 后人把降级分支的 bound 改坏（例如退化成 null、或又把金额混进来）不会转红。
        // 这里补上降级路径的逐卡 bound：与 countBound 同源、降级时同样只含剩余节数。
        $this->assertSame(29, $sum['cards_list'][0]['bound'],
            '降级路径的 cards_list.bound 必须与 countBound 同源（只含剩余节数），改坏必须转红');
    }

    public function test_underivable_is_surfaced_through_degraded(): void
    {
        $sum = $this->summarizeAmountCards([$this->amountCard([
            'curr_unit_cash_value' => '0', 'usage_total' => '0.00', 'consume_amount_format' => '',
        ])]);
        $c = $this->rawCustomer([
            'name' => '降级上报会员', 'phone' => '13800001111', 'phone_tail' => '1111',
            'venue' => '绿地店', 'source' => 'KeepYoga', 'owner' => '店长', 'consultant' => '店长',
            'status' => '在籍', 'layer' => 'P4', 'external_id' => 'ky:test-degraded',
            'main_card' => $sum['main_card'], 'remain_times' => $sum['remain_times'],
            'card_stats' => $sum['card_stats'], 'cards_list' => $sum['cards_list'],
            'attend_m1' => 6, 'attend_m2' => 6, 'attend_m3' => 6,
            'last_visit' => now()->subDays(3)->toDateString(),
        ]);

        $degraded = customerDecision($c->fresh())['renewal']['degraded'];
        $this->assertNotEmpty($degraded, '推导不出已耗次数必须显式降级，不能静默');
        $this->assertStringContainsString('已耗次数', implode('；', $degraded));
    }

    public function test_negative_or_non_finite_values_are_treated_as_underivable(): void
    {
        $sum = $this->summarizeAmountCards([$this->amountCard([
            'curr_unit_cash_value' => '-1', 'usage_total' => '466.63', 'consume_amount_format' => '-3次',
        ])]);

        $this->assertSame(29, $sum['card_stats']['countBound'], '脏数据下分母退化为剩余节数，不得为负或离谱');
        $this->assertGreaterThanOrEqual(0, $sum['card_stats']['countBoundUnderivable']);
    }
}
