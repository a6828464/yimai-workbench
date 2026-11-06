<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Services\KyMemberSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `/today/summary` 的 SQL 下推 + 缓存 + `/customers/list-ids`（t11 / B1）回归测试。
 *
 * 为什么要有这个文件：B1 把 `/today/summary` 从「把整个可见范围 get() 进内存再数 4 个标量」
 * 改成「5 次 COUNT」，并给它加了 60 秒缓存。这类改写最容易在两个地方翻车，且都不会报错：
 *
 *  1. **数字悄悄变了**（谓词不等值、或共用 Builder 导致条件串味）—— 所以本文件的
 *     `test_summary_scalars_equal_the_in_memory_oracle` 把**改写前的那套内存算法**原样
 *     复刻成 oracle，逐项比对。oracle 用的是同一批 scope 函数，因此它验的是
 *     「内存判定 == SQL 判定」，而不是把某个写死的数字抄进测试。
 *  2. **悄悄又变回全表加载**（有人把 COUNT 改回 `->get()`）—— 所以第 1 个用例直接
 *     监听查询日志：出现 `select * from "customers"` / `"leads"` / `"tasks"` 即失败。
 *
 * 缓存另有两个必须钉住的行为：会员写入后数字要立刻变（不能等 60 秒 TTL）、
 * 超管保存今日快照后工作台要立刻看到新快照。两者都各自有一个用例。
 */
class TodaySummaryPushdownTest extends TestCase
{
    use RefreshDatabase;

    // ============================================================ 1. 不得再全表加载

    /**
     * `/today/summary` 期间不得出现任何「整行拉表」的查询，且必须出现 ≥5 次聚合查询。
     *
     * 实证方式：查询日志里 `select * from "customers"`（sqlite 用双引号包表名）是
     * `Customer::query()->get()` 的形态；下推后只会有 `select count(*) as aggregate from ...`。
     * 这条断言就是「Eloquent 实例 ~1600 → 0」的可执行版本。
     */
    public function test_summary_uses_aggregates_instead_of_loading_full_tables(): void
    {
        $this->seedMixedWorld();

        Sanctum::actingAs($this->super());

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/today/summary')->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $sql = array_map(fn (array $q) => (string) $q['query'], $log);

        foreach (['customers', 'leads', 'tasks'] as $table) {
            $this->assertFalse(
                (bool) preg_grep('/^\s*select\s+\*\s+from\s+"'.$table.'"/i', $sql),
                "不得出现整行拉取 {$table} 的查询：\n".implode("\n", $sql)
            );
        }

        $aggregates = preg_grep('/select count\(\*\) as aggregate from "(customers|leads)"/i', $sql);
        $this->assertGreaterThanOrEqual(
            5,
            count($aggregates),
            "下推后应有 ≥5 次 COUNT（会员侧 4 个 + 客资侧 1 个），实际：\n".implode("\n", $sql)
        );

        // 上限只是防倒退（改前是 6 条查询但每条都拉全表；现在条数略多、每条形如 count(*)）。
        $this->assertLessThanOrEqual(30, count($sql), "查询条数不应反常增长：\n".implode("\n", $sql));
    }

    // ==================================================== 2. 与内存算法逐项等值

