<?php

/**
 * 一麦工作台 · 全局业务辅助函数（原 routes/api.php helpers 区块迁入）
 * 经 composer autoload.files 加载，控制器与路由均可直接调用。
 */

use App\Models\Approval;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\Lead;
use App\Models\ModelGenerationRecord;
use App\Models\SyncArtifact;
use App\Models\SyncJob;
use App\Models\StaffAlias;
use App\Models\Task;
use App\Models\User;
use App\Services\KyMemberSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

function ok($data)
{
    return response()->json(['code' => 0, 'data' => $data]);
}

function requireSuper(Request $request): void
{
    abort_unless(userHasRole($request->user(), 'R_SUPER'), 403, '仅超管可执行此操作');
}

/** 配置单例访问器：带锁获取唯一 AppSetting 行，规避并发 firstOrCreate 造出重复行后读到陈旧副本 */
function setting(): AppSetting
{
    $existing = AppSetting::oldest('id')->first();
    if ($existing) {
        return $existing;
    }
    $lock = Cache::lock('appsetting:singleton', 10);

    return $lock->get(fn () => AppSetting::firstOrCreate([])) ?? AppSetting::oldest('id')->firstOrFail();
}

function profilePayload(User $user): array
{
    return [
        'name' => $user->name,
        'nickname' => $user->nickname,
        'phone' => $user->phone,
        'avatar' => $user->avatar,
        'email' => $user->email,
        'role' => primaryRole(userRoles($user)),
        'roles' => userRoles($user),
        'venues' => $user->venues ?? [],
        'profile' => (array) ($user->profile ?? []),
    ];
}

function syncJobPayload(SyncJob $job): array
{
    $range = (array) ($job->date_range ?? []);
    $from = (string) ($range['from'] ?? '');
    $to = (string) ($range['to'] ?? '');
    $payload = camel($job);
    $payload['dateRange'] = $from && $to ? ($from === $to ? $from : "{$from} ~ {$to}") : '会员/卡项全量';
    $payload['startedAt'] = optional($job->started_at)->format('Y-m-d H:i:s');
    $payload['finishedAt'] = optional($job->finished_at)->format('Y-m-d H:i:s');
    $payload['artifactsCount'] = (int) ($job->artifacts_count ?? 0);

    return $payload;
}

function syncArtifactPayload(SyncArtifact $artifact): array
{
    return [
        'id' => $artifact->id,
        'type' => $artifact->artifact_type,
        'displayName' => $artifact->display_name,
        'rowCount' => $artifact->row_count,
        'size' => $artifact->size,
        'sha256' => $artifact->sha256,
        'dateFrom' => optional($artifact->date_from)->toDateString(),
        'dateTo' => optional($artifact->date_to)->toDateString(),
        'isFull' => $artifact->is_full,
        'createdAt' => optional($artifact->created_at)->format('Y-m-d H:i:s'),
    ];
}

function finishModelRecord(ModelGenerationRecord $record, string $status, float $startedAt, string $preview = '', ?string $error = null, array $usage = []): void
{
    $record->update([
        'status' => $status,
        'latency_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
        'input_tokens' => isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
        'output_tokens' => isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
        'total_tokens' => isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : null,
        'output_preview' => mb_substr($preview, 0, 1000),
        'error_message' => $error ? mb_substr($error, 0, 500) : null,
        'completed_at' => now(),
    ]);
}

function ssePreview(string $raw): string
{
    $preview = '';
    foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
        if (! str_starts_with($line, 'data:')) {
            continue;
        }
        $json = trim(substr($line, 5));
        if ($json === '' || $json === '[DONE]') {
            continue;
        }
        $payload = json_decode($json, true);
        $preview .= (string) ($payload['choices'][0]['delta']['content'] ?? $payload['choices'][0]['message']['content'] ?? '');
        if (mb_strlen($preview) >= 1000) {
            break;
        }
    }

    return mb_substr($preview, 0, 1000);
}

function retentionSettings(): array
{
    return array_merge([
        'systemLogDays' => 7,
        'auditLogDays' => 180,
        'modelGenerationDays' => 90,
    ], (array) (AppSetting::oldest('id')->first()?->retention ?? []));
}

function pruneSystemRecords(): array
{
    $settings = retentionSettings();
    $deleted = ['systemLogs' => 0, 'auditLogs' => 0, 'modelGenerations' => 0, 'cacheRows' => 0];
    if ($settings['auditLogDays'] !== null) {
        $deleted['auditLogs'] = AuditLog::where('time', '<', now()->subDays((int) $settings['auditLogDays']))->delete();
    }
    if ($settings['modelGenerationDays'] !== null) {
        $deleted['modelGenerations'] = ModelGenerationRecord::where('created_at', '<', now()->subDays((int) $settings['modelGenerationDays']))->delete();
    }
    if ($settings['systemLogDays'] !== null) {
        $cutoff = now()->subDays((int) $settings['systemLogDays'])->getTimestamp();
        foreach (array_merge(glob(storage_path('logs/runtime*.log')) ?: [], glob(storage_path('logs/error*.log')) ?: []) as $file) {
            if (filemtime($file) < $cutoff && @unlink($file)) {
                $deleted['systemLogs']++;
            }
        }
    }

    // 清理已过期的缓存行。
    //
    // database 驱动的过期键只是「读不到」，行本身不会消失；而业务缓存键带着版本号
    // （member_lists:v12:…），每次写操作失效后都会换一批新键，旧键 120 秒后过期却永远
    // 留在 cache 表里 —— 在 2H2G 的机器上这是持续增长的磁盘占用（也是每次业务缓存读
    // 都要查的那张表）。`Cache::forever` 写的版本号键 expiration 是 9999999999，不受影响。
    if (config('cache.default') === 'database') {
        $table = (string) config('cache.stores.database.table', 'cache');
        if (Schema::hasTable($table)) {
            $deleted['cacheRows'] = DB::table($table)
                ->where('expiration', '<', now()->subHour()->getTimestamp())
                ->delete();
        }
    }

    return $deleted;
}

function isOnlineLead(Lead $lead): bool
{
    $text = implode(' ', [(string) $lead->source, (string) $lead->order_platform]);

    return (bool) preg_match('/美团|大众点评|抖音|小红书|视频号|线上|团购/u', $text);
}

/**
 * KeepYoga 同步留痕到「系统日志 → 运行日志」。
 * 手动触发与系统定时都走这里，后台日志能看到同一批次是谁、以什么方式、同步了哪家店、结果如何。
 * 失败的详细堆栈仍由调用方写 system_error，这里只保证「执行过」这件事在运行日志可见。
 */
function logSyncRun(SyncJob $job, string $trigger): void
{
    try {
        $level = match ($job->status) {
            '成功' => 'info',
            '进行中' => 'info',
            default => 'warning',
        };
        Log::channel('runtime')->log($level, 'KeepYoga 同步执行', [
            'trigger' => $trigger,
            'batch' => $job->batch_no,
            'venue' => $job->venue,
            'operator' => $job->operator,
            'status' => $job->status,
            'job_id' => $job->id,
            'started_at' => optional($job->started_at)->format('Y-m-d H:i:s'),
            'finished_at' => optional($job->finished_at)->format('Y-m-d H:i:s'),
            'detail' => mb_substr((string) $job->detail, 0, 300),
            'error' => mb_substr((string) $job->error_message, 0, 300),
        ]);
    } catch (Throwable $e) {
        // 日志写入失败不能反过来打断同步本身
        Log::channel('system_error')->error('同步运行日志写入失败', [
            'job_id' => $job->id, 'error' => $e->getMessage(),
        ]);
    }
}

