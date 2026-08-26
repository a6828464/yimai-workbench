<?php

use App\Models\AppSetting;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\PublishedShare;
use App\Models\SyncJob;
use App\Models\TrainingPlan;
use App\Models\Task;
use App\Models\User;
use App\Services\KyClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [App\Http\Controllers\AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [App\Http\Controllers\AuthController::class, 'me']);
    Route::post('/auth/logout', [App\Http\Controllers\AuthController::class, 'logout']);

    // ---------- 留资 ----------
    Route::get('/leads', function (Request $r) {
        $u = $r->user();
        $q = Lead::query()->where('venue', 'like', '%');
        if ($u->role === 'R_MANAGER') $q->where('venue', $u->venue);
        if ($u->role === 'R_TEACHER') {
            if ($u->venue) $q->where('venue', $u->venue);
            $q->where(function ($w) use ($u) {
                $w->where('service_teacher', $u->name)->orWhere('service_teacher', '');
            });
        }
        if ($n = $r->query('name')) $q->where('name', 'like', "%{$n}%");
        if ($v = $r->query('venue')) $q->where('venue', $v);
        if ($s = $r->query('status')) $q->where('status', $s);
        $rows = $q->orderByDesc('id')->get()->map(fn ($x) => camel($x));
        return ok(['records' => array_slice($rows->toArray(), 0, (int) ($r->query('size', 500))), 'total' => $rows->count()]);
    });

    Route::post('/leads', function (Request $r) {
        $d = $r->validate([
            'name' => 'required|string', 'source' => 'required|string', 'venue' => 'required|string',
        ]);
        $lead = Lead::create(camelToSnake($r->all()) + ['created_by' => $r->user()->name, 'status' => $r->input('status', '新留资')]);
        audit($r, '新增', '前端客资', $lead->id, "{$lead->name}（{$lead->source}）", $lead->venue, '录入客资');
        return ok(['id' => $lead->id]);
    });

    Route::patch('/leads/{id}', function (Request $r, int $id) {
        $lead = Lead::findOrFail($id);
        $before = camel($lead)->toJson();
        $lead->update(camelToSnake($r->all()));
        audit($r, '修改', '前端客资', $id, "{$lead->name}（{$lead->source}）", $lead->venue, '字段更新');
        return ok(['before' => json_decode($before), 'after' => camel($lead)]);
    });

    Route::get('/leads/{id}/history', function (Request $r, int $id) {
        $rows = AuditLog::where('module', '前端客资')->where('target_id', (string) $id)->orderByDesc('id')->get()
            ->map(fn ($x) => camel($x));
        return ok($rows);
    });

    // ---------- 会员/客户 ----------
    Route::get('/customers', function (Request $r) {
        $u = $r->user();
        $q = Customer::query();
        if ($u->role === 'R_MANAGER') $q->where('venue', $u->venue);
        if ($u->role === 'R_TEACHER') {
            if ($u->venue) $q->where('venue', $u->venue);
            $q->where('owner', $u->name);
        }
        if ($n = $r->query('name')) $q->where('name', 'like', "%{$n}%");
        if ($l = $r->query('list')) $q->whereIn('id', filteredIds($l));
        $rows = $q->orderBy('id')->get()->map(fn ($x) => camel($x));
        return ok(['records' => array_slice($rows->toArray(), 0, (int) ($r->query('size', 500))), 'total' => $rows->count()]);
    });

    Route::patch('/customers/{id}', function (Request $r, int $id) {
        $c = Customer::findOrFail($id);
        $c->update(collect(camelToSnake($r->all()))->only([
            'renewal_plan', 'decline', 'stop_reason', 'expected_return',
            'last_touch', 'needs_help', 'in_revive', 'eval_score', 'eval_at',
        ])->all());
        audit($r, $r->input('_action', '修改'), '会员管理', $id, "{$c->name}（{$c->venue}）", $c->venue, '工作流字段更新');
        return ok(camel($c));
    });

    Route::get('/member-rules', fn (Request $r) => ok(rules()));
    Route::put('/member-rules', function (Request $r) {
        setRules($r->all());
        audit($r, '修改', '会员管理', 0, '清单规则阈值', '双店', json_encode($r->all(), JSON_UNESCAPED_UNICODE));
        return ok(rules());
    });

    // ---------- 任务 / 审批 ----------
    Route::get('/tasks', function (Request $r) {
        $u = $r->user();
        $q = Task::query();
        if ($u->role === 'R_MANAGER') $q->where('venue', $u->venue);
        if ($u->role === 'R_TEACHER') $q->where(fn ($w) => $w->where('owner', $u->name)->orWhere('owner', '未分配'));
        return ok($q->orderBy('id')->get()->map(fn ($x) => camel($x)));
    });

    Route::get('/approvals', function (Request $r) {
        $u = $r->user();
        abort_unless(in_array($u->role, ['R_SUPER', 'R_MANAGER']), 403);
        return ok(Approval::orderBy('id')->get()->map(fn ($x) => camel($x)));
    });

    Route::post('/approvals/{id}/decide', function (Request $r, int $id) {
        $a = Approval::findOrFail($id);
        $decision = $r->input('decision');
        $map = ['初审通过' => '待老板终审', '终审通过' => '已通过', '驳回' => '已驳回'];
        abort_unless(isset($map[$decision]), 422, '未知决定');
        $a->update(['status' => $map[$decision]]);
        audit($r, $decision, '价格审批', $id, "价格审批单 #{$id}", '双店', "审批决定：{$decision}");
        return ok(camel($a));
    });

    // ---------- 审计 ----------
    Route::get('/audit-logs', function (Request $r) {
        $u = $r->user();
        abort_unless($u->role === 'R_SUPER', 403, '仅老板可查看操作留痕');
        $q = AuditLog::query()->orderByDesc('id');
        if ($o = $r->query('operator')) $q->where('operator_name', 'like', "%{$o}%");
        if ($m = $r->query('module')) $q->where('module', $m);
        if ($a = $r->query('action')) $q->where('action', $a);
        return ok($q->limit((int) $r->query('size', 200))->get()->map(fn ($x) => camel($x)));
    });

    // ---------- KeepYoga 只读代理（阶段1：凭据仅存服务端） ----------
    Route::post('/ky/session', function (Request $r) {
        try {
            KyClient::token((bool) $r->input('force'));
        } catch (\Throwable $e) {
            abort(502, $e->getMessage());
        }

        return ok(['ok' => true]);
    });

    Route::post('/ky/call', function (Request $r) {
        $path = (string) $r->input('path', '');
        abort_unless(is_array($r->input('form')), 422, 'form 必须是对象');
        try {
            return ok(KyClient::call($path, $r->input('form')));
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        } catch (\Throwable $e) {
            abort(502, $e->getMessage());
        }
    });

    // ---------- AI 大模型代理（OpenAI 兼容协议，解决浏览器跨域） ----------
    Route::post('/ai/chat', function (Request $r) {
        $d = $r->validate([
            'baseUrl' => 'required|url',
            'apiKey' => 'required|string',
            'model' => 'required|string',
            'messages' => 'required|array',
        ]);
        abort_unless(str_starts_with($d['baseUrl'], 'https://'), 422, '接口地址必须为 https');

        $payload = [
            'model' => $d['model'],
            'messages' => $d['messages'],
            'temperature' => (float) ($r->input('temperature', 0.8)),
        ];
        if ($r->filled('maxTokens')) $payload['max_tokens'] = (int) $r->input('maxTokens');

        try {
            $resp = Http::withToken($d['apiKey'])
                ->timeout(90)
                ->post(rtrim($d['baseUrl'], '/').'/chat/completions', $payload);
        } catch (\Throwable $e) {
            abort(502, '无法连接大模型接口: '.mb_substr($e->getMessage(), 0, 160));
        }
        if (! $resp->successful()) {
            abort(502, '大模型返回 HTTP '.$resp->status().': '.mb_substr($resp->body(), 0, 200));
        }
        $content = $resp->json('choices.0.message.content');
        abort_if($content === null, 502, '大模型响应缺少内容');

        return ok(['content' => $content]);
    });

    Route::post('/ai/models', function (Request $r) {
        $d = $r->validate([
            'baseUrl' => 'required|url',
            'apiKey' => 'required|string',
        ]);
        abort_unless(str_starts_with($d['baseUrl'], 'https://'), 422, '接口地址必须为 https');

        try {
            $resp = Http::withToken($d['apiKey'])
                ->timeout(30)
                ->get(rtrim($d['baseUrl'], '/').'/models');
        } catch (\Throwable $e) {
            abort(502, '无法连接大模型接口: '.mb_substr($e->getMessage(), 0, 160));
        }
        if (! $resp->successful()) {
            abort(502, '获取模型列表 HTTP '.$resp->status().': '.mb_substr($resp->body(), 0, 200));
        }

        // OpenAI 兼容格式：{data:[{id}]}；部分厂商为 {data:{...}} 或 {models:[...]}
        $json = $resp->json();
        $raw = $json['data'] ?? $json['models'] ?? [];
        if (is_array($raw) && isset($raw[0]) && is_array($raw[0])) {
            $ids = array_map(fn ($m) => (string) ($m['id'] ?? $m['name'] ?? ''), $raw);
        } else {
            $ids = is_array($raw) ? array_map('strval', $raw) : [];
        }
        $ids = array_values(array_filter(array_unique($ids)));
        sort($ids, SORT_NATURAL | SORT_FLAG_CASE);

        return ok(['models' => $ids]);
    });

    // ---------- 人员管理 ----------
    Route::get('/accounts', function (Request $r) {
        abort_unless($r->user()->role === 'R_SUPER', 403);
        $roleMap = ['R_SUPER' => '超管', 'R_MANAGER' => '店长', 'R_TEACHER' => '老师', 'R_MEDIA' => '新媒体'];

        return ok(User::orderBy('id')->get()->map(fn ($u) => [
            'key' => $u->username,
            'userName' => $u->name,
            'roleCode' => $u->role,
            'roleLabel' => $roleMap[$u->role] ?? $u->role,
            'venues' => $u->venues ?? [],
            'email' => $u->email,
            'status' => '启用',
        ]));
    });

    // ---------- 训练计划（按人持久化，整表同步） ----------
    Route::get('/training-plans', function (Request $r) {
        return ok(TrainingPlan::where('created_by', $r->user()->name)->orderBy('id')->get()
            ->map(fn ($p) => array_merge(['id' => $p->id], $p->payload ?? [])));
    });

    Route::put('/training-plans/bulk', function (Request $r) {
        $plans = $r->input('plans');
        abort_unless(is_array($plans), 422, 'plans 必须是数组');

        // 整表替换（按人隔离）：id 由前端维护，服务端原样持久化
        \Illuminate\Support\Facades\DB::transaction(function () use ($plans, $r) {
            TrainingPlan::where('created_by', $r->user()->name)->delete();
            foreach ($plans as $p) {
                if (! is_array($p)) continue;
                TrainingPlan::create([
                    'id' => (int) ($p['id'] ?? 0),
                    'member_name' => (string) ($p['memberName'] ?? '') ?: '未命名',
                    'payload' => $p,
                    'status' => (string) ($p['status'] ?? '草稿'),
                    'share' => is_array($p['share'] ?? null) ? $p['share'] : null,
                    'source' => (string) ($p['source'] ?? ''),
                    'created_by' => $r->user()->name,
                    'confirmed_at' => ($p['status'] ?? '') === '已确认' ? now() : null,
                ]);
            }
        });

        return ok(['saved' => count($plans)]);
    });

    // ---------- KeepYoga 导入落库 + 同步批次 ----------
    Route::post('/customers/import', function (Request $r) {
        $d = $r->validate([
            'venue' => 'required|string',
            'venueId' => 'required|string',
            'rows' => 'required|array',
        ]);
        $created = 0;
        $updated = 0;
        foreach ($d['rows'] as $row) {
            if (! is_array($row) || empty($row['memberId'])) continue;
            $externalId = "ky:{$d['venueId']}:{$row['memberId']}";
            $existing = Customer::where('external_id', $externalId)->first();
            if ($existing) {
                $updated++;
                continue;
            }
            Customer::create([
                'name' => (string) ($row['name'] ?? '会员') ?: '会员',
                'phone' => (string) ($row['phone'] ?? ''),
                'phone_tail' => substr((string) ($row['phone'] ?? ''), -4),
                'venue' => $d['venue'],
                'source' => (string) ($row['source'] ?? '') ?: 'KeepYoga',
                'main_card' => '待同步卡项',
                'layer' => 'P5',
                'status' => '暂缓',
                'owner' => '未分配',
                'next_action' => '分配负责人并建档',
                'external_id' => $externalId,
            ]);
            $created++;
        }
        $total = $created + $updated;

        $batch = 'IMP-'.now()->format('Ymd-His').'-'.substr((string) mt_rand(1000, 9999), 0, 4);
        SyncJob::create([
            'batch_no' => $batch,
            'data_type' => '会员全量',
            'venue' => $d['venue'],
            'total_count' => $total,
            'success_count' => $total,
            'fail_count' => 0,
            'status' => '成功',
            'operator' => $r->user()->name,
            'finished_at' => now(),
        ]);
        audit($r, '导入', 'KeepYoga同步', 0, "批次{$batch}", $d['venue'], "全量会员导入：新增{$created} 更新{$updated} 共{$total}");

        return ok(['created' => $created, 'updated' => $updated, 'total' => $total, 'batchNo' => $batch]);
    });

    Route::get('/sync-jobs', function () {
        return ok(SyncJob::orderByDesc('id')->limit(100)->get()->map(fn ($x) => camel($x)));
    });

    // ---------- 今日工作台汇总（服务端计算） ----------
    Route::get('/today/summary', function (Request $r) {
        $u = $r->user();

        $custQ = Customer::query();
        if ($u->role === 'R_MANAGER') $custQ->where('venue', $u->venue);
        if ($u->role === 'R_TEACHER' && $u->venue) $custQ->where('venue', $u->venue);
        $scopedCustomers = $custQ->get();

        $leadQ = Lead::query();
        if ($u->role === 'R_MANAGER') $leadQ->where('venue', $u->venue);
        if ($u->role === 'R_TEACHER') $leadQ->where(fn ($w) => $w->where('service_teacher', $u->name)->orWhere('service_teacher', ''));
        $leads = $leadQ->get();

        $taskQ = Task::query();
        if ($u->role === 'R_MANAGER') $taskQ->where('venue', $u->venue);
        if ($u->role === 'R_TEACHER') $taskQ->where(fn ($w) => $w->where('owner', $u->name)->orWhere('owner', '未分配'));
        $overdueTasks = $taskQ->where('status', '已逾期')->count();

        $renewalIds = filteredIds('待续课');
        $expiringMembers = $scopedCustomers->whereIn('id', $renewalIds)->count();
        $tomorrow = now()->addDay()->toDateString();

        $setting = AppSetting::first();
        $snap = $setting?->snapshot;

        return ok([
            'newLeads' => $scopedCustomers->where('layer', 'P5')->count() + $leads->where('status', '新留资')->count(),
            'pendingFollowup' => $scopedCustomers->where('next_action_time', '<=', $tomorrow)->count(),
            'expiringMembers' => $expiringMembers,
            'riskCount' => $overdueTasks + $scopedCustomers->where('owner', '未分配')->count() + $leads->where('status', '新留资')->count(),
            'pendingApprovals' => $u->role === 'R_MEDIA' ? 0 : Approval::where('status', 'like', '待%')->count(),
            'todayBookings' => [
                '绿地店' => (! $u->venue || $u->venue === '绿地店') ? ($snap['todayBookings']['绿地店'] ?? 0) : 0,
                '东部店' => (! $u->venue || $u->venue === '东部店') ? ($snap['todayBookings']['东部店'] ?? 0) : 0,
            ],
            'trialBookings' => [
                '绿地店' => (! $u->venue || $u->venue === '绿地店') ? ($snap['trialBookings']['绿地店'] ?? 0) : 0,
                '东部店' => (! $u->venue || $u->venue === '东部店') ? ($snap['trialBookings']['东部店'] ?? 0) : 0,
            ],
            'scopeLabel' => $u->venue ? "本店 · {$u->venue}" : '双店',
            'snapshotTime' => in_array($u->role, ['R_SUPER', 'R_MANAGER'], true) ? (string) ($snap['fetchedAt'] ?? '') : '',
        ]);
    });

    Route::get('/today/followups', function (Request $r) {
        $u = $r->user();
        $q = Customer::query()->whereIn('layer', ['P0', 'P1', 'P5']);
        if ($u->role === 'R_MANAGER') $q->where('venue', $u->venue);
        if ($u->role === 'R_TEACHER' && $u->venue) $q->where('venue', $u->venue);

        return ok($q->orderBy('id')->limit(6)->get()->map(function ($c) {
            $arr = camel($c);
            $days = $c->last_visit ? (int) ((time() - strtotime($c->last_visit)) / 86400) : 9999;
            $arr['lastVisitDays'] = $days;

            return $arr;
        }));
    });

    Route::get('/today/alerts', function (Request $r) {
        $u = $r->user();
        $alerts = [];

        $leadQ = Lead::query()->where('status', '新留资');
        if ($u->role === 'R_MANAGER') $leadQ->where('venue', $u->venue);
        foreach ($leadQ->orderByDesc('id')->limit(2)->get() as $l) {
            $alerts[] = ['id' => 9000 + $l->id, 'level' => '高', 'text' => "[{$l->venue}] 新客资 {$l->name} 待首响（{$l->source}）", 'action' => '24小时内完成首轮联系'];
        }

        $taskQ = Task::query()->where('status', '已逾期');
        if ($u->role === 'R_MANAGER') $taskQ->where('venue', $u->venue);
        if ($u->role === 'R_TEACHER') $taskQ->where(fn ($w) => $w->where('owner', $u->name)->orWhere('owner', '未分配'));
        foreach ($taskQ->limit(4)->get() as $t) {
            $alerts[] = ['id' => 100 + $t->id, 'level' => '中', 'text' => "任务「{$t->title}-{$t->customer_name}」已逾期", 'action' => '提醒责任人完成闭环'];
        }

        $expiring = Customer::whereIn('id', filteredIds('待续课'))->orderBy('expire_date')->limit(2)->get();
        foreach ($expiring as $c) {
            $alerts[] = ['id' => 200 + $c->id, 'level' => '中', 'text' => "{$c->name} 卡项临近到期（剩余{$c->remain_times}节）", 'action' => '确认续费窗口沟通结果'];
        }

        return ok($alerts);
    });

    // ---------- 对外发布（H5 分享快照） ----------
    Route::post('/shares/publish', function (Request $r) {
        $d = $r->validate([
            'type' => 'required|string|max:16',
            'token' => 'required|string|max:40',
            'payload' => 'required|array',
        ]);
        PublishedShare::updateOrCreate(
            ['type' => $d['type'], 'token' => $d['token']],
            ['payload' => $d['payload'], 'created_by' => $r->user()->name]
        );
        audit($r, '发布', 'H5分享', 0, "分享码[{$d['token']}]", '双店', "类型：{$d['type']}");

        return ok(['ok' => true]);
    });
});

