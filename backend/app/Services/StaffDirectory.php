<?php

namespace App\Services;

use App\Models\StaffAlias;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 人员身份中心：把「同一个人在各处的不同名字」收成一条身份。
 *
 * ## 为什么必须有一个中心
 *
 * 归属列（`leads.service_teacher` / `customers.consultant|owner` / `ky_bookings.teacher_name`
 * / `tasks.owner` / `training_plans.created_by` / `post_class_reviews.teacher_name`）
 * 存的是**姓名字符串**。同一个人在三个地方可能被写成三个名字：
 *
 * | 来源 | 典型写法 | 例子 |
 * | --- | --- | --- |
 * | 随心瑜登记 | 简称 / 花名 | `苏米`、`CC`、`嘉欣` |
 * | 留资/会员录入 | 下拉里的历史值（自由文本，可手输） | `蒙澍南`、`张芷晴` |
 * | 工作台账号 | 注册时填的真名 | `澍南`、`张娇娇` |
 *
 * 三个名字之间**没有任何可计算的等值关系** —— 只能靠人确认一次。确认之后的产物必须
 * 只有一份（`staff_aliases`），并且**回填只走一条路**，否则又会出现
 * 「会员页看到 30 人、排课只认出 20 人」这类同源分裂。
 *
 * 这个类就是那份唯一实现：
 *
 *  - {@see nameMap()}      名字 → 账号 id（全站唯一口径，含昵称与别名）
 *  - {@see backfill()}     把业务表的 `*_user_id` 补齐（历史行、新建账号后、改名后）
 *  - {@see assign()}       把一个名字并入某个账号（人员的"认领"动作）
 *  - {@see merge()}        把整个账号并进另一个账号（同一个人被建了两次时用）
 *  - {@see unmapped()}     业务数据里出现、但对不上任何账号的名字（悬空清单）
 *  - {@see coverage()}     各归属列 id 覆盖率（用来回答"到底还差多少行"）
 *
 * ## 为什么昵称也算名字
 *
 * 前端曾经把 `nickname` 写进归属列（`userInfo.userName` 优先昵称），而后端只按 `name`
 * 过滤 —— 那条数据对本人**直接消失**。事后把昵称整体登记进明细表是一次性补丁，
 * 这里让昵称成为名字池的一等公民，但**不与权威名争位**：昵称若与任何账号的本名或
 * 别名撞车，一律忽略（权威名优先），绝不让一个昵称把别人的数据抢走。
 */
class StaffDirectory
{
    /**
     * 归属列清单：表 → [姓名列 => id 列]。
     *
     * **唯一来源**。`helpers.php` 里的回填、未映射扫描，以及控制器里的合并都读它，
     * 加表/加列只改这里。`published_shares` 只有 id 列没有姓名列（销售分享行），
     * 它在 `staleOwnerUserIds()` 里单独登记。
     */
    public const OWNER_COLUMNS = [
        'leads' => [
            'service_teacher' => 'service_teacher_user_id',
            'trial_teacher' => 'trial_teacher_user_id',
            'created_by' => 'created_by_user_id',
        ],
        'customers' => [
            'consultant' => 'consultant_user_id',
            'owner' => 'owner_user_id',
        ],
        'ky_bookings' => [
            'teacher_name' => 'teacher_user_id',
        ],
        'tasks' => [
            'owner' => 'owner_user_id',
        ],
        'training_plans' => [
            'created_by' => 'created_by_user_id',
        ],
        'post_class_reviews' => [
            'teacher_name' => 'teacher_user_id',
        ],
    ];

    /** 列 → 人话标签（未映射清单与覆盖率面板共用，避免两处各写一套中文） */
    public const COLUMN_LABELS = [
        'customers.consultant' => '会员·会籍顾问',
        'customers.owner' => '会员·负责人',
        'leads.service_teacher' => '留资·会籍顾问',
        'leads.trial_teacher' => '留资·上课老师',
        'leads.created_by' => '留资·录入人',
        'ky_bookings.teacher_name' => '排课·上课老师',
        'tasks.owner' => '任务·负责人',
        'training_plans.created_by' => '训练计划·创建人',
        'post_class_reviews.teacher_name' => '课后分析·老师',
    ];

    /** 不是人名的占位符：不算未映射，也不做回填 */
    public const NON_PERSON_NAMES = ['未分配', '待分配', '待完善', '未确认', '门店', '门店（东部）', '—', '-'];

    /** @var array<string,int>|null 名字 → 账号 id */
    private ?array $mapCache = null;

