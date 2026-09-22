<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PayrollMonthlyInput;
use App\Models\PayrollProfile;
use App\Models\User;
use App\Support\PayrollRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 薪酬计算的权限闸门与档案读写（**逐端点**覆盖）。
 *
 * 用户诉求原话：「这个仅限超管可见」。前端隐藏菜单**不算**权限控制 ——
 * 后端每个端点必须自己 `requireSuper`，非超管一律 403。
 *
 * 本测试对规格 §7.7 权限矩阵里的 **11 个端点逐个断言**，任何一个漏了守卫都会红。
 */
class PayrollAccessTest extends TestCase
{
    use RefreshDatabase;

    /** 规格 §7.7 的 11 个端点：method => uri */
    private function endpoints(): array
    {
        return [
            ['GET', '/api/payroll/hours?month=2026-08'],
            ['GET', '/api/payroll/performance?month=2026-08'],
            ['POST', '/api/payroll/performance/preview'],
            ['POST', '/api/payroll/performance/commit'],
            ['GET', '/api/payroll/profiles'],
            ['PUT', '/api/payroll/profiles/1'],
            ['GET', '/api/payroll/roles'],
            ['GET', '/api/payroll/monthly-inputs?month=2026-08'],
            ['PUT', '/api/payroll/monthly-inputs'],
            ['POST', '/api/payroll/monthly-inputs/copy-from-previous'],
            ['GET', '/api/payroll/calculate?month=2026-08'],
        ];
    }

    private function user(string $role, string $name = ''): User
    {
        static $n = 0;
        $n++;

        return User::factory()->create([
            'name' => $name !== '' ? $name : "用户{$n}",
            'username' => 'payroll-'.$role.'-'.$n,
            'role' => $role, 'roles' => [$role],
            'venue' => '绿地店', 'venues' => ['绿地店'], 'status' => '启用',
        ]);
    }

    private function super(): User
    {
        return $this->user('R_SUPER', '超管');
    }

    /** 逐端点：未登录 401 */
    public function test_逐端点未登录返回401(): void
    {
        foreach ($this->endpoints() as [$method, $uri]) {
            $this->json($method, $uri)->assertStatus(401, "{$method} {$uri} 未登录应为 401");
        }
    }

    /** 逐端点：非超管 403 */
    public function test_逐端点非超管返回403(): void
    {
        foreach (['R_MANAGER', 'R_SERVICE', 'R_TEACHER', 'R_MEDIA'] as $role) {
            Sanctum::actingAs($this->user($role));
            foreach ($this->endpoints() as [$method, $uri]) {
                $res = $this->json($method, $uri);
                $res->assertStatus(403, "{$role} 访问 {$method} {$uri} 应为 403");
                $this->assertSame('仅超管可执行此操作', $res->json('message'));
            }
        }
    }

    /** 逐端点：超管不因权限被拦（可能因校验回 422，但绝不是 401/403） */
    public function test_逐端点超管不被权限拦(): void
    {
        Sanctum::actingAs($this->super());
        foreach ($this->endpoints() as [$method, $uri]) {
            $status = $this->json($method, $uri)->status();
            $this->assertNotContains($status, [401, 403], "超管访问 {$method} {$uri} 不应被权限拦（实得 {$status}）");
        }
    }

    /** 无角色的账号也必须 403（roles 漏写时的失败关闭） */
    public function test_无角色账号返回403(): void
    {
        $u = User::factory()->create([
            'name' => '无角色', 'username' => 'payroll-norole', 'role' => '', 'roles' => [],
            'venue' => '绿地店', 'venues' => ['绿地店'], 'status' => '启用',
        ]);
        Sanctum::actingAs($u);
        foreach ($this->endpoints() as [$method, $uri]) {
            $this->json($method, $uri)->assertStatus(403, "{$method} {$uri} 无角色应为 403");
        }
    }

    // ------------------------------------------------------------------
    // 身份标签枚举唯一下发点
    // ------------------------------------------------------------------