// ---------- 公开接口（免登录，H5 分享页用） ----------
Route::prefix('public')->group(function () {
    Route::get('/training/{code}', function (string $code) {
        $plan = TrainingPlan::where('share->code', $code)->first();
        if (! $plan || ($plan->share['enabled'] ?? false) !== true || $plan->status !== '已确认') {
            return response()->json(['errno' => 404, 'emsg' => '分享不存在或已停用'], 404);
        }
        $plan->share = array_merge($plan->share ?? [], ['views' => ($plan->share['views'] ?? 0) + 1]);
        $plan->save();

        return ok([
            'memberName' => $plan->member_name,
            'profile' => $plan->profile,
            'goal' => $plan->goal,
            'content' => $plan->content,
            'images' => $plan->images,
            'confirmedAt' => optional($plan->confirmed_at)->toDateString(),
        ]);
    });

    Route::get('/sales/{token}', function (string $token) {
        $share = PublishedShare::where('type', 'sales')->where('token', $token)->first();
        if (! $share) {
            return response()->json(['errno' => 404, 'emsg' => '分享不存在或已停用'], 404);
        }

        return ok($share->payload);
    });

    // ---------- 版本更新（仅超管） ----------
    Route::get('/system/version', function () {
        return ok(systemVersionInfo());
    });

    Route::get('/system/changelog', function () {
        $file = dirname(base_path()).'/CHANGELOG.md';
        abort_unless(is_file($file), 404, 'CHANGELOG.md 不存在');

        return ok(['content' => file_get_contents($file)]);
    });

    Route::post('/system/update', function (Request $r) {
        abort_unless($r->user()->role === 'R_SUPER', 403);
        $log = [];

        // 拉取最新代码（仅快进合并，避免覆盖本地改动）
        $pull = runInRepo(['git', 'pull', '--ff-only', 'origin', 'main']);
        $log[] = ['step' => 'git pull', 'ok' => $pull['ok'], 'output' => $pull['output']];
        if (! $pull['ok']) {
            audit($r, '失败', '版本更新', 0, '在线更新', '双店', implode("\n", array_slice($pull['output'], -3)));
            return ok(['success' => false, 'log' => $log]);
        }

        // 数据库结构同步（幂等）
        $migrate = runInRepo([PHP_BINARY, ArtisanBinary(), 'migrate', '--force']);
        $log[] = ['step' => 'php artisan migrate', 'ok' => $migrate['ok'], 'output' => $migrate['output']];

        // 后端依赖同步（composer.lock 有变化时才装）
        if ($pull['changed']) {
            $composer = runInRepo(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-dev']);
            $log[] = ['step' => 'composer install', 'ok' => $composer['ok'], 'output' => $composer['output']];
        }

        $info = systemVersionInfo();
        audit($r, '执行', '版本更新', 0, '在线更新至 '.substr((string) $info['remote']['commit'], 0, 7), '双店', implode("\n", array_map(fn ($l) => $l['step'].': '.($l['ok'] ? 'OK' : 'FAIL'), $log)));

        return ok(['success' => ! in_array(false, array_column($log, 'ok'), true), 'version' => $info, 'log' => $log]);
    });
});

// ---------- helpers ----------
function ok($data) { return response()->json(['code' => 0, 'data' => $data]); }

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
        if ($k === '_action') continue;
        $out[strtolower(preg_replace('/([a-z\d])([A-Z])/', '$1_$2', $k))] = $v;
    }
    return array_filter($out, fn ($v) => $v !== null);
}

