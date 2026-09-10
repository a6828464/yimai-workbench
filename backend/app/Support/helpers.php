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
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

function ok($data)
{
    return response()->json(['code' => 0, 'data' => $data]);
}

function requireSuper(Request $request): void
{
    abort_unless($request->user()?->role === 'R_SUPER', 403, '仅超管可执行此操作');
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
        'role' => $user->role,
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
    $deleted = ['systemLogs' => 0, 'auditLogs' => 0, 'modelGenerations' => 0];
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

    return $deleted;
}

function isOnlineLead(Lead $lead): bool
{
    $text = implode(' ', [(string) $lead->source, (string) $lead->order_platform]);

    return (bool) preg_match('/美团|大众点评|抖音|小红书|视频号|线上|团购/u', $text);
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
    if ($user->role === 'R_MANAGER') {
        $taskQ->where('venue', $user->venue);
    } elseif ($user->role === 'R_TEACHER') {
        $taskQ->where('venue', $user->venue)->where('owner', $user->name);
    } elseif ($user->role === 'R_MEDIA') {
        $taskQ->where('owner', $user->name);
    }
    $taskCount = $taskQ->count();
    if ($taskCount > 0) {
        $items[] = ['key' => 'tasks-'.$taskCount, 'category' => 'todo', 'level' => 'warning', 'title' => "有 {$taskCount} 项任务待处理", 'detail' => $user->role === 'R_SUPER' ? '双店任务' : ($user->venue ?: '本人任务'), 'path' => '/yimai/tasks'];
    }
    if (in_array($user->role, ['R_SUPER', 'R_MANAGER', 'R_TEACHER'], true)) {
        $customerQ = scopeCustomersForUser(Customer::query(), $user)->whereIn('id', filteredIds('待续课'));
        $renewals = $customerQ->count();
        if ($renewals > 0) {
            $items[] = ['key' => 'renewals-'.$renewals, 'category' => 'todo', 'level' => 'high', 'title' => "有 {$renewals} 位会员进入待续课", 'detail' => '请完成评估并明确下一步动作', 'path' => '/yimai/members'];
        }
    }
    if (in_array($user->role, ['R_SUPER', 'R_MANAGER'], true)) {
        $approvalQ = Approval::where('status', 'like', '待%');
        if ($user->role === 'R_MANAGER') {
            $approvalQ->where('venue', $user->venue);
        }
        $approvals = $approvalQ->count();
        if ($approvals > 0) {
            $items[] = ['key' => 'approvals-'.$approvals, 'category' => 'notice', 'level' => 'warning', 'title' => "有 {$approvals} 项价格审批待处理", 'detail' => '审批中心', 'path' => '/yimai/approvals'];
        }
    }
    if ($user->role === 'R_SUPER') {
        $lastSync = SyncJob::where('status', '成功')->latest('finished_at')->first();
        if ($lastSync) {
            $items[] = ['key' => 'sync-'.$lastSync->id, 'category' => 'notice', 'level' => 'info', 'title' => '最近一次 KeepYoga 同步已完成', 'detail' => (string) $lastSync->finished_at, 'path' => '/yimai/sync'];
        }
    }
    if ($user->role === 'R_MEDIA') {
        $newLeads = Lead::where('created_by', $user->name)->where('status', '新留资')->count();
        if ($newLeads > 0) {
            $items[] = ['key' => 'media-leads-'.$newLeads, 'category' => 'message', 'level' => 'info', 'title' => "你录入的 {$newLeads} 条新客资待承接", 'detail' => '新媒体客资', 'path' => '/yimai/leads'];
        }
    }

    return $items;
}

function scopeCustomersForUser($query, User $user)
{
    if ($user->role === 'R_MANAGER') {
        $query->where('venue', $user->venue);
    } elseif ($user->role === 'R_TEACHER') {
        $query->where('venue', $user->venue)
            ->where(fn ($q) => $q->where('owner', $user->name)->orWhere('consultant', $user->name));
    } elseif ($user->role === 'R_MEDIA') {
        $query->where('layer', 'P5');
    }

    return $query;
}

function scopeLeadsForUser($query, User $user)
{
    if ($user->role === 'R_MANAGER') {
        $query->where('venue', $user->venue);
    } elseif ($user->role === 'R_TEACHER') {
        $query->where('venue', $user->venue)
            ->where(fn ($q) => $q->where('service_teacher', $user->name)->orWhere('service_teacher', ''));
    }

    return $query;
}

/** 经营看板 venue 下推：超管看双店，新媒体按 venues 授权，店长/老师锁定本店。 */
function applyVenueScope($query, User $user, string $venue)
{
    if ($user->role === 'R_SUPER') {
        if ($venue !== '') {
            $query->where('venue', $venue);
        }
    } elseif ($user->role === 'R_MEDIA') {
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
    return match ($user->role) {
        'R_SUPER' => true,
        'R_MANAGER' => $customer->venue === $user->venue,
        'R_TEACHER' => $customer->venue === $user->venue
            && in_array($user->name, [$customer->owner, $customer->consultant], true),
        default => false,
    };
}

function renewalEvaluationContext(Customer $customer): array
{
    $memberId = str_starts_with((string) $customer->external_id, 'ky:')
        ? (string) last(explode(':', (string) $customer->external_id))
        : '';
    $attendance = KyBooking::query()
        ->where('venue', $customer->venue)
        ->where('status', 'signed')
        ->where('start_at', '>=', now()->subDays(30)->startOfDay())
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

function camelToSnake(array $in): array
{
    $out = [];
    foreach ($in as $k => $v) {
        if ($k === '_action') {
            continue;
        }
        $out[strtolower(preg_replace('/([a-z\d])([A-Z])/', '$1_$2', $k))] = $v;
    }

    return array_filter($out, fn ($v) => $v !== null);
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
    $roleMap = ['R_SUPER' => '超管', 'R_MANAGER' => '店长', 'R_TEACHER' => '老师', 'R_MEDIA' => '新媒体'];
    AuditLog::create([
        'operator_id' => $r->user()->id,
        'operator_name' => $r->user()->name,
        'operator_role' => $roleMap[$r->user()->role] ?? $r->user()->role,
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
