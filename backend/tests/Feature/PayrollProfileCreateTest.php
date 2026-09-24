<?php

namespace Tests\Feature;

use App\Http\Controllers\PayrollController;
use App\Models\KyBooking;
use App\Models\Lead;
use App\Models\PayrollProfile;
use App\Models\User;
use App\Services\PayrollNameResolver;
use App\Services\PayrollService;
use Database\Seeders\PayrollProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 建档与「待完善」安全闸门。
 *
 * ## 这一组用例在钉什么
 *
 * 用户要求「新增建档时把已知信息填好，未知的留空由我完善」。危险恰在「留空」：
 *
 *  - `payroll_profiles.role` 列有 `default('全职老师')`；
 *  - `PayrollRoles::allowsBaseReward('全职老师')` 为真 ⇒ 两店累计有效课时 ≥80 就发
 *    200~1000 元底薪奖励，**这笔钱完全不看档案金额**，只看实际课时；
 *  - `allowsBaseSalary('')` 也为真（只要 != '兼职老师'）⇒ 底薪进应发。
 *
 * 也就是说「字段留空」在现有实现里**不等于**「不参与计算」。所以待完善的档案
 * 必须整行不参与计算、且**显式出现在 unavailable 里**（不能静默少人）。
 *
 * 第二个雷是预填建档的判重：系统里的 `teacher_name` 多半是别名
 * （冰璐→钱冰璐、苏米→罗柳柳…），按字面判重会给别名再建一条档案，
 * 使该名字在解析器里变成**歧义 → resolve() 返回 null → 本人从课时统计里消失**。
 */
class PayrollProfileCreateTest extends TestCase
{
    use RefreshDatabase;

    private function super(): User
    {
        return User::factory()->create([
            'username' => 'payroll-super', 'name' => '超管', 'role' => 'R_SUPER', 'venue' => null,
        ]);
    }

    private function profile(array $attrs = []): PayrollProfile
    {
        return PayrollProfile::create(array_merge([
            'name' => '张老师', 'venue' => '绿地店', 'role' => '全职老师',
            'base_salary' => 2000, 'fee_private60' => 160,
            'fee_small' => 160, 'fee_group' => 160, 'fee_enterprise' => 160,
            'status' => '有效',
        ], $attrs));
    }

    /** 某月让某人有课时：一条已签到、非体验的预约 */
    private function teach(string $teacher, string $venue = '绿地店', ?string $when = null): void
    {
        KyBooking::create([
            'source_key' => uniqid('t-'),
            'venue' => $venue,
            'booking_type' => '私教',
            'course_kind' => 'private',
            'member_id' => 'm-'.md5($teacher.$when.microtime(true)),
            'member_name' => '会员甲',
            'phone' => '13800000001',
            'start_at' => $when ?? now()->format('Y-m-d 10:00'),
            'course_name' => '定制私教60分钟',
            'teacher_name' => $teacher,
            'status_raw' => '已签到',
            'status' => 'signed',
            'is_trial' => false,
        ]);
    }

    // ------------------------------------------------------------------
    // 建档
    // ------------------------------------------------------------------

    public function test_super_can_create_profile_and_it_starts_pending(): void
    {
        Sanctum::actingAs($this->super());

        $res = $this->postJson('/api/payroll/profiles', [
            'name' => '新老师甲',
            'venue' => '绿地店',
        ])->assertOk()->json('data.profile');

        $this->assertSame('新老师甲', $res['name']);
        // 关键：新档案必须标待完善，否则会被按 `role` 默认值当全职老师算工资
        $this->assertTrue($res['pendingReview']);
        $this->assertSame('', $res['role']);
    }

    public function test_create_rejects_duplicate_name_in_same_venue(): void
    {
        Sanctum::actingAs($this->super());
        $this->profile(['name' => '重名老师', 'venue' => '绿地店']);

        $this->postJson('/api/payroll/profiles', [
            'name' => '重名老师', 'venue' => '绿地店',
        ])->assertStatus(422)->assertJsonPath('code', 'PROFILE_EXISTS');
    }

