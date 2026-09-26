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
use Carbon\CarbonImmutable;
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
    // 可见范围收敛到 scopeTasksForUser（唯一收口点，含角色不明兜底）。
    // 本处此前是 if/elseif 的「最大范围」口径，与其它出口的逐条 AND 不同，故单独作为 'notify' 口径保留。
    $taskCount = scopeTasksForUser(Task::query()->whereNotIn('status', ['已完成']), $user, 'notify')->count();
    if ($taskCount > 0) {
        $items[] = ['key' => 'tasks-'.$taskCount, 'category' => 'todo', 'level' => 'warning', 'title' => "有 {$taskCount} 项任务待处理", 'detail' => userHasRole($user, 'R_SUPER') ? '双店任务' : ($user->venue ?: '本人任务'), 'path' => '/yimai/tasks'];
    }
    if (userHasRole($user, 'R_SUPER') || userHasRole($user, 'R_MANAGER') || userIsTeacherSide($user)) {
        $customerQ = scopeCustomersForUser(Customer::query(), $user)->whereIn('id', filteredIds('待续课'));
        $renewals = $customerQ->count();
        if ($renewals > 0) {
            // D6：后端清单键仍是「待续课」（API 契约，改键会打断前端与缓存），
            // 但**用户可见文案**统一为「待续费」——店长看的是「要不要催他续费」，
            // 「待续课」在业务上容易被理解成「课还没上完」。
            $items[] = ['key' => 'renewals-'.$renewals, 'category' => 'todo', 'level' => 'high', 'title' => "有 {$renewals} 位会员待续费", 'detail' => '请完成评估并明确下一步动作', 'path' => '/yimai/members'];
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

/**
 * 是否至少认识一个角色 —— 权限收口的**共用判据**。
 *
 * 起因：`roles` 漏写、或账号还挂着已下架的旧角色码时，各出口原先的「按角色加条件」
 * 全都不会命中，于是查询上没有任何条件，这个账号就静默拿到了全量/他人数据
 * （t8 会员、t15 客资与任务列表、t17 任务各出口，同一根因反复出现）。
 *
 * 所以「认不认得出来」只在这里定义一次，各 scope 函数统一用它做兜底判据，
 * 而不各自写 `! userHasAnyRole($u, ROLE_PRECEDENCE)`：
 *  - 角色全集来自 ROLE_PRECEDENCE，将来新增角色只改那一处，所有兜底自动跟着放开；
 *  - 兜底方向一律是**失败关闭**（空集），不是「按 venue/姓名猜一个范围」。
 */
function isKnownRoleUser(?User $user): bool
{
    return userHasAnyRole($user, ROLE_PRECEDENCE);
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
        // 销售分享行同样会在账号删除后留下悬挂 id（t27 新增列）。
        // 它不在上面那张「姓名 ↔ id」迁移清单里，因此单独在此登记：
        // 本函数是人员管理「归属映射」面板 staleIds 的唯一数据源，漏一张表就少一处运维可见性。
        'published_shares' => ['created_by_user_id'],
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
 * 请求内、按模型实例隔离的临时缓存容器。
 *
 * ## 为什么需要它（原实现是一个真实缺陷）
 *
 * 这里以前把缓存直接挂成模型的**动态属性**：
 *
 *     $user->staffNamesCache = array_keys($names);      // 旧写法
 *
 * `staffNamesCache` 不是 users 表的列，而 Eloquent 的 `attributes` 数组同时承担
 * 「数据库列」与「动态属性」两个角色，于是这个值进了 `attributes`：
 *
 *   - 同实例随后 `save()`（`ProfileController::updateMyProfile` 就是 `$u->save()`）会把它
 *     当作列写进去 → `SQLSTATE[42S22] no such column: staffNamesCache`；
 *   - `getDirty()`/`toArray()` 也被污染，缓存值会混进「已改动字段」判断与序列化结果。
 *
 * 触发条件是「同一请求内先做归属解析、再写 users 表」—— 服务老师打开会员列表
 * （`canAccessCustomer` → `staffNames`）后保存个人资料即命中，实测 500。
 *
 * ## 为什么用 WeakMap 而不是给 $guarded 加名字
 *
 * `$guarded` 只约束**批量赋值**（`fill`/`create`/`update`），而旧写法是直接赋值，
 * 走的是 `setAttribute`，`$guarded` 根本不参与 —— 实测把 `staffNamesCache` 加进
 * `$guarded` 后 `save()` 依旧抛 `no such column`（`User` 定义了 `$fillable`，
 * `$guarded` 默认为 `['*']`，该键本来就已经不是 fillable）。
 * 更根本的是：只要缓存还挂在**模型**上，下一次加列时仍然可能撞名。
 *
 * 换成 WeakMap 后缓存存在模型**之外**，物理上不可能被 Eloquent 写进任何表 ——
 * 这从「靠约定防错」变成「结构上不可能」，不需要每加一列就回来维护一张黑名单。
 *
 * 键用对象实例而不是 user id，是为了保持与原来完全一致的语义：
 * 同一实例复用、不同实例各算各的（同 id 的两个实例也不会串，长驻进程/测试串跑都安全）。
 * 容器随实例被回收，不需要手动清理。
 */
function modelScopedCache(object $model): stdClass
{
    /** @var WeakMap<object, stdClass>|null $storage */
    static $storage = null;
    $storage ??= new WeakMap();

    return $storage[$model] ??= new stdClass();
}

/**
 * 课型的**唯一**判定入口：随心瑜 `course_type` → private/small/group。
 *
 * ## 为什么必须只有一个入口
 *
 * `course_type` 存在时的映射原本抄了 5 份（同步写入、模型访问器、今日预约、
 * 新客培养、看板），而 `course_type` **缺失时**的兜底各写各的、方向甚至相反：
 * 同步写 `private`、模型访问器写 `private`、今日预约写「团课」、新客培养与看板写
 * `group`。后果是**同一行预约在不同出口显示的课型不同**，而课型又参与
 * 「我的学员」按人隔离、三分统计、课后分析候选，属于同一份事实被多处推断。
 * 现在映射与兜底都只在本函数定义，各出口传值进来即可。
 *
 * ## 映射方向（`1`=团课 / `2`=私教 / `3`=小班）—— 曾两次判错，此处钉死
 *
 * 这个方向被翻过两次，**当前口径 = 原始实现**（`2 => private`、`3 => small`）：
 *
 *  1. v3.1.20 原始实现即为此方向，作者记录「`course_type=2` 私教、`course_type=3`
 *     小班（随心瑜界面显示为「精品课」）」；
 *  2. t26 曾判「无法判定」（当时被禁止读实地勘察笔记，且本地 810 行全是演示数据）；
 *  3. t35 **误判为反向**并翻转成 `2 => small` / `3 => private`，还配了重算迁移
 *     `2026_09_21_000003`（已随 v3.2.0 / v3.2.1 发布，生产可能已改写真实数据）；
 *  4. 用户第一手确认后**翻回**本方向，由迁移 `2026_09_21_000004` 重算纠正。
 *
 * ### t35 为什么错（关键教训：采信了「顺序推断」，否掉了「同文档的实测例子」）
 *
 * t35 的唯一依据是 `随心瑜后台解读/notes.md:425-426` 的
 * 「`course_type=1(团课) / 2(精品课) / 3(私教课)`」。但该行的措辞是
 * 「课程类型自定义名称（仅影响约课页展示）：团课→显示『精品团课』；精品课→显示
 * 『私教小班』；私教课→显示『定制私教』」——**这是设置页的显示顺序，不是数字绑定**；
 * 1/2/3 是 t35 按该顺序**推**出来的。设置页截图
 * （`随心瑜后台解读/screenshots/60-系统设置-功能设置.png`）确实按
 * 团课/精品课/私教课/班课 排列，但页面顺序与接口枚举值无必然关系。
 *
 * 而**同一次探索**里记录的逐行实测例子，方向恰好相反
 * （《随心瑜后台完整解读》`:266`）：
 *  - 「核心床｜上肢线条雕刻」= `type=3`
 *  - 「VIP定制私教｜45Min」= `type=2`
 *
 * 用户第一手确认这两门课的真实课型：核心床｜上肢线条雕刻 = **私教小班（精品课）**，
 * VIP定制私教｜45Min = **定制私教（私教课）** ⇒ `3`=精品课/小班、`2`=私教课/私教。
 *
 * ### 交叉验证（三处独立来源一致，且与接口归属自洽）
 *
 *  - `docs/体验课课后分析-产品规划.md:31`：「小班行带 `course_type=3`」；
 *  - 前端 `admin-web/src/api/keepyoga.ts:266`：`2=私教，3=小班（精品课）`
 *    （t35 只翻了后端，前端一直是本方向 —— 翻转期间前后端口径相反）；
 *  - 接口归属自洽：`queryreversionprivate`（**私教**预约页）返回 `type=2` 的课
 *    ⇒ 私教页返回私教课；`queryreversionleague`（**团课**课表页）返回 `type=1`
 *    与 `type=3` ⇒ 团课课表同时排团课与精品课，与该页「显示团课 / 显示精品课」
 *    两个勾选框完全对应（见 `screenshots/15-课程管理-课程表.png`）。
 *    若按 t35 的口径，则「私教预约页返回精品课、团课课表返回私教课」，与该页
 *    的筛选器与业务常识都矛盾。
 *
 * 命名层（两套名字的对应）本身无争议，截图已证实：
 * 团课→「精品团课」、精品课→「私教小班」、私教课→「定制私教」（两店一致）。
 *
 * 历史数据影响：`ky_bookings.course_kind` 是同步时按当时映射落盘的。t35 的
 * `000003` 按**错误方向**重算过一遍（生产可能已执行），故新增
 * `2026_09_21_000004_recompute_course_kind_after_mapping_correction` 按本方向
 * 再重算一次（幂等；真实行都能从 `raw.course_type` 逐行复原）。
 *
 * ## 缺失时兜底为什么选 `group`（而非按接口来源取 private）
 *
 * 两种原策略方向相反，证据不足以判定上游到底哪种课会漏 `course_type`，所以按
 * 「**不放大可见范围**」取保守侧：
 *
 *  - `course_kind='private'` 是**按人隔离的判据**（`privateStudentKeys()` 取
 *    `course_kind='private'` 的学员，再被会员列表、客资可见性、客资详情读写
 *    ——`EnsureUserIsEnabled` 与 `privateTeaches()`——当授权依据）。兜底偏
 *    `private` 会把「课型未知」的行算进授课老师的私教学员，从而**扩大**该老师
 *    在会员/客资上的可见范围；漏判成 `group` 只会让老师少看到一行，属失败关闭。
 *  - `group` 是三分里的**剩余类**：能确定是私教的行上游会带 `course_type=2`，
 *    小班带 `3`，所以「没带课型」时按非私教处理，与「private 必须有显式证据」
 *    的权限原则一致。
 *
 * 代价（需在后续裁决时一并处理）：若漏 `course_type` 的行实为私教，该老师的
 * 「我的学员」会少人。这是把授权正确性置于展示完整性之上 —— 展示少一行可被发现，
 * 越权看到他人会员数据不可撤。同步侧原有注释称按私教兜底是为「避免授课老师的
 * 『我的学员』静默为空」，本函数以显式的失败关闭取代该取舍：宁可空，不可越权；
 * 若确认上游确有私教行不带 `course_type`，应由同步侧补 `course_type`（数据修正），
 * 而不是让读侧的授权判据跟着放宽。
 *
 * @param  string|null  $courseType  随心瑜原始 course_type（缺失传 null / ''）
 */
function courseKindFrom(?string $courseType): string
{
    return match ((string) $courseType) {
        // 1=团课（对客显示「精品团课」）→ 工作台「团课」、
        // 2=私教课（对客显示「定制私教」）→ 工作台「私教」、
        // 3=精品课（对客显示「私教小班」）→ 工作台「小班」。
        // 方向依据见本函数注释（含 t35 误判的复盘，勿再翻转）。
        '2' => 'private',
        '3' => 'small',
        '1' => 'group',
        default => 'group',
    };
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
 * 结果放在请求内缓存里（见 `modelScopedCache()`），**不再挂到模型属性上** ——
 * 挂模型属性会让它被当作列写入数据库。
 */
function staffNames(User $user): array
{
    $cache = modelScopedCache($user);
    if (isset($cache->staffNames)) {
        return $cache->staffNames;
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

    return $cache->staffNames = array_keys($names);
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
 *
 * 缓存同样放在 `modelScopedCache()` 里而不是模型属性上 —— 原来挂
 * `privateStudentKeysCache` 动态属性与 `staffNamesCache` 是同一个缺陷（实测该键
 * 也会进入 `attributes`/`getDirty()`，同实例 `save()` 即抛 `no such column`）。
 * 不用函数级 static：长驻进程／测试串跑时，不同请求的同 id 实例会读到上一次的结果。
 */
function privateStudentKeys(User $user): array
{
    $cache = modelScopedCache($user);
    if (isset($cache->privateStudentKeys)) {
        return $cache->privateStudentKeys;
    }

    $keys = ['external_ids' => [], 'phones' => []];
    if (! userHasRole($user, 'R_TEACHER') || staffNames($user) === []) {
        return $cache->privateStudentKeys = $keys;
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

    return $cache->privateStudentKeys = [
        'external_ids' => array_keys($ids),
        // ⚠️ 必须 strval 归一化，**不要**「简化」成 array_keys($phones)。
        //
        // `$phones` 是拿手机号当数组键去重（见上 `$phones[$phone] = true`），而 PHP 会把
        // **纯数字字符串键强转成 int** ⇒ array_keys() 返回 int[]。调用方全部按 string
        // 严格比较（`in_array($phone, $keys['phones'], true)`），于是**恒为 false**：
        // 手机号关联通道整个失效，老师会静默漏看「只能靠手机号关联」的学员
        // （external_id 缺失/乱序时唯一的关联途径）。
        //
        // 归一化只做在这一处（返回边界），而不是在收集处改写键：既保持上面的去重语义
        // 不变（'13800000001' 与 int 13800000001 本就是同一个键，去重仍然正确），
        // 又让所有读方拿到的类型与它们的断言口径一致。
        //
        // 注意强转是**不一致**的：只有「纯数字」才变 int，带 `+86` 前缀或前导 0 的
        // （如 '+8613800000002'、'01380000001'）仍留在 string —— 所以这个缺陷表现为
        // 「部分号码能命中、部分不能」，比全量失效更难被发现。
        'phones' => array_map('strval', array_keys($phones)),
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
 *
 * **一个角色都识别不出来时按最小权限处理（空集），不再返回未加条件的查询。**
 * 这与姊妹函数 scopeLeadsForUser 是同一口径，也是同一教训：`roles` 漏写、或账号角色是
 * 已下架的旧角色码（`ASSIGNABLE` 里已不存在，但历史库里仍有这类账号）时，原先末尾的
 * `return $query;` 会让这个账号静默拿到**双店全量会员名单**——越权不该是默认值。
 *
 * 为什么选空集而不是「按 `user->venues` 收窄」：本函数的角色口径里并没有 venues 这一维。
 * `venues` 只服务于经营看板的门店下拉（见 applyVenueScope），而这里是**会员名单**的可见性；
 * 用 venues 收窄会给一个角色不明的账号开出「授权门店内全部会员」这种既非超管、也非店长的
 * 第三类权限，等于为提示「配置缺失」而先发一份名单出去。空集则与 scopeLeadsForUser 完全对称，
 * 且失败方向是「本人看不到数据（会被立刻上报）」而不是「别人看到不该看的数据（可能无人发现）」。
 * 修配置的路径很短：roles/role 任一列写对即刻恢复，不需要改代码。
 */
/**
 * 「前端客资/留资」的**唯一谓词**：`layer = 'P5'` 且**非 ky: 来源**。
 *
 * 为什么必须带 `ky:` 守卫：分层 `layer='P5'` 的业务含义是「**无卡项资产**」，
 * 这是对的——同步会员的卡项全部过期时他确实没有资产，落 P5 并不算错。
 * 错的是**消费方**把「P5」直接读成「留资」。`ky:` 前缀代表「来自 KeepYoga 同步」，
 * 这类行无论有无资产都是**正式会员**，绝不能当成前端客资。
 *
 * 实测事故：R_MEDIA（新媒体）账号的可见范围是裸的 `where('layer','P5')`，
 * 于是它能看到「卡项全部过期的会员」的姓名与手机号——那是越权，不是留资。
 * 今日待办的 newLeads、followups 与留资查重的 kind 判定也有同一个误读。
 *
 * 凡是要把 P5 当「留资」用的地方都必须走这里，与 `/customers?type=lead` 逐字一致。
 */
function isLeadOnlyCustomer(Customer $c): bool
{
    return (string) $c->layer === 'P5'
        && ! str_starts_with((string) $c->external_id, 'ky:');
}

/** 把「前端客资」谓词作用到查询上（与 isLeadOnlyCustomer 同义，供 query builder 用） */
function scopeLeadOnlyCustomers($query)
{
    return $query->where('layer', 'P5')
        ->where(fn ($w) => $w->whereNull('external_id')->orWhere('external_id', 'not like', 'ky:%'));
}

/** 「正式会员」谓词：非 P5，或虽落 P5 但来自 ky: 同步 */
function scopeMemberCustomers($query)
{
    return $query->where(fn ($w) => $w->where('layer', '!=', 'P5')->orWhere('external_id', 'like', 'ky:%'));
}

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
        // 新媒体只看前端客资——必须用「P5 且非 ky:」这个谓词，不能只看 layer。
        // 裸 where('layer','P5') 会把「卡项全部过期的正式会员」一并发出去（越权）。
        return scopeLeadOnlyCustomers($query);
    }

    // 兜底：一个角色都没识别出来（roles 漏写、或旧角色码已下架）——最小权限空集。
    // 原先这里 `return $query;` 等于给角色不明的账号发双店全量会员，与 scopeLeadsForUser 的
    // 收口口径不一致；`1 = 0` 与调用方后续叠加的任何过滤都只可能更窄，不会放大范围。
    return $query->whereRaw('1 = 0');
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

/**
 * 任务可见范围 —— **所有任务出口的唯一收口点**。
 *
 * 背景：任务可见范围此前在 6 处各写一遍（TaskController::index、TodayController 的
 * summary/alerts/todo、helpers::businessNotifications、以及经营看板那次 applyVenueScope 调用）。
 * 结果是每个出口都要各自记得补兜底，而「漏一处」的代价是他人任务静默可见：
 * 角色不明账号（roles 漏写、或 role 是已下架的旧角色码）在 /api/today/todo、/api/today/alerts
 * 上都能拿到**他人**任务的标题与客户姓名，/api/today/summary 与 /api/analytics/summary
 * 还在拿它们计数。现在角色识别与兜底只此一份，调用方只选口径（$surface），不再自己写角色分支。
 *
 * 兜底（本函数存在的主要理由）：一个已知角色都识别不出来 → 最小权限空集。
 * 与 scopeLeadsForUser / scopeCustomersForUser 同口径；角色全集取 ROLE_PRECEDENCE，
 * 将来新增角色只改那一处，这里自动跟着放开。
 *
 * $surface 保留各出口沿用至今的口径。**它们历史上确实不一致，本次刻意逐字保留**
 * （改口径会牵动前端已适配的展示，属于另一件事），只把「角色 → 范围」的知识与兜底收敛到此处：
 *
 *   surface    超管  店长      服务老师                授课老师      新媒体
 *   list       全部  本店      本店 + (本人∨待认领)    本店 + 本人   本人（跨店）
 *   alerts     全部  本店      本店 + (本人∨待认领)    本店 + 本人   **无**
 *   todo       全部  本店      本店 + (本人∨待认领)    本店 + 本人   本人（跨店，优先于门店）
 *   summary    全部  本店      (本人∨待认领)【不限门店】 本人【不限门店】 本人【不限门店】
 *   notify     全部  本店      本店 + 本人             本店 + 本人   本人（跨店）
 *
 * 多角色叠加的实测口径（同样是既存行为）：list/alerts/todo/summary 是**逐条 AND**（叠加只会更窄），
 * notify 是**if/elseif 首个命中**（店长 > 老师侧 > 新媒体）。
 * 注意这与会员/客资侧的「并集」口径相反，属既存差异，勿顺手改。
 *
 * 经营看板（/api/analytics/*）不在本函数的 surface 里：它走 applyVenueScope 的 venue 下推口径，
 * 那个函数自带同一个「角色不明 → 空集」兜底（helpers.php），两处共用 isKnownRoleUser 判据。
 *
 * 这些分歧本身是待收敛的技术债（同一份数据在不同页面口径不同，用户会看到"任务数对不上"），
 * 但收敛它们需要产品确认统一口径，不在本次范围；本次只消除「重复实现 + 兜底散落」。
 */
function scopeTasksForUser($query, User $user, string $surface = 'list')
{
    $roles = userRoles($user);
    $isSuper = in_array('R_SUPER', $roles, true);
    $isManager = in_array('R_MANAGER', $roles, true);
    $isService = in_array('R_SERVICE', $roles, true);
    $isTeacher = in_array('R_TEACHER', $roles, true);
    $isMedia = in_array('R_MEDIA', $roles, true);

    // 兜底：一个已知角色都没有 → 空集（判据与其它 scope 共用 isKnownRoleUser）。
    if (! isKnownRoleUser($user)) {
        return $query->whereRaw('1 = 0');
    }

    // 本人：id 或姓名/别名任一命中（staffOwnerFilter 口径）
    $own = fn ($q) => $q->where(staffOwnerFilter($user, 'owner_user_id', 'owner'));
    // 本人名下 + 待认领池
    $pool = fn ($q) => $q->where(fn ($w) => $w->where(staffOwnerFilter($user, 'owner_user_id', 'owner'))->orWhere('owner', '未分配'));

    if ($surface === 'notify') {
        // 历史口径是 if/elseif（首个命中即定），不是逐条 AND
        if ($isManager) {
            return $query->where('venue', $user->venue);
        }
        if ($isService || $isTeacher) {
            return $query->where('venue', $user->venue)->where($own);
        }
        if ($isMedia) {
            return $query->where($own);
        }

        return $query;
    }

    // 注意：这里**没有** R_SUPER 的早退分支。原实现在 list/summary/alerts/notify 里同样没有 ——
    // 超管单独一个角色时「不加任何条件」是「所有角色分支都不命中」的自然结果，
    // 而不是一条显式短路。若在这里补一条 `if ($isSuper) return $query;`，
    // 超管叠加老师侧角色时就会被放开成全量（实测 R_SUPER+R_SERVICE 的 riskCount 应从 4 变成 9），
    // 那是行为变更。保持与实测一致：逐条叠加、只收紧不放宽。

    if ($surface === 'todo' && $isMedia) {
        // 待办页把新媒体单独提前：按人（跨店），且不再叠加门店（历史口径）
        return $query->where($own);
    }

    if ($surface === 'todo') {
        // 原实现是「非新媒体」分支内的三条**顺序 if**（不是 elseif）：
        // 叠加服务老师+授课老师时两条都要生效（实测 todo=[G本人]，只留 pool 会多出待认领）。
        if (! $isSuper) {
            $query->where('venue', $user->venue);
        }
        if ($isService) {
            $query->where($pool);
        }
        if ($isTeacher) {
            $query->where($own);
        }

        return $query;
    }

    if ($surface === 'summary') {
        // 待办汇总：店长卡门店，老师侧/新媒体按人且**不限门店**（历史口径）。
        // 注意这里**没有** R_SUPER 短路分支 —— 超管叠加老师侧角色时仍会被按人收窄，
        // 这是既存行为（实测 R_SUPER+R_SERVICE 的 riskCount=4 而非 9），勿顺手"修正"。
        if ($isManager) {
            $query->where('venue', $user->venue);
        }
        if ($isService) {
            $query->where($pool);
        }
        if ($isTeacher || $isMedia) {
            $query->where($own);
        }

        return $query;
    }

    if ($surface === 'alerts') {
        if ($isManager) {
            $query->where('venue', $user->venue);
        }
        if ($isService) {
            $query->where('venue', $user->venue)->where($pool);
        }
        if ($isTeacher) {
            $query->where('venue', $user->venue)->where($own);
        }
        if ($isMedia) {
            // 逾期提醒对新媒体一律不展示（历史口径）
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    // list（GET /api/tasks）
    if ($isManager) {
        $query->where('venue', $user->venue);
    }
    if ($isService) {
        $query->where('venue', $user->venue)->where($pool);
    }
    if ($isTeacher) {
        $query->where('venue', $user->venue)->where($own);
    }
    if ($isMedia) {
        $query->where($own);
    }

    return $query;
}

/** 经营看板 venue 下推：超管看双店，新媒体按 venues 授权，店长/服务老师/授课老师锁定本店。 */
function applyVenueScope($query, User $user, string $venue)
{
    // 兜底：角色不明账号（roles 漏写、或 role 是已下架角色码）一律失败关闭。
    //
    // 这个兜底必须在门店分支**之前**：下面的 else 分支会把范围压成 `venue = 本人门店`，
    // 而角色不明账号的 venue 恰好等于本人门店时，本店**他人**的数据就会被计进来。
    // 实测影响 /api/analytics/summary 的 totalTasks（把别人家的任务算进自己的数字）与
    // leads/cards/bookings 的同类计数。该函数是经营看板的唯一 venue 口径入口
    // （AnalyticsController 全部 7 处调用都走它），所以在这里收口等于 7 个出口一次修好，
    // 不必去改调用方、也不新增重复实现。
    //
    // 口径与 scopeLeadsForUser / scopeCustomersForUser / scopeTasksForUser 一致：
    // 身份未确认时不猜范围。两种失败方向的代价不对称（空集=本人看不到自己的数据、会被立刻上报；
    // 放行=他人数据静默可见），故取空集。
    if (! isKnownRoleUser($user)) {
        return $query->whereRaw('1 = 0');
    }

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
    // 续费窗口**必须**与清单同源：此前这里自己重算了一遍阈值（且没有 m3 门槛），
    // 于是同一个会员能同时得到「在待续课清单里」与「续费窗口 0 分」两个相反结论（C4 口径分裂）。
    // 现在直接取唯一判定入口 customerDecision() 的结论，三处（会员管理页签 / 今日待办 / 续费评估）
    // 不可能再分歧。
    $decision = customerDecision($customer);
    $renewal = $decision['renewal'];
    // 续费窗口分：在清单 → 紧急 10 / 观察 5。
    // **不在清单但接近阈值 → 仍给 5**（保留 HEAD 的次级档语义）。
    //
    // 为什么必须保留次级档：HEAD 的 cardWindow 对「剩余量 ≤ 2×阈值」或
    // 「到期日落在 (阈值, 2×阈值]」的会员给 5 分。t6 一度把它压成「不在清单一律 0」，
    // 结果是一批**刚好在门槛外**的会员评估分整体下降一档（高机会 → 重点培育），
    // 店长会把它读成「数据出错了」。判定口径变了不该顺带改变评分尺度。
    $rulesForWindow = rules();
    $thresholdForWindow = (int) ($rulesForWindow['renewalThreshold'] ?? 10);
    $expireRuleForWindow = (int) ($rulesForWindow['renewalExpireDays'] ?? 30);
    $nearMiss = false;
    if (! $renewal['in']) {
        $statsForWindow = (array) ($customer->card_stats ?? []);
        $residueForWindow = array_key_exists('countResidue', $statsForWindow)
            ? $statsForWindow['countResidue']
            : $customer->remain_times;
        $nearMiss = ($residueForWindow !== null && (int) $residueForWindow <= $thresholdForWindow * 2)
            || ($expireDays !== null && $expireDays > $expireRuleForWindow && $expireDays <= $expireRuleForWindow * 2);
    }
    $cardWindow = $renewal['in'] ? ($renewal['urgent'] ? 10 : 5) : ($nearMiss ? 5 : 0);
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
        // 与清单同源的结论，供评估页直接展示「为什么他在/不在待续费清单」——
        // 治本做法：让店长能自查口径，而不是靠猜。
        'renewalIn' => $renewal['in'],
        'renewalBucket' => $renewal['bucket'],
        'renewalWhy' => $renewal['why'],
        'renewalDegraded' => $renewal['degraded'],
        'layer' => $decision['layer'],
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

/**
 * 清单阈值（只读）。
 *
 * **只读**是刻意的：`setting()` 是「带锁 firstOrCreate」的写路径，
 * 而 rules() 被清单引擎、分层重算、迁移回填在**只读上下文**里调用。
 * 若这里走 setting()，任何一次读取都会在库里凭空建出一个空的 AppSetting 行
 * （迁移回填期间尤其严重：迁移先建出 id=1 的空行，此后用户保存的配置
 * 若因并发或测试顺序落到 id=2，`oldest('id')` 就永远读不到——配置「保存了但不生效」）。
 * 配置行的创建只应发生在真正要写配置的地方（setRules / 设置页保存）。
 */
function rules(): array
{
    $s = AppSetting::oldest('id')->first();
    $defaults = [
        'renewalThreshold' => 10,
        'renewalCountPercent' => 20,
        'renewalExpireDays' => 30,
        'renewalExpirePercent' => 0,
        // 已过期但仍有余额的卡，只回溯最近 N 天（决策 D4）：
        // 陈年过期卡每天都挂在待续费清单里，会把清单训练成「不用看」。
        'renewalExpiredBackfillDays' => 90,
        'vipAmountThreshold' => 30000,
        'declineMode' => 'strict',
        'predropMin' => 15,
        'predropMax' => 30,
        'reviveDays' => 30,
        // 新客培养：入会 90 天内各类别课的「养成目标节数」（已上课节数达此值视为养成习惯），分别可调
        'cultivationPrivate' => 8,
        'cultivationSmall' => 12,
        'cultivationGroup' => 12,
        // 新媒体线上运营业绩机制（**算钱**的口径，全部可调，勿在别处硬编码）：
        //  mediaVisitReward  每个「时效内到店的线上新客」奖励金额（元/人），默认 20
        //  mediaValidMonths  时效月数 n：留资月 + 之后 (n-1) 个自然月内到店/成交才算新媒体新客。
        //                    默认 2 ⇒ 9.1 留资 → 10.31 前有效（留资月 + 下一个自然月）
        'mediaVisitReward' => 20,
        'mediaValidMonths' => 2,
    ];

    return array_merge($defaults, (array) ($s?->rules ?? []));
}

/**
 * 新媒体业绩参数（归一化：防止在配置里写成 0/负数/空串把奖励或时效算塌）。
 *
 * @return array{visitReward: float, validMonths: int}
 */
function mediaPerformanceParams(): array
{
    $rules = rules();
    $reward = $rules['mediaVisitReward'] ?? 20;
    $months = $rules['mediaValidMonths'] ?? 2;

    return [
        // 负值无意义；0 允许（临时停发奖励的运营手段），非数字回落默认值
        'visitReward' => is_numeric($reward) && (float) $reward >= 0 ? (float) $reward : 20.0,
        // 时效至少 1 个月（0 会让所有到店都失效，属误配），非数字回落默认值
        'validMonths' => is_numeric($months) && (int) $months >= 1 ? (int) $months : 2,
    ];
}

function setRules(array $rules): void
{
    unset($rules['vipThreshold']);
    $s = setting();
    // 合并写入：只更新本次提交的键，保留其余（含养成阈值）不被冲掉
    $s->update(['rules' => array_merge((array) ($s->rules ?? []), $rules)]);
    invalidateBusinessCaches('member_lists');
    // 阈值变了 → 经营分层也跟着变（P0 续费窗口直接用续费判定结论）。
    // 分层失败绝不能把「改阈值」这个动作一起回滚，所以单独兜住。
    try {
        recalculateMemberLayers();
    } catch (Throwable $e) {
        Log::warning('改阈值后重算经营分层失败', ['error' => $e->getMessage()]);
    }
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
    // 五清单只取 memberListCache() 的 lists 部分。
    //
    // **不要再在这里重写一遍指纹与键**：此前 memberListIds() 与 memberListCache() 各有一份
    // 键计算代码，加 MAX(id) 时只改了后者、前者仍是旧的两字段指纹 ——
    // 于是同一次请求里两个函数会算出**不同的缓存键**，「清单」与「清单明细」
    // 可能来自两次不同的扫描，正是我们在别处极力避免的同源分裂。
    // 缓存键的构造必须只有一处（见 memberListCache 的注释说明指纹粒度限制）。
    return memberListCache()['lists'];
}

/**
 * 清单明细：与 memberListIds() **同一次扫描、同一份缓存**，因此不会出现
 * 「徽标计数说 5 个、列表里 4 个」这种同源分裂。
 *
 * 返回：
 *   - 待续费明细：id => [why[], bucket, urgent, revive, remain, expireDays, degraded, ...]
 *   - 待开卡明细：id => [titles[], count]（未开卡卡项；D5：不新增第 6 个清单页签）
 *   - 经营分层：  id => 'P0'..'P5'
 */
function memberListWatch(): array
{
    return memberListCache()['watch'];
}

/** 五清单 + 明细的唯一缓存入口 */
function memberListCache(): array
{
    $rules = rules();
    // 指纹 = MAX(updated_at) + MAX(id) + COUNT(*)。
    //
    // **粒度限制（务必知悉）**：`MAX(updated_at)` 在 MySQL 的 DATETIME 精度下可能同秒相同，
    // 所以「同一秒内的多次写入」指纹不变、缓存不会换键——理论上会读到最多 120 秒的旧值。
    // 之所以还留着它：它是「某条写入路径忘了调 invalidateBusinessCaches()」时的**自愈兜底**，
    // 去掉会让清单永久发旧值（比慢 120 秒严重得多）。
    // 加 MAX(id) 是为了让**新增**行立刻换键（id 单调递增，不受时间精度影响）；
    // 同秒内的**更新**仍靠显式失效兜住，这条限制是已知且可接受的。
    $fingerprint = Customer::query()
        ->selectRaw('MAX(updated_at) as mu, MAX(id) as mid, COUNT(*) as cnt')
        ->first();
    $key = 'member_lists:v'.businessCacheVersion('member_lists')
        .':'.md5(json_encode($rules))
        .':'.($fingerprint?->mu ?? '0').':'.($fingerprint?->mid ?? '0').':'.$fingerprint?->cnt;

    return Cache::remember($key, 120, fn () => computeMemberLists($rules));
}

/** 兼容旧调用：只取 ID 清单时请直接用 memberListIds() */
function computeMemberListIds(array $rules): array
{
    return computeMemberLists($rules)['lists'];
}

/**
 * 经营分层定义（唯一口径来源，键固定 P0-P5）。
 *
 * 分层是**互斥的单值**，按「先算哪个」的优先级从高到低排列：一个会员只落一层。
 * P5 的语义必须保持「无卡项资产 = 前端客资/留资」不变——`scopeCustomersForUser()`
 * 的新媒体分支以及 `/customers?type=lead` 都依赖它，所以判定条件与那些授权条件
 * **必须字面一致**（见 `scopeLeadOnlyCustomers()` / `scopeMemberCustomers()`）。
 *
 * **P5 的判据是「无资产」，不是「main_card 为空」**（t23 修正）：
 * `main_card` 只是资产的**汇总证据之一**，且它在「有卡但上游没给卡标题」时是空串
 * （`pick()` 返回 ''，而 '' 在排除表里）。只看它会把有真实资产的会员误判成客资 →
 * 既从会员列表消失，又可能被新媒体读到 PII。现在 `customerDecision()` 用
 * 「汇总字段 或 卡项明细（liveCount/liveTime/unknownResidue）」任一为证判定资产。
 *
 * 该放宽**不会**扩大 P5 授权面，依据是一条不变量：
 *   `main_card === '—'` 只在 `$active` 为空时出现，而 `cards_list` 同样映射自 `$active`，
 *   故 `main_card='—'` 必然蕴含 `cards_list` 为空 → 三项明细证据全空 → 仍判无资产。
 * 即新增证据只可能把「确实有卡」的会员从 P5 拉出来，绝不会把真客资推进 P5。
 * （回归 `test_asset_detection_falls_back_to_cards_list` / `test_real_lead_still_lands_p5` 锁这条不变量。）
 *
 * 顺带说明为什么 P0 只看「紧急」：P0 是「立刻要打电话」的池子，
 * 观察态（近 30 天没来但有余额）的会员在 P1/P2 里更合适，塞进 P0 会让池子失去优先级意义。
 */
function layerDefinitions(): array
{
    return [
        'P0' => ['label' => '续费窗口', 'desc' => '命中续费判定（课时尾段 / 临期 / 已过期但有余量）'],
        'P1' => ['label' => '高资产低活跃', 'desc' => '停练超过 reviveDays 阈值、或人工标记待复活（有卡项资产）'],
        'P2' => ['label' => '频次下降', 'desc' => '仍在出勤、近三档 30 天窗口逐月下降（M1>M2>M3 且 M3>0，优先于待复活落层）；停练的递减者不在此层'],
        'P3' => ['label' => '过期有余额', 'desc' => '到期日已过、仍有余量，但**超出** renewalExpiredBackfillDays 回溯窗（未命中待续费）'],
        'P4' => ['label' => '可升级', 'desc' => '有卡项资产、未命中以上任何一层'],
        'P5' => ['label' => '新客转化', 'desc' => '无卡项资产（前端客资/留资）'],
    ];
}

/**
 * 单会员判定唯一入口：五清单、经营分层、续费评估三处共用同一份结论。
 *
 * 这是 C4「口径分裂」的治本修法——此前会员管理页签（`computeMemberListIds`）与
 * 续费评估（`renewalEvaluationContext`）各自实现一套阈值判断，同一个会员能同时
 * 得到「在待续课清单里」和「续费窗口 0 分」两个相反结论。现在只有一个实现。
 *
 * @return array{
 *   hasAsset:bool, m1:int, m2:int, m3:int, lastVisitDays:?int, expireDays:?int,
 *   revive:bool, preLoss:bool, declining:bool, vip:bool,
 *   hasBalance:bool, expiredWithBalance:bool, layer:string,
 *   renewal:array{in:bool, why:string[], bucket:string, urgent:bool, revive:bool, degraded:string[]}
 * }
 */
function customerDecision(Customer $c, ?array $rules = null): array
{
    $rules ??= rules();
    $threshold = (int) ($rules['renewalThreshold'] ?? 10);
    $countPercent = (int) ($rules['renewalCountPercent'] ?? 0);
    $expireDaysRule = (int) ($rules['renewalExpireDays'] ?? 30);
    $expirePercent = (int) ($rules['renewalExpirePercent'] ?? 0);
    // 已过期但仍有余额的卡：只回溯最近 N 天（决策 D4）。再久远的过期卡属于
    // 「陈年旧账」，每天出现在待续费清单里会训练店长忽略这个清单。
    $backfillDays = (int) ($rules['renewalExpiredBackfillDays'] ?? 90);
    $vip = (float) ($rules['vipAmountThreshold'] ?? 30000);
    $reviveDays = (int) ($rules['reviveDays'] ?? 30);
    $predropMin = (int) ($rules['predropMin'] ?? 15);
    $predropMax = (int) ($rules['predropMax'] ?? 30);

    $m1 = (int) $c->attend_m1;
    $m2 = (int) $c->attend_m2;
    $m3 = (int) $c->attend_m3;
    $dd = $c->last_visit ? (int) ((time() - strtotime((string) $c->last_visit)) / 86400) : null;
    $mainCard = $c->main_card;
    $expireDays = $c->expire_date ? (int) now()->startOfDay()->diffInDays($c->expire_date, false) : null;
    $paid = (float) ($c->card_paid_amount ?? 0);

    $stats = (array) ($c->card_stats ?? []);
    $countBound = (int) ($stats['countBound'] ?? 0);
    $daysLeft = $stats['daysLeft'] ?? null;
    $daysTotal = (int) ($stats['daysTotal'] ?? 0);
    $cards = is_array($c->cards_list) ? $c->cards_list : [];
    // 过期卡保留区（card_stats.expiredCards，由 KyMemberSyncService 写入）。
    // ⚠️ 它**只服务于「炸弹会员」清单**，绝不参与待续费判定：
    // 待续费的分类循环只读 $cards（= cards_list），而保留区的卡**不在** cards_list 里。
    $expiredCards = is_array($stats['expiredCards'] ?? null) ? $stats['expiredCards'] : [];

    $why = [];
    $whyCodes = [];
    $degraded = [];
    $bucket = '—';
    $liveCount = $liveTime = [];
    // 到期/过期类命中：**结构化标记**，供 $urgent 读取。
    // 不能用「在 $why 文案里找『到期/过期』字样」来判定——$why 内嵌卡名，
    // 一张叫「到期提醒卡」的卡即使 deadline 远在窗外，也会让会员被误判为紧急。
    // 文案是给人看的，判定必须看标记。
    $deadlineHit = false;

    // ── 分类必须放在判定 $hasAsset **之前**：cards_list 也是资产证据（见下） ──
    // 分类：用完的卡 / 未开卡 / 余额未知（C2 + C3 + C7）
    //
    // ⚠️ 这个循环是卡项状态的**唯一分派点**。下游任何「某类卡项存不存在」的判断
    // 都必须由这里派生（计数/集合），**不得再逐类列举状态名** —— 见下面两处证据集的注释。
    $unactivated = [];
    $unknownResidue = [];
    $exhaustedCount = false;
    foreach ($cards as $card) {
        if (! empty($card['unactivated'])) {
            $unactivated[] = $card;

            continue;
        }
        $isTime = array_key_exists('type', $card)
            ? (string) $card['type'] === '2'
            : (($card['unit'] ?? '节') === '天');
        $residue = $card['residue'] ?? null;
        if ($residue === null) {
            // 「余额未知」≠「余额为 0」。上游没返回 residue_amount 时不能断言课时已用完，
            // 更不能据此判紧急——那是在用缺失数据下结论。单独收集，走 degraded 显式告知。
            $unknownResidue[] = $card;

            continue;
        }
        if ((int) $residue <= 0) {
            // 只有**确认** residue === 0（有数值）才算「课时已耗尽」
            if (! $isTime) {
                $exhaustedCount = true;
            }

            continue;
        }
        $isTime ? $liveTime[] = $card : $liveCount[] = $card;
    }
    if ($unknownResidue !== []) {
        $titles = implode('、', array_slice(array_map(fn ($x) => (string) ($x['title'] ?? '卡项'), $unknownResidue), 0, 3));
        $degraded[] = "「{$titles}」未返回剩余量，该卡不参与「课时耗尽/剩余量」判定（可能漏提醒）";
    }
    // 已耗次数推导不出的卡：分母只含剩余节数 → 占比**偏高**（少报尾段，不会误报）。
    // 这是显式降级：用户调了「次卡剩余占比」却看不到命中时，必须能查到原因，
    // 否则又是一次「阈值可调但无效」。**不允许**为此凭空补一个已耗次数。
    if ((int) ($stats['countBoundUnderivable'] ?? 0) > 0) {
        $degraded[] = '部分次卡未返回单次折算价与已耗次数，剩余占比按「仅剩余节数」保守计算（可能少报尾段）';
    }

    // ── 有无卡项资产：**单点派生**，不列举卡项状态 ──
    //
    // `cards_list` 非空 ⟺ 名下至少有一张有效卡（它映射自 KyMemberSyncService 的 `$active`），
    // 所以「明细非空」本身就是「有资产」的充要证据。汇总字段 `main_card` 只作为**兜底**：
    // 老数据/未同步行的 cards_list 为 NULL 而 main_card 有值，那些会员必须仍算有资产。
    //
    // ⚠️ 为什么不再逐类列举（这正是 t19-F1 → t24-F1 → t24-F2 连续三轮的根因）：
    // 此处曾写作 `$hasAssetFromSummary || $liveCount !== [] || $liveTime !== [] || $unknownResidue !== []`。
    // 那种写法要求**每次给分类循环加一种卡项状态，都要回来同步这份枚举**——
    // t23 补了 liveCount/liveTime/unknownResidue，却漏了 unactivated，于是
    // 「未开卡·有标题」→ hasAsset=true，而「未开卡·无标题」（main_card 为空串）→ hasAsset=false → 落 P5。
    // 仅差一个 card_title 就让同一张未开卡卡项的结论翻转，且落 P5 会把它当成前端客资（PII 面）。
    // 现在改为「明细非空 或 汇总有卡名」：**新增任何卡项状态都不需要再改这里**，
    // 因为任何新状态都出现在 cards_list 里，cards_list 非空即已覆盖。
    //
    // 授权面不变（P5 只可能变窄，不会变宽）——依据是一条已验证的不变量：
    //   `main_card === '—'` 只在 `$active` 为空时出现，而 cards_list 同样映射自 `$active`，
    //   故 `main_card='—'` **必然蕴含** cards_list 为空 → 两个析取项都为 false → hasAsset 仍为 false。
    // 即：本判定只可能在「确实有卡」时把会员从 P5 **拉出来**，绝不会把真客资推进 P5。
    // 真客资（无卡、main_card='—'）依旧落 P5，`scopeLeadOnlyCustomers()` 的授权面不变。
    $hasAssetFromSummary = $mainCard !== null && ! in_array($mainCard, ['', '—', '待同步卡项'], true);
    $hasAsset = $cards !== [] || $hasAssetFromSummary;

    // m1/m2/m3 是三个连续且等长的 30 天滚动窗口（再前30天 / 前30天 / 近30天）：
    // 三档等长才使下面的「逐档下降」比较有意义。
    $revive = (bool) $c->in_revive || ($dd !== null && $dd > $reviveDays && $hasAsset);
    $preLoss = ! $revive && $dd !== null && (($m2 > 0 && $m3 === 0) || ($dd >= $predropMin && $dd <= $predropMax));
    // 「出勤降低」必须读配置里的 declineMode（用户可调），否则就是「可调但无效」的阈值。
    // HEAD 原本就读它，t6 一度硬编码成 strict —— 那是回归，已改回。
    // 分层 P2 仍固定用 strict（见下面 $decliningForLayer）：分层表达的是「趋势」，
    // 不应该因为店长调了「清单阈值」而换层，两者是不同用途，故用两个变量。
    $declineStrict = ($rules['declineMode'] ?? 'strict') === 'strict';
    $declining = ! $revive && ! $preLoss
        && ($declineStrict ? ($m1 > $m2 && $m2 > $m3) : ($m2 > $m3));

    if (! $hasAsset) {
        // 无卡项资产：不进任何续费判定（P5 前端客资/留资）
        $bucket = '无资产';
    } else {

        if ($cards === []) {
            // ── 老数据/未同步：无卡项明细，退回汇总口径，占比规则**明确降级** ──
            // C9：此前这里的分支表达式 `(string) $c->remain_times !== null` 恒为真（死代码），
            // 且占比类规则在 card_stats 为 NULL 时静默失效——用户调了阈值「没反应」却查不到原因。
            // 现在把「哪条规则没生效」写进 degraded，由接口显式返回。
            // 汇总口径优先取 card_stats.countResidue（同步写入），仅在完全没有快照时才用
            // remain_times（更老的单主卡字段）。
            $countResidue = array_key_exists('countResidue', $stats) ? $stats['countResidue'] : $c->remain_times;
            if ($countPercent > 0 && $countBound <= 0) {
                $degraded[] = '无卡项汇总快照，剩余占比规则未生效（请先执行一次同步）';
            }
            if ($expirePercent > 0 && ($daysLeft === null || $daysTotal <= 0)) {
                $degraded[] = '无期限卡汇总快照，有效期占比规则未生效（请先执行一次同步）';
            }
            if ($countResidue !== null && $countResidue <= $threshold) {
                $why[] = "次卡剩余合计 {$countResidue} 节 ≤ {$threshold}";
            } elseif ($countPercent > 0 && $countBound > 0 && $countResidue !== null
                && $countResidue / $countBound * 100 <= $countPercent) {
                $why[] = '次卡剩余占比 '.round($countResidue / $countBound * 100, 1)."% ≤ {$countPercent}%（余 {$countResidue} 节）";
            }
        } else {
            // ── 在用次卡：合计口径（4a）+ 逐卡尾段（4b）并存（决策 D2） ──
            if ($liveCount !== []) {
                $residueSum = array_sum(array_map(fn ($x) => (int) $x['residue'], $liveCount));
                $boundSum = array_sum(array_map(fn ($x) => (int) ($x['bound'] ?? $x['residue']), $liveCount));
                if ($residueSum <= $threshold) {
                    $why[] = "在用次卡合计余 {$residueSum} 节 ≤ {$threshold}";
                } elseif ($countPercent > 0 && $boundSum > 0 && $residueSum / $boundSum * 100 <= $countPercent) {
                    $why[] = '在用次卡合计占比 '.round($residueSum / $boundSum * 100, 1)."% ≤ {$countPercent}%（余 {$residueSum} 节）";
                }
                // 逐卡：合计口径会掩盖「一张卡已到尾段」（合计 52 节，但其中一张只剩 2 节）
                foreach ($liveCount as $card) {
                    $cardResidue = (int) $card['residue'];
                    $cardBound = (int) ($card['bound'] ?? $cardResidue);
                    $title = (string) ($card['title'] ?? '卡项');
                    if ($cardResidue <= $threshold) {
                        $why[] = "单卡「{$title}」仅余 {$cardResidue} 节 ≤ {$threshold}（合计仍有 {$residueSum} 节）";

                        break;
                    }
                    if ($countPercent > 0 && $cardBound > 0 && $cardResidue / $cardBound * 100 <= $countPercent) {
                        $why[] = "单卡「{$title}」占比 ".round($cardResidue / $cardBound * 100, 1)."% ≤ {$countPercent}%（余 {$cardResidue} 节）";

                        break;
                    }
                }
            }
            // 次卡课时已耗尽（无在用次卡，但**确认**有次卡 residue=0 在册）：
            // 卡在、课没了，正是该续课的时候。
            // 注意这里用 $exhaustedCount（只有 residue === 0 才算），
            // 不用「有次卡但余额未知」——那是拿缺失数据下结论。
            if ($liveCount === [] && $exhaustedCount) {
                $why[] = '在用次卡课时已耗尽（无剩余课时）';
                $whyCodes[] = 'count_exhausted';
            }
            // ── 待开卡：**确实只有未开卡卡项**时才算（C7） ──
            //
            // ⚠️ 这里刻意**不列举**卡项状态（不再写「没有 liveCount、没有 liveTime、
            // 没有 unknownResidue、没有 exhaustedCount」）。那种写法要求每次给分类循环
            // 新增一种状态都回来补一格，而**漏补不会报错、只会静默改变判定**：
            // t19-F1 补了 unknownResidue、t24-F1 又发现漏了 exhaustedCount —— 同一处连续两轮漏项。
            //
            // 现在改为**计数断言**：「$cards 里每一项都进了 $unactivated」。
            // 分类循环对每张卡必然二选一（进 $unactivated，或进其余某类），
            // 所以 `count($unactivated) === count($cards)` ⟺ 除未开卡外没有任何其他卡项。
            // **新增任何卡项状态都不需要改这里**：新状态必然不是 unactivated，
            // 于是计数不再相等、自动退出待开卡分支。
            //
            // 实测漏判（修复前）：未开卡次卡 + 一张「已确认耗尽·到期+5天」的次卡
            //   → 旧 guard 漏看 $exhaustedCount，判「待开卡」→ 下行的
            //     `$in = $hasAsset && $why !== [] && $bucket !== '待开卡'` 把 why 里
            //     **已经算出的**「在用次卡课时已耗尽」整个否决 → in=false/P4，
            //     而「只有那张已耗尽次卡」时是 in=true/P0 —— 加一张未开卡卡项就把信号抹掉。
            if ($unactivated !== [] && count($unactivated) === count($cards)) {
                // D5：不新增第 6 个清单页签，只在 reason 里说明。
                $bucket = '待开卡';
            }
        }

        if ($bucket !== '待开卡') {
            // ── 逐卡到期提醒（C3）：只看**还有余量**的卡，不再用全局最早 expire_date ──
            foreach (array_merge($liveCount, $liveTime) as $card) {
                $days = $card['deadline']
                    ? (int) now()->startOfDay()->diffInDays($card['deadline'], false)
                    : null;
                if ($days === null) {
                    continue;
                }
                $title = (string) ($card['title'] ?? '卡项');
                $unit = (string) ($card['unit'] ?? '节');
                if ($days >= 0 && $days <= $expireDaysRule) {
                    $why[] = "「{$title}」{$days} 天后到期（仍余 {$card['residue']}{$unit}）";
                    $whyCodes[] = 'deadline_near';
                    $deadlineHit = true;
                } elseif ($days < 0 && $days >= -$backfillDays) {
                    // C8：已过期但仍有余额——卡里的课还能上（或需补偿），此前完全漏判
                    $why[] = "「{$title}」已过期 ".abs($days)." 天，仍余 {$card['residue']}{$unit}";
                    $whyCodes[] = 'deadline_expired';
                    $deadlineHit = true;
                }
            }
            // ── 余额未知的**期限卡**：不能静默。
            // 期限卡的价值在于「有效期」，即使上游没给剩余天数，只要 deadline 在窗外，
            // 仍应按到期日单独判——这正是 R6 指出的漏提醒路径。
            foreach ($unknownResidue as $card) {
                if (! (array_key_exists('type', $card) ? (string) $card['type'] === '2' : ($card['unit'] ?? '节') === '天')) {
                    continue; // 次卡余额未知：已进 degraded，不猜
                }
                $days = $card['deadline']
                    ? (int) now()->startOfDay()->diffInDays($card['deadline'], false)
                    : null;
                if ($days === null) {
                    continue;
                }
                $title = (string) ($card['title'] ?? '卡项');
                if ($days >= 0 && $days <= $expireDaysRule) {
                    $why[] = "「{$title}」{$days} 天后到期（该卡未返回剩余量，按到期日判定）";
                    $whyCodes[] = 'deadline_near';
                    $deadlineHit = true;
                } elseif ($days < 0 && $days >= -$backfillDays) {
                    $why[] = "「{$title}」已过期 ".abs($days).' 天（该卡未返回剩余量，按到期日判定）';
                    $whyCodes[] = 'deadline_expired';
                    $deadlineHit = true;
                }
            }
            // 汇总口径的到期日（无卡项明细时的唯一依据；有明细时作为补充）
            if ($cards === [] && $expireDays !== null) {
                if ($expireDays >= 0 && $expireDays <= $expireDaysRule) {
                    $why[] = "到期日临近（{$expireDays} 天后）";
                    $whyCodes[] = 'deadline_near';
                    $deadlineHit = true;
                } elseif ($expireDays < 0 && $expireDays >= -$backfillDays) {
                    $why[] = '到期日已过 '.abs($expireDays).' 天';
                    $whyCodes[] = 'deadline_expired';
                    $deadlineHit = true;
                }
            }
            // ── 有效期占比（汇总口径，保持 daysLeft/daysTotal 的「在用期限卡合计」语义） ──
            if ($expirePercent > 0 && $daysLeft !== null && $daysTotal > 0
                && $daysLeft / $daysTotal * 100 <= $expirePercent) {
                $why[] = '在用期限卡有效期仅剩 '.round($daysLeft / $daysTotal * 100, 1).'%';
                $whyCodes[] = 'validity_percent';
            }
        }
    }

    $in = $hasAsset && $why !== [] && $bucket !== '待开卡';
    // 出勤不再是门槛，只决定紧急度（C4）。
    // 「到期/过期」类命中即使近 30 天没来也必须紧急——卡马上要作废，等会员自己回来就来不及了。
    // 这里读**结构化标记** $deadlineHit，不做文案匹配：$why 里内嵌卡名，
    // 一张叫「到期提醒卡」的卡会把 str_contains 命中，让观察态误升为紧急态。
    $urgent = $in && ($m3 > 0 || $deadlineHit);
    if ($in) {
        $bucket = $urgent ? '待续费·紧急' : '待续费·观察';
    } elseif ($bucket !== '待开卡' && $bucket !== '无资产') {
        $bucket = '—';
    }

    // 有余额（用于 P3 过期有余额、以及 C8 的「过期但还有课」判断）
    $hasBalance = ($liveCount !== [] || $liveTime !== [])
        || (int) ($c->remain_times ?? 0) > 0
        || ($daysLeft !== null && (int) $daysLeft > 0);
    $expiredWithBalance = $hasAsset && $expireDays !== null && $expireDays < 0 && $hasBalance;

    // ── 炸弹会员（用户新增需求 2026-09-24；2026-09-25 补第 5 条）──────────
    // 口径（五条必须同时成立）：
    //   1. 正式会员 —— 由**查询侧**的 scopeMemberCustomers() 保证（见 computeMemberLists），
    //      本函数只看卡项，不重复判「是不是客资」：那是授权面的事，两处各判一次必然分叉。
    //   2. 持有次卡（type=1，排除 is_taste / 体验·员工·测试）
    //   3. 该卡有余额（residue > 0）
    //   4. 已过期且超过 6 个月（deadline < 今天 − 183 天）
    //   5. 超过 6 个月没有出勤记录（用户 2026-09-25 补充口径）：
    //      last_visit 为 null（从未到店）视为满足；有 last_visit 但距今未超过
    //      $bombDays 天的排除。口径与卡过期同用 $bombDays（用户定义里都是「6 个月」）。
    //      注意是「最近一次出勤距今超 6 个月」，不是「从未出勤」——人只要 184 天前
    //      来过一次就永远不再算炸弹，除非中间又来一次把计时刷新。
    //
    // 为什么「卡有余额 + 已过期」不会同时进待续费：过期卡（status≠4/5/7）不参与
    // ACTIVE_CARD_STATUSES，因此不在 cards_list、不参与任何续费分支；
    // 它们**只**出现在 card_stats.expiredCards 保留区里，专供本判定消费。
    //
    // deadline 是 Unix 时间戳 —— 由 KyMemberSyncService::date() 统一转成了 Y-m-d，
    // 这里再解析成日期（**不要**直接当字符串比大小）。
    $bombDays = (int) ($rules['bombExpiredDays'] ?? 183);
    $bombCards = [];
    $bombSections = 0;
    foreach ($expiredCards as $card) {
        $deadline = $card['deadline'] ?? null;
        if (! is_string($deadline) || $deadline === '') {
            continue;
        }
        $days = (int) now()->startOfDay()->diffInDays(CarbonImmutable::parse($deadline), false);
        if ($days >= -$bombDays) {
            continue;   // 未过期，或过期未满 6 个月
        }
        $bombCards[] = $card + ['daysExpired' => abs($days)];
        $bombSections += (int) ($card['residue'] ?? 0);
    }
    // 口径 5：超过 6 个月没有出勤。$dd 即函数前部算好的 lastVisitDays
    // （last_visit 距今天数；last_visit 为 null 时 $dd 也为 null，表示从未到店）。
    // 从未到店的人比「183 天前来过」更久没来，直接满足。
    $bombLastVisitOk = $c->last_visit === null
        || ($dd !== null && $dd > $bombDays);
    $bomb = $bombCards !== [] && $bombLastVisitOk;

    // 分层优先级：P0 → P2(仍在出勤) → P1 → P2(停练兜底) → P3 → P4，先命中先落层（互斥单值）。
    //
    // P0 放最前是**必须**的：真实数据里「待续费」与「待复活」重叠率 100%
    // （见诊断文档 C5），若把待复活放在 P0 之前，待续费窗口池会被整池搬空——
    // 那正是「P0 恒为空」的另一种成因。重叠会员的展示优先级由 watch 里的
    // primary/coLists 表达（D3：不隐藏，只分主次），不由分层表达。
    //
    // P2 用**独立常量 strict**（M1>M2>M3），不跟随 declineMode：
    // declineMode 是「出勤降低」**清单**的可调阈值（店长改它期望清单变化），
    // 而分层表达的是长期趋势，不应因为店长调清单阈值而整层换位。
    // 两者用途不同，所以是两个变量：$declining（清单，读配置）/ $decliningForLayer（分层，固定 strict）。
    //
    // ── P2 拆成两档、且「仍在出勤」档提到 P1 之前（2026-09-24 修复） ──
    //
    // 缺陷形态（用户报告「P2 只剩 2 人」的根因）：旧链是 P0 → P1 → P2，
    // 而 $revive = dd > reviveDays(30) 没来。频次下降（M1>M2>M3）的人出勤在萎缩，
    // last_visit 往往也越来越久远——只要超过 30 天就先命中 P1，P2 被整层吃空：
    // 频次下降最严重的会员（M3 已归零、dd 必然 > 30）全部进 P1，
    // P2 只剩「还在来且递减」的极小样本，经营价值归零。
    //
    // 修法依据 P2 的经营语义「正在流失、还来得及拦」把严格递减拆成两档：
    //   $decliningActive（主档）：M3 > 0 且严格递减——本月还来，趋势下降是**主动信号**，
    //     排到 P1 之前落 P2。M3 > 0 蕴含 last_visit 在 30 天内，与 $revive 天然互斥，
    //     但必须显式排前：否则「仍在出勤的递减者」会先被 P1 抢走（正是本缺陷）。
    //   $decliningForLayer（兜底档）：不要求 M3 > 0 的严格递减——已停练的递减者
    //     理论上会被 $revive / $preLoss 捕获（M3=0 且 M2>0 直接命中 $preLoss；
    //     dd>30 命中 $revive），此分支仅兜住两者都没接住的残余（例如 M2=M3=0
    //     且 dd 在 reviveDays 内、未开卡的严格递减者），放回 P1 之后维持旧行为，
    //     不扩大 P2 的授权面。
    // 停练者（M3=0）不是「频次下降」，是「已经流失」——那是 P1 待复活的画像（验收 2）。
    $decliningActive = $m3 > 0 && ($m1 > $m2 && $m2 > $m3);
    $decliningForLayer = ! $revive && ! $preLoss && ($m1 > $m2 && $m2 > $m3);
    $layer = ! $hasAsset ? 'P5'
        : ($in ? 'P0'
            : ($decliningActive ? 'P2'
                : ($revive ? 'P1'
                    : ($decliningForLayer ? 'P2'
                        : ($expiredWithBalance ? 'P3' : 'P4')))));

    return [
        'hasAsset' => $hasAsset,
        'm1' => $m1, 'm2' => $m2, 'm3' => $m3,
        'lastVisitDays' => $dd,
        'expireDays' => $expireDays,
        'revive' => $revive,
        'preLoss' => $preLoss,
        'declining' => $declining,
        'vip' => $paid >= $vip,
        'hasBalance' => $hasBalance,
        'expiredWithBalance' => $expiredWithBalance,
        // 炸弹会员：正式会员 + 次卡有余额 + 已过期超 6 个月 + 超 6 个月未到店（口径见上方 $bomb 注释）
        'bomb' => ['in' => $bomb, 'sections' => $bombSections, 'cards' => $bombCards],
        'layer' => $layer,
        'renewal' => [
            'in' => $in,
            'why' => $why,
            // 结构化命中类型（deadline_near / deadline_expired / count_exhausted / validity_percent…）。
            // $urgent 只读这些标记，不做文案匹配；前端也可按 code 分类展示，不必解析中文。
            'whyCodes' => $whyCodes,
            'deadlineHit' => $deadlineHit,
            'bucket' => $bucket,
            'urgent' => $urgent,
            'revive' => $revive,
            'degraded' => $degraded,
        ],
    ];
}

/** 单会员经营分层（P0-P5），口径见 layerDefinitions() */
function customerLayerFor(Customer $c, ?array $rules = null): string
{
    return customerDecision($c, $rules)['layer'];
}

/**
 * 按 id 重算**单个**会员的经营分层并落库，返回是否发生变化。
 *
 * 这是「派生列必须在写入判定输入后重算」的**单一 owner**：任何改动过
 * `in_revive` / `last_visit` / `card_stats` / `cards_list` / `attend_m*` 等
 * 判定输入列的写入方，都应该调它（或调全量版 recalculateMemberLayers()），
 * 而不是各自记得手动写 layer。
 *
 * 之所以要有这个函数：t6 只在「同步」与「改阈值」两处触发了重算，
 * 漏掉了 `PATCH /customers/{id}` 写 `in_revive` 这条路径——店长点「标记待复活」后，
 * 分层不会跟着变，`layer` 与 `customerLayerFor()` 当场不一致，
 * 而经营池分层页面读的正是 `layer` 列。派生列与判定函数分叉是最难查的一类 bug：
 * 页面上显示 P4、详情页算出来 P1，两边都「看起来对」。
 *
 * 与全量版共用同一口径（customerDecision），且同样不碰 updated_at。
 */
function recomputeLayerFor(int $id): bool
{
    if (! Schema::hasTable('customers') || ! Schema::hasColumn('customers', 'layer')) {
        return false;
    }
    $c = Customer::query()
        ->select([
            'id', 'layer', 'main_card', 'remain_times', 'expire_date', 'last_visit',
            'attend_m1', 'attend_m2', 'attend_m3', 'in_revive', 'card_paid_amount',
            'card_stats', 'cards_list',
        ])
        ->find($id);
    if (! $c) {
        return false;
    }
    $target = customerDecision($c)['layer'];
    if ((string) $c->layer === $target) {
        return false;
    }
    // query builder：不触发 Eloquent 的 updated_at 自动维护（见 recalculateMemberLayers 注释）
    DB::table('customers')->where('id', $id)->update(['layer' => $target]);

    return true;
}

/**
 * 重算全部会员的经营分层并落库，返回实际改动的行数。
 *
 * 三个必须守住的约束：
 *  1. **不碰 `updated_at`**。五清单缓存的键含 `MAX(updated_at)`（见 memberListIds），
 *     本函数在每次同步后都会跑，若写 updated_at 会每轮击穿清单缓存。
 *     所以用 query builder 的 update（不触发 Eloquent 时间戳），而非 $customer->save()。
 *  2. **幂等且廉价**：先算目标分层、与现值比对，只更新真正变化的行；无变化时零写入。
 *  3. **绝不因分层失败而回滚同步**：调用方是同步主流程，分层只是派生数据。
 *     本函数自身不做事务，调用方按需 try/catch。
 *
 * P5 的语义必须保持「无资产 = 前端客资」不变（授权条件依赖它），
 * 所以无资产的会员会被写成 P5，而不是「跳过不动」——否则一次误判留在 P4 就再也不会被纠正。
 */
function recalculateMemberLayers(): int
{
    if (! Schema::hasTable('customers') || ! Schema::hasColumn('customers', 'layer')) {
        return 0;
    }

    $rules = rules();
    $changed = 0;

    Customer::query()
        ->select([
            'id', 'layer', 'main_card', 'remain_times', 'expire_date', 'last_visit',
            'attend_m1', 'attend_m2', 'attend_m3', 'in_revive', 'card_paid_amount',
            'card_stats', 'cards_list',
        ])
        ->chunkById(500, function ($customers) use (&$changed, $rules) {
            $byLayer = [];
            foreach ($customers as $c) {
                $target = customerDecision($c, $rules)['layer'];
                if ((string) $c->layer !== $target) {
                    $byLayer[$target][] = $c->id;
                }
            }
            foreach ($byLayer as $layer => $ids) {
                foreach (array_chunk($ids, 500) as $batch) {
                    // query builder：不触发 Eloquent 的 updated_at 自动维护
                    DB::table('customers')->whereIn('id', $batch)->update(['layer' => $layer]);
                    $changed += count($batch);
                }
            }
        });

    return $changed;
}

/**
 * 五清单 + 明细的唯一计算实现。
 *
 * @return array{lists:array<string,int[]>, watch:array<string,array>}
 */
function computeMemberLists(array $rules): array
{
    $lists = ['待续课' => [], '出勤降低' => [], 'VIP' => [], '预流失' => [], '待复活' => [], '炸弹会员' => []];
    $watch = ['待续费' => [], '待开卡' => [], '经营分层' => [], '炸弹会员' => []];

    // 「正式会员」集合 = **既有谓词** scopeMemberCustomers()（不得另写一份等价条件：
    // 该谓词的 `layer != 'P5' OR external_id like 'ky:%'` 双条件正是为了兜住
    // 「卡项全部过期的正式会员也会落 P5」——只写 layer != 'P5' 会把这些人当客资漏掉）。
    // 一次性取 id 集合（本函数整体已缓存 120s），循环内 O(1) 查表。
    $memberIds = [];
    foreach (scopeMemberCustomers(Customer::query())->select('id')->pluck('id') as $id) {
        $memberIds[(int) $id] = true;
    }

    Customer::query()
        ->select([
            'id', 'main_card', 'remain_times', 'expire_date', 'last_visit',
            'attend_m1', 'attend_m2', 'attend_m3', 'in_revive', 'card_paid_amount',
            'card_stats', 'cards_list',
        ])
        ->chunkById(500, function ($customers) use (&$lists, &$watch, $rules, $memberIds) {
            foreach ($customers as $c) {
                $d = customerDecision($c, $rules);
                $renewal = $d['renewal'];

                if ($renewal['in']) {
                    $lists['待续课'][] = $c->id;
                    $watch['待续费'][$c->id] = [
                        'why' => $renewal['why'],
                        // 结构化命中类型，与 why 文案并存：文案给人看，code 给程序用
                        'whyCodes' => $renewal['whyCodes'],
                        'deadlineHit' => $renewal['deadlineHit'],
                        'bucket' => $renewal['bucket'],
                        'urgent' => $renewal['urgent'],
                        // D3：待复活**不隐藏**会员，只标注，供前端分主次展示
                        'revive' => $renewal['revive'],
                        'remain' => $c->remain_times,
                        'expireDays' => $d['expireDays'],
                        'attendM3' => $d['m3'],
                        'degraded' => $renewal['degraded'],
                    ];
                } elseif ($renewal['bucket'] === '待开卡') {
                    // D5：不新增页签，但要让「有卡未开」可见，否则这类会员会静默消失。
                    // why/whyCodes 与 watch['待续费'] 同源取 decision 的真实结论：
                    // 硬编码一句固定文案会让「为什么他在这个池子里」失去可追溯性，
                    // 而 degraded（如「未返回剩余量」）正是用户排查「阈值调了没反应」的唯一线索。
                    // 只有当 decision 确实没给出任何 why 时才回退到默认文案。
                    $watch['待开卡'][$c->id] = [
                        'why' => $renewal['why'] !== []
                            ? $renewal['why']
                            : ['有未开卡卡项，尚未开始消耗课时'],
                        'whyCodes' => $renewal['whyCodes'],
                        'degraded' => $renewal['degraded'],
                    ];
                }
                $watch['经营分层'][$c->id] = $d['layer'];

                if ($d['declining']) {
                    $lists['出勤降低'][] = $c->id;
                }
                if ($d['vip']) {
                    $lists['VIP'][] = $c->id;
                }
                if ($d['preLoss']) {
                    $lists['预流失'][] = $c->id;
                }
                if ($d['revive']) {
                    $lists['待复活'][] = $c->id;
                }
                // ── 炸弹会员（正式会员 + 次卡有余额 + 过期超 6 个月 + 超 6 个月未到店）──
                // 卡项四条 + 出勤一条共五条口径由 customerDecision 的 $bomb 给出；
                // 「正式会员」这一条必须由 scopeMemberCustomers() 判（见函数开头 $memberIds 注释）。
                // 两者**同时**成立才入清单：卡项条件判「是不是炸弹」，会员条件判「要不要提醒他」。
                if ($d['bomb']['in'] && isset($memberIds[(int) $c->id])) {
                    $lists['炸弹会员'][] = $c->id;
                    $titles = implode('、', array_slice(array_map(
                        fn ($x) => (string) ($x['title'] ?? '卡项'),
                        $d['bomb']['cards']
                    ), 0, 3));
                    $watch['炸弹会员'][$c->id] = [
                        'why' => ['「'.$titles.'」已过期超 6 个月，仍余 '.$d['bomb']['sections'].' 节未消课，且超 6 个月未到店'],
                        'whyCodes' => ['bomb_expired'],
                        'sections' => $d['bomb']['sections'],
                        'cardCount' => count($d['bomb']['cards']),
                        'earliestExpiredDays' => max(array_map(fn ($x) => (int) $x['daysExpired'], $d['bomb']['cards'])),
                        'cards' => $d['bomb']['cards'],
                    ];
                }
            }
        });

    // 跨清单共属标记：同一会员既在待续费又在待复活时，前端需要知道「哪个是主标签」。
    // 放在扫描之后统一算，避免在循环里回头查清单。
    $coMembership = [];
    foreach ($lists as $key => $ids) {
        if ($key === '待续课') {
            continue;
        }
        foreach ($ids as $id) {
            $coMembership[$id][] = $key;
        }
    }
    foreach ($watch['待续费'] as $id => $payload) {
        $co = $coMembership[$id] ?? [];
        $watch['待续费'][$id]['coLists'] = $co;
        // 主标签：待复活态先唤醒，否则按紧急度
        $watch['待续费'][$id]['primary'] = $payload['revive'] ? '待复活'
            : ($payload['urgent'] ? '待续费·紧急' : '待续费·观察');
    }

    return ['lists' => $lists, 'watch' => $watch];
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