function contractRows(array $response): array
{
    $data = $response['data'] ?? [];
    if (array_is_list($data)) {
        return array_values(array_filter($data, 'is_array'));
    }
    foreach (['contracts', 'list', 'rows', 'items', 'data'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            return array_values(array_filter($data[$key], 'is_array'));
        }
    }

    return [];
}

function contractPartyState(array $row, string $party): string
{
    // 随心瑜真实字段（getallcontractlist 实测）：customer_signatory_status / venue_signatory_status，0=未签、2=已签
    $direct = (string) ($row[($party === 'customer' ? 'customer' : 'venue').'_signatory_status'] ?? '');
    if ($direct !== '') {
        if ($direct === '2' || preg_match('/已签署|签署完成|已完成|completed|signed/i', $direct)) {
            return 'completed';
        }
        if ($direct === '0' || preg_match('/未签署|待签署|待会员签署|待场馆签署|签署中|incomplete|pending|unsigned/i', $direct)) {
            return 'incomplete';
        }

        return 'unknown';
    }

    // 兜底：历史猜测字段（*_sign_status 等）
    $prefixes = $party === 'customer' ? ['customer', 'member', 'm'] : ['venue', 'gym'];
    $complete = '/已签署|签署完成|已完成|completed|signed/i';
    $incomplete = '/未签署|待签署|待会员签署|待场馆签署|签署中|incomplete|pending|unsigned/i';
    foreach ($prefixes as $prefix) {
        foreach (["{$prefix}_sign_status", "{$prefix}_signature_status", "{$prefix}_sign_status_desc"] as $key) {
            $value = (string) ($row[$key] ?? '');
            if ($value !== '' && preg_match($complete, $value)) {
                return 'completed';
            }
            if ($value !== '' && preg_match($incomplete, $value)) {
                return 'incomplete';
            }
        }
        foreach (["{$prefix}_sign_time", "{$prefix}_signed_at", "{$prefix}_signature_id"] as $key) {
            if (! empty($row[$key])) {
                return 'completed';
            }
        }
    }

    return 'unknown';
}

function businessNotifications(User $user): array
{
    $items = [];
    $taskQ = Task::query()->whereNotIn('status', ['已完成']);
    // 多角色按「最大范围」取任务范围：店长看本店，老师侧看本人，新媒体看本人
    if (userHasRole($user, 'R_MANAGER')) {
        $taskQ->where('venue', $user->venue);
    } elseif (userIsTeacherSide($user)) {
        $taskQ->where('venue', $user->venue)->where(staffOwnerFilter($user, 'owner_user_id', 'owner'));
    } elseif (userHasRole($user, 'R_MEDIA')) {
        $taskQ->where(staffOwnerFilter($user, 'owner_user_id', 'owner'));
    }
    $taskCount = $taskQ->count();
    if ($taskCount > 0) {
        $items[] = ['key' => 'tasks-'.$taskCount, 'category' => 'todo', 'level' => 'warning', 'title' => "有 {$taskCount} 项任务待处理", 'detail' => userHasRole($user, 'R_SUPER') ? '双店任务' : ($user->venue ?: '本人任务'), 'path' => '/yimai/tasks'];
    }
    if (userHasRole($user, 'R_SUPER') || userHasRole($user, 'R_MANAGER') || userIsTeacherSide($user)) {
        $customerQ = scopeCustomersForUser(Customer::query(), $user)->whereIn('id', filteredIds('待续课'));
        $renewals = $customerQ->count();
        if ($renewals > 0) {
            $items[] = ['key' => 'renewals-'.$renewals, 'category' => 'todo', 'level' => 'high', 'title' => "有 {$renewals} 位会员进入待续课", 'detail' => '请完成评估并明确下一步动作', 'path' => '/yimai/members'];
        }
    }
    if (userHasRole($user, 'R_SUPER') || userHasRole($user, 'R_MANAGER')) {
        $approvalQ = Approval::where('status', 'like', '待%');
        if (! userHasRole($user, 'R_SUPER') && userHasRole($user, 'R_MANAGER')) {
            $approvalQ->where('venue', $user->venue);
        }
        $approvals = $approvalQ->count();
        if ($approvals > 0) {
            $items[] = ['key' => 'approvals-'.$approvals, 'category' => 'notice', 'level' => 'warning', 'title' => "有 {$approvals} 项价格审批待处理", 'detail' => '审批中心', 'path' => '/yimai/approvals'];
        }
    }
    if (userHasRole($user, 'R_SUPER')) {
        $lastSync = SyncJob::where('status', '成功')->latest('finished_at')->first();
        if ($lastSync) {
            $items[] = ['key' => 'sync-'.$lastSync->id, 'category' => 'notice', 'level' => 'info', 'title' => '最近一次 KeepYoga 同步已完成', 'detail' => (string) $lastSync->finished_at, 'path' => '/yimai/sync'];
        }
    }
    if (userHasRole($user, 'R_MEDIA') && ! userHasAnyRole($user, ['R_SUPER', 'R_MANAGER']) && ! userIsTeacherSide($user)) {
        $newLeads = Lead::where(staffOwnerFilter($user, 'created_by_user_id', 'created_by'))
            ->where('status', '新留资')->count();
        if ($newLeads > 0) {
            $items[] = ['key' => 'media-leads-'.$newLeads, 'category' => 'message', 'level' => 'info', 'title' => "你录入的 {$newLeads} 条新客资待承接", 'detail' => '新媒体客资', 'path' => '/yimai/leads'];
        }
    }

    return $items;
}

/**
 * 角色口径（2026-09 拆分 + 多角色叠加）：
 *  - R_SERVICE  服务老师 ＝ 会籍顾问。看得见的是「挂在自己名下的会员和客资」。
 *  - R_TEACHER  授课老师 ＝ 私教主教练。看得见「自己上过私教课的学员」＋「挂在自己名下的会籍会员」。
 *               小班、团课不计入「我的学员」（用户明确口径）。
 *  - R_MANAGER  店长看本店全部；R_SUPER 看双店；R_MEDIA 只看 P5 留资与自己录入的客资。
 *
 * 一个账号可以拥有多个角色，可见范围取并集；店长/超管这类更大范围会自然吸收小范围
 * （业务确认：服务老师 + 授课老师 = 会员并集；再叠加店长 = 本店全部）。
 */

/** 角色展示名 */
function roleLabel(?string $role): string
{
    return match ($role) {
        'R_SUPER' => '超管',
        'R_MANAGER' => '店长',
        'R_SERVICE' => '服务老师',
        'R_TEACHER' => '授课老师',
        'R_MEDIA' => '新媒体',
        default => (string) $role,
    };
}

/** 按权限从大到小排序，用于选「主角色」与多角色展示 */
const ROLE_PRECEDENCE = ['R_SUPER', 'R_MANAGER', 'R_TEACHER', 'R_SERVICE', 'R_MEDIA'];

/**
 * 账号的全部角色。
 *
 * 以 roles 数组为准；老数据或尚未回填时回退到单值 role，两者都空则返回空数组。
 * 这样迁移到一半、或某个写入路径漏写 roles 时，权限不会突然放大或丢失。
 */
function userRoles(?User $user): array
{
    if (! $user) {
        return [];
    }
    $roles = is_array($user->roles) ? $user->roles : [];
    $roles = array_values(array_filter(array_map('strval', $roles)));
    if ($roles === [] && (string) $user->role !== '') {
        $roles = [(string) $user->role];
    }

    return $roles;
}

/** 是否拥有某角色 */
function userHasRole(?User $user, string $role): bool
{
    return in_array($role, userRoles($user), true);
}