    /** GET /payroll/roles：11 个标签 + 每个标签的六项算法说明 */
    public function test_roles下发11个标签与算法说明(): void
    {
        Sanctum::actingAs($this->super());
        $data = $this->getJson('/api/payroll/roles')->assertOk()->json('data');

        $this->assertCount(11, $data['roles']);
        $this->assertSame(PayrollRoles::ROLES, array_column($data['roles'], 'value'));

        foreach ($data['roles'] as $role) {
            foreach (['base', 'performance', 'hourly', 'commission', 'baseReward', 'storeCommission'] as $key) {
                $this->assertArrayHasKey($key, $role['salaryRules'], "{$role['value']} 缺少 {$key} 说明");
                $this->assertNotEmpty($role['salaryRules'][$key]);
            }
        }

        // 关键口径必须写明，避免前端自己猜
        $byValue = collect($data['roles'])->keyBy('value');
        $this->assertStringContainsString('强制 0', $byValue['兼职老师']['salaryRules']['base']);
        $this->assertStringContainsString('固定 7%', $byValue['专职老师']['salaryRules']['commission']);
        $this->assertStringContainsString('不享', $byValue['专职老师']['salaryRules']['hourlyIncentive']);
        $this->assertStringContainsString('80/100/110/120', $byValue['全职老师']['salaryRules']['baseReward']);
        $this->assertStringContainsString('2%', $byValue['店长']['salaryRules']['storeCommission']);

        $this->assertSame(PayrollRoles::VENUES, $data['venues']);
        $this->assertSame(PayrollRoles::DUAL_BASE_SALARY_WHITELIST, $data['dualBaseSalaryWhitelist']);
    }

    // ------------------------------------------------------------------
    // 档案读写
    // ------------------------------------------------------------------

    public function test_档案列表(): void
    {
        Sanctum::actingAs($this->super());
        $p = PayrollProfile::create([
            'name' => '张情', 'venue' => '绿地店', 'role' => '全职老师',
            'base_salary' => 4000, 'performance' => 0, 'fee_private60' => 160,
            'fee_private45' => 0, 'fee_small' => 160, 'fee_group' => 160,
            'fee_enterprise' => 160, 'status' => '有效',
        ]);
        $p->aliases = ['芷晴']; $p->save();

        $data = $this->getJson('/api/payroll/profiles')->assertOk()->json('data');
        $row = collect($data['rows'])->firstWhere('name', '张情');

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(4000.0, $row['baseSalary'], 0.001);
        $this->assertEqualsWithDelta(160.0, $row['feePrivate60'], 0.001);
        // 45 分钟单价为独立字段；配 0 时给出折算值与「是折算值」标记
        $this->assertEqualsWithDelta(0.0, $row['feePrivate45'], 0.001);
        $this->assertEqualsWithDelta(120.0, $row['feePrivate45Effective'], 0.001);
        $this->assertTrue($row['feePrivate45Derived']);
        $this->assertSame(['芷晴'], $row['aliases']);
    }

    public function test_档案按门店与状态过滤(): void
    {
        Sanctum::actingAs($this->super());
        foreach ([['张情', '绿地店', '全职老师'], ['王嘉欣', '东部店', '全职老师']] as [$n, $v, $r]) {
            PayrollProfile::create([
                'name' => $n, 'venue' => $v, 'role' => $r, 'base_salary' => 4000,
                'performance' => 0, 'fee_private60' => 160, 'fee_private45' => 0,
                'fee_small' => 160, 'fee_group' => 160, 'fee_enterprise' => 160, 'status' => '有效',
            ]);
        }

        $rows = $this->getJson('/api/payroll/profiles?venue='.rawurlencode('绿地店'))
            ->assertOk()->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertSame('张情', $rows[0]['name']);
    }