function audit(Request $r, string $action, string $module, int|string $targetId, string $targetLabel, string $venue, string $detail): void
{
    $roleMap = ['R_SUPER' => '超管', 'R_MANAGER' => '店长', 'R_TEACHER' => '老师', 'R_MEDIA' => '新媒体'];
    AuditLog::create([
        'operator_name' => $r->user()->name,
        'operator_role' => $roleMap[$r->user()->role] ?? $r->user()->role,
        'action' => $action, 'module' => $module,
        'target_id' => (string) $targetId, 'target_label' => $targetLabel,
        'venue' => $venue, 'detail' => $detail,
    ]);
}

function rules(): array
{
    $s = AppSetting::first();
    return $s?->rules ?? ['renewalThreshold' => 10, 'vipThreshold' => 100, 'declineMode' => 'strict'];
}

function setRules(array $rules): void
{
    $s = AppSetting::firstOrCreate([]);
    $s->update(['rules' => $rules]);
}

/** 五清单引擎：返回命中清单的会员ID集合（口径：卓越店长训练营） */
function filteredIds(string $list): array
{
    $rules = rules();
    $threshold = $rules['renewalThreshold'] ?? 10;
    $vip = $rules['vipThreshold'] ?? 100;
    $strict = ($rules['declineMode'] ?? 'strict') === 'strict';
    $days = fn ($d) => $d ? (int) ((time() - strtotime($d)) / 86400) : 9999;

    return Customer::query()->get()
        ->filter(function (Customer $c) use ($list, $threshold, $vip, $strict, $days) {
            $m1 = $c->attend_m1; $m2 = $c->attend_m2; $m3 = $c->attend_m3;
            $dd = $days($c->last_visit);
            return match ($list) {
                '待续课' => $m3 > 0 && $c->remain_times !== null && $c->remain_times < $threshold,
                '出勤降低' => $strict ? ($m1 > $m2 && $m2 > $m3) : ($m2 > $m3),
                'VIP' => $c->total_purchased > $vip,
                '预流失' => ($m2 > 0 && $m3 === 0) || ($dd >= 15 && $dd <= 30),
                '待复活' => $dd > 30 && $c->main_card !== '—',
                default => false,
            };
        })->pluck('id')->all();
}