/** 是否拥有其中任一角色 */
function userHasAnyRole(?User $user, array $roles): bool
{
    return array_intersect($roles, userRoles($user)) !== [];
}

/** 主角色：按权限从大到小取第一个命中的（用于展示与 role 单值列回写） */
function primaryRole(array $roles): string
{
    foreach (ROLE_PRECEDENCE as $r) {
        if (in_array($r, $roles, true)) {
            return $r;
        }
    }

    return '';
}

/** 是否为「老师侧」角色（服务老师 / 授课老师）——涉及会员与客资的按人隔离 */
function isTeacherSide(?string $role): bool
{
    return in_array($role, ['R_SERVICE', 'R_TEACHER'], true);
}

/**
 * 本地门店名 → 随心瑜 venue_id。
 *
 * 单一来源在 config/services.php 的 ky.stores（可用 KY_STORES 环境变量覆盖）。
 * 之前这份映射硬编码在 KyController 的三个方法里，加店要改三处、漏一处就会出现
 * "某店在合同页有数据、在经营概览里没有"这种很难查的现象。
 */
function kyStores(): array
{
    return config('services.ky.stores') ?: [];
}

/** 该账号是否属于老师侧（多角色任一命中） */
function userIsTeacherSide(?User $user): bool
{
    return userHasAnyRole($user, ['R_SERVICE', 'R_TEACHER']);
}

/**
 * 姓名（含别名）→ 账号 id。**唯一命中才返回**，歧义或对不上返回 null。
 *
 * 写入归属时用它把 id 一起落库（`service_teacher` 与 `service_teacher_user_id` 双写）：
 * id 不随改名变化，也不怕同名，是归属判断的长期依据。
 *
 * 不缓存：规范名与别名都建了索引，一两次查询成本很低；而函数级 static 在长驻进程／
 * 测试串跑时会串数据（同 `privateStudentKeys()` 的注释）。
 */
function staffUserId(?string $name): ?int
{
    $name = trim((string) $name);
    if ($name === '') {
        return null;
    }

    $hits = User::where('name', $name)->limit(2)->pluck('id');
    if ($hits->count() === 1) {
        return (int) $hits->first();
    }
    if ($hits->count() > 1) {
        return null; // 同名多账号：宁可不认，也不猜
    }

    $aliases = StaffAlias::where('alias', $name)->limit(2)->pluck('user_id');

    return $aliases->count() === 1 ? (int) $aliases->first() : null;
}

/**
 * 一次取出「姓名/别名 → 账号 id」全表映射，供同步这类批量写入使用。
 *
 * 歧义的名字（对上多个账号）不出现在结果里 —— 调用方拿不到就写空，该行仍能靠
 * 「姓名 + 别名」这条路被本人看到，不会掉数据。
 *
 * @return array<string, int>
 */
function staffNameToIdMap(): array
{
    $map = [];
    $ambiguous = [];

    foreach (User::orderBy('id')->get(['id', 'name']) as $u) {
        $n = trim((string) $u->name);
        if ($n !== '') {
            isset($map[$n]) ? $ambiguous[$n] = true : $map[$n] = (int) $u->id;
        }
    }
    foreach (StaffAlias::orderBy('id')->get(['user_id', 'alias']) as $a) {
        $n = trim((string) $a->alias);
        if ($n !== '') {
            isset($map[$n]) ? $ambiguous[$n] = true : $map[$n] = (int) $a->user_id;
        }
    }
    foreach (array_keys($ambiguous) as $n) {
        unset($map[$n]);
    }

    return $map;
}

/**
 * 归属条件：**id 命中，或姓名/别名命中**（两者取并集）。
 *
 * ## 为什么姓名这一路不能省
 *
 * 曾经写成「id 命中，或（**id 为空**且姓名命中）」—— 想避免"id 已指明 A、姓名还写着 B"
 * 时两个人同时看到同一条数据。但那种写法有个静默得多的失败模式：**id 悬挂时会永久挡住
 * 姓名兜底**。账号被删掉重建后，老数据的 `*_user_id` 指向已不存在的账号，id 不为空、
 * 姓名又明明对得上 —— 结果是这个人永远看不到这批数据，而且「归属映射」名单也发现不了
 * （姓名能对上账号，不会被列为未映射）。
 *
 * **丢数据和重复可见之间，选重复可见**：重复至少能被发现（两个人都会去跟同一条客户），
 * 丢了就是彻底看不见。实际写入是双写的，id 与姓名指向不同人只可能来自删除重建或历史脏
 * 数据 —— 这种情形由「归属映射」面板的异常归属提示来暴露，而不是靠查询时静默取舍。
 *
 * @return \Closure(\Illuminate\Database\Eloquent\Builder): void
 */
function staffOwnerFilter(User $user, string $idColumn, string $nameColumn): Closure
{
    $names = staffNames($user);

    return function ($q) use ($user, $idColumn, $nameColumn, $names) {
        $q->where($idColumn, $user->id)->orWhereIn($nameColumn, $names);
    };
}

/**
 * 单条记录是否属于该账号（内存判断，口径与 `staffOwnerFilter` 完全一致）：id 或姓名任一命中。
 */
function staffOwnsRow(User $user, $row, string $idAttribute, string $nameAttribute): bool
{
    // 与 staffOwnerFilter 同口径：id 或姓名任一命中即算本人的（并集，不因 id 悬挂而漏）
    if ((int) ($row->{$idAttribute} ?? 0) === (int) $user->id) {
        return true;
    }

    return in_array(trim((string) ($row->{$nameAttribute} ?? '')), staffNames($user), true);
}

/**
 * 归属 id 指向了**不存在的账号**的行（账号删掉重建、或历史脏数据留下的悬挂 id）。
 *
 * 这类行不会丢数据（姓名那一路仍能让本人看到，见 `staffOwnerFilter`），但属于需要清理的
 * 历史包袱：id 不清掉，将来同一批数据可能被两个人同时认领。把它列在
 * 「人员管理 → 归属映射」里让管理员能看见、能处理，而不是靠查询时静默取舍。
 *
 * @return array<int, array{table: string, column: string, user_id: int, rows: int}>
 */
function staleOwnerUserIds(): array
{
    $columns = [
        'leads' => ['service_teacher_user_id', 'trial_teacher_user_id', 'created_by_user_id'],
        'customers' => ['consultant_user_id', 'owner_user_id'],
        'ky_bookings' => ['teacher_user_id'],
        'tasks' => ['owner_user_id'],
        'training_plans' => ['created_by_user_id'],
        'post_class_reviews' => ['teacher_user_id'],
    ];

    $valid = User::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    $valid = array_flip($valid);

    $out = [];
    foreach ($columns as $table => $cols) {
        if (! Schema::hasTable($table)) {
            continue;
        }
        foreach ($cols as $col) {
            if (! Schema::hasColumn($table, $col)) {
                continue;
            }
            $rows = DB::table($table)
                ->select($col, DB::raw('count(*) as c'))
                ->whereNotNull($col)
                ->groupBy($col)
                ->get();
            foreach ($rows as $r) {
                $id = (int) $r->{$col};
                if ($id > 0 && ! isset($valid[$id])) {
                    $out[] = ['table' => $table, 'column' => $col, 'user_id' => $id, 'rows' => (int) $r->c];
                }
            }
        }
    }

    return $out;
}

/**
 * 归属列的 id 回填（迁移里那份逻辑的可重入版本）。
 *
 * 为什么需要它：管理员在「人员管理 → 归属映射」里补了别名之后，**历史行的 id 不会自己出现**
 * ——迁移在校验时已经跑过一次了。所以改完别名要能再回填一次，把刚对上的行补上 id。
 * 返回补了多少行。
 */
