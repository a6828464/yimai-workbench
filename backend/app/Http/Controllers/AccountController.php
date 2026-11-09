<?php

namespace App\Http\Controllers;

use App\Models\StaffAlias;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AccountController extends Controller
{
    /** 可选角色，顺序即权限从大到小 */
    private const ASSIGNABLE = ['R_SUPER', 'R_MANAGER', 'R_TEACHER', 'R_SERVICE', 'R_MEDIA'];

    /** 需要锁定到单一门店的角色（超管/新媒体为双店） */
    private const VENUE_BOUND = ['R_MANAGER', 'R_SERVICE', 'R_TEACHER'];

    private function roleMap(): array
    {
        return ['R_SUPER' => '超管', 'R_MANAGER' => '店长', 'R_SERVICE' => '服务老师', 'R_TEACHER' => '授课老师', 'R_MEDIA' => '新媒体'];
    }

    /** 角色码数组 → 中文标签串（按权限从大到小排，展示顺序稳定） */
    private function label(array $roles): string
    {
        $map = $this->roleMap();
        $ordered = array_values(array_filter(self::ASSIGNABLE, fn ($r) => in_array($r, $roles, true)));

        return implode(' + ', array_map(fn ($x) => $map[$x] ?? $x, $ordered));
    }

    /**
     * GET /accounts
     *
     * 排序口径：`sort_order` 优先，其次 `id`（开通先后）。
     *
     * `sort_order` 默认 0，所以**没拖过顺序**时全部账号同分，复合排序退化成原来的
     * `orderBy('id')` —— 升级前后列表顺序一个字节都不变，不需要回填迁移。
     * 一旦用过「调整顺序」，这批账号会被重写成 10/20/30…，新账号取 max+10 接末尾。
     */
    public function index(Request $r)
    {
        abort_unless(userHasRole($r->user(), 'R_SUPER'), 403);
        $selfName = $r->user()->name;

        return ok(User::orderBy('sort_order')->orderBy('id')->get()->map(function ($u) use ($selfName) {
            $roles = userRoles($u);

            return [
                'key' => $u->username,
                'userName' => $u->name,
                // roleCode 保留为「主角色」，roles 为全部角色（前端按多选渲染）
                'roleCode' => primaryRole($roles),
                'roles' => $roles,
                'roleLabel' => $this->label($roles),
                'venues' => $u->venues ?? [],
                'email' => $u->email,
                'status' => $u->status ?? '启用',
                'self' => $u->name === $selfName,
                // 该账号在业务归属列里可能出现的其它名字（见 helpers.php staffNames）
                'aliases' => StaffAlias::where('user_id', $u->id)->orderBy('id')->pluck('alias')->all(),
            ];
        }));
    }

    /**
     * PUT /accounts/order —— 保存人员列表的手动顺序（前端拖动排序）
     *
     * 入参是**完整顺序**（登录名数组），而不是「谁挪到第几位」的增量。
     * 拖动一次可能跨十几行，增量需要服务端复现前端的排序算法才能得到同一结果；
     * 一旦两边在「同分 / 缺项 / 过滤后只拖了子集」上有差，顺序就会静默错位。
     * 传全量 = 前端看到什么就存什么。
     *
     * 归一化口径（这三条决定了「保存顺序」会不会误伤没拖的行）：
     *
     * 1. 列表内的账号编号 10/20/30…（各留 9 个空位，将来插队不用重排全表）；
     * 2. **列表里没有的账号**（别人刚开通、或前端只拖了某个子集）不丢弃、更不置 0，
     *    而是按原有的 (sort_order, id) 顺序接在这批后面 —— 置 0 会让它们瞬间跳到最前面，
     *    那是「保存顺序」不该产生的副作用；
     * 3. 认不出的登录名直接忽略（账号可能刚被删掉）。
     *
     * 整个过程在一个事务里：部分成功会让列表停在「拖了一半」的中间态。
     */
    public function updateOrder(Request $r)
    {
        abort_unless(userHasRole($r->user(), 'R_SUPER'), 403);
        $d = $r->validate([
            'order' => 'required|array|min:1',
            'order.*' => 'string',
        ]);

        $existing = User::orderBy('sort_order')->orderBy('id')->get(['id', 'username']);
        $known = $existing->pluck('username')->all();

        $ordered = [];
        foreach ($d['order'] as $key) {
            if (in_array($key, $known, true) && ! in_array($key, $ordered, true)) {
                $ordered[] = $key;
            }
        }
        abort_if($ordered === [], 422, '顺序里没有任何有效账号');

        foreach ($known as $key) {
            if (! in_array($key, $ordered, true)) {
                $ordered[] = $key;
            }
        }

        DB::transaction(function () use ($ordered) {
            foreach ($ordered as $i => $key) {
                // 直接走查询构造器：Eloquent 的 save/update 会自动带上 updated_at，
                // 一次拖动把几十个账号的「修改时间」全刷新，等于把真正的编辑痕迹淹掉。
                DB::table('users')->where('username', $key)->update(['sort_order' => ($i + 1) * 10]);
            }
        });

        audit(
            $r,
            '排序',
            '人员管理',
            '',
            '全部账号',
            '双店',
            '调整人员列表顺序：'.implode(' > ', array_slice($ordered, 0, 12))
                .(count($ordered) > 12 ? ' …（共 '.count($ordered).' 个）' : '')
        );

        return ok(['updated' => count($ordered)]);
    }

    /**
     * GET /accounts/staff-mapping
     *
     * 人员身份归一总览（原「归属映射」面板的数据源）。
     *
     * 这里回答四个问题，缺一个管理员就只能靠猜：
     *  1. 每个账号有哪几个名字（本名 / 昵称 / 别名），各自名下有多少行数据；
     *  2. 业务数据里有哪些名字还没归属（`unmapped`，带疑似账号建议）；
     *  3. 各归属列的 id 覆盖率（`coverage`，还差多少行没落 id）；
     *  4. 归属 id 指向已不存在账号的行（`staleIds`，账号删掉重建留下的）。
     *
     * 旧字段（accounts / unmapped / staleIds）结构保持不变，只增不减 —— 老客户端
     * 与既有测试都依赖它。
     */
    public function staffMapping(Request $r)
    {
        requireSuper($r);

        $directory = staffDirectory();

        $accounts = [];
        foreach ($directory->accounts() as $row) {
            $user = User::find($row['id']);
            $accounts[] = [
                'key' => (string) ($user?->username ?? ''),
                'name' => $row['name'],
                'nickname' => $row['nickname'],
                'aliases' => $row['aliases'],
                'roles' => $row['roles'],
                'venue' => $row['venue'],
                'status' => $row['status'],
                // 名下数据量：改名/认领后这个数字会立刻变化，是"生效了没有"的唯一直观证据
                'footprint' => $user ? $directory->footprint($user) : [],
            ];
        }

        $unmapped = array_values($directory->unmapped());

        return ok([
            'accounts' => $accounts,
            'unmapped' => $unmapped,
            'staleIds' => staleOwnerUserIds(),
            'coverage' => $directory->coverage(),
            'totals' => [
                'unmapped' => count($unmapped),
                'missingIds' => array_sum(array_column($directory->coverage(), 'missing')),
            ],
        ]);
    }

    /**
     * POST /accounts/staff-mapping/assign
     *
     * 把一个悬空姓名「认领」到某个账号。
     *
     * 这是「随心瑜叫苏米、留资写蒙澍南、工作台注册叫澍南」三套名字的统一动作：
     * 认领一次 → 写进 `staff_aliases` → 回填所有历史行的归属 id → 清业务缓存，
     * 本人刷新即能看到。名字一旦登记就全局唯一，重复认领会明确报冲突而不是静默覆盖。
     */
    public function assignStaffName(Request $r)
    {
        requireSuper($r);

        $d = $r->validate([
            'name' => 'required|string|max:60',
            'userKey' => 'required|string|max:60',
            'source' => 'nullable|string|in:manual,ky,merge',
        ]);

        $user = User::where('username', $d['userKey'])->first();
        abort_if($user === null, 404, '账号不存在');

        $result = staffDirectory()->assign(
            $d['name'],
            (int) $user->id,
            $d['source'] ?? 'manual',
            '人员管理 → 身份归一'
        );

        audit($r, '修改', '人员管理', $user->id, $user->name, is_string($user->venue) ? $user->venue : '双店',
            "归属认领：把「{$result['alias']}」并入账号 {$user->name}（回填 {$result['backfilled']} 行）");

        return ok($result);
    }

    /**
     * POST /accounts/staff-mapping/repair
     *
     * 全量补齐归属 id（可重复执行）。两件事：
     *  - `backfill`：按现有名字池把 `*_user_id` 为空的业务行补上；
     *  - `clearStale`（可选）：把指向已删除账号的悬挂 id 置空，再由下一轮补正确的。
     *
     * 为什么要有手动入口：同步写入 id 只在"写入那一刻"发生，账号是在数据之后建的
     * （或改过名）时，历史行就会一直是空 id —— 这种行只能靠一次全量回填纠正。
     */
    public function repairStaffMapping(Request $r)
    {
        requireSuper($r);

        $d = $r->validate([
            'clearStale' => 'nullable|boolean',
            'scan' => 'nullable|boolean',
        ]);

        $directory = staffDirectory();
        // scan=true 时先清悬挂 id 再回填：先清后补，补的是"按当前名字池能认出来的"
        $cleared = ! empty($d['clearStale']) ? $directory->clearStale() : 0;
        $result = $directory->backfill();
        invalidateBusinessCaches();

        audit($r, '修改', '人员管理', 0, '全部账号', '双店',
            "归属修复：回填 {$result['filled']} 行".($cleared > 0 ? "，清理悬挂 id {$cleared} 行" : ''));

        return ok([
            'backfilled' => $result['filled'],
            'byTable' => $result['byTable'],
            'cleared' => $cleared,
            'coverage' => $directory->coverage(),
        ]);
    }

    /**
     * POST /accounts/staff-mapping/merge
     *
     * 账号合并：把 `from` 并进 `into`（同一个人被建了两次、或旧账号要改用真名时用）。
     *
     * 与 `assign` 的区别：assign 只是"这个名字也归他"，merge 是把**整个账号**连人带
     * 数据并过去，并停用源账号 —— 源账号下的会员/客资/排课会改成目标账号的姓名与 id。
     */
    public function mergeStaffAccount(Request $r)
    {
        requireSuper($r);

        $d = $r->validate([
            'into' => 'required|string|max:60',
            'from' => 'required|array|min:1',
            'from.*' => 'string|max:60',
            'delete' => 'nullable|boolean',
        ]);

        $into = User::where('username', $d['into'])->first();
        abort_if($into === null, 404, '目标账号不存在');

        $fromIds = User::whereIn('username', $d['from'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        abort_if($fromIds === [], 422, '要合并的账号不存在');

        $result = staffDirectory()->merge((int) $into->id, $fromIds, (bool) ($d['delete'] ?? false));

        audit($r, '修改', '人员管理', $into->id, $into->name, is_string($into->venue) ? $into->venue : '双店',
            "账号合并：".implode('、', $d['from'])." → {$into->name}（迁移 {$result['rows']} 行，别名 {$result['aliases']} 条）");

        return ok($result);
    }

    /**
     * GET /staff/options
     *
     * 归属下拉的**唯一数据源**：返回账号（而不是历史自由文本）。
     *
     * 留资/会员表单此前用的是「会员档案里出现过的 consultant 去重」—— 那等于让用户
     * 从一堆历史写法里再挑一个，新数据继续产生悬空名字。改成账号列表后，写进归属列的
     * 永远是某个真实账号的本名，选择本身就把 id 一起带上了。
     */
    public function staffOptions(Request $r)
    {
        $me = $r->user();
        $directory = staffDirectory();

        $list = [];
        foreach (User::query()->where('status', '!=', '停用')->orderBy('id')->get() as $u) {
            $list[] = [
                'id' => (int) $u->id,
                // staffName 是归属用的规范名（不是昵称），前端提交它的原值
                'name' => trim((string) $u->name),
                'aliases' => $directory->namesFor($u),
                'roles' => userRoles($u),
                'venue' => $u->venue,
                'self' => (int) $u->id === (int) $me->id,
            ];
        }

        return ok(['staff' => $list]);
    }


    /**
     * PUT /accounts/{key}/aliases
     *
     * 整体替换某账号的别名列表。别名全局唯一：一个名字只能属于一个账号，
     * 否则归属就是歧义的 —— 宁可在这里被拦住，也不要在查询时静默算错。
     */
    public function updateAliases(Request $r, string $key)
    {
        requireSuper($r);
        $user = User::where('username', $key)->first();
        abort_if($user === null, 404, '账号不存在');

        $d = $r->validate([
            'aliases' => 'present|array|max:20',
            // nullable：前端多选框留空行会传空串，Laravel 的 ConvertEmptyStringsToNull
            // 会把它变成 null。这种行按"没有"处理即可，不该 422 打断整个保存。
            'aliases.*' => 'nullable|string|max:60',
        ]);

        $aliases = [];
        foreach ($d['aliases'] as $a) {
            $a = trim((string) $a);
            if ($a === '' || $a === (string) $user->name) {
                continue; // 规范名不用登记（staffNames 恒把它算在内）
            }
            $aliases[$a] = true;
        }
        $aliases = array_keys($aliases);

        // 与别的账号冲突的直接报出来，不做静默丢弃。
        // 两类冲突都要拦：① 这个名字已是别人的别名；② 这个名字其实是别人的**本名**
        // —— 后者若放行，那个人的本名解析会被别名挡住，他的数据反而归到这边来。
        $conflict = StaffAlias::whereIn('alias', $aliases)
            ->where('user_id', '!=', $user->id)
            ->pluck('alias')
            ->all();
        $nameConflict = User::whereIn('name', $aliases)
            ->where('id', '!=', $user->id)
            ->pluck('name')
            ->all();
        $conflict = array_values(array_unique(array_merge($conflict, $nameConflict)));
        if ($conflict !== []) {
            abort(422, '这些名字已属于其它账号：'.implode('、', $conflict));
        }

        StaffAlias::where('user_id', $user->id)->delete();
        foreach ($aliases as $a) {
            StaffAlias::create(['user_id' => $user->id, 'alias' => $a, 'source' => 'manual']);
        }

        // 名字池变了：先丢请求内缓存，再回填历史行的归属 id
        staffDirectory()->flush();
        // 别名改了要把历史行的归属 id 补上 —— 迁移只在校验时跑过一次，
        // 管理员刚映射好的那些姓名对应的老数据还需要这一步才能真正落 id。
        $filled = backfillStaffOwnerIds();

        // 归属范围变了，相关缓存要失效
        invalidateBusinessCaches();

        return ok(['aliases' => $aliases, 'backfilled' => $filled]);
    }

    /**
     * 查看账号的微信绑定状态（超管）。
     *
     * 只返回「是否已绑定 / 绑定时间」，**不返回 openid**：openid 是可被冒用的身份键，
     * 一旦出现在管理端响应里，前端日志、浏览器缓存、截图都会把它带走。
     */
    public function wxBinding(Request $r, string $key)
    {
        requireSuper($r);
        $user = User::where('username', $key)->first();
        abort_if($user === null, 404, '账号不存在');

        return ok([
            'bound' => ! empty($user->wx_openid),
            'boundAt' => $user->wx_bound_at?->toIso8601String(),
            'hasUnionid' => ! empty($user->wx_unionid),
        ]);
    }

    /**
     * 管理员绑定微信身份（超管）。
     *
     * 为什么需要这一步：code2session 只返回 openid，而 openid 与「员工是谁」之间
     * 没有任何天然对应关系 —— 微信不给手机号，员工系统也不能开放注册。
     * 所以绑定必须由人来做：员工在自己微信里用账号密码登录过一次后，
     * 前端把该次登录拿到的 openid 报给管理员，由管理员写入对应账号。
     *
     * 幂等与冲突：
     *  - 同一个 openid 只能绑一个账号（唯一索引兜底，这里先做可读的 422）；
     *  - 重复绑定同一个账号视为成功，不报错（管理员点两次不该看到失败）。
     */
    public function bindWx(Request $r, string $key)
    {
        requireSuper($r);
        $user = User::where('username', $key)->first();
        abort_if($user === null, 404, '账号不存在');

        $d = $r->validate([
            'openid' => 'required|string|min:8|max:64',
            'unionid' => 'nullable|string|max:64',
        ]);

        $taken = User::where('wx_openid', $d['openid'])->where('id', '!=', $user->id)->first();
        // 注意：不能写成 abort_if($taken !== null, 422, '…'.$taken->name.'…')
        // —— abort_if 的 message 参数是无条件求值的，$taken 为 null 时会在
        // 「一切正常」的那条路径上炸掉（500）。必须先把消息拼好再 abort。
        if ($taken !== null) {
            abort(422, '该微信已绑定到账号「'.$taken->name.'」，请先解绑');
        }

        $attrs = [
            'wx_openid' => $d['openid'],
            'wx_bound_at' => $user->wx_bound_at ?? now(),
        ];
        if (! empty($d['unionid'])) {
            $attrs['wx_unionid'] = $d['unionid'];
        }
        // forceFill：wx_* 不在 $fillable 里，是有意防止 Mass Assignment 伪造身份
        $user->forceFill($attrs)->save();

        return ok(['bound' => true, 'boundAt' => $user->wx_bound_at?->toIso8601String()]);
    }

    /** 解除微信绑定（超管）。员工换微信、或绑错人时用。 */
    public function unbindWx(Request $r, string $key)
    {
        requireSuper($r);
        $user = User::where('username', $key)->first();
        abort_if($user === null, 404, '账号不存在');

        $user->forceFill([
            'wx_openid' => null,
            'wx_unionid' => null,
            'wx_bound_at' => null,
        ])->save();

        // 解绑后该员工已签发的 token 仍有效（会话是账号级的，不是微信级的），
        // 这是有意的：解绑不该把正在上班的人踢下线。要踢人用 disable。
        return ok(['bound' => false]);
    }

    /** POST /accounts */
    public function store(Request $r)
    {
        requireSuper($r);
        // 邮箱是可选字段：前端传空串（表单清空）等价于「不填」，先归一成 null 再校验 ——
        // `email` 规则对 '' 会判为格式错误，那是把「不填」当成了「填错」。
        // 也不再伪造 `{userName}@yimai.local`：那批假邮箱既不能收信，又会在人员列表里
        // 冒充真实联系方式，管理员无法区分「真邮箱」与「系统占位」。
        $r->merge(['email' => filled($r->input('email')) ? $r->input('email') : null]);
        $d = $r->validate([
            'userName' => 'required|string|max:20',
            'name' => 'nullable|string|max:20',
            // roles 与 roleCode 二选一：roles 是多角色入口，roleCode 保留给旧客户端
            'roles' => 'nullable|array|min:1',
            'roles.*' => 'string|in:R_SUPER,R_MANAGER,R_SERVICE,R_TEACHER,R_MEDIA',
            'roleCode' => 'nullable|string|in:R_SUPER,R_MANAGER,R_SERVICE,R_TEACHER,R_MEDIA',
            'venues' => 'required|array|min:1',
            'venues.*' => 'string|in:绿地店,东部店',
            // 唯一性保留：同一个邮箱不能挂两个账号（DB 上也有唯一索引兜底）。
            // null 不参与 unique 校验，所以「不填邮箱」的账号可以有很多个。
            'email' => 'nullable|email|max:60|unique:users,email',
            'password' => 'required|string|min:8|max:64',
        ]);
        abort_unless(User::where('username', $d['userName'])->doesntExist(), 422, '登录名已存在');

        // 兼容旧客户端：只传 roleCode 时按单角色处理
        $roles = array_values(array_unique($d['roles'] ?? array_filter([$d['roleCode'] ?? ''])));
        abort_if($roles === [], 422, '请至少选择一个角色');

        $name = trim((string) ($d['name'] ?? '')) !== '' ? trim((string) $d['name']) : $d['userName'];
        $venues = $d['venues'];
        // 含任一门店绑定角色（店长/服务老师/授课老师）即锁定到单一门店
        $bound = array_intersect($roles, self::VENUE_BOUND) !== [];
        $venue = $bound ? $venues[0] : null;
        $user = User::create([
            'name' => $name,
            'username' => $d['userName'],
            'email' => $d['email'] ?? null,
            'password' => $d['password'],
            'role' => primaryRole($roles),
            'roles' => $roles,
            'venue' => $venue,
            'venues' => $venues,
            'status' => '启用',
            // 新账号接在末尾（max+10）：手动顺序属于「业务顺序」，新人不该插到店长前面。
            // 没人拖过顺序时全员都是 0，这里给出 10 也仍然排在它们之后 —— 与 id 序一致。
            'sort_order' => (int) User::max('sort_order') + 10,
        ]);
        // 新账号建好就回填一次：如果业务数据里早就有同名（随心瑜/留资里写的就是这个真名），
        // 这一步会让那些行立刻带上 id —— 否则管理员要先开「身份归一」再点修复才生效。
        $backfilled = backfillStaffOwnerIds();
        invalidateBusinessCaches();

        audit($r, '新增', '人员管理', $user->id, $user->name, is_string($venue) ? $venue : '双店',
            "开通账号：{$user->name}（{$this->label($roles)}）/ 门店[".implode('、', $venues).']'
            .($backfilled > 0 ? "，回填历史归属 {$backfilled} 行" : ''));

        return ok(['key' => $user->username, 'backfilled' => $backfilled]);
    }

    /** PATCH /accounts/{key} */
    public function update(Request $r, string $key)
    {
        requireSuper($r);
        $user = User::where('username', $key)->firstOrFail();
        if ($user->id === $r->user()->id) {
            abort(422, '不能操作自己的账号');
        }

        $action = $r->input('action', 'update');
        $allowed = ['update', 'disable', 'enable', 'resetPassword', 'delete'];
        abort_unless(in_array($action, $allowed, true), 422, '未知操作');

        $d = $r->validate([
            'name' => 'nullable|string|max:20',
            'roles' => 'nullable|array|min:1',
            'roles.*' => 'string|in:R_SUPER,R_MANAGER,R_SERVICE,R_TEACHER,R_MEDIA',
            'roleCode' => 'nullable|string|in:R_SUPER,R_MANAGER,R_SERVICE,R_TEACHER,R_MEDIA',
            'venues' => 'nullable|array|min:1',
            'venues.*' => 'string|in:绿地店,东部店',
            'password' => 'nullable|string|min:8|max:64',
        ]);
        $detail = '';
        if ($action === 'delete') {
            $targetName = $user->name;
            $targetVenue = $user->venue;
            $user->tokens()->delete();
            $user->delete();
            audit($r, '删除', '人员管理', $user->id, $targetName, is_string($targetVenue) ? $targetVenue : '双店', "删除账号：{$targetName}（登录名 {$key}）");

            return ok(['ok' => true]);
        }
        if ($action === 'disable' || $action === 'enable') {
            $user->update(['status' => $action === 'disable' ? '停用' : '启用']);
            if ($action === 'disable') {
                $user->tokens()->delete();
            }
            $detail = $action === 'disable' ? '停用账号' : '启用账号';
        } elseif ($action === 'resetPassword') {
            abort_unless(! empty($d['password']), 422, '请输入新密码');
            $user->update(['password' => $d['password']]);
            $user->tokens()->delete();
            $detail = '重置密码';
        } else {
            $patch = [];
            /*
             * 改名（本次新增能力）。
             *
             * 归属列存的是姓名字符串，改完名之后**历史行的姓名列还写着旧名**，
             * 只改 users.name 会让这个人瞬间看不到自己的会员/客资/排课。
             * 所以改名必须同时把旧名登记为别名（一条记录，「这个账号以前叫这个」），
             * 让 `staffNames()` 的并集继续覆盖历史行。旧名若已被别的账号占用则跳过
             * （那是真实的同名冲突，不能强行抢），并在审计里说明。
             */
            $newName = trim((string) ($d['name'] ?? ''));
            $oldName = trim((string) $user->name);
            if ($newName !== '' && $newName !== $oldName) {
                abort_if(
                    User::where('name', $newName)->where('id', '!=', $user->id)->exists(),
                    422,
                    '姓名「'.$newName.'」已被其它账号使用'
                );
                $patch['name'] = $newName;
                if ($oldName !== ''
                    && StaffAlias::where('alias', $oldName)->doesntExist()
                    && User::where('name', $oldName)->where('id', '!=', $user->id)->doesntExist()) {
                    StaffAlias::create([
                        'user_id' => $user->id,
                        'alias' => $oldName,
                        'source' => 'rename',
                        'note' => '改名前自动登记（历史数据仍写着这个名字）',
                    ]);
                }
                $detail = "改名：{$oldName} → {$newName}";
            }
            // 兼容旧客户端：只传 roleCode 时按单角色处理
            $roles = $d['roles'] ?? (isset($d['roleCode']) && $d['roleCode'] !== '' ? [$d['roleCode']] : null);
            if ($roles !== null) {
                $roles = array_values(array_unique(array_filter($roles)));
                abort_if($roles === [], 422, '请至少选择一个角色');
                $before = userRoles($user);
                $patch['roles'] = $roles;
                $patch['role'] = primaryRole($roles);
                // 含门店绑定角色即锁定到单一门店；否则放开为双店
                $bound = array_intersect($roles, self::VENUE_BOUND) !== [];
                $patch['venue'] = $bound ? (($d['venues'] ?? $user->venues)[0] ?? null) : null;
                $detail = ($detail !== '' ? $detail.'；' : '').'角色调整：'.$this->label($before).' → '.$this->label($roles);
            }
            if (! empty($d['venues'])) {
                $patch['venues'] = $d['venues'];
            }
            if (! empty($patch)) {
                $user->update($patch);
                // 角色变了要立刻生效：清掉旧 token，避免前端还拿着旧角色继续用
                if (isset($patch['roles'])) {
                    $user->tokens()->delete();
                }
                // 改名后历史行仍写着旧名：立刻回填一次，把旧名对应的行补上 id，
                // 这样"按 id 过滤"的新路径也不会漏（姓名兜底只是保险，不是主路）。
                if (isset($patch['name'])) {
                    staffDirectory()->flush();
                    backfillStaffOwnerIds();
                    invalidateBusinessCaches();
                }
            }
            $detail = $detail ?: '资料更新';
        }
        audit($r, $action === 'resetPassword' ? '重置' : '修改', '人员管理', $user->id, $user->name, is_string($user->venue) ? $user->venue : '双店', $detail);

        return ok(['ok' => true]);
    }
}