    /** 更新档案写审计留痕（含改动前后） */
    public function test_更新档案写审计(): void
    {
        Sanctum::actingAs($super = $this->super());
        $p = PayrollProfile::create([
            'name' => '张情', 'venue' => '绿地店', 'role' => '全职老师',
            'base_salary' => 4000, 'performance' => 0, 'fee_private60' => 160,
            'fee_private45' => 0, 'fee_small' => 160, 'fee_group' => 160,
            'fee_enterprise' => 160, 'status' => '有效',
        ]);

        $this->putJson("/api/payroll/profiles/{$p->id}", [
            'feePrivate60' => 200, 'baseSalary' => 4500,
        ])->assertOk();

        $log = AuditLog::where('module', '薪酬计算')->where('action', '更新薪酬档案')->first();
        $this->assertNotNull($log, '更新档案必须写审计');
        $this->assertSame((int) $super->id, (int) $log->operator_id);
        $this->assertSame('张情', $log->target_label);
        $this->assertStringContainsString('before', (string) $log->detail);
        $this->assertStringContainsString('after', (string) $log->detail);
        $this->assertStringContainsString('200', (string) $log->detail, '审计要能看到改成多少');

        $p->refresh();
        $this->assertEqualsWithDelta(200.0, (float) $p->fee_private60, 0.001);
        $this->assertEqualsWithDelta(4500.0, (float) $p->base_salary, 0.001);
    }

    /** 别名唯一：撞车时明确报错，不静默覆盖 */
    public function test_别名撞车时明确报错(): void
    {
        Sanctum::actingAs($this->super());
        $a = PayrollProfile::create([
            'name' => '张情', 'venue' => '绿地店', 'role' => '全职老师', 'base_salary' => 4000,
            'performance' => 0, 'fee_private60' => 160, 'fee_private45' => 0,
            'fee_small' => 160, 'fee_group' => 160, 'fee_enterprise' => 160, 'status' => '有效',
        ]);
        $b = PayrollProfile::create([
            'name' => '徐婷婷', 'venue' => '绿地店', 'role' => '兼职老师', 'base_salary' => 0,
            'performance' => 0, 'fee_private60' => 130, 'fee_private45' => 0,
            'fee_small' => 130, 'fee_group' => 130, 'fee_enterprise' => 130, 'status' => '有效',
        ]);
        $a->aliases = ['婷婷']; $a->save();

        $res = $this->putJson("/api/payroll/profiles/{$b->id}", ['aliases' => ['婷婷']])->assertStatus(422);
        $this->assertSame('AMBIGUOUS_NAME', $res->json('code'));
    }

    // ------------------------------------------------------------------
    // 月度输入
    // ------------------------------------------------------------------

    private function makeProfile(string $name = '张情', array $extra = []): PayrollProfile
    {
        return PayrollProfile::create(array_merge([
            'name' => $name, 'venue' => '绿地店', 'role' => '全职老师', 'base_salary' => 4000,
            'performance' => 0, 'fee_private60' => 160, 'fee_private45' => 0,
            'fee_small' => 160, 'fee_group' => 160, 'fee_enterprise' => 160, 'status' => '有效',
        ], $extra));
    }

    /** 未输入时：考勤按全勤、个税 0、社保 0，且都能标出「是默认值」 */
    public function test_未输入时按默认值并可区分(): void
    {
        Sanctum::actingAs($this->super());
        $this->makeProfile();

        $data = $this->getJson('/api/payroll/monthly-inputs?month=2026-08')->assertOk()->json('data');
        $row = $data['rows'][0];

        $this->assertTrue($row['attendanceIsDefault']);
        $this->assertTrue($row['taxIsDefault']);
        $this->assertEqualsWithDelta(0.0, $row['tax'], 0.001);
        $this->assertSame('inherit', $row['socialSecurityMode']);
        $this->assertEqualsWithDelta(0.0, $row['socialSecurity'], 0.001);
        $this->assertSame('无历史设置（按 0）', $row['socialSecurityLabel']);
    }

    /** 社保「固定沿用」：本月无操作时沿用最近一次有效设置 */
    public function test_社保固定沿用最近一次有效设置(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->makeProfile();

        // 6 月显式设 500；7、8 月都不操作
        PayrollMonthlyInput::create([
            'payroll_profile_id' => $p->id, 'venue' => '绿地店', 'month' => '2026-06',
            'social_security' => 500, 'social_security_mode' => 'set',
        ]);

        $row = collect($this->getJson('/api/payroll/monthly-inputs?month=2026-08')->json('data.rows'))->first();
        $this->assertSame('inherit', $row['socialSecurityMode']);
        $this->assertEqualsWithDelta(500.0, $row['socialSecurity'], 0.001, '跨月沿用，不是只看上月');
        $this->assertSame('2026-06', $row['socialSecurityInheritedFrom']);
        $this->assertSame('沿用 2026-06 的 ¥500.00', $row['socialSecurityLabel']);
    }