    /** @var array<int, array{id:int,name:string,nickname:string,aliases:array<int,string>}>|null */
    private ?array $accountsCache = null;

    /** @var array<string,true> 与权威名/别名冲突、因此不可用于归属的昵称 */
    private array $blockedNicknames = [];

    /** @var array<string,array<int,string>> 名字 → 出现在哪些列（用于未映射清单） */
    private array $nameSourceCache = [];

    /**
     * 名字池版本号。每次 {@see flush()} 自增。
     *
     * 供 `staffNames()` 这类「按模型实例缓存」的调用方判断缓存是否新鲜：
     * 只清服务内部缓存不够，调用方手里的数组也会过期。
     */
    public int $version = 0;

    /** 丢弃请求内缓存（写完别名/合并后必须调用，否则同请求内后续判断还是旧口径） */
    public function flush(): void
    {
        $this->version++;
        $this->mapCache = null;
        $this->accountsCache = null;
        $this->blockedNicknames = [];
        $this->nameSourceCache = [];
    }

    /**
     * 全部账号（含各自的名字）。
     *
     * `nickname` 与 `name` 不同的会单列出来，前端要让人看见「这个账号还有一个昵称」，
     * 否则又会出现"昵称写过归属列、但没人知道该去补哪条映射"。
     *
     * @return array<int, array{id:int,name:string,nickname:string,aliases:array<int,string>,status:string,roles:array<int,string>,venue:?string}>
     */
    public function accounts(): array
    {
        if ($this->accountsCache !== null) {
            return $this->accountsCache;
        }

        $aliases = [];
        foreach (StaffAlias::query()->orderBy('id')->get(['user_id', 'alias']) as $row) {
            $a = trim((string) $row->alias);
            if ($a !== '') {
                $aliases[(int) $row->user_id][] = $a;
            }
        }

        $out = [];
        foreach (User::query()->orderBy('id')->get() as $u) {
            $out[(int) $u->id] = [
                'id' => (int) $u->id,
                // key = 登录名：前端「认领/合并」按它定位账号（id 不对外当主键用）
                'key' => (string) $u->username,
                'name' => trim((string) $u->name),
                'nickname' => trim((string) $u->nickname),
                'aliases' => $aliases[(int) $u->id] ?? [],
                'status' => (string) ($u->status ?? '启用'),
                'roles' => userRoles($u),
                'venue' => $u->venue,
            ];
        }

        return $this->accountsCache = $out;
    }

    /**
     * 该账号在归属列里可能出现的**全部名字**（本名 → 别名 → 安全昵称）。
     *
     * @return array<int,string>
     */
    public function namesFor(User $user): array
    {
        $accounts = $this->accounts();
        $id = (int) $user->id;
        $row = $accounts[$id] ?? null;

        $names = [];
        $canonical = $row['name'] ?? trim((string) $user->name);
        if ($canonical !== '') {
            $names[$canonical] = true;
        }
        foreach ($row['aliases'] ?? [] as $a) {
            $names[$a] = true;
        }
        $nickname = $row['nickname'] ?? trim((string) $user->nickname);
        if ($this->nicknameUsable($nickname)) {
            $names[$nickname] = true;
        }

        return array_keys($names);
    }

    /**
     * 名字 → 账号 id 的**全表映射**（歧义的名字不出现）。
     *
     * 优先级：本名 > 别名 > 昵称。昵称只有在不与任何本名/别名冲突时才进池。
     *
     * @return array<string,int>
     */
    public function nameMap(): array
    {
        if ($this->mapCache !== null) {
            return $this->mapCache;
        }

        $accounts = $this->accounts();
        $map = [];
        $ambiguous = [];

        // ① 本名（权威）
        foreach ($accounts as $id => $row) {
            $n = trim((string) $row['name']);
            if ($n === '') {
                continue;
            }
            if (isset($map[$n]) && $map[$n] !== $id) {
                $ambiguous[$n] = true;

                continue;
            }
            $map[$n] = $id;
        }

        // ② 别名（人工确认过的"这个人还可能被写成什么"）
        foreach ($accounts as $id => $row) {
            foreach ($row['aliases'] as $a) {
                $a = trim((string) $a);
                if ($a === '') {
                    continue;
                }
                if (isset($map[$a]) && $map[$a] !== $id) {
                    $ambiguous[$a] = true;

                    continue;
                }
                $map[$a] = $id;
            }
        }

        // ③ 昵称：只补空缺，绝不覆盖
        foreach ($accounts as $id => $row) {
            $n = trim((string) $row['nickname']);
            if ($n === '' || $n === trim((string) $row['name'])) {
                continue;
            }
            if (isset($map[$n]) || isset($ambiguous[$n])) {
                // 与权威名/别名撞车：昵称让位（并记下来，namesFor 也不会再把它算进去）
                $this->blockedNicknames[$n] = true;

                continue;
            }
            $map[$n] = $id;
        }

        foreach (array_keys($ambiguous) as $n) {
            unset($map[$n]);
        }

        return $this->mapCache = $map;
    }