function backfillStaffOwnerIds(): int
{
    /** 表 → [姓名列 => id 列]（与迁移 add_owner_user_ids 保持一致） */
    $columns = [
        'leads' => ['service_teacher' => 'service_teacher_user_id', 'trial_teacher' => 'trial_teacher_user_id', 'created_by' => 'created_by_user_id'],
        'customers' => ['consultant' => 'consultant_user_id', 'owner' => 'owner_user_id'],
        'ky_bookings' => ['teacher_name' => 'teacher_user_id'],
        'tasks' => ['owner' => 'owner_user_id'],
        'training_plans' => ['created_by' => 'created_by_user_id'],
        'post_class_reviews' => ['teacher_name' => 'teacher_user_id'],
    ];

    $map = staffNameToIdMap();
    $filled = 0;

    foreach ($columns as $table => $pairs) {
        if (! Schema::hasTable($table)) {
            continue;
        }
        foreach ($pairs as $nameColumn => $idColumn) {
            if (! Schema::hasColumn($table, $nameColumn) || ! Schema::hasColumn($table, $idColumn)) {
                continue;
            }
            foreach ($map as $name => $userId) {
                $filled += DB::table($table)
                    ->where($nameColumn, $name)
                    ->whereNull($idColumn)
                    ->update([$idColumn => $userId]);
            }
        }
    }

    return $filled;
}

/**
 * 该账号在业务归属字段里可能出现的**全部名字**。
 *
 * 归属列（`leads.service_teacher` / `customers.consultant|owner` /
 * `ky_bookings.teacher_name` / `tasks.owner` / `training_plans.created_by` …）存的是
 * 姓名字符串而不是外键，而姓名会变：改过名、随心瑜登记的是另一个姓名、
 * 历史数据里写过 nickname。只比 `users.name` 会把这些数据判成"不是本人的"。
 *
 * 所以判断归属时一律用本函数取全集，再 `whereIn`：
 *
 *     $names = staffNames($user);
 *     $q->whereIn('consultant', $names)->orWhereIn('owner', $names);
 *
 * 别名在 `staff_aliases` 表里维护（人员管理页可改），规范名 `users.name` 恒算在内。
 * 结果挂在 User 实例上做请求内缓存；理由同 `privateStudentKeys()`。
 */
function staffNames(User $user): array
{
    if (isset($user->staffNamesCache)) {
        return $user->staffNamesCache;
    }

    $names = [];
    $canonical = trim((string) $user->name);
    if ($canonical !== '') {
        $names[$canonical] = true;
    }

    if ($user->id) {
        foreach (StaffAlias::where('user_id', $user->id)->pluck('alias') as $alias) {
            $alias = trim((string) $alias);
            if ($alias !== '') {
                $names[$alias] = true;
            }
        }
    }

    return $user->staffNamesCache = array_keys($names);
}

/**
 * 业务数据里出现过、但对不上任何账号的归属姓名。
 *
 * 用来在人员管理页提示"这些名字还没映射到账号"。只要它非空，就说明有数据的归属
 * 是悬空的 —— 那个（那些）人看不到自己的会员/客资/任务，管理员补一条别名即可修好。
 *
 * @return array<string, array{name: string, counts: array<string, int>}>
 */
function unmappedStaffNames(): array
{
    $known = [];
    foreach (User::query()->pluck('name') as $n) {
        $n = trim((string) $n);
        if ($n !== '') {
            $known[$n] = true;
        }
    }
    foreach (StaffAlias::query()->pluck('alias') as $a) {
        $a = trim((string) $a);
        if ($a !== '') {
            $known[$a] = true;
        }
    }

    // 归属列 → 展示用的中文来源名
    $columns = [
        'customers.consultant' => '会员·会籍顾问',
        'customers.owner' => '会员·负责人',
        'leads.service_teacher' => '留资·会籍顾问',
        'leads.trial_teacher' => '留资·上课老师',
        'ky_bookings.teacher_name' => '排课·上课老师',
        'tasks.owner' => '任务·负责人',
        'training_plans.created_by' => '训练计划·创建人',
        'post_class_reviews.teacher_name' => '课后分析·老师',
    ];

    $out = [];
    foreach ($columns as $ref => $label) {
        [$table, $column] = explode('.', $ref);
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            continue;
        }
        $rows = DB::table($table)
            ->select($column, DB::raw('count(*) as c'))
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->get();

        foreach ($rows as $row) {
            $name = trim((string) $row->{$column});
            if ($name === '' || isset($known[$name])) {
                continue;
            }
            // 明确的非人员占位符不算未映射
            if (in_array($name, ['未分配', '待分配', '待完善', '未确认'], true)) {
                continue;
            }
            $out[$name] ??= ['name' => $name, 'counts' => []];
            $out[$name]['counts'][$label] = (int) $row->c;
        }
    }

    ksort($out);
    return $out;
}

/**
 * 授课老师（私教主教练）实际授课的学员标识。
 *
 * 只统计私教（course_kind=private），signed 状态；小班与团课不算「我的学员」。
 * 返回 external_id（ky:{venueId}:{memberId}）与手机号两组键，
 * 供会员/客资按人隔离时交叉匹配（external_id 精确匹配，手机号兜底乱序数据）。
 * 本函数结果挂在 User 实例上做请求内缓存。
 * 不用函数级 static：长驻进程／测试串跑时，不同请求的同 id 实例会读到上一次的结果。
 */
function privateStudentKeys(User $user): array
{
    if (isset($user->privateStudentKeysCache)) {
        return $user->privateStudentKeysCache;
    }

    $keys = ['external_ids' => [], 'phones' => []];
    if (! userHasRole($user, 'R_TEACHER') || staffNames($user) === []) {
        return $user->privateStudentKeysCache = $keys;
    }

    $rows = KyBooking::query()
        ->where(staffOwnerFilter($user, 'teacher_user_id', 'teacher_name'))
        ->where('venue', $user->venue)
        ->where('course_kind', 'private')
        ->where('status', 'signed')
        ->get(['source_key', 'member_id', 'phone']);

    $ids = [];
    $phones = [];
    foreach ($rows as $b) {
        $venueId = explode(':', (string) $b->source_key)[0] ?? '';
        $mid = (string) $b->member_id;
        if ($mid !== '' && $venueId !== '') {
            $ids["ky:{$venueId}:{$mid}"] = true;
        }
        $phone = (string) $b->phone;
        if ($phone !== '') {
            $phones[$phone] = true;
        }
    }

    return $user->privateStudentKeysCache = [
        'external_ids' => array_keys($ids),
        'phones' => array_keys($phones),
    ];
}

/**
 * 会员可见范围（多角色取并集）。
 *
 *  超管  → 不加条件（双店）
 *  店长  → 本店全部
 *  服务老师 → 本店 + 挂在自己名下的会员
 *  授课老师 → 本店 + （自己名下的会员 ∪ 私教课学员）
 *  新媒体 → P5 留资
 *
 * 多角色时把各自的「按人条件」并起来；店长/超管这类更大范围直接吸收其余角色。
 */