    /** 「本月显式设为 0」与「本月无操作」必须可区分 */
    public function test_显式设0与未操作可区分(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->makeProfile();
        PayrollMonthlyInput::create([
            'payroll_profile_id' => $p->id, 'venue' => '绿地店', 'month' => '2026-07',
            'social_security' => 600, 'social_security_mode' => 'set',
        ]);

        // 本月显式设 0
        $this->putJson('/api/payroll/monthly-inputs', [
            'month' => '2026-08',
            'rows' => [['profileId' => $p->id, 'socialSecurity' => 0, 'socialSecurityMode' => 'set']],
        ])->assertOk();

        $row = collect($this->getJson('/api/payroll/monthly-inputs?month=2026-08')->json('data.rows'))->first();
        $this->assertSame('set', $row['socialSecurityMode'], '显式设 0 必须是 set，不能退化成 inherit');
        $this->assertEqualsWithDelta(0.0, $row['socialSecurity'], 0.001);
        $this->assertSame('本月已设', $row['socialSecurityLabel']);
        $this->assertNull($row['socialSecurityInheritedFrom'], 'set 状态不应有沿用来源');

        // 另一个人本月无操作 → inherit，沿用 7 月的 600
        $q = $this->makeProfile('徐秀娟');
        PayrollMonthlyInput::create([
            'payroll_profile_id' => $q->id, 'venue' => '绿地店', 'month' => '2026-07',
            'social_security' => 600, 'social_security_mode' => 'set',
        ]);
        $row2 = collect($this->getJson('/api/payroll/monthly-inputs?month=2026-08')->json('data.rows'))
            ->firstWhere('name', '徐秀娟');
        $this->assertSame('inherit', $row2['socialSecurityMode']);
        $this->assertEqualsWithDelta(600.0, $row2['socialSecurity'], 0.001);
    }

    /** 🔴 `off` 必须打断继承链：停缴后不能把历史非零值沿回来 */
    public function test_off打断继承链(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->makeProfile();

        PayrollMonthlyInput::create([
            'payroll_profile_id' => $p->id, 'venue' => '绿地店', 'month' => '2026-06',
            'social_security' => 700, 'social_security_mode' => 'set',
        ]);
        // 7 月显式停缴
        PayrollMonthlyInput::create([
            'payroll_profile_id' => $p->id, 'venue' => '绿地店', 'month' => '2026-07',
            'social_security' => null, 'social_security_mode' => 'off',
        ]);

        $row = collect($this->getJson('/api/payroll/monthly-inputs?month=2026-08')->json('data.rows'))->first();
        $this->assertEqualsWithDelta(0.0, $row['socialSecurity'], 0.001, 'off 之后不得沿回 6 月的 700');
        $this->assertSame('本月不缴', $row['socialSecurityLabel']);

        // 计算响应里也应体现为 0
        $calc = $this->getJson('/api/payroll/calculate?month=2026-08')->assertOk()->json('data');
        $rowCalc = collect($calc['rows'])->firstWhere('name', '张情');
        $this->assertEqualsWithDelta(0.0, $rowCalc['socialSecurity'], 0.001);
    }

    /** 社保回退跨月但**不跨门店** */
    public function test_社保回退不跨门店(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->makeProfile('张情');
        PayrollMonthlyInput::create([
            'payroll_profile_id' => $p->id, 'venue' => '绿地店', 'month' => '2026-06',
            'social_security' => 800, 'social_security_mode' => 'set',
        ]);
        // 东部店无历史
        PayrollMonthlyInput::create([
            'payroll_profile_id' => $p->id, 'venue' => '东部店', 'month' => '2026-08',
            'social_security_mode' => 'inherit',
        ]);

        $rows = $this->getJson('/api/payroll/monthly-inputs?month=2026-08')->json('data.rows');
        $east = collect($rows)->firstWhere('venue', '东部店');
        $this->assertEqualsWithDelta(0.0, $east['socialSecurity'], 0.001, '社保按门店扣，不跨店沿用');
        $this->assertNull($east['socialSecurityInheritedFrom']);
    }