    public function test_create_rejects_unknown_role_and_venue(): void
    {
        Sanctum::actingAs($this->super());

        $this->postJson('/api/payroll/profiles', [
            'name' => '甲', 'venue' => '绿地店', 'role' => '店长助理',
        ])->assertStatus(422)->assertJsonPath('code', 'ROLE_NOT_ALLOWED');

        $this->postJson('/api/payroll/profiles', [
            'name' => '乙', 'venue' => '不存在店',
        ])->assertStatus(422)->assertJsonPath('code', 'INVALID_VENUE');
    }

    public function test_non_super_cannot_create_profile(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'username' => 'mgr', 'name' => '店长', 'role' => 'R_MANAGER', 'venue' => '绿地店',
        ]));

        $this->postJson('/api/payroll/profiles', [
            'name' => '偷偷建的人', 'venue' => '绿地店',
        ])->assertStatus(403);
    }

    /** 保存时身份标签合法即视为「已确认」，自动解除待完善 */
    public function test_saving_valid_role_confirms_the_profile(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->profile(['name' => '待确认老师', 'role' => '', 'pending_review' => true]);
        $this->assertTrue($p->fresh()->pending_review);

        // 用全职老师（兼职老师另有「底薪/绩效必须为 0」的硬闸门，属另一条规则）
        $res = $this->putJson('/api/payroll/profiles/'.$p->id, [
            'role' => '全职老师',
        ])->assertOk()->json('data.profile');

        $this->assertSame('全职老师', $res['role']);
        $this->assertFalse($res['pendingReview'], '身份标签已确认，应解除待完善');
    }

    /** 兼职老师的底薪/绩效强制 0：既有闸门不能被建档流程绕过 */
    public function test_parttime_role_rejects_nonzero_base_salary(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->profile(['name' => '兼职候选人', 'role' => '', 'pending_review' => true]);

        $this->putJson('/api/payroll/profiles/'.$p->id, [
            'role' => '兼职老师',
        ])->assertStatus(422)->assertJsonPath('code', 'PARTTIME_FIXED_SALARY');

        // 连同底薪一起归零才允许
        $this->putJson('/api/payroll/profiles/'.$p->id, [
            'role' => '兼职老师', 'baseSalary' => 0, 'performance' => 0,
        ])->assertOk()->assertJsonPath('data.profile.pendingReview', false);
    }

    /** 身份标签留空保存不放行（等于没确认过算法分叉点） */
    public function test_saving_without_role_keeps_pending_and_is_rejected(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->profile(['name' => '仍待确认', 'role' => '', 'pending_review' => true]);

        // 空身份标签在既有校验里就被拒（ROLE_NOT_ALLOWED），档案保持待完善
        $this->putJson('/api/payroll/profiles/'.$p->id, ['note' => '只改备注'])
            ->assertStatus(422)->assertJsonPath('code', 'ROLE_NOT_ALLOWED');

        $this->assertTrue($p->fresh()->pending_review);
    }

    // ------------------------------------------------------------------
    // 待完善闸门：不参与计算 + 显式告知
    // ------------------------------------------------------------------

    public function test_pending_profile_is_excluded_from_calculation_and_reported(): void
    {
        $this->teach('待完善老师');
        // 已确认的对照人
        $ok = $this->profile(['name' => '已确认老师']);
        // 待完善：身份标签空 + 课时很多（若被当全职老师会触发 200~1000 底薪奖励）
        $pending = $this->profile([
            'name' => '待完善老师', 'role' => '', 'base_salary' => 0,
            'performance' => 0, 'pending_review' => true,
        ]);

        $r = app(PayrollService::class)->calculate(now()->format('Y-m'), null);
        $names = array_column($r['rows'], 'name');

        $this->assertContains('已确认老师', $names);
        $this->assertNotContains('待完善老师', $names, '待完善档案不得进入计算行');

        // 必须显式告知，不能静默少人
        $gate = collect($r['unavailable'])->first(fn ($u) => str_contains($u['item'], '待完善'));
        $this->assertNotNull($gate, '待完善人员必须出现在 unavailable 里');
        $this->assertStringContainsString('待完善老师', $gate['reason']);
        // 同时结构化下发姓名（前端据此显示「N 人未计入」，不去正则解析那句话术）
        $this->assertContains('待完善老师', $r['pendingNames']);

        $this->assertTrue($pending->fresh()->pending_review);
        $this->assertFalse($ok->fresh()->pending_review);
    }

    /** 解除待完善后，同一个人立刻恢复计算（闸门不是单向的） */
    public function test_confirming_profile_brings_it_back_into_calculation(): void
    {
        $this->teach('回归老师');
        $p = $this->profile([
            'name' => '回归老师', 'role' => '', 'base_salary' => 0, 'performance' => 0,
            'pending_review' => true,
        ]);

        $before = array_column(app(PayrollService::class)->calculate(now()->format('Y-m'), null)['rows'], 'name');
        $this->assertNotContains('回归老师', $before);

        $p->role = '全职老师';
        $p->pending_review = false;
        $p->save();

        $after = array_column(app(PayrollService::class)->calculate(now()->format('Y-m'), null)['rows'], 'name');
        $this->assertContains('回归老师', $after, '确认后应恢复计算');
    }

    // ------------------------------------------------------------------
    // 预填：判重必须走解析器（否则砸掉别名解析）
    // ------------------------------------------------------------------

    public function test_prefill_creates_only_genuinely_unregistered_people(): void
    {
        Sanctum::actingAs($this->super());
        // 既有档案 + 别名：系统里的 teacher_name 会用「苏米」这个别名出现
        $this->profile(['name' => '罗柳柳', 'aliases' => ['苏米']]);

        $this->teach('苏米');
        $this->teach('真新老师');

        $res = $this->postJson('/api/payroll/profiles/prefill', ['dryRun' => true])
            ->assertOk()->json('data');

        $names = array_column($res['willCreate'], 'name');
        $this->assertContains('真新老师', $names);
        $this->assertNotContains('苏米', $names, '别名已指向既有档案，不得再建一条');
        $this->assertSame(0, $res['created'], 'dryRun 不得落库');

        // 跳过的项要说明命中了谁（让用户能核对）
        $skipped = collect($res['skipped'])->firstWhere('name', '苏米');
        $this->assertNotNull($skipped);
        $this->assertStringContainsString('罗柳柳', $skipped['reason']);
    }

    /**
     * 🔴 回归护栏：预填不得让既有别名变成歧义。
     *
     * 这是本功能最危险的失误形态 —— 按字面判重就会中招：
     * 给「苏米」再建一条档案后，一个名字对应两个档案，
     * `PayrollNameResolver` 判定歧义 → resolve() 返回 null →
     * **罗柳柳从课时统计里整个消失**（本来明明是解析得到的）。
     */
    public function test_prefill_does_not_break_existing_alias_resolution(): void
    {
        Sanctum::actingAs($this->super());
        $this->profile(['name' => '罗柳柳', 'aliases' => ['苏米']]);
        $this->teach('苏米');

        $resolver = app(PayrollNameResolver::class);
        $this->assertSame('罗柳柳', $resolver->resolve('苏米')?->name, '前提：别名本来可解析');

        $this->postJson('/api/payroll/profiles/prefill', [])->assertOk();

        // 落库后再问一次：必须仍解析到同一个人，且不是歧义
        $fresh = app(PayrollNameResolver::class);
        $this->assertFalse($fresh->isAmbiguous('苏米'), '预填后「苏米」变成了歧义 —— 会让人从课时统计里消失');
        $this->assertSame('罗柳柳', $fresh->resolve('苏米')?->name);

        // 课时统计里必须仍能看到这个人
        $hours = app(PayrollService::class)->hours(now()->format('Y-m'), null);
        $this->assertContains('罗柳柳', array_column($hours['rows'], 'name'));
    }

    /** 预填建档的人一律待完善，且不编造金额 */
    public function test_prefilled_profiles_are_pending_and_have_no_invented_amounts(): void
    {
        Sanctum::actingAs($this->super());
        $this->teach('新来的老师');

        $res = $this->postJson('/api/payroll/profiles/prefill', [])->assertOk()->json('data');
        $this->assertSame(1, $res['created']);

        $p = PayrollProfile::where('name', '新来的老师')->firstOrFail();
        $this->assertTrue($p->pending_review);
        $this->assertSame('', $p->role, '身份标签必须留空由用户填，不得猜');
        // 金额一律 0：这些是算钱的输入，系统不能替用户编
        $this->assertSame(0.0, (float) $p->base_salary);
        $this->assertSame(0.0, (float) $p->performance);
        $this->assertSame(0.0, (float) $p->fee_private60);
        $this->assertSame(0.0, (float) $p->fee_small);
    }

    /** 重复预填必须幂等（不能越跑越多） */
    public function test_prefill_is_idempotent(): void
    {
        Sanctum::actingAs($this->super());
        $this->teach('老师甲');
        $this->teach('老师乙');

        $first = $this->postJson('/api/payroll/profiles/prefill', [])->assertOk()->json('data.created');
        $second = $this->postJson('/api/payroll/profiles/prefill', [])->assertOk()->json('data.created');

        $this->assertSame(2, $first);
        $this->assertSame(0, $second, '第二次不应再建');
        $this->assertSame(2, PayrollProfile::count());
    }

    /** 留资里登记的上课老师也要能扫到（还没产生课次的新老师） */
    public function test_prefill_also_scans_leads_trial_teacher(): void
    {
        Sanctum::actingAs($this->super());
        Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '留资客', 'phone' => '13900000041',
            'source' => '美团', 'venue' => '绿地店', 'service_teacher' => '',
            'status' => '新留资', 'trial_teacher' => '只在留资里的老师',
        ]);

        $res = $this->postJson('/api/payroll/profiles/prefill', ['dryRun' => true])
            ->assertOk()->json('data');

        $row = collect($res['willCreate'])->firstWhere('name', '只在留资里的老师');
        $this->assertNotNull($row);
        $this->assertContains('leads', $row['sources']);
    }

    public function test_non_super_cannot_prefill(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'username' => 'mgr2', 'name' => '店长2', 'role' => 'R_MANAGER', 'venue' => '绿地店',
        ]));

        $this->postJson('/api/payroll/profiles/prefill', [])->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // 账号绑定：payroll_profiles.user_id 唯一约束 vs 跨店老师一人两行
    //
    // 实测故障（测试服 laravel-2026-09-24.log 09:38:59 / 09:39:16）：
    //   SQLSTATE[23000]: Integrity constraint violation: 1062
    //   Duplicate entry '11' for key 'payroll_profiles_user_id_unique'
    //   at PayrollController.php:472（prefillProfiles 的 DB::transaction 内）
    //
    // 因果链：候选键是「姓名|门店」，跨店老师产出两条候选（设计意图：venue 是
    // 工资所属门店）；而两条候选各自执行一次「同名唯一则写 user_id」，
    // 第二条把同一个 user_id 又写一遍 ⇒ 1062 ⇒ 事务回滚 ⇒ 整个请求 500。
    // storeProfile（单条新建第二条门店行）与 PayrollProfileSeeder 是同一循环体的复制品。
    // ------------------------------------------------------------------

    /** 一个有登录账号的员工（`venue` 是账号所属门店） */
    private function account(string $name, ?string $venue = null): User
    {
        return User::factory()->create([
            'username' => 'u-'.uniqid(),
            'name' => $name,
            'role' => 'R_TEACHER',
            'roles' => ['R_TEACHER'],
            'venue' => $venue,
            'venues' => $venue === null ? null : [$venue],
            'status' => '启用',
        ]);
    }

    /**
     * `user_id` 唯一约束的真实语义：**任何时刻都不得有两条档案共用一个账号**。
     *
     * 这是本组用例的核心断言 —— 只看「请求返回 2xx」不足以证明没有踩约束，
     * 必须直接对库做聚合查询。
     */
    private function assertNoDuplicateUserId(string $context = ''): void
    {
        $dups = DB::table('payroll_profiles')
            ->select('user_id')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->havingRaw('count(*) > 1')
            ->pluck('user_id')
            ->all();

        $this->assertSame([], $dups, 'payroll_profiles.user_id 出现重复'.($context !== '' ? "（{$context}）" : '').'：'.json_encode($dups));
    }

    /** 库里 `venue => user_id` 的规范化快照（用于「打乱顺序结果不变」「幂等不变」断言） */
    private function bindingSnapshot(string $name): array
    {
        $map = [];
        foreach (PayrollProfile::where('name', $name)->get(['venue', 'user_id']) as $p) {
            $map[(string) $p->venue] = $p->user_id === null ? null : (int) $p->user_id;
        }
        ksort($map);

        return $map;
    }

    /**
     * 🔴 预填：跨店老师（绿地店 + 东部店）必须建出**两行**，且恰有一行带 user_id。
     *
     * 修复前：第二条行写同一个 user_id ⇒ 1062 ⇒ 500（用户报的故障）。
     */
    public function test_prefill_cross_store_teacher_creates_two_rows_with_single_binding(): void
    {
        Sanctum::actingAs($this->super());
        $u = $this->account('Nico', '绿地店');
        $this->teach('Nico', '东部店');
        $this->teach('Nico', '绿地店');

        // 修复前这里直接 500（Duplicate entry '…' for key 'payroll_profiles_user_id_unique'）
        $res = $this->postJson('/api/payroll/profiles/prefill', [])->assertOk()->json('data');

        $this->assertSame(2, $res['created'], '跨店老师两店各一行，两行都要建出来');
        $rows = PayrollProfile::where('name', 'Nico')->get();
        $this->assertCount(2, $rows, '两行都必须落库');
        $this->assertSame(['东部店', '绿地店'], $rows->pluck('venue')->sort()->values()->all());

        // 恰好一行带 user_id
        $bound = $rows->filter(fn ($p) => $p->user_id !== null);
        $this->assertCount(1, $bound, '一个账号只能绑一行：跨店老师的 user_id 必须恰好一行非空');
        $this->assertSame((int) $u->id, (int) $bound->first()->user_id);
        // User.venue = 绿地店 ⇒ 绑该行（确定性规则，不靠行序碰到谁算谁）
        $this->assertSame('绿地店', $bound->first()->venue, '账号门店与候选行门店一致时应优先绑该行');

        $this->assertNoDuplicateUserId('prefill 跨店老师');

        // 未绑定的那行必须可解释：note 与返回体都要能看到原因，不得静默
        $unbound = $rows->first(fn ($p) => $p->user_id === null);
        $this->assertNotNull($unbound);
        $this->assertStringContainsString('账号绑定', (string) $unbound->note, '未绑定原因必须写进 note');

        $entry = collect($res['willCreate'])->firstWhere('venue', '东部店');
        $this->assertNotNull($entry, '返回体必须列出待建清单');
        $this->assertNull($entry['userId'] ?? null, '东部店那行在返回体里也必须是未绑定');
        $this->assertNotEmpty($entry['bindingReason'] ?? null, '返回体必须给出未绑定原因（不得静默）');
        $this->assertStringContainsString('绿地店', (string) $entry['bindingReason'], '原因要点明账号归了哪一行');

        $this->assertSame(['bound' => 1, 'unbound' => 1], $res['bindings'] ?? null);
    }

    /**
     * 绑定选择必须**确定性**：候选行的插入顺序不得影响结果。
     *
     * 分别在「东部店先插、绿地店先插」两种顺序下跑同一次预填，结果必须逐字相同。
     */
    public function test_cross_store_binding_is_order_independent(): void
    {
        Sanctum::actingAs($this->super());
        $u = $this->account('Nico', '绿地店');

        $run = function (array $order) {
            KyBooking::query()->delete();
            PayrollProfile::query()->delete();
            foreach ($order as $venue) {
                $this->teach('Nico', $venue);
            }
            $res = $this->postJson('/api/payroll/profiles/prefill', [])->assertOk()->json('data');

            return ['created' => $res['created'], 'map' => $this->bindingSnapshot('Nico')];
        };

        $first = $run(['东部店', '绿地店']);
        $second = $run(['绿地店', '东部店']);

        $this->assertSame(2, $first['created']);
        $this->assertSame($first, $second, '打乱候选行插入顺序后，建档与绑定结果必须完全一致');
        $this->assertSame(['东部店' => null, '绿地店' => (int) $u->id], $first['map']);
        $this->assertNoDuplicateUserId('打乱插入顺序');
    }

    /**
     * 绑定规则的确定性还要在**规则本身**上可证（不经 HTTP）：同一组候选行换顺序，
     * 结论必须相同；账号同一门店的那一行恒定优先。
     */
    public function test_binding_rule_picks_the_account_venue_row_regardless_of_row_order(): void
    {
        $account = $this->account('Nico', '绿地店');

        $rows = static fn (array $order) => array_combine(
            $order,
            array_map(fn ($v) => ['venue' => $v, 'currentUserId' => null, 'profileId' => null], $order)
        );

        $a = PayrollController::distributeUserAccounts('Nico', $rows(['东部店', '绿地店']), [['id' => $account->id, 'venue' => '绿地店']]);
        $b = PayrollController::distributeUserAccounts('Nico', $rows(['绿地店', '东部店']), [['id' => $account->id, 'venue' => '绿地店']]);

        $this->assertSame((int) $account->id, $a['绿地店']['userId']);
        $this->assertNull($a['东部店']['userId']);
        $this->assertNotEmpty($a['东部店']['reason'], '未绑定行必须带原因');
        $this->assertSame($a, $b, '行序不得影响绑定结论');
    }

    /** 同名多个账号时**不猜**：全员留空并说明（猜错就是把钱记到别人头上） */
    public function test_binding_rule_does_not_guess_between_same_name_accounts(): void
    {
        $a = $this->account('张老师', '绿地店');
        $b = $this->account('张老师', '东部店');

        $out = PayrollController::distributeUserAccounts('张老师', [
            'row' => ['venue' => '绿地店', 'currentUserId' => null, 'profileId' => null],
        ], [['id' => $a->id, 'venue' => '绿地店'], ['id' => $b->id, 'venue' => '东部店']]);

        $this->assertNull($out['row']['userId']);
        $this->assertStringContainsString('同名', (string) $out['row']['reason']);
    }

    /** 🔴 幂等：跨店老师连点两次预填 —— 第二次不新增、不改动既有 user_id、不报错 */
    public function test_cross_store_prefill_is_idempotent_and_keeps_bindings(): void
    {
        Sanctum::actingAs($this->super());
        $u = $this->account('Nico', '绿地店');
        $this->teach('Nico', '东部店');
        $this->teach('Nico', '绿地店');

        $first = $this->postJson('/api/payroll/profiles/prefill', [])->assertOk()->json('data');
        $this->assertSame(2, $first['created']);
        $rowsBefore = PayrollProfile::orderBy('id')->get(['id', 'venue', 'user_id', 'note'])
            ->map(fn ($p) => [$p->id, $p->venue, $p->user_id === null ? null : (int) $p->user_id, (string) $p->note])->all();
        $mapBefore = $this->bindingSnapshot('Nico');

        $second = $this->postJson('/api/payroll/profiles/prefill', [])->assertOk()->json('data');

        $this->assertSame(0, $second['created'], '第二次不得再建行');
        $rowsAfter = PayrollProfile::orderBy('id')->get(['id', 'venue', 'user_id', 'note'])
            ->map(fn ($p) => [$p->id, $p->venue, $p->user_id === null ? null : (int) $p->user_id, (string) $p->note])->all();

        $this->assertSame($rowsBefore, $rowsAfter, '第二次不得改动既有行（含 user_id 与 note）');
        $this->assertSame(2, PayrollProfile::count());
        $this->assertSame($mapBefore, $this->bindingSnapshot('Nico'), '两次调用后 user_id 集合必须完全一致');
        $this->assertSame((int) $u->id, $this->bindingSnapshot('Nico')['绿地店']);
        $this->assertNoDuplicateUserId('重复预填');
    }

    /**
     * 🔴 storeProfile（单条新建）的第二条门店行不得 500。
     *
     * 用户手建跨店老师的第二行时走的是同一个缺陷（逐字相同的绑定块）：
     * 修复前第二条行写同一个 user_id ⇒ 1062 ⇒ 500。
     */
    public function test_store_profile_second_store_row_does_not_500_and_explains_unbound(): void
    {
        Sanctum::actingAs($this->super());
        $u = $this->account('Nico', '绿地店');

        $first = $this->postJson('/api/payroll/profiles', ['name' => 'Nico', 'venue' => '绿地店'])
            ->assertOk()->json('data');
        $this->assertSame((int) $u->id, (int) $first['profile']['userId'], '第一行按同名唯一绑定');
        $this->assertNull($first['binding']['reason'] ?? null);

        // 修复前：这里 500（Duplicate entry for key 'payroll_profiles_user_id_unique'）
        $second = $this->postJson('/api/payroll/profiles', ['name' => 'Nico', 'venue' => '东部店'])
            ->assertOk()->json('data');

        $this->assertNull($second['profile']['userId'], '第二行不得抢同一个账号');
        $this->assertNotEmpty($second['binding']['reason'] ?? null, '未绑定原因必须在返回体里可见');
        $this->assertStringContainsString('账号绑定', (string) $second['profile']['note'], '未绑定原因必须同时写进 note');
        $this->assertSame(['东部店' => null, '绿地店' => (int) $u->id], $this->bindingSnapshot('Nico'));
        $this->assertNoDuplicateUserId('storeProfile 第二条门店行');
    }

    /**
     * 建档（storeProfile）与预填（prefill）必须给出**同一条规则**的结论：
     * 同一门店只有账号所在店的那一行拿到 user_id，另一行留空并给出原因。
     *
     * 两条路径各建两行，最终 `venue => user_id` 必须一致 —— 若哪条路径偷偷复制了一份
     * 绑定逻辑（本任务要消灭的「第三份复制」），这里就会分叉。
     */
    public function test_store_profile_and_prefill_agree_on_binding(): void
    {
        Sanctum::actingAs($this->super());
        $this->account('老店老师', '绿地店');

        foreach (['绿地店', '东部店'] as $venue) {
            $this->postJson('/api/payroll/profiles', ['name' => '老店老师', 'venue' => $venue])->assertOk();
        }
        $viaStore = $this->bindingSnapshot('老店老师');

        // 预填路径：换一个同名的人，用课次制造两条候选
        PayrollProfile::query()->delete();
        KyBooking::query()->delete();
        $this->account('新店老师', '绿地店');
        $this->teach('新店老师', '绿地店');
        $this->teach('新店老师', '东部店');
        $this->postJson('/api/payroll/profiles/prefill', [])->assertOk();
        $viaPrefill = $this->bindingSnapshot('新店老师');

        $this->assertSame(array_keys($viaStore), array_keys($viaPrefill));
        $this->assertSame(
            array_map(fn ($v) => $v === null ? null : 'bound', $viaStore),
            array_map(fn ($v) => $v === null ? null : 'bound', $viaPrefill),
            '建档与预填必须得到相同的绑定结论（同一处实现）'
        );
        $this->assertSame(['东部店' => null, '绿地店' => 'bound'], array_map(fn ($v) => $v === null ? null : 'bound', $viaStore));
        $this->assertNoDuplicateUserId('两条路径');
    }

    /**
     * 🔴 人员主档 seeder 的同源缺陷：**同一姓名对应多条主档行**时，
     * 修复前第二行会写同一个 user_id ⇒ 1062 ⇒ 整个 db:seed 事务回滚。
     *
     * 用合成的最小主档（脱敏夹具，不含任何真实 PII）构造这个形态。
     */
    public function test_seeder_does_not_collide_when_one_name_has_multiple_rows(): void
    {
        $u = $this->account('双店老师', '绿地店');
        $path = $this->makeMasterFixture([
            ['人员编号', '真实姓名', '所属门店', '岗位', '人员状态'],
            ['YM-T-001', '双店老师', '绿地店', '全职老师', '有效'],
            ['YM-T-002', '双店老师', '东部店', '全职老师', '有效'],
            ['YM-T-003', '独店老师', '东部店', '全职老师', '有效'],
        ]);

        try {
            $this->seedWithMaster($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(3, PayrollProfile::count(), '两行 + 一行都要建出来');
        $this->assertSame(['东部店' => null, '绿地店' => (int) $u->id], $this->bindingSnapshot('双店老师'));
        $this->assertNoDuplicateUserId('seeder 同名多行');

        $unbound = PayrollProfile::where('name', '双店老师')->whereNull('user_id')->first();
        $this->assertNotNull($unbound);
        $this->assertStringContainsString('账号绑定', (string) $unbound->note, 'seeder 的未绑定原因也必须落在 note 里');

        // 没有同名账号的人照旧留空，且不写噪声原因
        $solo = PayrollProfile::where('name', '独店老师')->firstOrFail();
        $this->assertNull($solo->user_id);
        $this->assertSame('', (string) $solo->note, '没有同名账号不是异常，不该往 note 里塞原因');
    }

    /**
     * 主档 seeder 重跑必须幂等：同名多行的人第二次不得新增行、不得改绑、不得报错。
     *
     * `db:seed` 在发版流程里会被重复执行（本仓库的升级说明就要求它），
     * 第一版修复若把「同名唯一就绑」改成「每行都抢账号」，重跑就会 1062 —— 这里钉死。
     */
    public function test_seeder_rerun_is_idempotent_for_multi_row_names(): void
    {
        $u = $this->account('双店老师', '绿地店');
        $path = $this->makeMasterFixture([
            ['人员编号', '真实姓名', '所属门店', '岗位', '人员状态'],
            ['YM-T-001', '双店老师', '绿地店', '全职老师', '有效'],
            ['YM-T-002', '双店老师', '东部店', '全职老师', '有效'],
        ]);

        try {
            $this->seedWithMaster($path);
            $first = PayrollProfile::orderBy('id')->get(['id', 'venue', 'user_id', 'note'])
                ->map(fn ($p) => [$p->id, $p->venue, $p->user_id === null ? null : (int) $p->user_id, (string) $p->note])->all();

            $this->seedWithMaster($path); // 重跑
            $second = PayrollProfile::orderBy('id')->get(['id', 'venue', 'user_id', 'note'])
                ->map(fn ($p) => [$p->id, $p->venue, $p->user_id === null ? null : (int) $p->user_id, (string) $p->note])->all();
        } finally {
            @unlink($path);
        }

        $this->assertCount(2, $first);
        $this->assertSame($first, $second, '重跑 seeder 不得新增行或改动既有绑定/备注');
        $this->assertSame(['东部店' => null, '绿地店' => (int) $u->id], $this->bindingSnapshot('双店老师'));
        $this->assertNoDuplicateUserId('seeder 重跑');
    }

    /**
     * 人工按原因改绑之后，重跑 seeder / 重新预填**不得把人工的决定改回去**。
     *
     * 未绑定的原因文字就是「请人工确认应绑哪一行」；人工确认完（把账号挂到另一行）
     * 若被下一次导入静默改回，就等于系统在背后替人改账 —— 这条钉死「已绑定的行原样保留」。
     */
    public function test_manual_rebinding_survives_seeder_rerun(): void
    {
        $u = $this->account('双店老师', '绿地店');
        $path = $this->makeMasterFixture([
            ['人员编号', '真实姓名', '所属门店', '岗位', '人员状态'],
            ['YM-T-001', '双店老师', '绿地店', '全职老师', '有效'],
            ['YM-T-002', '双店老师', '东部店', '全职老师', '有效'],
        ]);

        try {
            $this->seedWithMaster($path);
            // 规则默认绑「绿地店」（账号门店）那行 —— 现在人工改判到「东部店」行
            PayrollProfile::where('name', '双店老师')->update(['user_id' => null]);
            $east = PayrollProfile::where('name', '双店老师')->where('venue', '东部店')->firstOrFail();
            $east->user_id = (int) $u->id;
            $east->save();

            $this->seedWithMaster($path); // 重跑
        } finally {
            @unlink($path);
        }

        $this->assertSame(
            ['东部店' => (int) $u->id, '绿地店' => null],
            $this->bindingSnapshot('双店老师'),
            '重跑 seeder 不得把人工改过的绑定改回去'
        );
        $this->assertNoDuplicateUserId('人工改绑后重跑 seeder');
    }

    /** 生成最小人员主档 xlsx（脱敏夹具，列名与 seeder 期望一致） */
    private function makeMasterFixture(array $rows): string
    {
        require_once __DIR__.'/support/mkxlsx.php';
        $path = tempnam(sys_get_temp_dir(), 'master').'.xlsx';
        mkXlsx($path, '人员主档', $rows);

        return $path;
    }

    /** 以指定主档跑一次 PayrollProfileSeeder，跑完把环境变量复原（避免污染同进程的其他测试） */
    private function seedWithMaster(string $path): void
    {
        $before = getenv('PAYROLL_MASTER_XLSX');
        putenv('PAYROLL_MASTER_XLSX='.$path);
        $_ENV['PAYROLL_MASTER_XLSX'] = $path;
        $_SERVER['PAYROLL_MASTER_XLSX'] = $path;

        try {
            $this->seed(PayrollProfileSeeder::class);
        } finally {
            if ($before === false) {
                putenv('PAYROLL_MASTER_XLSX');
                unset($_ENV['PAYROLL_MASTER_XLSX'], $_SERVER['PAYROLL_MASTER_XLSX']);
            } else {
                putenv('PAYROLL_MASTER_XLSX='.$before);
                $_ENV['PAYROLL_MASTER_XLSX'] = $before;
                $_SERVER['PAYROLL_MASTER_XLSX'] = $before;
            }
        }
    }
}
