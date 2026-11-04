<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * scopeCustomersForUser 的兜底口径回归测试。
 *
 * 背景：该函数末尾原先直接 `return $query;`，于是任何一个角色都识别不出来的账号
 * （roles 漏写、role 是历史/错误角色码）会**静默拿到双店全量会员**。姊妹函数
 * scopeLeadsForUser 早已用 `whereRaw('1 = 0')` 收口，这条测试把会员侧钉在同一口径上。
 *
 * 覆盖四件事：
 *  1. 无匹配角色的账号：列表与聚合接口都拿不到任何会员（不再是全量）；
 *  2. 无匹配角色的账号：跨店数据也拿不到（兜底不是「只放宽门店」）；
 *  3. 历史账号 roles 为空时仍按 role 单值收窄（向后兼容）；
 *  4. 五个已知角色的可见范围与修复前逐条一致（改动只动兜底路径）。
 * 另含自助注册写入 roles 列的回归（register() 原先只写 role 单值）。
 */class CustomerScopeFallbackTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- 兜底：空集

    /**
     * 无匹配角色的账号不得通过 /api/customers 拿到全量会员。
     *
     * `R_LEGACY` 是「本地库里出现过、但代码已不认识」的角色码形态（角色改名/下架，
     * 或有人直接改库写进去）。这类账号仍然能登录，所以它的可见范围必须由兜底口径决定。
     */
    public function test_unknown_role_user_does_not_see_all_customers_via_list(): void
    {
        $this->customer('绿地会员', '绿地店', '绿地顾问');
        $this->customer('东部会员', '东部店', '东部顾问');

        $legacy = $this->user('unknown-role', '历史账号', 'R_LEGACY', '绿地店');
        Sanctum::actingAs($legacy);

        $json = $this->getJson('/api/customers')->assertOk()->json('data');

        $this->assertSame([], collect($json['records'])->pluck('name')->all(), '无匹配角色的账号不该看到任何会员');
        $this->assertSame(0, $json['total']);
    }

    /**
     * roles 列有值但同样一个都识别不出来时，同样走最小权限兜底。
     *
     * 这里 role 故意写成 R_MANAGER：`userRoles()` 在 roles 非空时以 roles 为准，
     * 所以这条同时钉住了「roles 优先于 role」——否则店长兜底会让这个账号看到本店全部。
     */
    public function test_unrecognized_roles_column_is_denied_and_takes_precedence_over_role(): void
    {
        $this->customer('绿地会员', '绿地店', '绿地顾问');
        $this->customer('东部会员', '东部店', '东部顾问');

        $legacy = $this->user('unknown-role-column', '角色列异常账号', 'R_MANAGER', '绿地店');
        $legacy->forceFill(['roles' => ['R_LEGACY']])->save();

        $this->assertSame(['R_LEGACY'], userRoles($legacy), 'roles 非空时应以 roles 为准，不回退 role');

        Sanctum::actingAs($legacy);

        $json = $this->getJson('/api/customers')->assertOk()->json('data');
        $this->assertSame(0, $json['total'], 'roles 一个都识别不出来时不该拿到本店全部会员');
    }

    /**
     * 无匹配角色的账号不得通过依赖该 scope 的聚合接口拿到跨店数据。
     *
     * /analytics/summary 的 totalCustomers 直接来自 scopeCustomersForUser，
     * 修复前它会如实报出双店全部会员数（数字本身就是一次名单泄露的口径确认）。
     */
    public function test_unknown_role_user_does_not_see_all_customers_via_scoped_aggregates(): void
    {
        $this->customer('绿地会员', '绿地店', '绿地顾问');
        $this->customer('东部会员', '东部店', '东部顾问');

        $legacy = $this->user('unknown-role-aggregate', '历史账号聚合', 'R_LEGACY', '绿地店');
        Sanctum::actingAs($legacy);

        $data = $this->getJson('/api/analytics/summary')->assertOk()->json('data');

        $this->assertSame(0, $data['totalCustomers']);
        $this->assertSame(0, $data['totalMembers']);
    }

    /** 兜底是「空集」而不是「按 venues 放宽」：显式带门店参数也查不到别人家的会员。 */
    public function test_unknown_role_user_cannot_widen_scope_with_query_params(): void
    {
        $this->customer('绿地会员', '绿地店', '绿地顾问');
        $this->customer('东部会员', '东部店', '东部顾问');

        $legacy = $this->user('unknown-role-params', '历史账号参数', 'R_LEGACY', '绿地店');
        Sanctum::actingAs($legacy);

        foreach (['绿地店', '东部店'] as $venue) {
            $json = $this->getJson('/api/customers?venue='.urlencode($venue))->assertOk()->json('data');
            $this->assertSame(0, $json['total'], "{$venue} 不该对无匹配角色的账号可见");
        }
    }

    // ------------------------------------------------- 向后兼容：roles 为空回退单值

    /**
     * 历史账号（迁移回填前只有 role 单值）必须保持修复前的可见范围。
     *
     * 服务老师口径：本店 + 本人名下（consultant/owner 的 id 或姓名任一命中），
     * 跨店即使挂着本人的名字也不可见。
     */
    public function test_empty_roles_falls_back_to_single_role_column(): void
    {
        $own = $this->customer('本店本人会员', '绿地店', '绿地顾问');
        $this->customer('本店同事会员', '绿地店', '其他顾问');
        $cross = $this->customer('跨店本人会员', '东部店', '绿地顾问');

        $legacy = $this->user('legacy-service', '绿地顾问', 'R_SERVICE', '绿地店');
        $legacy->forceFill(['roles' => null])->save();

        $this->assertSame(['R_SERVICE'], userRoles($legacy), 'roles 为空时应回退到 role 单值');

        Sanctum::actingAs($legacy);

        $json = $this->getJson('/api/customers')->assertOk()->json('data');
        $this->assertSame(['本店本人会员'], collect($json['records'])->pluck('name')->all());
        $this->assertSame(1, $json['total']);

        // 单条访问同样按 role 单值收窄：本店本人会员可见、跨店同名会员仍 403
        // （canAccessCustomer 的判定也不因 roles 为空而放宽）
        $this->getJson("/api/customers/{$own->id}")->assertOk();
        $this->getJson("/api/customers/{$cross->id}")->assertForbidden();
    }

    // ------------------------------------------- 既有角色语义：逐条钉住修复前口径

    /**
     * 五个已知角色的可见范围与修复前完全一致（本次只改兜底路径）。
     *
     * 数据：绿地甲（绿地店/绿地顾问）、绿地乙（绿地店/其他顾问/私教学员）、
     *      东部丙（东部店/绿地顾问）、东部丁（东部店/东部顾问/P5）。
     */
    public function test_known_role_scopes_are_unchanged(): void
    {
        $greenA = $this->customer('绿地甲', '绿地店', '绿地顾问', 'P0');
        $greenB = $this->customer('绿地乙', '绿地店', '其他顾问', 'P1', 'ky:V1:M2');
        $this->customer('东部丙', '东部店', '绿地顾问', 'P0');
        $this->customer('东部丁', '东部店', '东部顾问', 'P5');

        // 超管：不加条件（双店）
        $super = $this->user('scope-super', '超管', 'R_SUPER', null, ['绿地店', '东部店']);
        $this->assertEqualsCanonicalizing(['绿地甲', '绿地乙', '东部丙', '东部丁'], $this->sortedVisible($super));

        // 店长：本店全部
        $manager = $this->user('scope-manager', '绿地店长', 'R_MANAGER', '绿地店');
        $this->assertEqualsCanonicalizing(['绿地甲', '绿地乙'], $this->sortedVisible($manager));

        // 服务老师：本店 + 本人名下（跨店的同名会员不可见）
        $service = $this->user('scope-service', '绿地顾问', 'R_SERVICE', '绿地店');
        $this->assertEqualsCanonicalizing(['绿地甲'], $this->sortedVisible($service));

        // 授课老师：本店 + 本人名下 ∪ 私教课学员
        $teacher = $this->user('scope-teacher', '绿地老师', 'R_TEACHER', '绿地店');
        KyBooking::create([
            'source_key' => 'V1:M2', 'venue' => '绿地店', 'booking_type' => '私教',
            'member_id' => 'M2', 'member_name' => '绿地乙', 'phone' => '',
            'start_at' => now()->subDay(), 'course_name' => '私教', 'status' => 'signed',
            'course_kind' => 'private', 'teacher_name' => '绿地老师', 'teacher_user_id' => $teacher->id,
        ]);
        $this->assertEqualsCanonicalizing(['绿地乙'], $this->sortedVisible($teacher));

        // 授课老师：私教课学员靠手机号兜底命中（外键缺失时仍认得出本人学员）
        Customer::whereKey($greenA->id)->update(['phone' => '13800000009']);
        KyBooking::create([
            'source_key' => 'V1:M1', 'venue' => '绿地店', 'booking_type' => '私教',
            'member_id' => 'M1', 'member_name' => '绿地甲', 'phone' => '13800000009',
            'start_at' => now()->subDay(), 'course_name' => '私教', 'status' => 'signed',
            'course_kind' => 'private', 'teacher_name' => '绿地老师', 'teacher_user_id' => $teacher->id,
        ]);
        // privateStudentKeys 把结果挂在 User 实例上做**请求内**缓存，这里换一个实例，
        // 等价于下一次请求（用同一个实例会读到上一次的空学员集，测的就不是本函数了）。
        $teacher = User::findOrFail($teacher->id);
        $this->assertEqualsCanonicalizing(['绿地甲', '绿地乙'], $this->sortedVisible($teacher));

        // 新媒体：P5（跨店，不锁门店）
        $media = $this->user('scope-media', '新媒体', 'R_MEDIA', null, ['绿地店', '东部店']);
        $this->assertEqualsCanonicalizing(['东部丁'], $this->sortedVisible($media));

        // 多角色叠加：服务老师 + 店长 = 本店全部
        $both = $this->user('scope-both', '绿地顾问双角色', 'R_MANAGER', '绿地店');
        $both->forceFill(['roles' => ['R_SERVICE', 'R_MANAGER']])->save();
        $this->assertEqualsCanonicalizing(['绿地甲', '绿地乙'], $this->sortedVisible($both));

        // id 与姓名并集口径不变：名下会员靠 consultant_user_id 命中
        $byId = $this->customer('按id归属会员', '绿地店', '');
        Customer::whereKey($byId->id)->update(['consultant_user_id' => $service->id]);
        $this->assertEqualsCanonicalizing(['绿地甲', '按id归属会员'], $this->sortedVisible($service));

        // 上述会员仍在超管可见范围内（确认数据本身存在，空集断言不是因为没数据）
        $this->assertContains($greenA->name, $this->sortedVisible($super));
        $this->assertContains($greenB->name, $this->sortedVisible($super));
    }

    /** 仅给 role 单值（roles 留空）的历史账号，五个角色的可见范围同样不变。 */
    public function test_known_role_scopes_are_unchanged_for_legacy_role_only_accounts(): void
    {
        $this->customer('绿地甲', '绿地店', '绿地顾问', 'P0');
        $this->customer('绿地乙', '绿地店', '其他顾问', 'P1');
        $this->customer('东部丙', '东部店', '绿地顾问', 'P0');
        $this->customer('东部丁', '东部店', '东部顾问', 'P5');

        $cases = [
            ['scope-legacy-super', '超管', 'R_SUPER', null, ['绿地甲', '绿地乙', '东部丙', '东部丁']],
            ['scope-legacy-manager', '绿地店长', 'R_MANAGER', '绿地店', ['绿地甲', '绿地乙']],
            ['scope-legacy-service', '绿地顾问', 'R_SERVICE', '绿地店', ['绿地甲']],
            ['scope-legacy-teacher', '绿地老师', 'R_TEACHER', '绿地店', []],
            ['scope-legacy-media', '新媒体', 'R_MEDIA', null, ['东部丁']],
        ];

        foreach ($cases as [$username, $name, $role, $venue, $expected]) {
            $legacy = $this->user($username, $name, $role, $venue, $venue ? [$venue] : ['绿地店', '东部店']);
            $legacy->forceFill(['roles' => null])->save();

            $this->assertSame([$role], userRoles($legacy), "{$username} 的 roles 为空时应回退到 role 单值");
            $this->assertEqualsCanonicalizing($expected, $this->sortedVisible($legacy), "{$username}（{$role}）的可见范围变了");
        }
    }

    // ------------------------------------------------------------- 注册写入 roles

    // ------------------------------------------------ 未分配归属：只给管理层与新媒体

    /**
     * 未分配的会员（顾问与负责人都空 / 占位）**只对超管、店长、新媒体可见**。
     *
     * 用户原话（2026-11）：「所有没有标记会籍顾问、没有分配服务老师的客户，都不应该出现在
     * 超管店长新媒体以外任何人的会员管理或留资管理中」。这条把口径钉在 scope 上：
     *  - 服务老师 / 授课老师：只看得到有明确主人的行（姓名或 `*_user_id` 任一命中本人）；
     *  - 店长：本店全部（含未分配）—— 它是分配方，看不到就没法分配；
     *  - 超管：双店全部。
     * 另外钉住「列表与单条同口径」：列表看不到的未分配会员，直达 URL 不能看也不能改。
     */
    public function test_unassigned_customers_are_invisible_to_service_and_teacher_but_visible_to_manager_and_super(): void
    {
        $service = $this->user('pool-service', '绿地顾问', 'R_SERVICE', '绿地店');
        $teacher = $this->user('pool-teacher', '绿地老师', 'R_TEACHER', '绿地店');

        $assignedToService = $this->customer('服务名下会员', '绿地店', '绿地顾问');
        $assignedByOwner = $this->customer('负责人归属会员', '绿地店', '');
        Customer::whereKey($assignedByOwner->id)->update(['owner' => '绿地顾问']);
        $assignedById = $this->customer('按id归属会员', '绿地店', '');
        Customer::whereKey($assignedById->id)->update(['consultant_user_id' => $service->id]);
        $this->customer('老师名下会员', '绿地店', '绿地老师');
        $blank = $this->customer('未分配空值会员', '绿地店', '');
        $this->customer('未分配占位会员', '绿地店', '未分配');
        $this->customer('东部未分配会员', '东部店', '');

        // 服务老师：本人名下（姓名或 id）∪ 有明确主人；未分配的看不到
        $this->assertEqualsCanonicalizing(
            ['服务名下会员', '负责人归属会员', '按id归属会员'],
            $this->sortedVisible($service)
        );
        // 授课老师：只看得到本人名下；未分配的同样看不到
        $this->assertEqualsCanonicalizing(['老师名下会员'], $this->sortedVisible($teacher));

        // 店长：本店全部（含未分配、含占位名）
        $manager = $this->user('pool-manager', '绿地店长', 'R_MANAGER', '绿地店');
        $this->assertEqualsCanonicalizing(
            ['服务名下会员', '负责人归属会员', '按id归属会员', '老师名下会员', '未分配空值会员', '未分配占位会员'],
            $this->sortedVisible($manager)
        );

        // 超管：双店全部
        $super = $this->user('pool-super', '超管', 'R_SUPER', null, ['绿地店', '东部店']);
        $this->assertContains('东部未分配会员', $this->sortedVisible($super));

        // 列表与单条同口径：未分配会员不能靠猜 URL 打开/改写
        Sanctum::actingAs($service);
        $this->getJson("/api/customers/{$blank->id}")->assertForbidden();
        $this->patchJson("/api/customers/{$blank->id}", ['consultant' => '绿地顾问'])->assertForbidden();
        $this->getJson("/api/customers/{$assignedToService->id}")->assertOk();

        Sanctum::actingAs($manager);
        $this->getJson("/api/customers/{$blank->id}")->assertOk();
        $this->patchJson("/api/customers/{$blank->id}", ['consultant' => '绿地顾问'])->assertOk();
        $this->assertDatabaseHas('customers', ['id' => $blank->id, 'consultant' => '绿地顾问']);
    }

    /**
     * 授课老师的「私教学员」通道**保留**，但只能放大到有主人的会员上。
     *
     * 事故（2026-11 真机）：「从授课老师角色进入小程序，发现有一些不是她名下的客户
     * 也出现在了客户管理清单里」—— 私教签到关系（privateStudentKeys）把从未录入
     * 顾问/负责人的同步会员带进了名单。现在这条通道只在「该会员已有主人」时生效，
     * 既保留老师的私教学员视图，又不会把无主会员发出去。
     */
    public function test_teacher_private_students_remain_visible_only_when_the_member_has_an_owner(): void
    {
        $teacher = $this->user('coach-pool', '绿地私教', 'R_TEACHER', '绿地店');

        $owned = $this->customer('私教已分配学员', '绿地店', '其他顾问', 'P1', 'ky:V1:M11');
        $unowned = $this->customer('私教未分配学员', '绿地店', '', 'P1', 'ky:V1:M12');
        Customer::whereKey($owned->id)->update(['phone' => '13800000101']);
        Customer::whereKey($unowned->id)->update(['phone' => '13800000102']);

        foreach ([['V1:M11', 'M11', '13800000101'], ['V1:M12', 'M12', '13800000102']] as [$key, $member, $phone]) {
            KyBooking::create([
                'source_key' => $key, 'venue' => '绿地店', 'booking_type' => '私教',
                'member_id' => $member, 'member_name' => '学员'.$member, 'phone' => $phone,
                'start_at' => now()->subDay(), 'course_name' => '私教', 'status' => 'signed',
                'course_kind' => 'private', 'teacher_name' => '绿地私教', 'teacher_user_id' => $teacher->id,
            ]);
        }

        // privateStudentKeys 挂在 User 实例上做请求内缓存：换一个实例等价于下一次请求
        $teacher = User::findOrFail($teacher->id);

        $this->assertEqualsCanonicalizing(['私教已分配学员'], $this->sortedVisible($teacher));

        Sanctum::actingAs($teacher);
        $this->getJson("/api/customers/{$owned->id}")->assertOk();
        $this->getJson("/api/customers/{$unowned->id}")->assertForbidden();
    }

    /**
     * 老师侧的**其它会员入口**也必须与列表同口径（t5 O5）。
     *
     * 列表被挡住但旁路没挡，是「列表看不到、直达 URL 能看」的经典残法：
     * 这里把 `renewal-evaluation`（GET/PUT）、`/customers/options`、`/customers/list-counts`
     * 三条路径钉在同一个 scope 上 —— 未分配的会员在这些出口同样不可见。
     */
    public function test_unassigned_member_is_blocked_on_teacher_side_entry_points(): void
    {
        $service = $this->user('entry-service', '绿地顾问', 'R_SERVICE', '绿地店');
        // 未分配但「卡项即将到期」的会员：若没有口径，他会出现在服务的待续课清单里
        $blank = Customer::create([
            'name' => '未分配待续会员', 'phone' => '13800000201', 'phone_tail' => '0201',
            'venue' => '绿地店', 'source' => 'KeepYoga', 'consultant' => '', 'owner' => '',
            'main_card' => '私教卡', 'remain_times' => 5, 'attend_m3' => 4,
            'expire_date' => now()->addDays(5)->toDateString(), 'layer' => 'P2', 'status' => '在籍',
        ]);
        $owned = $this->customer('本人名下会员', '绿地店', '绿地顾问');

        Sanctum::actingAs($service);

        // ① 续费评估：未分配会员 GET / PUT 都 403；本人名下的 GET 正常（对照，排除端点整体 403）
        $this->getJson("/api/customers/{$blank->id}/renewal-evaluation")->assertForbidden();
        $this->putJson("/api/customers/{$blank->id}/renewal-evaluation", [])->assertForbidden();
        $this->getJson("/api/customers/{$owned->id}/renewal-evaluation")->assertOk();

        // ② 会籍顾问下拉只回本人可见范围里的顾问名
        $consultants = $this->getJson('/api/customers/options')->assertOk()->json('data.consultants');
        $this->assertSame(['绿地顾问'], $consultants, '未分配会员不该贡献顾问名');

        // ③ 五清单徽标：未分配会员不进服务老师的任何清单
        $counts = $this->getJson('/api/customers/list-counts?type=member')->assertOk()->json('data.counts');
        $this->assertSame(0, $counts['待续课'], '未分配的待续会员不能计进服务的徽标');
        $this->assertSame(0, array_sum($counts), '未分配会员不进服务老师的任何清单');

        // 店长（分配方）仍能看到这条：证明数据本身在清单里，服务侧的 0 是收口而不是没数据
        Sanctum::actingAs($this->user('entry-manager', '绿地店长', 'R_MANAGER', '绿地店'));
        $managerCounts = $this->getJson('/api/customers/list-counts?type=member')->assertOk()->json('data.counts');
        $this->assertSame(1, $managerCounts['待续课']);
    }

    /** 自助注册必须把 roles 写成与 role 单值一致的单元素数组。 */
    public function test_register_writes_roles_column_in_sync_with_role(): void
    {
        config(['services.registration.enabled' => true, 'services.registration.code' => '']);

        $this->postJson('/api/auth/register', [
            'name' => '自助注册老师',
            'userName' => 'selfreg001',
            'password' => 'password123',
            'venue' => '绿地店',
        ])->assertOk();

        $user = User::where('username', 'selfreg001')->firstOrFail();

        $this->assertSame(['R_TEACHER'], $user->roles);
        $this->assertSame('R_TEACHER', $user->role);
        $this->assertSame([$user->role], $user->roles, 'roles 与 role 必须一致（roles 为主角色单元素数组）');
        $this->assertSame(['R_TEACHER'], $user->userInfo()['roles']);
    }

    // ---------------------------------------------------------------------- 工具

    /**
     * 直接调用被测 scope，返回可见会员名（不经过 HTTP，口径最纯）。
     * 断言一律用 assertEqualsCanonicalizing：可见范围是集合，顺序不该成为测试的隐含契约。
     */
    private function sortedVisible(User $user): array
    {
        return scopeCustomersForUser(Customer::query(), $user)->pluck('name')->all();
    }

    private function user(string $username, string $name, string $role, ?string $venue, ?array $venues = null): User
    {
        return User::factory()->create([
            'username' => $username,
            'name' => $name,
            'role' => $role,
            'venue' => $venue,
            'venues' => $venues ?? ($venue ? [$venue] : ['绿地店', '东部店']),
            'status' => '启用',
        ]);
    }

    private function customer(string $name, string $venue, string $consultant, string $layer = 'P0', ?string $externalId = null): Customer
    {
        return Customer::create([
            'name' => $name,
            'venue' => $venue,
            'consultant' => $consultant,
            'owner' => $consultant,
            'layer' => $layer,
            'external_id' => $externalId,
        ]);
    }
}
