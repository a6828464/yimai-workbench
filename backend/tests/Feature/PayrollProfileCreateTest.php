<?php

namespace Tests\Feature;

use App\Models\KyBooking;
use App\Models\Lead;
use App\Models\PayrollProfile;
use App\Models\User;
use App\Services\PayrollNameResolver;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