function scopeCustomersForUser($query, User $user)
{
    $roles = userRoles($user);

    if (in_array('R_SUPER', $roles, true)) {
        return $query;
    }
    if (in_array('R_MANAGER', $roles, true)) {
        return $query->where('venue', $user->venue);
    }

    $personScopes = [];
    if (userHasAnyRole($user, ['R_SERVICE', 'R_TEACHER'])) {
        $personScopes[] = fn ($q) => $q
            ->where(staffOwnerFilter($user, 'consultant_user_id', 'consultant'))
            ->orWhere(staffOwnerFilter($user, 'owner_user_id', 'owner'));
    }
    if (in_array('R_TEACHER', $roles, true)) {
        $keys = privateStudentKeys($user);
        if ($keys['external_ids'] !== [] || $keys['phones'] !== []) {
            $personScopes[] = function ($q) use ($keys) {
                if ($keys['external_ids'] !== []) {
                    $q->orWhereIn('external_id', $keys['external_ids']);
                }
                if ($keys['phones'] !== []) {
                    $q->orWhereIn('phone', $keys['phones']);
                }
            };
        }
    }

    if ($personScopes !== []) {
        return $query->where('venue', $user->venue)->where(function ($q) use ($personScopes) {
            $q->where(function ($inner) use ($personScopes) {
                foreach ($personScopes as $scope) {
                    $inner->orWhere($scope);
                }
            });
        });
    }
    if (in_array('R_MEDIA', $roles, true)) {
        return $query->where('layer', 'P5');
    }

    return $query;
}

/**
 * 客资可见范围（多角色取并集），口径与会员一致。
 *
 *  超管  → 不加条件（双店）
 *  店长  → 本店全部
 *  服务老师 → 本店 + 本人名下 + 待承接池
 *  授课老师 → 本店 + 本人名下 + 本人上过体验课的 + 本人私教学员
 *  新媒体 → 自己录入的客资
 *
 * **新媒体必须在这里收窄**。这个函数此前对 media 直接返回未加条件的查询，注释写的是
 * 「由调用方按场景叠加自己的过滤」—— 但 `/leads` 列表与 `/today/alerts` 都没叠加，
 * 结果是新媒体账号能拉到双店全部留资（姓名/手机号/微信/成交金额）。"调用方记得补"
 * 这种约定只要有一处漏掉就是一次客户名单泄露，所以口径收回到这里，调用方只管调用。
 *
 * 新媒体不叠加门店条件：它是双店账号（`venue` 为空），锁门店会把范围压成空集；
 * 它看到什么由「谁录入的」决定，本身就跨门店。
 */
function scopeLeadsForUser($query, User $user)
{
    $roles = userRoles($user);

    if (in_array('R_SUPER', $roles, true)) {
        return $query;
    }
    if (in_array('R_MANAGER', $roles, true)) {
        return $query->where('venue', $user->venue);
    }

    $storeScopes = [];  // 坐店角色（服务老师/授课老师）：本店 + 按人
    $mediaScope = null; // 新媒体：跨门店，只看自己录入的

    if (in_array('R_SERVICE', $roles, true)) {
        // 服务老师是客资承接主体：本人名下的 + 待承接池
        $storeScopes[] = fn ($q) => $q
            ->where(staffOwnerFilter($user, 'service_teacher_user_id', 'service_teacher'))
            ->orWhere('service_teacher', '');
    }
    if (in_array('R_TEACHER', $roles, true)) {
        $keys = privateStudentKeys($user);
        $storeScopes[] = function ($q) use ($user, $keys) {
            $q->where(staffOwnerFilter($user, 'service_teacher_user_id', 'service_teacher'))
                ->orWhere(staffOwnerFilter($user, 'trial_teacher_user_id', 'trial_teacher'));
            if ($keys['phones'] !== []) {
                $q->orWhereIn('phone', $keys['phones']);
            }
        };
    }
    if (in_array('R_MEDIA', $roles, true)) {
        $mediaScope = fn ($q) => $q->where(staffOwnerFilter($user, 'created_by_user_id', 'created_by'));
    }

    if ($storeScopes === [] && $mediaScope === null) {
        // 一个角色都没识别出来时按最小权限处理。原先返回未加条件的查询，
        // 意味着 `roles` 漏写的账号会静默拿到全量客资 —— 越权不该是默认值。
        return $query->whereRaw('1 = 0');
    }

    return $query->where(function ($outer) use ($user, $storeScopes, $mediaScope) {
        if ($storeScopes !== []) {
            $outer->orWhere(function ($w) use ($user, $storeScopes) {
                $w->where('venue', $user->venue)->where(function ($inner) use ($storeScopes) {
                    foreach ($storeScopes as $scope) {
                        $inner->orWhere($scope);
                    }
                });
            });
        }
        if ($mediaScope !== null) {
            $outer->orWhere($mediaScope);
        }
    });
}

/** 经营看板 venue 下推：超管看双店，新媒体按 venues 授权，店长/服务老师/授课老师锁定本店。 */
function applyVenueScope($query, User $user, string $venue)
{
    if (userHasRole($user, 'R_SUPER')) {
        if ($venue !== '') {
            $query->where('venue', $venue);
        }
    } elseif (userHasRole($user, 'R_MEDIA') && ! in_array('R_MANAGER', userRoles($user), true)) {
        $allowed = array_values(array_intersect((array) $user->venues, ['绿地店', '东部店']));
        if ($venue !== '') {
            abort_unless(in_array($venue, $allowed, true), 403, '无权查看该门店');
            $query->where('venue', $venue);
        } else {
            $query->whereIn('venue', $allowed);
        }
    } else {
        $query->where('venue', $user->venue ?: '__none__');
    }

    return $query;
}

function canAccessCustomer(User $user, Customer $customer): bool
{
    if (userHasRole($user, 'R_SUPER')) {
        return true;
    }
    if ($customer->venue !== $user->venue) {
        return false;
    }
    if (userHasRole($user, 'R_MANAGER')) {
        return true;
    }
    // 老师侧：名下会员 ∪ 私教课学员（多角色即两个条件的并集）
    //
    // 与 staffOwnerFilter 同一口径：id 或姓名任一命中（并集），不因 id 悬挂而漏掉本人数据
    if (userHasAnyRole($user, ['R_SERVICE', 'R_TEACHER'])) {
        $names = staffNames($user);
        if ((int) $customer->owner_user_id === (int) $user->id
            || (int) $customer->consultant_user_id === (int) $user->id
            || in_array($customer->owner, $names, true)
            || in_array($customer->consultant, $names, true)) {
            return true;
        }
        if (privateTeaches($user, $customer)) {
            return true;
        }
    }

    return false;
}

function privateTeaches(User $user, Customer $customer): bool
{
    $keys = privateStudentKeys($user);
    $externalId = (string) $customer->external_id;
    if ($externalId !== '' && in_array($externalId, $keys['external_ids'], true)) {
        return true;
    }
    $phone = (string) $customer->phone;

    return $phone !== '' && in_array($phone, $keys['phones'], true);
}

