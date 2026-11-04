<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\StaffAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 人员身份归一（StaffDirectory）。
 *
 * 背景：同一个员工在三个地方可能被写成三个名字 —— 随心瑜登记名（花名/简称）、
 * 留资里手输的名字、工作台注册时的真名。归属列存的是姓名字符串，三个名字之间
 * 没有任何可计算的等值关系，只能由人确认一次，然后把「一个人还可能被写成什么」
 * 记在一处、并且**回填只走一条路**。
 *
 * 这里锁定七件事：
 *  1. 昵称也算名字（前端历史版本把 nickname 写进归属列，那批数据不能丢）；
 *  2. 昵称不与权威名争位（撞车时让位，绝不抢别人的本名）；
 *  3. 认领一个名字 → 历史行立刻带上 id → 本人立刻看到（不必刷新数据）；
 *  4. 认领不能抢别人的本名；
 *  5. 账号合并把数据连人带 id 一起搬过去，源账号停用；
 *  6. 全量修复能补齐覆盖率，且可重复执行（幂等）；
 *  7. 录入一个没人认领的归属名时当场提示（而不是等人来问"为什么我看不到"）。
 */
class StaffIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name, string $username, string $role = 'R_SERVICE', array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'name' => $name, 'username' => $username, 'role' => $role, 'roles' => [$role],
            'venue' => '绿地店', 'venues' => ['绿地店'], 'status' => '启用',
        ], $extra));
    }

    private function lead(string $serviceTeacher, string $customer = '客户甲'): Lead
    {
        return Lead::create([
            'lead_date' => now()->toDateString(), 'name' => $customer, 'phone' => '13800000001',
            'source' => '自然到店', 'venue' => '绿地店', 'service_teacher' => $serviceTeacher,
            'status' => '新留资', 'trial_cards' => [],
        ]);
    }

    private function member(string $consultant, string $customer = '会员甲'): Customer
    {
        return Customer::create([
            'name' => $customer, 'phone' => '13800000002', 'phone_tail' => '0002',
            'venue' => '绿地店', 'source' => '自然到店', 'main_card' => '—',
            'layer' => 'P3', 'status' => '跟进中', 'consultant' => $consultant,
        ]);
    }

    // ── ① 昵称 ────────────────────────────────────────────────────────────

    public function test_nickname_counts_as_an_owned_name(): void
    {
        // 前端历史版本用 userName（优先昵称）写归属列，后端只按 name 过滤 → 本人看不到。
        $u = $this->user('蒙澍南', 'shunan', extra: ['nickname' => '澍南']);
        $this->lead('澍南', '昵称归属的客户');

        $this->assertContains('澍南', staffNames($u));
        $this->assertSame((int) $u->id, staffUserId('澍南'));

        Sanctum::actingAs($u);
        $names = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))->pluck('name')->all();
        $this->assertContains('昵称归属的客户', $names);
    }

    public function test_nickname_never_steals_another_accounts_real_name(): void
    {
        // A 的昵称恰好是 B 的本名：本名是权威，昵称让位（否则 B 会丢数据）
        $a = $this->user('李教练', 'coach-a', 'R_TEACHER', ['nickname' => '王教练']);
        $b = $this->user('王教练', 'coach-b', 'R_TEACHER');

        $this->assertSame((int) $b->id, staffUserId('王教练'), '本名优先级高于别人的昵称');
        $this->assertNotContains('王教练', staffNames($a), '撞车的昵称不能算进 A 的名字集');
        $this->assertContains('王教练', staffNames($b));
    }

    // ── ② 认领 ────────────────────────────────────────────────────────────

    public function test_assign_claims_a_name_and_makes_history_visible(): void
    {
        $this->user('苏米', 'sumi');
        $lead = $this->lead('苏小米', '随心瑜名字写法的客户');
        $member = $this->member('苏小米');

        $this->assertNull($lead->fresh()->service_teacher_user_id);
        $this->assertNull($member->fresh()->consultant_user_id);

        $sumi = User::where('username', 'sumi')->firstOrFail();
        Sanctum::actingAs($this->user('超管', 'boss', 'R_SUPER'));
        $this->postJson('/api/accounts/staff-mapping/assign', [
            'name' => '苏小米',
            'userKey' => 'sumi',
        ])->assertOk()->assertJsonPath('data.user', (int) $sumi->id);

        // 认领后：历史行立刻带 id（不只是姓名能对上）
        $this->assertSame((int) $sumi->id, (int) $lead->fresh()->service_teacher_user_id);
        $this->assertSame((int) $sumi->id, (int) $member->fresh()->consultant_user_id);

        // 本人立刻能看到这条客资
        Sanctum::actingAs($sumi);
        $names = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))->pluck('name')->all();
        $this->assertContains('随心瑜名字写法的客户', $names);
    }

    public function test_assign_refuses_to_steal_another_accounts_real_name(): void
    {
        $this->user('王教练', 'coach-b');
        $this->user('李教练', 'coach-a');
        Sanctum::actingAs($this->user('超管', 'boss', 'R_SUPER'));

        $this->postJson('/api/accounts/staff-mapping/assign', [
            'name' => '王教练',
            'userKey' => 'coach-a',
        ])->assertStatus(422);
    }

    public function test_assign_is_idempotent(): void
    {
        $this->user('苏米', 'sumi');
        $this->lead('苏小米');
        Sanctum::actingAs($this->user('超管', 'boss', 'R_SUPER'));

        $this->postJson('/api/accounts/staff-mapping/assign', ['name' => '苏小米', 'userKey' => 'sumi'])->assertOk();
        $this->postJson('/api/accounts/staff-mapping/assign', ['name' => '苏小米', 'userKey' => 'sumi'])->assertOk();

        $this->assertSame(1, StaffAlias::where('alias', '苏小米')->count());
    }

    // ── ③ 合并 ────────────────────────────────────────────────────────────

    public function test_merge_moves_data_and_disables_source_account(): void
    {
        $keep = $this->user('蒙澍南', 'shunan');
        $dup = $this->user('澍南', 'shunan-old');
        $lead = $this->lead('澍南', '重复账号名下的客户');
        $member = $this->member('澍南');

        Sanctum::actingAs($this->user('超管', 'boss', 'R_SUPER'));
        $this->postJson('/api/accounts/staff-mapping/merge', [
            'into' => 'shunan',
            'from' => ['shunan-old'],
        ])->assertOk()->assertJsonPath('data.into', (int) $keep->id);

        $this->assertSame('蒙澍南', $lead->fresh()->service_teacher);
        $this->assertSame((int) $keep->id, (int) $lead->fresh()->service_teacher_user_id);
        $this->assertSame((int) $keep->id, (int) $member->fresh()->consultant_user_id);
        $this->assertSame('停用', $dup->fresh()->status);
        // 旧名保留为别名：历史数据里仍可能写着它
        $this->assertContains('澍南', staffNames($keep->fresh()));
    }

    // ── ④ 全量修复 ────────────────────────────────────────────────────────

    public function test_repair_backfills_ids_and_is_idempotent(): void
    {
        $u = $this->user('苏米', 'sumi');
        $this->lead('苏米', '历史行');
        $this->member('苏米', '历史会员');
        // 模拟历史遗留：姓名对得上，但 id 一直是空
        Lead::where('name', '历史行')->update(['service_teacher_user_id' => null]);
        Customer::where('name', '历史会员')->update(['consultant_user_id' => null]);

        Sanctum::actingAs($this->user('超管', 'boss', 'R_SUPER'));
        $first = $this->postJson('/api/accounts/staff-mapping/repair', [])->assertOk()->json('data.backfilled');
        $this->assertGreaterThanOrEqual(2, $first);

        // 再跑一次不应再补（已经补齐）
        $second = $this->postJson('/api/accounts/staff-mapping/repair', [])->assertOk()->json('data.backfilled');
        $this->assertSame(0, $second);

        $this->assertSame((int) $u->id, (int) Lead::where('name', '历史行')->value('service_teacher_user_id'));
        $this->assertSame((int) $u->id, (int) Customer::where('name', '历史会员')->value('consultant_user_id'));
    }

    public function test_coverage_reports_missing_ids(): void
    {
        $this->user('苏米', 'sumi');
        $this->lead('苏米', '有主的');
        $this->lead('查无此人', '悬空的');
        Lead::where('name', '有主的')->update(['service_teacher_user_id' => null]);

        Sanctum::actingAs($this->user('超管', 'boss', 'R_SUPER'));
        $coverage = collect($this->getJson('/api/accounts/staff-mapping')->assertOk()->json('data.coverage'))
            ->firstWhere('ref', 'leads.service_teacher');

        $this->assertSame(2, $coverage['total']);
        $this->assertSame(0, $coverage['withId']);
        $this->assertSame(2, $coverage['missing']);
    }

    // ── ⑤ 账号生命周期 ────────────────────────────────────────────────────

    public function test_new_account_backfills_same_name_history(): void
    {
        $this->lead('小花', '账号建立之前就有的客资');

        Sanctum::actingAs($this->user('超管', 'boss', 'R_SUPER'));
        $this->postJson('/api/accounts', [
            'userName' => 'xiaohua',
            'name' => '小花',
            'roles' => ['R_SERVICE'],
            'venues' => ['绿地店'],
            'password' => 'password123',
        ])->assertOk();

        $u = User::where('username', 'xiaohua')->firstOrFail();
        $this->assertSame((int) $u->id, (int) Lead::where('name', '账号建立之前就有的客资')->value('service_teacher_user_id'));
    }

    public function test_rename_keeps_history_visible_via_alias(): void
    {
        $u = $this->user('李小花', 'xiaohua');
        $lead = $this->lead('李小花', '改名前的客资');

        Sanctum::actingAs($this->user('超管', 'boss', 'R_SUPER'));
        $this->patchJson('/api/accounts/xiaohua', ['name' => '李华'])->assertOk();

        $u->refresh();
        $this->assertSame('李华', $u->name);
        $this->assertContains('李小花', staffNames($u), '旧名要留成别名，否则历史数据会"消失"');

        Sanctum::actingAs($u);
        $names = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))->pluck('name')->all();
        $this->assertContains('改名前的客资', $names);
        // 历史行的 id 也补上了（不再只靠姓名兜底）
        $this->assertNotNull($lead->fresh()->service_teacher_user_id);
    }

    // ── ⑥ 录入即提示 ──────────────────────────────────────────────────────

    public function test_lead_write_warns_when_owner_has_no_account(): void
    {
        // 用超管录入：服务老师只能把客资录到自己名下（既有准入规则），
        // 而这里要验证的正是"录给一个还没有账号的名字"。
        Sanctum::actingAs($this->user('超管', 'boss', 'R_SUPER'));

        $res = $this->postJson('/api/leads', [
            'leadDate' => now()->toDateString(),
            'name' => '新客甲',
            'phone' => '13800000009',
            'source' => '自然到店',
            'venue' => '绿地店',
            'serviceTeacher' => '还没开号的花名',
        ])->assertOk();

        $codes = collect($res->json('data.warnings'))->pluck('code')->all();
        $this->assertContains('owner_unmapped', $codes, '无人认领的归属名必须当场提示，而不是等本人来问');
    }

    public function test_lead_write_does_not_warn_for_known_owner(): void
    {
        $this->user('苏米', 'sumi');
        Sanctum::actingAs($this->user('超管', 'boss', 'R_SUPER'));

        $res = $this->postJson('/api/leads', [
            'leadDate' => now()->toDateString(),
            'name' => '新客乙',
            'phone' => '13800000010',
            'source' => '自然到店',
            'venue' => '绿地店',
            'serviceTeacher' => '苏米',
        ])->assertOk();

        $codes = collect($res->json('data.warnings'))->pluck('code')->all();
        $this->assertNotContains('owner_unmapped', $codes);
    }

    // ── ⑦ 下拉数据源 ──────────────────────────────────────────────────────

    public function test_staff_options_expose_canonical_names_for_pickers(): void
    {
        $u = $this->user('蒙澍南', 'shunan', 'R_TEACHER', ['nickname' => '澍南']);
        Sanctum::actingAs($u);

        $staff = collect($this->getJson('/api/staff/options')->assertOk()->json('data.staff'));
        $me = $staff->firstWhere('id', (int) $u->id);

        $this->assertNotNull($me);
        $this->assertSame('蒙澍南', $me['name'], '下拉提交的必须是规范名（归属列存的就是它）');
        $this->assertContains('澍南', $me['aliases']);
        $this->assertTrue($me['self']);
    }

    // ── ⑧ 别名认领 ≠ 放开未分配 ──────────────────────────────────────────

    /**
     * 认领别名后本人按姓名就能看到归属数据；但**未分配的数据不会跟着一起放开**。
     *
     * 两件事共用 `staffNames()`，改可见范围时最容易被一并放宽：把「本人名下」误写成
     * 「本人认识的任何名字」，或者把未分配行当成「谁都能接」的池子。2026-11 口径已经明确
     * 未分配客资/会员只对超管、店长、新媒体可见（`scopeLeadsForUser` / `scopeCustomersForUser`），
     * 这条把边界锁在两个入口上：可见的只有别名名下那条，单条 URL 同样不给。
     */
    public function test_alias_owner_unlocks_scope_but_unassigned_data_stays_hidden(): void
    {
        $sumi = $this->user('苏米', 'sumi');
        $this->lead('苏小米', '别名名下的客户');
        $unassignedLead = $this->lead('', '未分配的客户');
        $claimedMember = $this->member('苏小米', '别名名下的会员');
        $unassignedMember = $this->member('', '未分配的会员');

        Sanctum::actingAs($this->user('超管', 'boss', 'R_SUPER'));
        $this->postJson('/api/accounts/staff-mapping/assign', ['name' => '苏小米', 'userKey' => 'sumi'])->assertOk();

        $sumi = User::findOrFail($sumi->id);
        $this->assertContains('苏小米', staffNames($sumi), '认领后别名必须算进本人的名字集');
        Sanctum::actingAs($sumi);

        $leadNames = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))->pluck('name')->all();
        $this->assertContains('别名名下的客户', $leadNames);
        $this->assertNotContains('未分配的客户', $leadNames);

        $memberNames = collect($this->getJson('/api/customers?type=member')->assertOk()->json('data.records'))->pluck('name')->all();
        $this->assertContains('别名名下的会员', $memberNames);
        $this->assertNotContains('未分配的会员', $memberNames);

        // 单条入口同口径（列表看不到的，直达 URL 也不能看/改）
        $this->getJson("/api/leads/{$unassignedLead->id}/history")->assertForbidden();
        $this->getJson("/api/customers/{$unassignedMember->id}")->assertForbidden();

        // 认领到别名的那条仍能正常打开：收口不能顺手把本人的数据也关掉
        $this->getJson("/api/customers/{$claimedMember->id}")->assertOk();
    }
}