    /** 该名字是否是被权威名挡下的昵称 */
    private function nicknameUsable(string $nickname): bool
    {
        if ($nickname === '') {
            return false;
        }
        $map = $this->nameMap();
        if (isset($this->blockedNicknames[$nickname])) {
            return false;
        }
        // 昵称本身已在池里 → 认（它就是这个账号的名字）
        return isset($map[$nickname]);
    }

    /**
     * 解析一个名字属于哪个账号（歧义/未登记返回 null）。口径与 {@see nameMap()} 一致。
     */
    public function resolve(?string $name): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $map = $this->nameMap();

        return $map[$name] ?? null;
    }

    /**
     * 业务数据里出现过、但对不上任何账号的姓名。
     *
     * 每条带 `counts`（出现在哪些列的多少行）、`suggestions`（疑似账号，按证据强度排序）
     * 与 `evidence`（为什么会这样猜）。**只给建议，不自动绑定** —— 同名不同人时有发生，
     * 绑错等于把客户名单交给另一个人。
     *
     * @return array<int, array{name:string, counts:array<string,int>, suggestions:array<int,array{id:int,key:string,name:string,reason:string,confidence:string}>}>
     */
    public function unmapped(): array
    {
        $known = $this->nameMap();
        $out = [];

        foreach (self::OWNER_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $nameColumn => $idColumn) {
                if (! Schema::hasColumn($table, $nameColumn)) {
                    continue;
                }
                $ref = "{$table}.{$nameColumn}";
                $label = self::COLUMN_LABELS[$ref] ?? $ref;

                $rows = DB::table($table)
                    ->select($nameColumn, DB::raw('count(*) as c'))
                    ->whereNotNull($nameColumn)
                    ->where($nameColumn, '!=', '')
                    ->groupBy($nameColumn)
                    ->get();

                foreach ($rows as $row) {
                    $name = trim((string) $row->{$nameColumn});
                    if ($name === '' || isset($known[$name]) || in_array($name, self::NON_PERSON_NAMES, true)) {
                        continue;
                    }
                    $out[$name] ??= ['name' => $name, 'counts' => []];
                    $out[$name]['counts'][$label] = ($out[$name]['counts'][$label] ?? 0) + (int) $row->c;
                }
            }
        }

        foreach ($out as $name => $item) {
            $out[$name]['suggestions'] = $this->suggest($name);
        }

        // 出现次数多的排前面：先修影响面大的
        uasort($out, fn ($a, $b) => array_sum($b['counts']) <=> array_sum($a['counts']));

        return array_values($out);
    }

    /**
     * 某个悬空姓名的疑似账号。
     *
     * 只用**可解释的证据**，不上模糊算法：
     *  1. `paired` —— 同一张表里这个名字的 id 列已指向某账号（同名列的部分行已绑定过），
     *     这是最硬的证据，说明有人或某次同步已经认出过它；
     *  2. `nickname` —— 恰好是某账号的昵称；
     *  3. `contains` —— 与某账号本名互相包含（≥2 字），如「蒙澍南」↔「澍南」、「张娇娇」↔「娇娇」。
     *
     * @return array<int, array{id:int,key:string,name:string,reason:string,confidence:string}>
     */
    public function suggest(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }
        $accounts = $this->accounts();
        $byId = [];
        $push = function (int $id, string $reason, string $confidence) use (&$byId, $accounts) {
            if (! isset($accounts[$id])) {
                return;
            }
            // 同一个人只保留最强的那条理由
            $rank = ['high' => 3, 'medium' => 2, 'low' => 1];
            if (isset($byId[$id]) && $rank[$byId[$id]['confidence']] >= $rank[$confidence]) {
                return;
            }
            $byId[$id] = [
                'id' => $id,
                'key' => $accounts[$id]['key'],
                'name' => $accounts[$id]['name'],
                'reason' => $reason,
                'confidence' => $confidence,
            ];
        };

        // ① 同表里已绑定过的同名行（id 列非空）
        foreach (self::OWNER_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $nameColumn => $idColumn) {
                if (! Schema::hasColumn($table, $idColumn)) {
                    continue;
                }
                $ids = DB::table($table)
                    ->where($nameColumn, $name)
                    ->whereNotNull($idColumn)
                    ->where($idColumn, '>', 0)
                    ->distinct()
                    ->pluck($idColumn)
                    ->all();
                foreach ($ids as $id) {
                    $push((int) $id, "{$table}.{$nameColumn} 已有同名行绑定到该账号", 'high');
                }
            }
        }

        // ② 昵称完全一致
        foreach ($accounts as $id => $row) {
            if ($row['nickname'] !== '' && $row['nickname'] === $name) {
                $push($id, '该账号的昵称就是这个名字', 'high');
            }
        }

        // ③ 与本名互相包含
        foreach ($accounts as $id => $row) {
            $canonical = $row['name'];
            if ($canonical === '' || $canonical === $name) {
                continue;
            }
            if (mb_strlen($canonical) >= 2 && mb_strlen($name) >= 2
                && (mb_strpos($canonical, $name) !== false || mb_strpos($name, $canonical) !== false)) {
                $push($id, "与本名「{$canonical}」互为包含关系", 'medium');
            }
        }

        $list = array_values($byId);
        usort($list, function ($a, $b) {
            $rank = ['high' => 3, 'medium' => 2, 'low' => 1];

            return $rank[$b['confidence']] <=> $rank[$a['confidence']];
        });

        return array_slice($list, 0, 5);
    }

    /**
     * 各归属列的 id 覆盖率。
     *
     * 回答的是「还有多少行归属是悬空的」。`withId` 只统计**非空 id**；姓名列为空的行
     * （待分配）不计入 total —— 它们没人可归，不是缺陷。
     *
     * @return array<int, array{ref:string,label:string,total:int,withId:int,missing:int}>
     */
    public function coverage(): array
    {
        $out = [];
        foreach (self::OWNER_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $nameColumn => $idColumn) {
                if (! Schema::hasColumn($table, $nameColumn) || ! Schema::hasColumn($table, $idColumn)) {
                    continue;
                }
                // 占位符（未分配 / 待完善 / 门店…）不是"悬空的归属"，它们本来就没有主人。
                // 不排除的话「会员·负责人」会显示 1800+ 行缺失，把真正的缺口淹掉 ——
                // 一个永远修不完的数字等于没有数字。
                $base = DB::table($table)
                    ->whereNotNull($nameColumn)
                    ->where($nameColumn, '!=', '')
                    ->whereNotIn($nameColumn, self::NON_PERSON_NAMES);
                $total = (int) (clone $base)->count();
                if ($total === 0) {
                    continue;
                }
                $withId = (int) (clone $base)->whereNotNull($idColumn)->count();
                $ref = "{$table}.{$nameColumn}";
                $out[] = [
                    'ref' => $ref,
                    'label' => self::COLUMN_LABELS[$ref] ?? $ref,
                    'total' => $total,
                    'withId' => $withId,
                    'missing' => $total - $withId,
                ];
            }
        }

        return $out;
    }

    /**
     * 把业务表的归属 id 补齐（可重复执行）。
     *
     * 只填**空值**，不改已有值：`id` 一旦落地就是人工/历史决定，覆盖它可能把数据
     * 从正确的人手里挪走。要改归属请走 {@see assign()} 或会员/留资编辑（那里会双写）。
     *
     * @return array{filled:int,byTable:array<string,int>}
     */
    public function backfill(): array
    {
        $map = $this->nameMap();
        $filled = 0;
        $byTable = [];

        foreach (self::OWNER_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $nameColumn => $idColumn) {
                if (! Schema::hasColumn($table, $nameColumn) || ! Schema::hasColumn($table, $idColumn)) {
                    continue;
                }
                foreach ($map as $name => $userId) {
                    $n = DB::table($table)
                        ->where($nameColumn, $name)
                        ->whereNull($idColumn)
                        ->update([$idColumn => $userId]);
                    if ($n > 0) {
                        $filled += $n;
                        $byTable[$table] = ($byTable[$table] ?? 0) + $n;
                    }
                }
            }
        }

        if ($filled > 0) {
            $this->flush();
        }

        return ['filled' => $filled, 'byTable' => $byTable];
    }

    /**
     * 把一个名字并入某个账号（"这个人还叫这个名字"），并立刻回填历史行。
     *
     * 这是管理员在「身份归一」面板上的**认领**动作：留资里写着 `CC`、工作台账号叫
     * `吴艳`，认领之后 `CC` 名下的客资/会员/排课立刻归到吴艳账号。
     *
     * @return array{alias:string,user:int,backfilled:int}
     */
    public function assign(string $name, int $userId, string $source = 'manual', string $note = ''): array
    {
        $name = trim($name);
        if ($name === '') {
            abort(422, '姓名不能为空');
        }
        $user = User::findOrFail($userId);

        if ($name === trim((string) $user->name)) {
            // 本名本来就属于该账号：不用登记，但历史行可能还缺 id
            $result = $this->backfill();

            return ['alias' => $name, 'user' => (int) $user->id, 'backfilled' => $result['filled']];
        }

        $taken = StaffAlias::where('alias', $name)->first();
        if ($taken !== null && (int) $taken->user_id !== (int) $user->id) {
            $owner = User::find($taken->user_id);
            abort(422, '「'.$name.'」已属于账号「'.($owner?->name ?? $taken->user_id).'」，请先解除');
        }

        // 与别人的本名撞车：别名一旦登记，那个人的本名解析就会被挡住
        $conflict = User::where('name', $name)->where('id', '!=', $user->id)->first();
        if ($conflict !== null) {
            abort(422, '「'.$name.'」是账号「'.$conflict->name.'」的本名，不能登记为别名');
        }

        if ($taken === null) {
            StaffAlias::create([
                'user_id' => $user->id,
                'alias' => $name,
                'source' => $source,
                'note' => $note,
            ]);
        }

        $this->flush();
        $result = $this->backfill();
        invalidateBusinessCaches();

        return ['alias' => $name, 'user' => (int) $user->id, 'backfilled' => $result['filled']];
    }

    /**
     * 把一个账号并进另一个账号（同一个人被建了两次、或旧账号要换成真名时用）。
     *
     * 做的事（顺序不能换）：
     *  1. 把 from 的别名改挂到 into（与本名/别人的别名冲突的跳过并报告）；
     *  2. 把业务表里 `姓名 in (from 的全部名字)` 的行**改名 + 改 id** 到 into；
     *  3. 停用 from（保留账号，不删：删了 `staleOwnerUserIds()` 会多出一批悬挂 id，
     *     而且历史审计日志还指着他）；
     *  4. 回填 + 清缓存。
     *
     * @param  array<int,int>  $fromIds
     * @param  bool  $deleteFrom  是否直接删除源账号（默认停用）
     * @return array{into:int,merged:array<int,int>,aliases:int,rows:int,skipped:array<int,string>}
     */
    public function merge(int $intoId, array $fromIds, bool $deleteFrom = false): array
    {
        $into = User::findOrFail($intoId);
        $fromIds = array_values(array_unique(array_map('intval', $fromIds)));
        $fromIds = array_values(array_filter($fromIds, fn ($id) => $id > 0 && $id !== (int) $into->id));

        if ($fromIds === []) {
            abort(422, '请选择要合并的账号');
        }

        $intoName = trim((string) $into->name);
        $movedAliases = 0;
        $movedRows = 0;
        $skipped = [];

        DB::transaction(function () use ($into, $intoName, $fromIds, $deleteFrom, &$movedAliases, &$movedRows, &$skipped) {
            foreach ($fromIds as $fromId) {
                $from = User::find($fromId);
                if ($from === null) {
                    $skipped[] = "账号 {$fromId} 不存在";

                    continue;
                }
                $fromNames = array_values(array_filter(array_unique(array_merge(
                    [trim((string) $from->name)],
                    StaffAlias::where('user_id', $from->id)->pluck('alias')->map(fn ($a) => trim((string) $a))->all()
                )), fn ($n) => $n !== ''));

                // ① 别名改挂
                foreach (StaffAlias::where('user_id', $from->id)->get() as $alias) {
                    $name = trim((string) $alias->alias);
                    if ($name === '' || $name === $intoName) {
                        $alias->delete();

                        continue;
                    }
                    $taken = StaffAlias::where('alias', $name)->where('user_id', '!=', $into->id)->first();
                    if ($taken !== null) {
                        $skipped[] = "别名「{$name}」已属于别的账号";

                        continue;
                    }
                    $alias->user_id = $into->id;
                    $alias->save();
                    $movedAliases++;
                }

                // from 的本名也保留为 into 的别名（否则历史行改名后就查不到出处了）。
                // 判断"是不是别人的本名"时必须**排除本批被合并的账号** —— 它们此刻还没停用，
                // 否则会把自己当成"别人"，别名永远建不出来（合并后旧名就再也搜不到）。
                $fromName = trim((string) $from->name);
                if ($fromName !== '' && $fromName !== $intoName
                    && StaffAlias::where('alias', $fromName)->doesntExist()
                    && User::where('name', $fromName)
                        ->whereNotIn('id', $fromIds)
                        ->where('id', '!=', $into->id)
                        ->doesntExist()) {
                    StaffAlias::create([
                        'user_id' => $into->id,
                        'alias' => $fromName,
                        'source' => 'merge',
                        'note' => '账号合并时自动登记（原账号 '.$from->username.'）',
                    ]);
                    $movedAliases++;
                }

                // ② 业务行改名 + 改 id
                foreach (self::OWNER_COLUMNS as $table => $columns) {
                    if (! Schema::hasTable($table)) {
                        continue;
                    }
                    foreach ($columns as $nameColumn => $idColumn) {
                        if (! Schema::hasColumn($table, $nameColumn)) {
                            continue;
                        }
                        $patch = [$nameColumn => $intoName];
                        if (Schema::hasColumn($table, $idColumn)) {
                            $patch[$idColumn] = (int) $into->id;
                        }
                        $movedRows += DB::table($table)->whereIn($nameColumn, $fromNames)->update($patch);
                    }
                }

                // ③ 停用 / 删除
                $from->tokens()->delete();
                if ($deleteFrom) {
                    $from->delete();
                } else {
                    $from->update(['status' => '停用']);
                }
            }
        });

        $this->flush();
        $result = $this->backfill();
        invalidateBusinessCaches();

        return [
            'into' => (int) $into->id,
            'merged' => $fromIds,
            'aliases' => $movedAliases,
            'rows' => $movedRows,
            'backfilled' => $result['filled'],
            'skipped' => $skipped,
        ];
    }

    /**
     * 该账号拥有的数据行数（按姓名 + id 两条路并集），用于人员列表直观显示
     * 「这个人现在能看到多少」—— 改名/认领后这个数字会立刻变化。
     *
     * @return array<string,int>
     */
    public function footprint(User $user): array
    {
        $names = $this->namesFor($user);
        $out = [];

        foreach (self::OWNER_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $nameColumn => $idColumn) {
                if (! Schema::hasColumn($table, $nameColumn)) {
                    continue;
                }
                $ref = "{$table}.{$nameColumn}";
                $label = self::COLUMN_LABELS[$ref] ?? $ref;
                $q = DB::table($table);
                $q->where(function ($w) use ($q, $table, $nameColumn, $idColumn, $names, $user) {
                    if ($names !== []) {
                        $w->whereIn($nameColumn, $names);
                    }
                    if (Schema::hasColumn($table, $idColumn)) {
                        $w->orWhere($idColumn, (int) $user->id);
                    }
                });
                $count = (int) $q->count();
                if ($count > 0) {
                    $out[$label] = ($out[$label] ?? 0) + $count;
                }
            }
        }

        return $out;
    }

    /**
     * 悬空 / 过期 id 的清理（账号删掉重建留下的悬挂 id）。
     *
     * 只清「id 指向不存在的账号」的行：把它置空，姓名那一路仍能让本人看到
     * （`staffOwnerFilter` 是并集），下一轮 {@see backfill()} 会按名字补上正确的 id。
     */
    public function clearStale(): int
    {
        $valid = User::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $valid = array_flip($valid);
        $cleared = 0;

        foreach (self::OWNER_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $idColumn) {
                if (! Schema::hasColumn($table, $idColumn)) {
                    continue;
                }
                $ids = DB::table($table)->whereNotNull($idColumn)->distinct()->pluck($idColumn)->all();
                $stale = array_values(array_filter($ids, fn ($id) => (int) $id > 0 && ! isset($valid[(int) $id])));
                foreach (array_chunk($stale, 500) as $chunk) {
                    $cleared += DB::table($table)->whereIn($idColumn, $chunk)->update([$idColumn => null]);
                }
            }
        }

        if ($cleared > 0) {
            $this->flush();
        }

        return $cleared;
    }

    /** 供测试注入/局部定制（生产代码不调用） */
    public function withMap(Closure $fn): mixed
    {
        return $fn($this->nameMap());
    }
}