// ---------- 版本更新 helpers ----------

function ArtisanBinary(): string
{
    return base_path('artisan');
}

/** 在仓库根目录执行命令，返回 [ok, output[], changed?] */
function runInRepo(array $cmd): array
{
    $process = new Symfony\Component\Process\Process($cmd, base_path());
    $process->setTimeout(300)->run();

    return [
        'ok' => $process->isSuccessful(),
        'output' => array_filter(array_map('trim', explode("\n", trim($process->getOutput()."\n".$process->getErrorOutput())))),
        'changed' => $process->isSuccessful() && str_contains($process->getOutput(), 'files changed'),
    ];
}

/** 本地 + 远程版本信息 */
function systemVersionInfo(): array
{
    $git = function (array $args): string {
        $p = new Symfony\Component\Process\Process(['git', ...$args], base_path());
        $p->setTimeout(30)->run();

        return trim($p->isSuccessful() ? $p->getOutput() : '');
    };

    $local = [
        'branch' => $git(['rev-parse', '--abbrev-ref', 'HEAD']),
        'commit' => $git(['rev-parse', 'HEAD']),
        'message' => $git(['log', '-1', '--pretty=%s']),
        'date' => $git(['log', '-1', '--pretty=%ci']),
    ];

    // 远端 main 最新提交（ls-remote 复用本机凭据，无需 API token）
    $remoteSha = '';
    $remoteErr = '';
    $p = new Symfony\Component\Process\Process(['git', 'ls-remote', 'origin', 'refs/heads/main'], base_path());
    $p->setTimeout(60)->run();
    if ($p->isSuccessful() && preg_match('/^([0-9a-f]{40})/m', $p->getOutput(), $m)) {
        $remoteSha = $m[1];
    } else {
        $remoteErr = mb_substr(trim($p->getErrorOutput()), 0, 200);
    }

    return [
        'local' => $local,
        'remote' => ['commit' => $remoteSha, 'error' => $remoteErr],
        'upToDate' => $remoteSha !== '' && str_starts_with($remoteSha, $local['commit']),
    ];
}