    /** `set` 模式缺金额 → 422 SOCIAL_SECURITY_MODE_INVALID */
    public function test_set模式缺金额被拦(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->makeProfile();

        $res = $this->putJson('/api/payroll/monthly-inputs', [
            'month' => '2026-08',
            'rows' => [['profileId' => $p->id, 'socialSecurityMode' => 'set']],
        ])->assertStatus(422);
        $this->assertSame('SOCIAL_SECURITY_MODE_INVALID', $res->json('code'));
    }

    /** 非法社保模式 → 422 */
    public function test_非法社保模式被拦(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->makeProfile();

        $this->putJson('/api/payroll/monthly-inputs', [
            'month' => '2026-08',
            'rows' => [['profileId' => $p->id, 'socialSecurityMode' => 'maybe', 'socialSecurity' => 100]],
        ])->assertStatus(422);
    }

    /** 更新月度输入写审计 */
    public function test_更新月度输入写审计(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->makeProfile();

        $this->putJson('/api/payroll/monthly-inputs', [
            'month' => '2026-08', 'venue' => '绿地店',
            'rows' => [['profileId' => $p->id, 'socialSecurity' => 557.76, 'socialSecurityMode' => 'set', 'tax' => 100]],
        ])->assertOk();

        $log = AuditLog::where('module', '薪酬计算')->where('action', '更新薪酬月度输入')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('张情', (string) $log->detail);
    }

    /** copy-from-previous：把上月显式值复制为本月 set */
    public function test_复制上月输入(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->makeProfile();
        PayrollMonthlyInput::create([
            'payroll_profile_id' => $p->id, 'venue' => '绿地店', 'month' => '2026-07',
            'social_security' => 557.76, 'social_security_mode' => 'set', 'attendance_days' => 27,
        ]);

        $res = $this->postJson('/api/payroll/monthly-inputs/copy-from-previous', [
            'month' => '2026-08', 'fields' => ['socialSecurity', 'attendanceDays'],
        ])->assertOk()->json('data');

        $this->assertSame('2026-07', $res['copiedFrom']);
        $this->assertSame(1, $res['copied']);

        $row = collect($this->getJson('/api/payroll/monthly-inputs?month=2026-08')->json('data.rows'))->first();
        $this->assertEqualsWithDelta(557.76, $row['socialSecurity'], 0.001);
        $this->assertSame('set', $row['socialSecurityMode'], '复制是本月的一次明确操作，应记为 set');
        $this->assertEqualsWithDelta(27.0, $row['attendanceDays'], 0.001);
    }

    /** 月度输入校验边界 */
    public function test_月度输入字段边界(): void
    {
        Sanctum::actingAs($this->super());
        $p = $this->makeProfile();

        // 应出勤天数上限 31
        $this->putJson('/api/payroll/monthly-inputs', [
            'month' => '2026-08', 'rows' => [['profileId' => $p->id, 'attendanceDays' => 32]],
        ])->assertStatus(422);
        // 请假小时上限 744
        $this->putJson('/api/payroll/monthly-inputs', [
            'month' => '2026-08', 'rows' => [['profileId' => $p->id, 'personalLeaveHours' => 745]],
        ])->assertStatus(422);
        // 负数被拒
        $this->putJson('/api/payroll/monthly-inputs', [
            'month' => '2026-08', 'rows' => [['profileId' => $p->id, 'tax' => -1]],
        ])->assertStatus(422);
    }

    /** 档案不存在 → 422 明确报错 */
    public function test_月度输入引用不存在的档案(): void
    {
        Sanctum::actingAs($this->super());
        $res = $this->putJson('/api/payroll/monthly-inputs', [
            'month' => '2026-08', 'rows' => [['profileId' => 999999]],
        ])->assertStatus(422);
        $this->assertSame('PROFILE_NOT_FOUND', $res->json('code'));
    }
}