    /**
     * 4 个标量 == 改写前的内存算法结果（oracle）。
     *
     * oracle 逐字复刻改写前的四行：
     *   newLeads        = $scopedCustomers->filter(isLeadOnlyCustomer)->count() + $leads->where('status','新留资')->count()
     *   pendingFollowup = $scopedCustomers->where('next_action_time','!=','')->where('…','<=',$tomorrow.' 23:59:59')->count()
     *   expiringMembers = $scopedCustomers->whereIn('id', filteredIds('待续课'))->count()
     *   riskCount       = $overdueTasks + $scopedCustomers->where('owner','未分配')->count() + $leads->where('status','新留资')->count()
     */
    public function test_summary_scalars_equal_the_in_memory_oracle(): void
    {
        $this->seedMixedWorld();
        $super = $this->super();
        Sanctum::actingAs($super);

        // ---- oracle：改写前的那套算法（同一批 scope 函数 + 内存遍历） ----
        $u = User::findOrFail($super->id);
        $scopedCustomers = scopeCustomersForUser(Customer::query(), $u)->get();
        $leads = scopeLeadsForUser(Lead::query(), $u)->get();
        $overdueTasks = scopeTasksForUser(Task::query(), $u, 'summary')->where('status', '已逾期')->count();
        $tomorrow = now()->addDay()->toDateString();

        $oracle = [
            'newLeads' => $scopedCustomers->filter(fn ($c) => isLeadOnlyCustomer($c))->count()
                + $leads->where('status', '新留资')->count(),
            'pendingFollowup' => $scopedCustomers
                ->where('next_action_time', '!=', '')
                ->where('next_action_time', '<=', $tomorrow.' 23:59:59')->count(),
            'expiringMembers' => $scopedCustomers->whereIn('id', filteredIds('待续课'))->count(),
            'riskCount' => $overdueTasks
                + $scopedCustomers->where('owner', '未分配')->count()
                + $leads->where('status', '新留资')->count(),
        ];

        $actual = $this->getJson('/api/today/summary')->assertOk()->json('data');

        foreach ($oracle as $key => $expected) {
            $this->assertSame($expected, $actual[$key], "{$key} 必须与内存算法等值");
        }

        // ---- 同时给绝对值断言：这批 fixture 的每个分支都恰好命中 1 次 ----
        // 客资侧：2 条新客资（1 条 P5 且非 ky: + 1 条留资记录）
        $this->assertSame(2, $actual['newLeads'], 'newLeads = 1 条前端客资 + 1 条新留资记录');
        // 到期日已过的 next_action_time 计 1；晚于明天 23:59:59 的那条不计
        $this->assertSame(1, $actual['pendingFollowup'], 'pendingFollowup 只数已到期的下回访时间');
        // 1 条已逾期任务 + 1 位未分配负责人会员 + 1 条新留资记录
        $this->assertSame(3, $actual['riskCount'], 'riskCount = 逾期任务 + 未分配负责人 + 新留资');
        // 两位会员命中待续课（夹具经真实卡项聚合，余 2 节）；超管双店全量可见
        $this->assertNotEmpty(filteredIds('待续课'), '夹具必须真的命中待续课，否则 expiringMembers 断言空转');
        $this->assertSame(2, $actual['expiringMembers'], 'expiringMembers = 命中待续课且可见的会员数');
    }

    /** 响应字段与形状：两端解析依赖这批键名，任何改名都是破坏性变更。 */
    public function test_summary_response_shape_is_unchanged(): void
    {
        $this->seedMixedWorld();
        Sanctum::actingAs($this->super());

        $data = $this->getJson('/api/today/summary')->assertOk()->json('data');

        foreach ([
            'newLeads', 'pendingFollowup', 'expiringMembers', 'riskCount', 'pendingApprovals',
            'todayBookings', 'trialBookings', 'todayKinds', 'scopeLabel', 'snapshotTime', 'snapshotAvailable',
        ] as $key) {
            $this->assertArrayHasKey($key, $data, "响应缺字段 {$key}");
        }
        $this->assertSame(['绿地店', '东部店'], array_keys($data['todayBookings']));
        $this->assertSame(['绿地店', '东部店'], array_keys($data['trialBookings']));
    }

    // ============================================================== 3. 缓存与失效