function renewalEvaluationContext(Customer $customer): array
{
    $memberId = str_starts_with((string) $customer->external_id, 'ky:')
        ? (string) last(explode(':', (string) $customer->external_id))
        : '';
    // 近 30 天出勤走 KyMemberSyncService 的窗口定义（含今天共 30 天），
    // 与会员表 attend_m3 同口径 —— 此前这里用 subDays(30)（31 天），
    // 边界日算出的节数比清单里的 attend_m3 多一天，同一个会员在两个页面上一个进清单一个不进。
    [$m3Start, $m3End] = KyMemberSyncService::attendanceWindows()[2];
    $attendance = KyBooking::query()
        ->where('venue', $customer->venue)
        ->where('status', 'signed')
        ->whereBetween('start_at', [$m3Start, $m3End])
        ->where(function ($q) use ($customer, $memberId) {
            if ($memberId !== '') {
                $q->where('member_id', $memberId);
                if ($customer->phone !== '') {
                    $q->orWhere('phone', $customer->phone);
                }
            } elseif ($customer->phone !== '') {
                $q->where('phone', $customer->phone);
            } else {
                $q->whereRaw('1 = 0');
            }
        })->count();
    $expireDays = $customer->expire_date
        ? now()->startOfDay()->diffInDays($customer->expire_date, false)
        : null;
    // 续费窗口与清单阈值同口径（客户管理可调），不再硬编码 10/30
    $rules = rules();
    $renewalThreshold = (int) ($rules['renewalThreshold'] ?? 10);
    $renewalExpireDays = (int) ($rules['renewalExpireDays'] ?? 30);
    $cardWindow = ($customer->remain_times !== null && $customer->remain_times <= $renewalThreshold)
        || ($expireDays !== null && $expireDays >= 0 && $expireDays <= $renewalExpireDays) ? 10
        : (($customer->remain_times !== null && $customer->remain_times <= $renewalThreshold * 2)
            || ($expireDays !== null && $expireDays > $renewalExpireDays && $expireDays <= $renewalExpireDays * 2) ? 5 : 0);
    $latest = $customer->renewalEvaluations()->latest('evaluated_at')->first();

    return [
        'attendanceCount' => $attendance,
        'cardWindow' => $cardWindow,
        'lastVisit' => $customer->last_visit,
        'remainTimes' => $customer->remain_times,
        'expireDate' => $customer->expire_date,
        'attendM1' => $customer->attend_m1,
        'attendM2' => $customer->attend_m2,
        'attendM3' => $customer->attend_m3,
        'latest' => $latest ? camel($latest) : null,
    ];
}

function renewalEvaluationScore(array $answers): int
{
    $attendance = (int) ($answers['attendanceCount'] ?? 0);
    $attendanceScore = match (true) {
        $attendance >= 8 => 25,
        $attendance >= 6 => 20,
        $attendance >= 4 => 10,
        $attendance >= 1 => 5,
        default => 0,
    };
    $maps = [
        'goal' => ['written_plan' => 15, 'agreed_goal' => 10, 'visible_progress' => 5, 'none' => 0],
        'feedback' => ['replied' => 15, 'no_reply' => 5, 'none' => 0],
        'wechat' => ['proactive' => 15, 'two_way' => 10, 'shallow' => 5, 'no_response' => 0, 'refused' => 0],
        'intent' => ['asked_plan' => 10, 'positive' => 8, 'uncertain' => 4, 'none' => 0],
        'service' => ['resolved' => 10, 'handled' => 8, 'normal' => 5, 'unresolved' => 0],
    ];
    $score = $attendanceScore + min(10, max(0, (int) ($answers['cardWindow'] ?? 0)));
    foreach ($maps as $key => $values) {
        $score += $values[$answers[$key] ?? ''] ?? 0;
    }
    $riskScores = ['purchase_refused' => 10, 'long_no_response' => 10, 'complaint_unresolved' => 15];
    foreach (array_unique((array) ($answers['risks'] ?? [])) as $risk) {
        $score -= $riskScores[$risk] ?? 0;
    }

    return min(100, max(0, $score));
}

function renewalLevel(int $score): string
{
    return $score >= 70 ? 'high' : ($score >= 40 ? 'medium' : 'low');
}

function renewalTaskSpec(Customer $customer, int $score): array
{
    $level = renewalLevel($score);
    $manager = User::where('role', 'R_MANAGER')->where('venue', $customer->venue)->where('status', '启用')->first();
    $owner = trim((string) $customer->consultant) ?: (trim((string) $customer->owner) ?: '未分配');
    if ($level === 'low' && $manager) {
        $owner = $manager->name;
    }

    return match ($level) {
        'high' => ['title' => '续费方案确认', 'owner' => $owner, 'priority' => '中', 'days' => 3, 'reviewRole' => 'R_MANAGER', 'standard' => '确认续费课种、方案、预计时间，并记录客户明确反馈'],
        'medium' => ['title' => '续费障碍跟进', 'owner' => $owner, 'priority' => '高', 'days' => 7, 'reviewRole' => 'R_MANAGER', 'standard' => '完成一次有效沟通或训练反馈，明确主要障碍与下一次安排'],
        default => ['title' => '店长介入续费修复', 'owner' => $owner, 'priority' => '高', 'days' => 3, 'reviewRole' => 'R_MANAGER', 'standard' => '店长完成介入，记录客户主要顾虑、解决动作及下一次跟进时间'],
    };
}

function camel($model): array
{
    $arr = $model->toArray();
    $out = [];
    foreach ($arr as $k => $v) {
        $out[str_replace('_', '', lcfirst(ucwords($k, '_')))] = $v;
    }

    return $out;
}

/**
 * 请求字段名转库表列名（camelCase → snake_case）。
 *
 * 这里**不再丢掉 null**：null 是「字段传了、值为空」，与「字段根本没传」是两件事，
 * 谁都不能少。丢 null 会让前端清空输入框的请求（空串经 ConvertEmptyStringsToNull 变 null）
 * 看起来跟没传一样，于是「怎么删都删不掉」；反过来拿 `?? ''` 兜底再把键补成 null，
 * 就会把用户没碰过的字段一起抹掉。清空语义统一交给 normalizeEmptyValues() 按列定义处理。
 */
function camelToSnake(array $in): array
{
    $out = [];
    foreach ($in as $k => $v) {
        if ($k === '_action') {
            continue;
        }
        $out[strtolower(preg_replace('/([a-z\d])([A-Z])/', '$1_$2', $k))] = $v;
    }

    return $out;
}

/**
 * 把「显式清空」（null）按列定义落到该列真正能接受的形态。
 *
 * 前端清空输入框提交的是空串，Laravel 的 ConvertEmptyStringsToNull 会先把它变成 null。
 * 这个 null 的含义是「用户要清空这个字段」，处理方式只有两种是对的：
 *   - 键不存在 → 该字段保持原值。部分字段提交依赖这一点（老师只改备注、只保存续课预报）；
 *   - 键存在且为 null → 可空列写 null，非空字符串列写 ''。
 * 混为一谈的两个方向都会出数据问题：当成「没传」就清不掉（备注删了刷新又回来），
 * 当成「清空」就误伤（编辑留资只改备注，成交金额被一起清成 null）。
 *
 * NOT NULL 的日期/数字列存不下空值，保持键缺失（等同「不可清空」），不写 '' 以免触发类型错误。
 *
 * @param  array  $except  不允许被清空的列，命中则丢键、保持原值。门店、来源、状态这类字段
 *                         写空会让这行从列表和统计里直接消失，不该由「清空输入框」触发。
 */
function normalizeEmptyValues(string $table, array $values, array $except = []): array
{
    if (! in_array(null, $values, true)) {
        return $values; // 没有要清空的字段，不必查列定义
    }
    if (! Schema::hasTable($table)) {
        return $values; // 取不到列定义就不猜，原样交回
    }

    $stringTypes = ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext'];
    foreach (Schema::getColumns($table) as $column) {
        $key = $column['name'];
        if (! array_key_exists($key, $values) || $values[$key] !== null) {
            continue;
        }
        if (in_array($key, $except, true)) {
            unset($values[$key]); // 不可清空：等同没传，保持原值

            continue;
        }
        if ($column['nullable']) {
            continue; // 可空列：null 本身就是「清空」
        }
        if (in_array(strtolower((string) $column['type_name']), $stringTypes, true)) {
            $values[$key] = '';

            continue;
        }
        unset($values[$key]); // 非空且非字符串：清不了，宁可不写
    }

    return $values;
}

