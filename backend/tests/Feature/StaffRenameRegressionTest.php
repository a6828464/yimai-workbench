<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\PublishedShare;
use App\Models\StaffAlias;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 「请求内缓存挂在模型属性上」导致的写库缺陷回归测试（t25）。
 *
 * ## 缺陷
 *
 * `staffNames()` / `privateStudentKeys()` 原先把结果挂成模型的**动态属性**：
 *
 *     $user->staffNamesCache = array_keys($names);      // helpers.php 旧写法
 *
 * `staffNamesCache` 不是 users 表的列。Eloquent 的 `attributes` 数组同时承担
 * 「数据库列」与「动态属性」两个角色，于是这个值进了 `attributes`：
 * 同一个实例随后 `save()` 就会把它当作列写进去 →
 * `SQLSTATE[42S22] no such column: staffNamesCache`。
 *
 * ## 本文件钉住三件事
 *
 *  1. 「先做归属解析、再保存同一实例」不再抛异常，也不会生成含缓存键的 SQL；
 *  2. 缓存值不再污染 `getDirty()` / `toArray()`（这是同一缺陷的第二重伤害：
 *     缓存被当成「待写字段」与「输出字段」，前者影响 isDirty 判断、后者会泄进接口响应）；
 *  3. `staffNames()` 的既有语义不变：规范名 + 别名并集、同实例复用、不同实例隔离。
 *
 * 两个缓存函数都覆盖 —— 它们原先是同一个写法，`privateStudentKeysCache` 实测同样会进
 * `attributes`/`getDirty()`，属于同一个缺陷的第二处。
 */
class StaffRenameRegressionTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------ 1. 缓存不再被当作列写库

    /**
     * 先 `staffNames()` 再对同一实例 `save()`（缺陷的核心复现路径）。
     *
     * 这正是 `ProfileController::updateMyProfile()` 的代码形状：它拿 `$r->user()` 后
     * 直接 `$u->save()`，而同一请求内只要先发生过归属解析（例如服务老师打开会员列表走
     * `canAccessCustomer()` → `staffNames()`），那个实例就已经带着缓存键了。
     */
    public function test_staff_names_then_save_does_not_write_cache_as_column(): void
    {
        $user = $this->user('张三', 'rename-a');
        StaffAlias::create(['user_id' => $user->id, 'alias' => '张三老师', 'source' => 'manual']);

        $names = staffNames($user);                    // 归属解析（缓存到实例）
        $this->assertContains('张三', $names);

        $user->nickname = '小张';
        $user->save();                                 // 修复前：SQLSTATE[42S22] no such column

        $this->assertSame('小张', $user->fresh()->nickname, '保存本身要成功且字段落库');
    }

    /** 同一路径下，不得生成任何把缓存键当列名的 SQL。 */
    public function test_save_after_staff_names_emits_no_cache_column_in_sql(): void
    {
        $user = $this->user('李四', 'rename-b');
        StaffAlias::create(['user_id' => $user->id, 'alias' => '李四老师', 'source' => 'manual']);
        staffNames($user);
        privateStudentKeys($user);

        $queries = $this->captureQueries(fn () => tap($user, function ($u) {
            $u->nickname = '小李';
            $u->save();
        }));

        $offenders = array_values(array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'staffNamesCache') || str_contains($sql, 'privateStudentKeysCache')
        ));

        $this->assertSame([], $offenders, 'save() 生成的 SQL 不得包含缓存键：'.implode(' | ', $offenders));
    }

    /** 兄弟缓存 `privateStudentKeysCache` 同缺陷、同样已修（它原先是同一个写法）。 */
    public function test_private_student_keys_then_save_does_not_write_cache_as_column(): void
    {
        $user = $this->user('绿地老师', 'rename-c', 'R_TEACHER');

        privateStudentKeys($user);                     // 只读解析，修复前同样会污染实例
        $user->phone = '13800000009';
        $user->save();                                 // 修复前：no such column: staffNamesCache

        $this->assertSame('13800000009', $user->fresh()->phone);
    }

    /**
     * 缓存值不得污染 `getDirty()` 与 `toArray()`。
     *
     * 这是同一缺陷的第二重伤害，且与「哪张表」无关：
     *  - `getDirty()` 被污染 → 依赖 `isDirty()` 的写入路径会误判（项目里
     *    `KyMemberSyncService` 就用 `isDirty()` 决定是否落库）；
     *  - `toArray()` 被污染 → 缓存内容会混进接口响应的字段集。
     */
    public function test_cache_does_not_pollute_dirty_state_or_array_output(): void
    {
        $user = $this->user('王五', 'rename-d');
        $user->syncOriginal();                         // 模拟「刚从库里读出来、无改动」
        $this->assertSame([], $user->getDirty());

        staffNames($user);
        privateStudentKeys($user);

        $this->assertSame([], $user->getDirty(), '归属解析后实例不应显示为「有改动」');
        $this->assertArrayNotHasKey('staffNamesCache', $user->toArray());
        $this->assertArrayNotHasKey('privateStudentKeysCache', $user->toArray());

        $user->nickname = '改名了';
        $this->assertSame(['nickname'], array_keys($user->getDirty()), '真正的改动仍要被看到');
    }

    /**
     * `$guarded` 挡不住这条路径 —— 记录「为什么不是靠 $guarded 修的」。
     *
     * `$guarded` 只约束**批量赋值**（fill/create/update）；旧写法是直接赋值，
     * 走 `setAttribute`，`$guarded` 根本不参与。`User` 定义了 `$fillable`，
     * `$guarded` 默认为 `['*']`，该键本来就已经不是 fillable —— 所以给 `$guarded`
     * 加名字是个 no-op，不能当成修复。
     */
    public function test_guard_does_not_constrain_direct_assignment_which_is_why_guard_is_not_the_fix(): void
    {
        $user = new User();
        $this->assertTrue($user->isGuarded('staffNamesCache'), '该键本来就已被 guarded 挡住批量赋值');
        $this->assertFalse($user->isFillable('staffNamesCache'));
    }

    // ---------------------------------------------- 2. 接口级：真实链路不再报错

    /**
     * 人员管理接口：改角色/门店（走 `$user->update()`）与改姓名映射（别名）都返回 200。
     *
     * 说明：本轮核实发现**没有任何接口会把 `users.name` 写成新值** ——
     * `PATCH /api/accounts/{key}` 的校验只接受 roles/roleCode/venues/password，
     * 前端编辑弹窗也只提交 roles/venues。产品上「改名」的正式入口是**别名映射**
     * （`PUT /api/accounts/{key}/aliases`，即人员管理页的归属映射面板），
     * 所以这里把两个真实入口都跑一遍。
     */
    public function test_account_rename_and_alias_endpoints_succeed(): void
    {
        $super = $this->user('超管', 'rename-boss', 'R_SUPER', null);
        $target = $this->user('绿地顾问', 'rename-target', 'R_SERVICE');
        Sanctum::actingAs($super);

        $this->patchJson('/api/accounts/rename-target', [
            'action' => 'update',
            'roles' => ['R_SERVICE'],
            'venues' => ['绿地店'],
        ])->assertOk();

        $this->putJson('/api/accounts/rename-target/aliases', [
            'aliases' => ['绿地顾问', '顾问旧名'],
        ])->assertOk()->assertJsonPath('data.aliases', ['顾问旧名']);
    }

    /**
     * 改姓名映射后，历史任务的归属仍正确关联（归属按「规范名 + 别名」并集解析）。
     *
     * 数据是「登记旧名」的历史任务：加上旧名别名后，本人才看得到。
     */
    public function test_historical_task_ownership_still_resolves_after_rename(): void
    {
        $super = $this->user('超管', 'rename-boss2', 'R_SUPER', null);
        $user = $this->user('王教练', 'rename-coach', 'R_TEACHER');
        Task::create([
            'title' => '历史任务（登记旧名）', 'customer_name' => '客户甲', 'venue' => '绿地店',
            'owner' => '王老师', 'status' => '待接收',
        ]);

        Sanctum::actingAs($user);
        $this->assertSame([], $this->getJson('/api/tasks')->assertOk()->json('data.records'),
            '改名前：用旧名登记的任务不该算到本人名下');

        Sanctum::actingAs($super);
        $this->putJson('/api/accounts/rename-coach/aliases', ['aliases' => ['王老师']])->assertOk();

        // 换一个新实例（真实请求就是这样），确认归属解析仍然有效
        Sanctum::actingAs(User::findOrFail($user->id));
        $titles = collect($this->getJson('/api/tasks')->assertOk()->json('data.records'))->pluck('title')->all();
        $this->assertSame(['历史任务（登记旧名）'], $titles, '加别名后历史任务应重新关联到本人');
    }

    /** `PUT /my/profile` 是唯一会 `$u->save()` 的个人接口，必须 200。 */
    public function test_profile_update_endpoint_succeeds(): void
    {
        $user = $this->user('赵六', 'rename-profile');
        Sanctum::actingAs($user);

        $this->putJson('/api/my/profile', ['nickname' => '小赵'])
            ->assertOk()
            ->assertJsonPath('data.nickname', '小赵');
    }

    // ------------------------------- 4. 运维可见性：published_shares 的悬挂 id 也要被看到

    /**
     * `published_shares.created_by_user_id` 指向已删除账号时，必须出现在 `staleOwnerUserIds()` 里。
     *
     * `staleOwnerUserIds()` 是人员管理「归属映射」面板 `staleIds` 的唯一数据源
     * （`AccountController::staffMapping()` 直接把它塞进响应，前端
     * `admin-web/src/views/yimai/accounts/index.vue` 渲染成「待清理」标签）。
     * 该函数的列清单原先漏了这张表 —— 于是这类悬挂 id 在运维面板上**不可见、无法清理**。
     *
     * 注：`ShareController::orphans()`（GET /shares/orphans）虽然也报 `published_shares`
     * 的悬挂行，但 admin-web 里没有任何入口调用它；本用例保的是**运维实际能看到的那条路径**。
     */
    public function test_published_share_dangling_owner_is_reported_as_stale_id(): void
    {
        $this->user('张店长', 'share-owner');

        $this->share(['created_by' => '张店长', 'created_by_user_id' => 999999]);

        $stale = staleOwnerUserIds();
        $hit = collect($stale)->firstWhere(fn ($s) => $s['table'] === 'published_shares'
            && $s['column'] === 'created_by_user_id');

        $this->assertNotNull($hit, 'published_shares 的悬挂 id 必须被 staleOwnerUserIds() 报出：'
            .json_encode($stale, JSON_UNESCAPED_UNICODE));
        $this->assertSame(999999, $hit['user_id']);
        $this->assertSame(1, $hit['rows']);
    }

    /** 指向**存在**账号的分享行不算悬挂（否则面板会长期刷出假警报）。 */
    public function test_published_share_with_valid_owner_is_not_reported(): void
    {
        $owner = $this->user('张店长', 'share-owner-ok');
        $this->share(['created_by' => '张店长', 'created_by_user_id' => $owner->id]);

        $tables = collect(staleOwnerUserIds())->pluck('table')->all();
        $this->assertNotContains('published_shares', $tables, '有效归属不该被报成悬挂 id');
    }

    /** 面板接口端到端：`GET /api/accounts/staff-mapping` 的 `staleIds` 里能看到它（前端读的就是这个字段）。 */
    public function test_staff_mapping_endpoint_exposes_dangling_share_owner(): void
    {
        $super = $this->user('超管', 'share-boss', 'R_SUPER', null);
        $this->share(['created_by' => '已离职店长', 'created_by_user_id' => 888888]);
        Sanctum::actingAs($super);

        $staleIds = $this->getJson('/api/accounts/staff-mapping')->assertOk()->json('data.staleIds');
        $hit = collect($staleIds)->firstWhere('table', 'published_shares');

        $this->assertNotNull($hit, 'staleIds 应包含 published_shares：'.json_encode($staleIds, JSON_UNESCAPED_UNICODE));
        $this->assertSame('created_by_user_id', $hit['column']);
        $this->assertSame(888888, $hit['user_id']);
    }

    /** `created_by_user_id` 为 null 的历史行不算悬挂（迁移前存量数据属正常状态）。 */
    public function test_published_share_without_owner_id_is_not_reported(): void
    {
        $this->share(['created_by' => '', 'created_by_user_id' => null]);

        $tables = collect(staleOwnerUserIds())->pluck('table')->all();
        $this->assertNotContains('published_shares', $tables, 'null 归属不是悬挂 id');
    }

    /**
     * 同一实例「先归属解析、再走个人资料保存」的完整组合（缺陷的最强复现）。
     *
     * 注：HTTP 测试里 `actingAs` 让多次调用共用同一个 User 实例，因而能复现
     * 「同一请求内先解析归属、后保存该实例」这一序列 —— 修复前这里 500。
     */
    public function test_ownership_resolution_then_profile_save_in_same_flow_succeeds(): void
    {
        $user = $this->user('绿地顾问', 'rename-seq', 'R_SERVICE');
        \App\Models\Customer::create([
            'name' => '会员甲', 'venue' => '绿地店', 'consultant' => '绿地顾问',
            'owner' => '绿地顾问', 'layer' => 'P0', 'phone' => '13800000007',
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/customers')->assertOk();          // 归属解析（缓存挂在该实例上）
        $this->putJson('/api/my/profile', ['nickname' => '改后'])
            ->assertOk()
            ->assertJsonPath('data.nickname', '改后');
    }

    // ------------------------------------------------ 3. staffNames() 语义不变

    /** 规范名 + 别名的并集（既有语义，修复前后逐字一致）。 */
    public function test_staff_names_is_union_of_canonical_name_and_aliases(): void
    {
        $user = $this->user('王教练', 'rename-union', 'R_TEACHER');
        StaffAlias::create(['user_id' => $user->id, 'alias' => '王老师', 'source' => 'manual']);
        StaffAlias::create(['user_id' => $user->id, 'alias' => '小王', 'source' => 'ky']);

        $this->assertSame(['王教练', '王老师', '小王'], staffNames($user));
    }

    /** 空别名/纯空白别名不进入结果；没有别名时就是规范名本身。 */
    public function test_staff_names_ignores_blank_aliases(): void
    {
        $user = $this->user('无别名', 'rename-blank', 'R_SERVICE');
        $this->assertSame(['无别名'], staffNames($user));

        StaffAlias::create(['user_id' => $user->id, 'alias' => '   ', 'source' => 'manual']);
        $user2 = User::findOrFail($user->id);
        $this->assertSame(['无别名'], staffNames($user2));
    }

    /**
     * 请求内缓存语义保持：同一实例复用（不再查库），不同实例各自解析。
     *
     * 「同 id 不同实例必须隔离」是原注释明确保护的性质（长驻进程/测试串跑时
     * 函数级 static 会串数据），WeakMap 按实例作键，语义与修复前完全一致。
     */
    public function test_cache_is_per_instance_and_reused_within_instance(): void
    {
        $user = $this->user('缓存甲', 'rename-cache');
        StaffAlias::create(['user_id' => $user->id, 'alias' => '缓存旧名', 'source' => 'manual']);

        $first = staffNames($user);
        $this->assertSame(['缓存甲', '缓存旧名'], $first);

        // 同一实例：第二次调用直接命中缓存，不产生新查询
        $this->assertSame([], $this->captureQueries(fn () => staffNames($user)), '同一实例的第二次调用不应再查库');

        // 另一个实例（同 id）：必须重新解析，才能拿到新加的别名
        StaffAlias::create(['user_id' => $user->id, 'alias' => '缓存新别名', 'source' => 'manual']);
        $fresh = User::findOrFail($user->id);
        $this->assertSame(['缓存甲', '缓存旧名', '缓存新别名'], staffNames($fresh));

        // 两个实例各自持有独立缓存：旧实例不会凭空看到新别名（与原语义一致）
        $this->assertSame(['缓存甲', '缓存旧名'], staffNames($user));
    }

    /** 没有 id 的（未落库）实例也要能正确解析，不能因此报错。 */
    public function test_staff_names_works_for_unsaved_instance(): void
    {
        $unsaved = new User(['name' => '未落库']);
        $this->assertSame(['未落库'], staffNames($unsaved));
    }

    // --------------------------- 5. privateStudentKeys() 的手机号必须是 string（t36）

    /**
     * ⚠️ `privateStudentKeys()['phones']` 的元素**必须是 string**。
     *
     * ## 缺陷（t36）
     *
     * `privateStudentKeys()` 用手机号当数组键去重（`$phones[$phone] = true`），
     * 而 PHP 会把**纯数字字符串键强转成 int** ⇒ `array_keys()` 返回 `int[]`。
     * 而所有读方都按 **string 严格比较**（`in_array(..., ..., true)`），于是恒为
     * false：**手机号关联通道整个失效**。
     *
     * ## 为什么必须是「严格比较能命中」而不是「宽松能命中」
     *
     * 宽松比较（`in_array($p, $keys, false)`）在旧代码下**也是 true**，
     * 所以断言宽松命中区分不了修复前后 —— 那样的测试是同义反复。
     * 这里钉的正是**严格**语义，因为生产里三条消费路径都用严格比较：
     *   - `helpers.php` `privateTeaches()`（客资详情/会员可见）
     *   - `EnsureUserIsEnabled:165`（客资读写准入）
     *   - `TodayController:163`（今日待办的按人收窄）
     */
    public function test_private_student_phones_are_strings_and_strict_match_works(): void
    {
        $teacher = $this->user('号码老师', 't36-phone-types', 'R_TEACHER');

        KyBooking::create([
            'source_key' => '77:私教:t36p1',
            'venue' => '绿地店',
            'booking_type' => '私教',
            'course_kind' => 'private',
            'member_id' => 'M3601',
            'member_name' => '号码学员',
            'phone' => '13800003601',
            'start_at' => now(),
            'course_name' => '私教课',
            'teacher_name' => '号码老师',
            'teacher_user_id' => $teacher->id,
            'status' => 'signed',
            'is_trial' => false,
            'raw' => [],
        ]);

        $keys = privateStudentKeys($teacher);

        // ① 元素类型必须是 string（缺陷本体）
        $this->assertNotSame([], $keys['phones'], '前提：该老师应解析出至少一个手机号');
        foreach ($keys['phones'] as $i => $p) {
            $this->assertIsString(
                $p,
                "phones[{$i}] 必须是 string；出现 int 说明 PHP 的数字键强转又回来了（见本用例注释）"
            );
        }

        // ② 严格比较必须命中（修复前恒 false）
        $this->assertTrue(
            in_array('13800003601', $keys['phones'], true),
            '手机号通道的严格比较必须能命中；恒 false 会让老师静默漏看只能靠手机号关联的学员'
        );

        // ③ 反向：不得因为归一化而凭空多出号码
        $this->assertNotContains('13900000000', $keys['phones'], '不得出现未授课学员的号码');
        $this->assertCount(1, $keys['phones'], '该老师只有 1 名手机号可解析的私教学员');
    }

    /**
     * 「只有手机号能关联」的学员必须能被老师看到（external_id 缺失）。
     *
     * 这是缺陷的**真实业务后果**，也是本任务修复的价值所在：
     * 学员档案没有 `external_id`（或与随心瑜对不上）时，手机号是**唯一**关联途径；
     * 严格比较恒 false ⇒ `privateTeaches()` 恒 false ⇒ 老师看不到自己的学员。
     *
     * 本用例直接断言端到端判据 `privateTeaches()`，而不是只测数组类型 ——
     * 避免「类型对了但消费路径没通」这种假修复。
     */
    public function test_student_linkable_only_by_phone_is_visible_to_own_teacher(): void
    {
        $teacher = $this->user('手机老师', 't36-phone-only', 'R_TEACHER');

        KyBooking::create([
            'source_key' => '77:私教:t36p2',
            'venue' => '绿地店',
            'booking_type' => '私教',
            'course_kind' => 'private',
            'member_id' => 'M3602',
            'member_name' => '仅手机号学员',
            'phone' => '13800003602',
            'start_at' => now(),
            'course_name' => '私教课',
            'teacher_name' => '手机老师',
            'teacher_user_id' => $teacher->id,
            'status' => 'signed',
            'is_trial' => false,
            'raw' => [],
        ]);

        // 学员档案：**故意不带 external_id**（external_id 为空）
        $customer = Customer::create([
            'name' => '仅手机号学员',
            'phone' => '13800003602',
            'venue' => '绿地店',
            'external_id' => '',
            'layer' => 'P1',
        ]);

        $this->assertTrue(
            privateTeaches($teacher, $customer),
            '只能靠手机号关联的学员必须能被自己的授课老师看到（修复前此处恒 false）'
        );

        // 反向：别的老师不得因此看到该学员（修复只补回本该可见的，不引入越权）。
        //
        // ⚠️ 关键是给这位老师**也配一名真实手机号私教学员**，让它的 `phones` 非空。
        // 否则该老师的 phones 恒为空数组，断言会「因为列表是空的」而 trivially 为真 ——
        // 那样测不出「手机号通道是按老师隔离的」这一性质（同义反复）。
        $other = $this->user('无关老师', 't36-other-teacher', 'R_TEACHER');

        KyBooking::create([
            'source_key' => '77:私教:t36p2o',
            'venue' => '绿地店',
            'booking_type' => '私教',
            'course_kind' => 'private',
            'member_id' => 'M3602O',
            'member_name' => '无关老师的学员',
            'phone' => '13800003603',
            'start_at' => now(),
            'course_name' => '私教课',
            'teacher_name' => '无关老师',
            'teacher_user_id' => $other->id,
            'status' => 'signed',
            'is_trial' => false,
            'raw' => [],
        ]);

        $otherKeys = privateStudentKeys($other);
        $this->assertNotSame([], $otherKeys['phones'], '前提：这位老师自己的手机号通道是通的（否则下面的断言无意义）');
        $this->assertContains('13800003603', $otherKeys['phones']);

        $this->assertFalse(
            privateTeaches($other, $customer),
            '其它老师即使手机号通道正常，也不得看到不属于自己的学员'
        );
    }

    /**
     * 修复方向定性：**放宽**（补回本该可见的），但不得放宽到他人。
     *
     * 用同一份数据同时断言两侧：
     *  - 自己的学员 → 手机号通道命中（修复目标）；
     *  - 他人学员的同名/异号 → 不命中（不得越权）。
     * 另断言 `external_ids` 的元素形状未变（`ky:{venueId}:{memberId}` 带前缀，
     * 天然是 string，不受数字键强转影响），说明本次只动 `phones`。
     */
    public function test_phone_channel_widens_only_to_own_students(): void
    {
        $teacher = $this->user('边界老师', 't36-boundary', 'R_TEACHER');

        KyBooking::create([
            'source_key' => '77:私教:t36p3',
            'venue' => '绿地店',
            'booking_type' => '私教',
            'course_kind' => 'private',
            'member_id' => 'M3603',
            'member_name' => '本人学员',
            'phone' => '13800003603',
            'start_at' => now(),
            'course_name' => '私教课',
            'teacher_name' => '边界老师',
            'teacher_user_id' => $teacher->id,
            'status' => 'signed',
            'is_trial' => false,
            'raw' => [],
        ]);

        $keys = privateStudentKeys($teacher);

        // external_ids 形状不变（带 ky: 前缀 ⇒ 恒为 string，不受本次修复影响）
        foreach ($keys['external_ids'] as $i => $id) {
            $this->assertIsString($id);
            $this->assertStringStartsWith('ky:', $id, "external_ids[{$i}] 应保持 ky:{{venueId}}:{{memberId}} 形状");
        }

        // 自己的学员：命中
        $this->assertTrue(in_array('13800003603', $keys['phones'], true));
        // 他人号码：不命中
        $this->assertFalse(in_array('13800009999', $keys['phones'], true));
    }

    // ---------------------------------------------------------------------- 工具

    private function user(string $name, string $username, string $role = 'R_SERVICE', ?string $venue = '绿地店'): User
    {
        return User::factory()->create([
            'name' => $name,
            'username' => $username,
            'role' => $role,
            'roles' => [$role],
            'venue' => $venue,
            'venues' => $venue ? [$venue] : ['绿地店', '东部店'],
            'status' => '启用',
        ]);
    }

    /**
     * 一行已发布的分享记录（`published_shares` 的启用/归属/来源列均已就位）。
     *
     * @param  array<string, mixed>  $overrides
     */
    private function share(array $overrides = []): PublishedShare
    {
        return PublishedShare::create(array_merge([
            'type' => 'sales',
            'token' => str_pad((string) random_int(1, PHP_INT_MAX), 16, '0', STR_PAD_LEFT),
            'created_by' => '张店长',
            'created_by_user_id' => null,
            'payload' => ['k' => 'v'],
            'enabled' => true,
            'token_source' => 'server',
        ], $overrides));
    }

    /** 跑一段代码并收集它产生的 SQL。 */
    private function captureQueries(callable $fn): array
    {
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $fn();

        return $queries;
    }
}