    /**
     * 会员写入后 `/today/summary` 必须立刻反映（不能等 60 秒 TTL）。
     *
     * 走真实接口：`PATCH /customers/{id}` 把 owner 从「未分配」改成店长，
     * riskCount 里的「未分配负责人」那一项应当场 -1。
     * 这条用例同时是「CustomerController::update 里那次 invalidateBusinessCaches
     * 必须放在 if (in_revive) 之外」的护栏 —— 放回 if 里这条会红。
     */
    public function test_summary_cache_is_invalidated_by_customer_write(): void
    {
        $this->seedMixedWorld();
        $super = $this->super();
        Sanctum::actingAs($super);

        $before = $this->getJson('/api/today/summary')->assertOk()->json('data.riskCount');
        $this->assertSame(3, $before);

        $unassigned = Customer::query()->where('owner', '未分配')->firstOrFail();
        $this->patchJson("/api/customers/{$unassigned->id}", ['owner' => '店长'])->assertOk();

        $after = $this->getJson('/api/today/summary')->assertOk()->json('data.riskCount');
        $this->assertSame(2, $after, '分配负责人后 riskCount 必须立刻少 1（缓存要被写入失效）');
    }

    /** 超管保存今日快照后，工作台必须立刻看到新快照（不必等 TTL）。 */
    public function test_summary_cache_is_invalidated_by_snapshot_save(): void
    {
        $this->seedMixedWorld();
        Sanctum::actingAs($this->super());

        $this->getJson('/api/today/summary')->assertOk();

        $this->putJson('/api/today/snapshot', [
            'todayBookings' => ['绿地店' => 7, '东部店' => 3],
            'trialBookings' => ['绿地店' => 2, '东部店' => 1],
        ])->assertOk();

        $data = $this->getJson('/api/today/summary')->assertOk()->json('data');
        $this->assertSame(7, $data['todayBookings']['绿地店'], '保存快照后当日预约数必须立刻更新');
        $this->assertSame(3, $data['todayBookings']['东部店']);
        $this->assertSame(2, $data['trialBookings']['绿地店']);
        $this->assertTrue($data['snapshotAvailable']);
    }

    // ========================================================= 4. /customers/list-ids

    /**
     * `/customers/list-ids` 的契约与「直接复用 memberListIds()」。
     *
     * 三条断言：
     *  1. 返回 `{lists: {清单名: id[]}}`，键集合与 `/customers/list-counts` 的 counts 完全一致；
     *  2. 超管拿到的 `lists` **逐字等于** `memberListIds()`（证明没有重算、没有二次扫描）；
     *  3. 非超管拿到的是一份**收窄**：每个 id 都必须能在自己的 `/customers` 范围内查到行
     *     （与 `GET /customers?list=<清单名>` 同源，这正是前端要的不变量）。
     */
    public function test_customer_list_ids_contract_and_scope(): void
    {
        $this->seedMixedWorld();

        // ---- 未登录 401 ----
        $this->getJson('/api/customers/list-ids')->assertStatus(401);

        // ---- 超管：逐字等于 memberListIds() ----
        Sanctum::actingAs($this->super());
        $data = $this->getJson('/api/customers/list-ids')->assertOk()->json('data');
        $this->assertArrayHasKey('lists', $data);

        $expected = array_map(
            fn (array $ids) => array_values(array_map('intval', $ids)),
            memberListIds()
        );
        $this->assertSame(array_keys($expected), array_keys($data['lists']), '清单键必须与 memberListIds() 一致');
        $this->assertSame($expected, $data['lists'], '超管路径必须直接回 memberListIds()，不得重算');

        $counts = $this->getJson('/api/customers/list-counts')->assertOk()->json('data.counts');
        $this->assertSame(array_keys($counts), array_keys($data['lists']), 'list-ids 与 list-counts 的键集合必须一致');

        foreach ($data['lists'] as $list => $ids) {
            $this->assertSame(count($ids), $counts[$list], "{$list}：id 数与 list-counts 的计数必须相等");
            foreach ($ids as $id) {
                $this->assertIsInt($id, "{$list} 的 id 必须是 int（前端按数字比较）");
            }
        }

        // ---- 服务老师：只回自己范围内的 id ----
        $teacher = $this->user('b1-teacher', 'B1顾问', 'R_SERVICE', '绿地店');
        Sanctum::actingAs(User::findOrFail($teacher->id));
        $scopedJson = $this->getJson('/api/customers/list-ids')->assertOk()->json('data.lists');

        $scopedIds = array_flip(
            scopeCustomersForUser(Customer::query(), User::findOrFail($teacher->id))->pluck('id')->all()
        );
        foreach ($scopedJson as $list => $ids) {
            foreach ($ids as $id) {
                $this->assertArrayHasKey(
                    $id,
                    $scopedIds,
                    "{$list} 返回了 {$id}，但它不在该账号的会员可见范围内（与 /customers?list= 不同源）"
                );
            }
        }

        // 收窄必须是真收窄：两位待续课会员里，顾问只挂了自己那一位，
        // 所以老师这份「待续课」= 1 条、超管那份 = 2 条（这条同时证明清单交集真的在起作用，
        // 而不是把超管全量原样发给了老师）。
        $mine = Customer::query()->where('name', 'B1-待续课本人')->firstOrFail();
        $others = Customer::query()->where('name', 'B1-待续课他人')->firstOrFail();

        $this->assertContains($mine->id, $data['lists']['待续课'], '超管应能看到自己名下那位待续课会员');
        $this->assertContains($others->id, $data['lists']['待续课'], '超管应能看到他人名下那位待续课会员');
        $this->assertSame([$mine->id], array_values($scopedJson['待续课']), '老师只能看到自己名下的待续课会员');
        $this->assertNotContains($others->id, $scopedJson['待续课'], '老师不得看到他人名下的待续课会员');
    }