/**
 * 手机号归一：只保留数字。
 *
 * 全站的手机号比对（身份键、去重、归属匹配）都按「纯数字」进行，上游同步入库的也是纯数字，
 * 但手工录入的留资可能带 `138-0000-0001` / `138 0000 0001` 这类分隔符。只要有一处按
 * 原始输入去查，同一号码就会被当成两个人（查重漏报 → 重复客资；关联匹配失败 → 会员 360 断链）。
 * 因此写入与查询都必须先归一，统一走这个函数，不要再各自写正则。
 */
function normalizePhone(?string $phone): string
{
    return preg_replace('/\D+/', '', (string) $phone) ?? '';
}

function assertPublicHttpsUrl(string $url): void
{
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    abort_unless(
        ($parts['scheme'] ?? '') === 'https'
        && $host !== ''
        && ! isset($parts['user'])
        && ! isset($parts['pass'])
        && ! in_array($host, ['localhost', 'localhost.localdomain'], true),
        422,
        '接口地址必须是公网 HTTPS 地址'
    );

    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : [];
    if ($ips === []) {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip) {
                $ips[] = $ip;
            }
        }
    }
    abort_if($ips === [], 422, '接口域名无法解析');
    foreach (array_unique($ips) as $ip) {
        abort_unless(
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false,
            422,
            '接口地址不能指向内网、回环或保留地址'
        );
    }
}

function audit(Request $r, string $action, string $module, int|string $targetId, string $targetLabel, string $venue, string $detail): void
{
    $roleMap = ['R_SUPER' => '超管', 'R_MANAGER' => '店长', 'R_SERVICE' => '服务老师', 'R_TEACHER' => '授课老师', 'R_MEDIA' => '新媒体'];
    AuditLog::create([
        'operator_id' => $r->user()->id,
        'operator_name' => $r->user()->name,
        'operator_role' => implode('+', array_map(fn ($x) => $roleMap[$x] ?? $x, userRoles($r->user()))),
        'action' => $action, 'module' => $module,
        'target_id' => (string) $targetId, 'target_label' => $targetLabel,
        'venue' => $venue, 'detail' => $detail,
        'ip' => (string) $r->ip(),
        'user_agent' => mb_substr((string) $r->header('User-Agent'), 0, 300),
    ]);
}

function rules(): array
{
    $s = setting();
    $defaults = [
        'renewalThreshold' => 10,
        'renewalCountPercent' => 20,
        'renewalExpireDays' => 30,
        'renewalExpirePercent' => 0,
        'vipAmountThreshold' => 30000,
        'declineMode' => 'strict',
        'predropMin' => 15,
        'predropMax' => 30,
        'reviveDays' => 30,
        // 新客培养：入会 90 天内各类别课的「养成目标节数」（已上课节数达此值视为养成习惯），分别可调
        'cultivationPrivate' => 8,
        'cultivationSmall' => 12,
        'cultivationGroup' => 12,
    ];

    return array_merge($defaults, (array) ($s?->rules ?? []));
}

function setRules(array $rules): void
{
    unset($rules['vipThreshold']);
    $s = setting();
    // 合并写入：只更新本次提交的键，保留其余（含养成阈值）不被冲掉
    $s->update(['rules' => array_merge((array) ($s->rules ?? []), $rules)]);
    invalidateBusinessCaches('member_lists');
}

/**
 * 业务缓存版本号：任何影响清单/看板口径的写入都会自增对应命名空间，旧缓存键立即失效。
 * 命名空间：member_lists（五清单引擎）、analytics（经营看板聚合）。
 */
function businessCacheVersion(string $namespace): int
{
    return (int) Cache::get("cachever:{$namespace}", 1);
}

/** 失效指定命名空间的业务缓存；不传则全部失效 */
function invalidateBusinessCaches(?string $namespace = null): void
{
    $namespaces = $namespace !== null ? [$namespace] : ['member_lists', 'analytics'];
    foreach ($namespaces as $ns) {
        Cache::forever("cachever:{$ns}", businessCacheVersion($ns) + 1);
    }
}

/** 五清单引擎：返回命中清单的会员ID集合（口径：卓越店长训练营） */
function filteredIds(string $list): array
{
    return memberListIds()[$list] ?? [];
}

/**
 * 一次性扫描五清单，返回 [清单名 => 会员ID[]]。
 * 只 select 必要列，供热路径（如 /today/todo 需要全部五清单）一次算好；
 * 单清单调用走 filteredIds（内部也调本函数一次）。
 * 结果按「缓存版本 + 规则哈希 + 客户表指纹」缓存 120 秒：
 * - 版本号：setRules / 同步 / 会员工作流写入时立即失效；
 * - 指纹（MAX(updated_at)+行数）：任何客户行增删改都会自动换键，杜绝陈旧清单。
 */
function memberListIds(): array
{
    $rules = rules();
    // 键 = 缓存版本 + 规则哈希 + customers 表指纹。
    //
    // 指纹（MAX(updated_at) + COUNT(*)）是**自愈兜底**：清单依赖 card_stats / attend_* /
    // last_visit 等列，正常路径（随心瑜同步、待复活开关）写完都会 invalidateBusinessCaches，
    // 但只要有任意一条写入路径忘了失效，没有指纹就会一直发旧清单、且不会被任何人发现。
    // 之前的问题是这条 SQL 没有可用索引、每次调用都全表扫（customer_lists 的每个请求
    // 会调多次）—— 已为 customers.updated_at 补索引（2026_09_17_000003），
    // MAX 走索引反向扫描、COUNT 走这条窄索引，成本降到可忽略。
    $fingerprint = Customer::query()
        ->selectRaw('MAX(updated_at) as mu, COUNT(*) as cnt')
        ->first();
    $key = 'member_lists:v'.businessCacheVersion('member_lists')
        .':'.md5(json_encode($rules))
        .':'.($fingerprint?->mu ?? '0').':'.$fingerprint?->cnt;

    return Cache::remember($key, 120, fn () => computeMemberListIds($rules));
}

