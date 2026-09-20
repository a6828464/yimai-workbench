<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 「角色不明」账号（roles 为空、role 也不是任何已知角色码）的越权收口回归测试。
 *
 * 与 CustomerScopeFallbackTest 互补：那边钉的是 scopeCustomersForUser 这条**共用 scope** 的兜底，
 * 这里是**不经过该 scope** 的两条独立路径（t10 用双快照差分查实的既存缺陷）：
 *
 *  1. GET /api/tasks —— TaskController 的 index/update 每个守卫都是「按角色授权」，
 *     四个角色分支对角色不明账号全不命中 → 查询上没有任何条件，直接返回双店全部任务
 *     （标题/客户姓名/负责人），且能 PATCH 改掉**任意门店他人**的任务字段（写入越权）。
 *     同一份数据在 /today/todo、/today/alerts 里各写了一遍收窄逻辑，
 *     「每个出口各写一遍」正是漏收口的根源。
 *  2. GET /api/leads/check —— 无状态、可按手机号枚举，能反查出**跨店他人会员**的
 *     姓名 + 门店 + 卡名，而同一时刻 /api/customers 已经是空集。本质是一台客户名单 oracle。
 *
 * 本文件同时钉住「既有五种角色与多角色叠加账号不受影响」，并留一条反向护栏
 * （正常角色的手机号查重必须照常工作），避免收口时把业务功能一起关掉。
 *
 * 兜底策略统一为**最小权限**：列表出口返回空集、写入口直接 403。理由见两处改动点的行内注释。
 */
class UnknownRoleIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** 角色码不在任何已知角色里（历史遗留/写错），账号还能正常登录。 */
    private const UNKNOWN_ROLE = 'R_LEGACY';

    // ------------------------------------------------------- /api/tasks 越权

    /**
     * 角色不明账号不得在 /api/tasks 看到任何他人任务（含跨店）。
     *
     * 数据故意做成「本店他人的 + 跨店他人的 + 本人的」三种，这样即使兜底策略换成
     * 「按门店收窄」也会被本用例抓住（那两条他人任务仍会漏出来）。
     *
     * 本人的那条**也**不返回：兜底取空集而非「按人放行」，理由见 TaskController::index 的注释
     * （角色不明 = 身份未确认，按人放行仍是在猜；且必须与 /api/customers 的空集结论一致）。
     * 代价是本人暂时看不到自己的任务 —— 会被立刻上报，把 roles/role 写对即恢复。
     */
    public function test_unknown_role_user_sees_no_tasks_at_all(): void
    {
        $user = $this->user('unknown-tasks', '角色不明账号', self::UNKNOWN_ROLE, '绿地店');

        $this->task('绿地他人的任务', '绿地店', '其他老师');
        $this->task('东部他人的任务', '东部店', '东部老师');
        $this->task('本人的任务', '绿地店', '角色不明账号', $user);

        Sanctum::actingAs($user);

        $data = $this->getJson('/api/tasks')->assertOk()->json('data');

        $this->assertSame([], collect($data['records'])->pluck('title')->all(), '角色不明账号不该看到任何任务');
        $this->assertSame(0, $data['total']);
    }

    /** 显式带 venue 参数也不能把角色不明账号的范围放宽（兜底不是「按门店放行」）。 */
    public function test_unknown_role_user_cannot_widen_tasks_with_venue_param(): void
    {
        $user = $this->user('unknown-tasks-venue', '角色不明账号带参', self::UNKNOWN_ROLE, '绿地店');
        $this->task('绿地他人的任务', '绿地店', '其他老师');
        $this->task('东部他人的任务', '东部店', '东部老师');

        Sanctum::actingAs($user);

        // venue=绿地店 命中 :35 的 abort_if 放行分支（不是超管但门店相同），随后必须仍无数据
        $data = $this->getJson('/api/tasks?venue='.urlencode('绿地店'))->assertOk()->json('data');

        $this->assertSame([], collect($data['records'])->pluck('title')->all());
        $this->assertSame(0, $data['total']);
    }

    // ------------------------------------------------ /api/leads/check oracle

    /**
     * 角色不明账号不得用 /api/leads/check 反查跨店他人会员的姓名/门店/卡名。
     *
     * 这是泄露的主体：接口无状态、手机号可枚举，每次请求返回姓名 + 门店 + 卡项，
     * 而同时刻 /api/customers 已是空集 —— 「列表拿不到但查重能拿到」本身就是越权。
     */
    public function test_unknown_role_user_cannot_probe_other_members_via_phone_check(): void
    {
        $user = $this->user('unknown-check', '角色不明账号查重', self::UNKNOWN_ROLE, '绿地店');
        $this->customer('东部他人会员', '东部店', '东部顾问', '13800000101', '东部私教年卡48次');
        $this->customer('绿地他人会员', '绿地店', '绿地顾问', '13800000102', '绿地小班年卡');

        Sanctum::actingAs($user);

        // 跨店他人会员：不得返回任何命中
        $cross = $this->getJson('/api/leads/check?phone=13800000101')->assertOk()->json('data');
        $this->assertSame([], $cross['matches'], '跨店他人会员不该被反查出来');
        $this->assertFalse($cross['exists']);

        // 本店他人会员：同样不得返回（角色不明账号连本店他人名单也不该有）
        $sameVenue = $this->getJson('/api/leads/check?phone=13800000102')->assertOk()->json('data');
        $this->assertSame([], $sameVenue['matches'], '本店他人会员也不该被反查出来');
        $this->assertFalse($sameVenue['exists']);

        // 与列表接口的口径一致（列表已是空集），两个出口不该互相矛盾
        $this->assertSame(0, $this->getJson('/api/customers')->assertOk()->json('data.total'));
    }

    /**
     * 角色不明账号连「本人名下」的会员也不返回 —— 与 /api/customers 的结论保持一致。
     *
     * 这是**有意**的选择，不是漏掉了本人那一档：角色不明意味着「这个账号是谁」未被确认，
     * 而本人判定走 staffOwnerFilter（id 或姓名/别名任一命中），此时按人放行仍是在猜。
     * 更硬的理由是一致性：同一时刻 /api/customers 对这类账号已是空集（t8），
     * 若查重侧却能把「本人的」捞回来，两个出口对同一账号又给出互相矛盾的结论。
     *
     * 已知代价（接受并记录）：该账号查重会返回 exists=false，于是新增留资时不会提示重复。
     * 这是数据质量问题、不是安全问题，且只影响配置错误的账号；本人看不到自己的数据会立刻
     * 被上报，把 roles/role 写对即恢复。反过来（放行）则是他人名单静默可见、可能长期无人发现。
     */
    public function test_unknown_role_user_sees_no_match_even_for_own_member(): void
    {
        $user = $this->user('unknown-check-own', '角色不明账号本人', self::UNKNOWN_ROLE, '绿地店');
        $own = $this->customer('本人名下会员', '绿地店', '角色不明账号本人', '13800000103', '本人私教卡');
        Customer::whereKey($own->id)->update(['consultant_user_id' => $user->id]);

        Sanctum::actingAs($user);

        $data = $this->getJson('/api/leads/check?phone=13800000103')->assertOk()->json('data');

        $this->assertSame([], $data['matches'], '角色不明账号一律不返回命中（含本人名下），与 /api/customers 空集一致');
        $this->assertFalse($data['exists']);
    }

    /**
     * 护栏：正常角色（服务老师）的手机号查重**照常命中本人名下会员**。
     *
     * 这条与上一条相反相成 —— 收口只作用在「角色不明」这条兜底路径上，绝不能把
     * /api/leads/check 的正常查重能力一起关掉（那会破坏「新增留资时提示手机号已存在」的功能）。
     * 本用例在修复前后都必须通过；若哪天有人图省事把 canSeePhoneMatch 改成恒 false，它会立刻红。
     */
    public function test_service_teacher_phone_check_still_matches_own_member(): void
    {
        $service = $this->user('check-own-service', '绿地顾问', 'R_SERVICE', '绿地店');
        $this->customer('本人名下会员', '绿地店', '绿地顾问', '13800000106', '本人私教卡');

        Sanctum::actingAs($service);

        $data = $this->getJson('/api/leads/check?phone=13800000106')->assertOk()->json('data');

        $this->assertCount(1, $data['matches'], '服务老师查询本人名下会员必须照常命中（查重功能不能被关掉）');
        $this->assertSame('本人名下会员', $data['matches'][0]['name']);
        $this->assertTrue($data['exists']);
    }

    /**
     * 角色不明账号不得用 /api/leads/check 探到他人**留资**（第二条泄露路径）。
     *
     * 留资走的是 canAccessLead 分支（kind=已有留资），与会员走的是两段代码，
     * 只修其中一段仍会漏，所以两条都钉住。
     */
    public function test_unknown_role_user_cannot_probe_other_users_leads(): void
    {
        $user = $this->user('unknown-check-lead', '角色不明账号留资', self::UNKNOWN_ROLE, '绿地店');
        $this->lead('东部他人留资', '13800000104', '东部店', '东部老师');
        $this->lead('绿地他人留资', '13800000105', '绿地店', '其他顾问');

        Sanctum::actingAs($user);

        foreach (['13800000104', '13800000105'] as $phone) {
            $data = $this->getJson("/api/leads/check?phone={$phone}")->assertOk()->json('data');
            $this->assertSame([], $data['matches'], "{$phone} 的他人留资不该被反查出来");
            $this->assertFalse($data['exists']);
        }
    }

    // ------------------------------------- 既有五种角色 + 多角色叠加：行为不变

    /**
     * 收口只作用于「角色不明」这条兜底路径，五种角色与多角色叠加的任务范围逐条不变。
     *
     * 期望值**逐个取自修复前的实测输出**（用 git show HEAD: 还原源文件后跑探针打印），
     * 不是照代码推出来的：任务侧的角色收窄是逐条 AND（多角色只会**更窄**），
     * 与会员/客资侧的并集口径相反。
     *
     * 数据：绿地甲任务（绿地店/其他老师）、东部乙任务（东部店/东部老师）、
     *      绿地未分配任务（绿地店/未分配）。
     */
    public function test_known_role_task_scopes_are_unchanged(): void
    {
        $this->task('绿地甲任务', '绿地店', '其他老师');
        $this->task('东部乙任务', '东部店', '东部老师');
        $this->task('绿地未分配任务', '绿地店', '未分配');

        $cases = [
            // 超管：不加条件（双店）
            ['task-scope-super', '超管', ['R_SUPER'], null, ['绿地甲任务', '东部乙任务', '绿地未分配任务']],
            // 店长：本店全部
            ['task-scope-manager', '绿地店长', ['R_MANAGER'], '绿地店', ['绿地甲任务', '绿地未分配任务']],
            // 服务老师：本店 + 本人名下 + 待认领池（本人此时无任务，只剩待认领）
            ['task-scope-service', '绿地顾问', ['R_SERVICE'], '绿地店', ['绿地未分配任务']],
            // 授课老师：本店 + 只处理派给本人的（本人无任务）
            ['task-scope-teacher', '绿地老师', ['R_TEACHER'], '绿地店', []],
            // 新媒体：只看派给本人的（跨店）
            ['task-scope-media', '新媒体', ['R_MEDIA'], null, []],
            // 多角色叠加：任务侧是 AND（更窄）——服务老师+店长 = 服务老师那档，不是「本店全部」
            ['task-scope-both', '绿地顾问双角色', ['R_SERVICE', 'R_MANAGER'], '绿地店', ['绿地未分配任务']],
            ['task-scope-mgr-teacher', '店长兼授课', ['R_MANAGER', 'R_TEACHER'], '绿地店', []],
            ['task-scope-svc-teacher', '顾问兼授课', ['R_SERVICE', 'R_TEACHER'], '绿地店', []],
        ];

        foreach ($cases as [$username, $name, $roles, $venue, $expected]) {
            $user = $this->user($username, $name, $roles[0], $venue);
            $user->forceFill(['roles' => $roles])->save();
            Sanctum::actingAs(User::findOrFail($user->id));

            $titles = collect($this->getJson('/api/tasks')->assertOk()->json('data.records'))->pluck('title')->all();
            $this->assertEqualsCanonicalizing($expected, $titles, "{$username}（".implode('+', $roles).'）的任务范围变了');
        }
    }

    /**
     * 角色不明账号同样不得**写**他人任务（index 之外的第二条路径）。
     *
     * 这条是修复过程中实测发现的：TaskController::update 的每个守卫都是「按角色授权」，
     * 角色不明时一个都不命中，于是这个账号能改任意门店任意人的任务字段
     * （实测 PATCH 他人任务返回 200 且标题真的被改掉）。同一文件同一个根因，一并收口。
     */
    public function test_unknown_role_user_cannot_write_other_users_tasks(): void
    {
        $user = $this->user('unknown-task-write', '角色不明账号写入', self::UNKNOWN_ROLE, '绿地店');
        $other = $this->task('东部他人任务', '东部店', '东部老师');

        Sanctum::actingAs($user);

        $this->patchJson("/api/tasks/{$other->id}", ['title' => '被越权改掉了'])->assertForbidden();
        $this->assertSame('东部他人任务', $other->fresh()->title, '越权写入不该落地');

        // 本店他人任务同样不可写（兜底不是「按门店放行」）
        $sameVenue = $this->task('绿地他人任务', '绿地店', '其他老师');
        $this->patchJson("/api/tasks/{$sameVenue->id}", ['status' => '进行中'])->assertForbidden();
        $this->assertSame('待接收', $sameVenue->fresh()->status);
    }

    /** 五种角色的手机号查重行为不变（命中本店自己人 / 全店 / 本人）。 */
    public function test_known_role_phone_check_scopes_are_unchanged(): void
    {
        $this->customer('绿地他人会员', '绿地店', '绿地顾问', '13800000110', '绿地卡');
        $this->customer('东部他人会员', '东部店', '东部顾问', '13800000110', '东部卡');

        // 店长：本店命中 1 条（修复前也是 1 条）
        $manager = $this->user('check-scope-manager', '绿地店长', 'R_MANAGER', '绿地店');
        Sanctum::actingAs($manager);
        $data = $this->getJson('/api/leads/check?phone=13800000110')->assertOk()->json('data');
        $this->assertSame(['绿地他人会员'], collect($data['matches'])->pluck('name')->all());

        // 超管：双店命中 2 条
        $super = $this->user('check-scope-super', '超管', 'R_SUPER', null, ['绿地店', '东部店']);
        Sanctum::actingAs($super);
        $data = $this->getJson('/api/leads/check?phone=13800000110')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['绿地他人会员', '东部他人会员'], collect($data['matches'])->pluck('name')->all());

        // 服务老师（李顾问）：同店但**不是**本人名下 → 不命中（修复前就不命中，收口后仍不命中）
        // 注意这里的姓名必须与 consultant 列的值不同，否则 staffOwnerFilter 的姓名那一路
        // 会让它变成「本人名下」，用例就不再测「他人」了。
        $service = $this->user('check-scope-service', '李顾问', 'R_SERVICE', '绿地店');
        Sanctum::actingAs($service);
        $data = $this->getJson('/api/leads/check?phone=13800000110')->assertOk()->json('data');
        $this->assertSame([], $data['matches'], '服务老师不该看到同店他人会员的手机号命中');
    }

    // ---------------------------------------------------------------------- 工具

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

    private function task(string $title, string $venue, string $owner, ?User $ownerUser = null): Task
    {
        return Task::create([
            'title' => $title,
            'customer_name' => $title.'客户',
            'venue' => $venue,
            'owner' => $owner,
            'owner_user_id' => $ownerUser?->id,
            'status' => '待接收',
        ]);
    }

    private function customer(string $name, string $venue, string $consultant, string $phone, string $mainCard): Customer
    {
        return Customer::create([
            'name' => $name,
            'phone' => $phone,
            'venue' => $venue,
            'consultant' => $consultant,
            'owner' => $consultant,
            'layer' => 'P0',
            'main_card' => $mainCard,
        ]);
    }

    private function lead(string $name, string $phone, string $venue, string $serviceTeacher): Lead
    {
        return Lead::create([
            'lead_date' => now()->toDateString(),
            'name' => $name,
            'phone' => $phone,
            'source' => '测试',
            'venue' => $venue,
            'service_teacher' => $serviceTeacher,
            'status' => '新留资',
        ]);
    }
}