    // ------------------------------------------------------------- 数据准备

    /**
     * 一批把 4 个标量的每个分支都命中一次的 fixture（超管视角）：
     *  - 1 条 P5 且非 ky: 的客户（= 前端客资）        → newLeads +1
     *  - 1 条 P5 但 external_id = ky:…（同步无资产）   → 不算客资
     *  - 1 位 owner = 未分配 的正式会员                → riskCount +1
     *  - 1 条 next_action_time 已过期                  → pendingFollowup +1
     *  - 1 条 next_action_time 远在未来                → pendingFollowup 不计
     *  - 1 条「新留资」客资记录                        → newLeads +1、riskCount +1
     *  - 1 条「已联系」客资记录                        → 不计
     *  - 1 条已逾期任务                                → riskCount +1
     */
    private function seedMixedWorld(): void
    {
        $base = [
            'phone' => '13900000000',
            'phone_tail' => '0000',
            'venue' => '绿地店',
            'source' => 'KeepYoga',
            'consultant' => '店长',
            'status' => '在籍',
            'main_card' => '—',
            'attend_m1' => 4, 'attend_m2' => 4, 'attend_m3' => 4,
            'last_visit' => now()->subDays(3)->toDateString(),
        ];

        // ① 前端客资：P5 + 非 ky:
        Customer::create($base + [
            'name' => 'B1-真客资',
            'layer' => 'P5',
            'external_id' => null,
            'owner' => '店长',
            'next_action_time' => '',
        ]);

        // ② 同步进来的「无资产会员」：P5 但 external_id 是 ky: → 不算客资
        Customer::create($base + [
            'name' => 'B1-同步无资产',
            'layer' => 'P5',
            'external_id' => 'ky:88001',
            'owner' => '店长',
            'next_action_time' => '',
        ]);

        // ③ 未分配负责人的正式会员（制造 riskCount 的 owner 分支）
        Customer::create($base + [
            'name' => 'B1-未分配',
            'layer' => 'P4',
            'external_id' => 'ky:88002',
            'owner' => '未分配',
            'next_action_time' => '',
        ]);

        // ④ 下回访时间已过期 → pendingFollowup +1
        Customer::create($base + [
            'name' => 'B1-待跟进已到期',
            'layer' => 'P4',
            'external_id' => 'ky:88003',
            'owner' => '店长',
            'next_action_time' => now()->subDay()->format('Y-m-d H:i:s'),
        ]);

        // ⑤ 下回访时间远在未来 → 不计
        Customer::create($base + [
            'name' => 'B1-待跟进未来',
            'layer' => 'P4',
            'external_id' => 'ky:88004',
            'owner' => '店长',
            'next_action_time' => now()->addDays(10)->format('Y-m-d H:i:s'),
        ]);

        // ⑥⑦ 客资记录
        Lead::create([
            'lead_date' => now()->toDateString(), 'name' => 'B1-新留资', 'phone' => '13900000001',
            'demand' => '体验课', 'source' => '美团', 'venue' => '绿地店',
            'service_teacher' => '店长', 'status' => '新留资',
        ]);
        Lead::create([
            'lead_date' => now()->toDateString(), 'name' => 'B1-已联系', 'phone' => '13900000002',
            'demand' => '体验课', 'source' => '美团', 'venue' => '绿地店',
            'service_teacher' => '店长', 'status' => '已联系',
        ]);

        // ⑧ 已逾期任务
        Task::create([
            'title' => 'B1-逾期任务', 'customer_name' => 'B1-未分配', 'venue' => '绿地店',
            'owner' => '店长', 'status' => '已逾期', 'deadline' => now()->subDay()->format('Y-m-d H:i'),
        ]);

        // ⑨⑩ 两位命中「待续课」的会员（余 2 节 / 已耗 18 节），用来让 expiringMembers
        //     与 list-ids 的清单交集**非空**——否则这两条断言会在空清单上空转通过。
        //     一位挂在自己名下（顾问 B1顾问），一位挂在别人名下，供 list-ids 的收窄断言用。
        //     卡项原始行口径与 MemberLayerTest 一致（status=4 在用、type=1 次卡）。
        $this->memberWithCards('B1-待续课本人', 'B1顾问');
        $this->memberWithCards('B1-待续课他人', '别的顾问');
    }