function computeMemberListIds(array $rules): array
{
    $threshold = $rules['renewalThreshold'] ?? 10;
    $countPercent = (int) ($rules['renewalCountPercent'] ?? 0);
    $expireDaysRule = (int) ($rules['renewalExpireDays'] ?? 30);
    $expirePercent = (int) ($rules['renewalExpirePercent'] ?? 0);
    $vip = (float) ($rules['vipAmountThreshold'] ?? 30000);
    $strict = ($rules['declineMode'] ?? 'strict') === 'strict';
    $predropMin = (int) ($rules['predropMin'] ?? 15);
    $predropMax = (int) ($rules['predropMax'] ?? 30);
    $reviveDays = (int) ($rules['reviveDays'] ?? 30);
    $days = fn ($d) => $d ? (int) ((time() - strtotime($d)) / 86400) : null;

    $lists = ['待续课' => [], '出勤降低' => [], 'VIP' => [], '预流失' => [], '待复活' => []];

    Customer::query()
        ->select(['id', 'main_card', 'remain_times', 'expire_date', 'last_visit', 'attend_m1', 'attend_m2', 'attend_m3', 'in_revive', 'card_paid_amount', 'card_stats'])
        ->chunkById(500, function ($customers) use (&$lists, $threshold, $countPercent, $expireDaysRule, $expirePercent, $vip, $strict, $predropMin, $predropMax, $reviveDays, $days) {
            foreach ($customers as $c) {
                $m1 = $c->attend_m1;
                $m2 = $c->attend_m2;
                $m3 = $c->attend_m3;
                $dd = $days($c->last_visit);
                $hasAsset = $c->main_card !== null && ! in_array($c->main_card, ['', '—', '待同步卡项'], true);
                $expireDays = $c->expire_date ? now()->startOfDay()->diffInDays($c->expire_date, false) : null;
                $revive = (bool) $c->in_revive || ($dd !== null && $dd > $reviveDays && $hasAsset);
                // m1/m2/m3 是三个连续且等长的 30 天滚动窗口（再前30天 / 前30天 / 近30天）：
                // 三档等长才使下面的「逐档下降」比较有意义。
                $preLoss = ! $revive && $dd !== null && (($m2 > 0 && $m3 === 0) || ($dd >= $predropMin && $dd <= $predropMax));
                $declining = ! $revive && ! $preLoss && ($strict ? ($m1 > $m2 && $m2 > $m3) : ($m2 > $m3));

                $stats = (array) ($c->card_stats ?? []);
                $countResidue = array_key_exists('countResidue', $stats)
                    ? $stats['countResidue']
                    : (($c->main_card !== null && (string) $c->remain_times !== null && $c->remain_times !== null) ? $c->remain_times : null);
                $countBound = (int) ($stats['countBound'] ?? 0);
                $daysLeft = $stats['daysLeft'] ?? null;
                $daysTotal = (int) ($stats['daysTotal'] ?? 0);
                $renewalHit = false;
                // 待续课要求「最近一个月有出勤」：m3 = 近 30 天（含今天），
                // 而非自然月的「上月」——否则本月恢复训练、上月停练的会员会被漏掉
                if ($countResidue !== null && $m3 > 0) {
                    $renewalHit = $countResidue <= $threshold
                        || ($countPercent > 0 && $countBound > 0 && $countResidue / $countBound * 100 <= $countPercent);
                }
                $renewalHit = $renewalHit
                    || ($expireDays !== null && $expireDays >= 0 && $expireDays <= $expireDaysRule)
                    || ($expirePercent > 0 && $daysLeft !== null && $daysTotal > 0 && $daysLeft / $daysTotal * 100 <= $expirePercent);

                if ($hasAsset && $renewalHit) {
                    $lists['待续课'][] = $c->id;
                }
                if ($declining) {
                    $lists['出勤降低'][] = $c->id;
                }
                if ((float) ($c->card_paid_amount ?? 0) >= $vip) {
                    $lists['VIP'][] = $c->id;
                }
                if ($preLoss) {
                    $lists['预流失'][] = $c->id;
                }
                if ($revive) {
                    $lists['待复活'][] = $c->id;
                }
            }
        });

    return $lists;
}

/** 生日是否为今天（忽略年份，2/29 生日在平年按 3/1 庆祝） */
function isBirthdayToday(?string $birthday): bool
{
    if (! $birthday || strlen($birthday) < 10) {
        return false;
    }
    $md = substr($birthday, 5, 5);
    $todayMd = now()->format('m-d');
    if ($md === '02-29' && $todayMd === '03-01' && ! now()->isLeapYear()) {
        return true;
    }

    return $md === $todayMd;
}

/** 执行一段 shell 脚本（在线更新用），返回 [ok, output[]] */
function runShell(string $script, int $timeout = 180): array
{
    if (! function_exists('proc_open')) {
        return [
            'ok' => false,
            'output' => [
                'PHP 已禁用 proc_open（宝塔 PHP 默认禁用的函数之一），无法执行受控更新脚本。',
                '请在宝塔面板 → 软件商店 → PHP 设置 → 禁用函数中移除 proc_open（建议同时移除 exec、popen、shell_exec）后重试。',
            ],
        ];
    }
    $process = new Process(['bash', '-c', $script], base_path());
    $process->setTimeout($timeout)->run();

    return [
        'ok' => $process->isSuccessful(),
        'output' => array_filter(array_map('trim', explode("\n", trim($process->getOutput()."\n".$process->getErrorOutput())))),
    ];
}

/** 本地 + 远程版本信息 */
function systemVersionInfo(): array
{
    // 本地版本：优先读取打包时生成的 version.json（生产部署非 git 仓库）
    $vFile = base_path().'/version.json';
    $local = ['branch' => 'main', 'commit' => '', 'message' => '', 'date' => ''];
    if (is_file($vFile)) {
        $vj = json_decode((string) file_get_contents($vFile), true);
        if (is_array($vj)) {
            $local = [
                'branch' => (string) ($vj['branch'] ?? 'main'),
                'commit' => (string) ($vj['commit'] ?? ''),
                'message' => (string) ($vj['message'] ?? ''),
                'date' => (string) ($vj['date'] ?? ''),
            ];
        }
    } else {
        // 回退：用 git（开发环境）
        $git = function (array $args): string {
            try {
                $p = new Process(['git', ...$args], base_path());
                $p->setTimeout(30)->run();

                return trim($p->isSuccessful() ? $p->getOutput() : '');
            } catch (Throwable) {
                return '';
            }
        };
        $local = [
            'branch' => $git(['rev-parse', '--abbrev-ref', 'HEAD']) ?: 'main',
            'commit' => $git(['rev-parse', 'HEAD']),
            'message' => $git(['log', '-1', '--pretty=%s']),
            'date' => $git(['log', '-1', '--pretty=%ci']),
        ];
    }

    // 远端 main 最新提交：优先 Gitee，失败时回退 GitHub
    $remoteSha = '';
    $remoteErr = '';
    $giteeToken = (string) config('services.gitee.token');
    try {
        $resp = Http::timeout(20)->get(
            'https://gitee.com/api/v5/repos/meng-taoo/yimai-workbench/commits/main',
            $giteeToken !== '' ? ['access_token' => $giteeToken] : []
        );
        if ($resp->successful() && ($resp->json('sha') ?? false)) {
            $remoteSha = (string) $resp->json('sha');
        } else {
            $fallback = Http::timeout(20)->get('https://api.github.com/repos/a6828464/yimai-workbench/commits/main');
            if ($fallback->successful() && ($fallback->json('sha') ?? false)) {
                $remoteSha = (string) $fallback->json('sha');
            } else {
                $remoteErr = 'Gitee/GitHub API 均不可达';
            }
        }
    } catch (Throwable $e) {
        $remoteErr = mb_substr($e->getMessage(), 0, 200);
    }

    return [
        'local' => $local,
        'remote' => ['commit' => $remoteSha, 'error' => $remoteErr],
        'upToDate' => $remoteSha !== '' && str_starts_with($local['commit'], $remoteSha),
    ];
}

function latestReleaseNotes(): array
{
    $empty = ['version' => '', 'name' => '', 'content' => '', 'url' => '', 'publishedAt' => ''];
    try {
        $gitee = Http::timeout(12)->get('https://gitee.com/api/v5/repos/meng-taoo/yimai-workbench/releases/latest');
        if ($gitee->successful() && $gitee->json('tag_name')) {
            $version = (string) $gitee->json('tag_name');

            return [
                'version' => $version,
                'name' => (string) ($gitee->json('name') ?? $version),
                'content' => (string) ($gitee->json('body') ?? ''),
                'url' => "https://gitee.com/meng-taoo/yimai-workbench/releases/{$version}",
                'publishedAt' => (string) ($gitee->json('created_at') ?? ''),
            ];
        }
    } catch (Throwable) {
    }
    try {
        $github = Http::timeout(12)->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get('https://api.github.com/repos/a6828464/yimai-workbench/releases/latest');
        if ($github->successful() && $github->json('tag_name')) {
            $version = (string) $github->json('tag_name');

            return [
                'version' => $version,
                'name' => (string) ($github->json('name') ?? $version),
                'content' => (string) ($github->json('body') ?? ''),
                'url' => (string) ($github->json('html_url') ?? ''),
                'publishedAt' => (string) ($github->json('published_at') ?? ''),
            ];
        }
    } catch (Throwable) {
    }

    return $empty;
}