    /** 造一位「命中待续课」的会员：经真实 summarizeCards() 聚合后落库（不做手工猜测）。 */
    private function memberWithCards(string $name, string $consultant): Customer
    {
        $summarize = new \ReflectionMethod(KyMemberSyncService::class, 'summarizeCards');
        $summarize->setAccessible(true);
        $sum = $summarize->invoke(null, [array_filter([
            'card_title' => '私教20次', 'status' => '4', 'type' => '1',
            'residue_amount' => 2, 'usage_total' => 18, 'curr_unit_cash_value' => '1',
            'deadline' => now()->addDays(300)->toDateString(),
        ])]);

        return Customer::create([
            'name' => $name,
            'phone' => '1370000'.random_int(1000, 9999),
            'phone_tail' => '0000',
            'venue' => '绿地店',
            'source' => 'KeepYoga',
            'consultant' => $consultant,
            'owner' => '店长',
            'status' => '在籍',
            'layer' => 'P4',
            'main_card' => $sum['main_card'],
            'remain_times' => $sum['remain_times'],
            'expire_date' => $sum['expire_date'],
            'card_stats' => $sum['card_stats'],
            'cards_list' => $sum['cards_list'],
            'attend_m1' => 6, 'attend_m2' => 6, 'attend_m3' => 6,
            'last_visit' => now()->subDays(3)->toDateString(),
            'next_action_time' => '',
        ]);
    }

    private function super(): User
    {
        return User::factory()->create([
            'username' => uniqid('b1-super-'),
            'name' => 'B1超管',
            'role' => 'R_SUPER',
            'roles' => ['R_SUPER'],
            'status' => '启用',
            'venue' => null,
            'venues' => ['绿地店', '东部店'],
        ]);
    }

    private function user(string $username, string $name, string $role, ?string $venue): User
    {
        return User::factory()->create([
            'username' => $username,
            'name' => $name,
            'role' => $role,
            'roles' => [$role],
            'venue' => $venue,
            'venues' => $venue ? [$venue] : ['绿地店', '东部店'],
            'status' => '启用',
        ]);
    }
}
