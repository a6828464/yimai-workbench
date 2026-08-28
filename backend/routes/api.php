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
use App\Services\KyMemberSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [App\Http\Controllers\AuthController::class, 'login']);
Route::post('/auth/register', [App\Http\Controllers\AuthController::class, 'register']);

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
        // 联系方式：手机号 / 电话尾号 / 微信
        if ($c = trim((string) $r->query('phone', ''))) {
            $q->where(function ($w) use ($c) {
                $w->where('phone', 'like', "%{$c}%")
                    ->orWhere('wechat', 'like', "%{$c}%");
            });
        }
        // 留资日期范围
        if ($df = $r->query('dateFrom')) $q->where('lead_date', '>=', $df);
        if ($dt = $r->query('dateTo')) $q->where('lead_date', '<=', $dt);
        $rows = $q->orderByDesc('id')->get()->map(fn ($x) => camel($x));
        return ok(['records' => array_slice($rows->toArray(), 0, (int) ($r->query('size', 500))), 'total' => $rows->count()]);
    });

    // 新增留资时校验手机号是否已命中会员 / 已有留资
    Route::get('/leads/check', function (Request $r) {
        $phone = trim((string) $r->query('phone', ''));
        if ($phone === '') return ok(['exists' => false, 'matches' => []]);

        $matches = [];
        foreach (Customer::where('phone', $phone)->get() as $c) {
            $layer = $c->layer === 'P5' ? '留资' : '会员';
            $matches[] = ['kind' => $layer, 'name' => $c->name, 'venue' => $c->venue, 'detail' => trim((string) $c->main_card) !== '' && $c->main_card !== '—' ? $c->main_card : '尚未购卡'];
        }
        foreach (Lead::where('phone', $phone)->orderByDesc('id')->get() as $l) {
            $matches[] = ['kind' => '已有留资', 'name' => $l->name, 'venue' => $l->venue, 'detail' => $l->status, 'id' => $l->id];
        }

        return ok(['exists' => count($matches) > 0, 'matches' => $matches]);
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
        if ($u->role === 'R_MEDIA') $q->where('layer', 'P5');
        if ($n = trim((string) $r->query('name', ''))) {
            $q->where('name', 'like', "%{$n}%");
        }
        if ($p = trim((string) $r->query('phone', ''))) {
            $q->where(function ($w) use ($p) {
                $w->where('phone', 'like', "%{$p}%")
                    ->orWhere('phone_tail', 'like', "%{$p}%");
            });
        }
        if ($v = $r->query('venue')) $q->where('venue', $v);
        if ($l = $r->query('list')) $q->whereIn('id', filteredIds($l));
        if ($ly = $r->query('layer')) $q->where('layer', $ly);
        if ($o = $r->query('owner')) $q->where('owner', $o);
        if ($s = $r->query('status')) $q->where('status', $s);
        if ($c = trim((string) $r->query('consultant', ''))) {
            if ($c === '待分配') {
                $q->where(fn ($w) => $w->whereNull('consultant')->orWhere('consultant', ''));
            } else {
                $q->where('consultant', $c);
            }
        }
        if ($src = $r->query('source')) $q->where('source', 'like', "%{$src}%");
        if ($r->query('type') === 'member') {
            $q->where(fn ($w) => $w->where('layer', '!=', 'P5')->orWhere('external_id', 'like', 'ky:%'));
        }
        if ($r->query('type') === 'lead') {
            $q->where('layer', 'P5')->where(fn ($w) => $w->whereNull('external_id')->orWhere('external_id', 'not like', 'ky:%'));
        }
        if ($r->query('haveCourse') === 'true') $q->whereNotNull('main_card')->where('main_card', '!=', '—');
        if ($r->query('haveCourse') === 'false') $q->where(fn ($w) => $w->whereNull('main_card')->orWhere('main_card', '—'));
        if (is_numeric($r->query('remainMax'))) $q->where('remain_times', '<=', (int) $r->query('remainMax'));
        $current = max(1, (int) $r->query('current', 1));
        $size = min(5000, max(1, (int) $r->query('size', 20)));
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('id')->forPage($current, $size)->get()->map(fn ($x) => camel($x));

        return ok(['records' => $rows, 'total' => $total, 'current' => $current, 'size' => $size]);
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

    // 客户360：主档 + 会员工作流留痕 + 按手机号关联的前端客资
    Route::get('/customers/{id}', function (Request $r, int $id) {
        $c = Customer::findOrFail($id);
        $u = $r->user();
        if ($u->role === 'R_MANAGER' && $c->venue !== $u->venue) abort(403, '无权查看其它门店');
        if ($u->role === 'R_TEACHER' && ($c->owner !== $u->name && ($u->venue ? $c->venue !== $u->venue : true))) abort(403, '无权查看');

        $logs = AuditLog::where('module', '会员管理')
            ->where('target_id', (string) $c->id)
            ->orderByDesc('id')->get()->map(fn ($x) => camel($x));

        $leads = $c->phone !== ''
            ? Lead::where('phone', $c->phone)->orderByDesc('id')->get()->map(fn ($x) => camel($x))
            : [];

        return ok([
            'customer' => camel($c),
            'logs' => $logs,
            'leads' => $leads,
        ]);
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
        $current = max(1, (int) $r->query('current', 1));
        $size = min(100, max(1, (int) $r->query('size', 20)));
        $total = (clone $q)->count();
        $rows = $q->orderBy('id')->forPage($current, $size)->get()->map(fn ($x) => camel($x));

        return ok(['records' => $rows, 'total' => $total, 'current' => $current, 'size' => $size]);
    });

    Route::post('/tasks', function (Request $r) {
        $d = $r->validate([
            'title' => 'required|string|max:50',
            'customerName' => 'required|string|max:20',
            'venue' => 'required|string|in:绿地店,东部店',
            'owner' => 'nullable|string|max:20',
            'priority' => 'nullable|in:高,中,低',
            'deadline' => 'nullable|string|max:24',
            'standard' => 'nullable|string|max:200',
        ]);
        $task = Task::create([
            'title' => $d['title'],
            'customer_name' => $d['customerName'],
            'venue' => $d['venue'],
            'owner' => $d['owner'] ?? '未分配',
            'priority' => $d['priority'] ?? '中',
            'deadline' => $d['deadline'] ?? '',
            'standard' => $d['standard'] ?? '',
            'status' => '待接收',
        ]);
        audit($r, '新增', '任务中心', $task->id, "{$task->title}·{$task->customer_name}", $task->venue, "创建任务，负责人[{$task->owner}]，验收标准[{$task->standard}]");
        return ok(camel($task));
    });

    Route::patch('/tasks/{id}', function (Request $r, int $id) {
        $task = Task::findOrFail($id);
        $allowed = ['title', 'customer_name', 'venue', 'owner', 'priority', 'deadline', 'standard', 'status'];
        $task->update(collect(camelToSnake($r->all()))->only($allowed)->all());
        audit($r, $r->input('_action', '修改'), '任务中心', $task->id, "{$task->title}·{$task->customer_name}", $task->venue, '任务流转：'.($r->input('status') ?: '字段更新'));
        return ok(camel($task));
    });
        Route::get('/approvals', function (Request $r) {
        $u = $r->user();
        abort_unless(in_array($u->role, ['R_SUPER', 'R_MANAGER']), 403);
        $current = max(1, (int) $r->query('current', 1));
        $size = min(100, max(1, (int) $r->query('size', 20)));
        $total = Approval::count();
        $rows = Approval::orderBy('id')->forPage($current, $size)->get()->map(fn ($x) => camel($x));

        return ok(['records' => $rows, 'total' => $total, 'current' => $current, 'size' => $size]);
    });

    Route::post('/approvals', function (Request $r) {
        if (! in_array($r->user()->role, ['R_SUPER', 'R_MANAGER'], true)) {
            return response()->json(['code' => 1, 'message' => '仅店长及以上可发起价格审批'], 403);
        }
        $d = $r->validate([
            'customerName' => 'required|string|max:20',
            'cardName' => 'required|string|max:40',
            'standardPrice' => 'required|numeric|min:0',
            'requestPrice' => 'required|numeric|min:0',
            'reason' => 'nullable|string|max:200',
        ]);
        if ((float) $d['requestPrice'] >= (float) $d['standardPrice']) {
            return response()->json(['code' => 1, 'message' => '申请价应低于标准价'], 422);
        }
        $a = Approval::create([
            'customer_name' => $d['customerName'],
            'applicant' => $r->user()->name,
            'card_name' => $d['cardName'],
            'standard_price' => (int) $d['standardPrice'],
            'request_price' => (int) $d['requestPrice'],
            'reason' => $d['reason'] ?? '',
            'status' => '待店长初审',
            'apply_time' => now()->format('Y-m-d H:i'),
        ]);
        audit($r, '发起', '价格审批', $a->id, "价格审批单 #{$a->id}", '双店', "申请[{$a->card_name}] {$a->apply_time} 特价{$a->request_price}/标准{$a->standard_price}");
        return ok(camel($a));
    });

    Route::post('/approvals/{id}/decide', function (Request $r, int $id) {
        $a = Approval::findOrFail($id);
        $decision = $r->input('decision');
        $map = ['初审通过' => '待老板终审', '终审通过' => '已通过', '驳回' => '已驳回', '关联成交' => '已关联成交'];
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
        $current = max(1, (int) $r->query('current', 1));
        $size = min(100, max(1, (int) $r->query('size', 20)));
        $total = (clone $q)->count();
        $rows = $q->forPage($current, $size)->get()->map(fn ($x) => camel($x));

        return ok(['records' => $rows, 'total' => $total, 'current' => $current, 'size' => $size]);
    });

    // ---------- KeepYoga 只读代理（阶段1：凭据仅存服务端） ----------
    Route::post('/ky/session', function (Request $r) {
        requireSuper($r);
        try {
            KyClient::token((bool) $r->input('force'));
        } catch (\Throwable $e) {
            return response()->json(['code' => 1, 'message' => $e->getMessage()]);
        }

        return ok(['ok' => true]);
    });

    Route::post('/ky/call', function (Request $r) {
        requireSuper($r);
        $path = (string) $r->input('path', '');
        abort_unless(is_array($r->input('form')), 422, 'form 必须是对象');
        try {
            return ok(KyClient::call($path, $r->input('form')));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['code' => 1, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['code' => 1, 'message' => $e->getMessage()]);
        }
    });

    // ---------- 随心瑜账号配置（仅超管，存数据库，可切换） ----------
    Route::get('/ky/config', function (Request $r) {
        abort_unless($r->user()->role === 'R_SUPER', 403);
        $ky = (AppSetting::first()?->ky) ?? [];

        return ok([
            'phone' => (string) ($ky['phone'] ?? ''),
            'configured' => (bool) ((($ky['phone'] ?? '') && ($ky['password'] ?? '')) || config('services.ky.phone')),
        ]);
    });

    Route::put('/ky/config', function (Request $r) {
        abort_unless($r->user()->role === 'R_SUPER', 403);
        $d = $r->validate(['phone' => 'required|string|max:20', 'password' => 'nullable|string|max:64']);
        $s = AppSetting::firstOrCreate([]);
        $ky = (array) (($s->ky) ?? []);
        $ky['phone'] = trim($d['phone']);
        if (! empty($d['password'])) $ky['password'] = $d['password'];
        $s->update(['ky' => $ky]);
        Illuminate\Support\Facades\Cache::forget('ky_access_token');
        audit($r, '修改', 'KeepYoga同步', 0, '随心瑜账号', '双店', "登录账号更新为 {$ky['phone']}");

        return ok(['phone' => $ky['phone'], 'configured' => true]);
    });

    // ---------- KeepYoga 服务端全量导入（服务器拉取+幂等落库，避免大请求经浏览器） ----------
    Route::post('/ky/import', function (Request $r) {
        requireSuper($r);
        $stores = ['绿地店' => '1', '东部店' => '4250'];
        $venue = (string) $r->input('venue');
        $venueId = (string) $r->input('venueId');
        abort_unless(isset($stores[$venue]) && $stores[$venue] === $venueId, 422, '门店参数无效');
        try {
            $result = KyMemberSyncService::sync($venue, $venueId);
        } catch (\Throwable $e) {
            return response()->json(['code' => 1, 'message' => 'KeepYoga 多表同步失败：'.$e->getMessage()]);
        }

        $batch = 'IMP-'.now()->format('Ymd-His').'-'.substr((string) mt_rand(1000, 9999), 0, 4);
        $detail = sprintf(
            '导出表格：会员基础表 %d 条 · 会员卡表 %d 条 · 团课预约 %d 条 · 私教预约 %d 条（出勤口径月 %s / %s / %s）；导入落库：新增 %d · 更新 %d · 未变化 %d · 跳过 %d',
            $result['total'], $result['cards'], $result['leagueBookings'] ?? 0, $result['privateBookings'] ?? 0,
            $result['attendancePeriod']['m1'] ?? '-', $result['attendancePeriod']['m2'] ?? '-', $result['attendancePeriod']['m3'] ?? '-',
            $result['created'], $result['updated'], $result['unchanged'], $result['skipped']
        );
        SyncJob::create([
            'batch_no' => $batch, 'data_type' => '会员/卡项/出勤多表', 'venue' => $venue,
            'total_count' => $result['total'],
            'success_count' => $result['created'] + $result['updated'] + $result['unchanged'],
            'fail_count' => $result['skipped'],
            'status' => $result['skipped'] > 0 ? '部分失败' : '成功',
            'operator' => $r->user()->name, 'finished_at' => now(), 'detail' => $detail,
        ]);
        audit($r, '导入', 'KeepYoga同步', 0, "批次{$batch}", $venue, $detail);

        return ok($result + ['batchNo' => $batch]);
    });

    // ---------- AI 大模型代理（OpenAI 兼容协议，解决浏览器跨域） ----------
    Route::post('/ai/chat', function (Request $r) {
        $d = $r->validate([
            'baseUrl' => 'required|url',
            'apiKey' => 'nullable|string',
            'model' => 'required|string',
            'messages' => 'required|array',
        ]);
        $saved = (array) (AppSetting::first()?->ai ?? []);
        $d['apiKey'] = ($d['apiKey'] && $d['apiKey'] !== 'server-configured') ? $d['apiKey'] : ($saved['apiKey'] ?? '');
        if ($d['apiKey'] === '') return response()->json(['code' => 1, 'message' => '尚未配置 API Key'], 422);
        if (! str_starts_with($d['baseUrl'], 'https://')) return response()->json(['code' => 1, 'message' => '接口地址必须为 https'], 422);

        // 推理模型（reasoner / r1 / o1 / o3 / thinking）不支持 temperature，
        // 且 max_tokens 需要足够大来容纳推理过程
        $isReasoning = (bool) preg_match('/(reasoner|^r1|deepseek-r1|^o1|^o3|-thinking|viz|thinking)/i', $d['model']);
        $payload = [
            'model' => $d['model'],
            'messages' => $d['messages'],
        ];
        // 非推理模型才带 temperature
        if (! $isReasoning && $r->filled('temperature')) {
            $payload['temperature'] = (float) $r->input('temperature');
        }
        // max_tokens：推理模型给足余量，普通模型按传入值
        if ($r->filled('maxTokens')) {
            $mt = (int) $r->input('maxTokens');
            $payload['max_tokens'] = $isReasoning ? max(2048, $mt) : max(16, $mt);
        }
        // 流式输出（中转站对 stream 请求才结算 token，否则可能不返回用量）
        $stream = (bool) $r->input('stream', false);
        if ($stream) $payload['stream'] = true;

        try {
            $resp = Http::withToken($d['apiKey'])
                ->timeout(120)
                ->withOptions($stream ? ['stream' => true] : [])
                ->post(rtrim($d['baseUrl'], '/').'/chat/completions', $payload);
        } catch (\Throwable $e) {
            return response()->json(['code' => 1, 'message' => '无法连接大模型接口: '.mb_substr($e->getMessage(), 0, 160)]);
        }
        if (! $resp->successful()) {
            return response()->json(['code' => 1, 'message' => '大模型返回 HTTP '.$resp->status().': '.mb_substr($resp->body(), 0, 250)]);
        }

        if ($stream) {
            // 转发上游 SSE 流（X-Accel-Buffering:no 关闭 nginx 缓冲）
            $upstream = $resp->toPsrResponse()->getBody();
            return response()->stream(function () use ($upstream) {
                while (! $upstream->eof()) {
                    $chunk = $upstream->read(1024);
                    if ($chunk === '') break;
                    echo $chunk;
                    if (ob_get_level() > 0) { ob_flush(); }
                    flush();
                }
            }, 200, [
                'Content-Type' => 'text/event-stream; charset=utf-8',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        $content = $resp->json('choices.0.message.content');
        if ($content === null) return response()->json(['code' => 1, 'message' => '大模型响应缺少内容: '.mb_substr($resp->body(), 0, 150)]);

        return ok(['content' => $content]);
    });

    Route::post('/ai/models', function (Request $r) {
        $d = $r->validate([
            'baseUrl' => 'required|url',
            'apiKey' => 'required|string',
        ]);
        if (! str_starts_with($d['baseUrl'], 'https://')) return response()->json(['code' => 1, 'message' => '接口地址必须为 https'], 422);

        try {
            $resp = Http::withToken($d['apiKey'])
                ->timeout(30)
                ->get(rtrim($d['baseUrl'], '/').'/models');
        } catch (\Throwable $e) {
            return response()->json(['code' => 1, 'message' => '无法连接大模型接口: '.mb_substr($e->getMessage(), 0, 160)]);
        }
        if (! $resp->successful()) {
            return response()->json(['code' => 1, 'message' => '获取模型列表 HTTP '.$resp->status().': '.mb_substr($resp->body(), 0, 200)]);
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

    Route::get('/ai/config', function (Request $r) {
        // 读取接口对全部登录角色开放（不含密钥明文），保证各角色工作台都能水合同一份 AI 配置
        $ai = (array) (AppSetting::first()?->ai ?? []);
        return ok(collect($ai)->except('apiKey')->put('configured', ! empty($ai['apiKey']))->all());
    });

    Route::put('/ai/config', function (Request $r) {
        requireSuper($r);
        $d = $r->validate([
            'enabled' => 'required|boolean', 'providerLabel' => 'required|string',
            'baseUrl' => 'required|url', 'apiKey' => 'nullable|string|max:500',
            'model' => 'required|string', 'temperature' => 'required|numeric|min:0|max:2',
        ]);
        $s = AppSetting::firstOrCreate([]);
        $ai = (array) ($s->ai ?? []);
        if (! empty($d['apiKey']) && $d['apiKey'] !== 'server-configured') $ai['apiKey'] = $d['apiKey'];
        unset($d['apiKey']);
        $s->update(['ai' => array_merge($ai, $d)]);
        return ok(array_merge($d, ['apiKey' => '', 'configured' => ! empty($ai['apiKey'])]));
    });

    // ---------- 人员管理（仅超管） ----------
    Route::get('/accounts', function (Request $r) {
        abort_unless($r->user()->role === 'R_SUPER', 403);
        $roleMap = ['R_SUPER' => '超管', 'R_MANAGER' => '店长', 'R_TEACHER' => '老师', 'R_MEDIA' => '新媒体'];
        $selfName = $r->user()->name;

        return ok(User::orderBy('id')->get()->map(function ($u) use ($roleMap, $selfName) {
            return [
                'key' => $u->username,
                'userName' => $u->name,
                'roleCode' => $u->role,
                'roleLabel' => $roleMap[$u->role] ?? $u->role,
                'venues' => $u->venues ?? [],
                'email' => $u->email,
                'status' => $u->status ?? '启用',
                'self' => $u->name === $selfName,
            ];
        }));
    });

    Route::post('/accounts', function (Request $r) {
        requireSuper($r);
        $d = $r->validate([
            'userName' => 'required|string|max:20',
            'roleCode' => 'required|string|in:R_SUPER,R_MANAGER,R_TEACHER,R_MEDIA',
            'venues' => 'required|array|min:1',
            'venues.*' => 'string|in:绿地店,东部店',
            'email' => 'nullable|email|max:60',
            'password' => 'required|string|min:8|max:64',
        ]);
        abort_unless(User::where('username', $d['userName'])->doesntExist(), 422, '登录名已存在');

        $venues = $d['venues'];
        $venue = $d['roleCode'] === 'R_MANAGER' ? $venues[0] : ($d['roleCode'] === 'R_TEACHER' ? $venues[0] : null);
        $user = User::create([
            'name' => $d['userName'],
            'username' => $d['userName'],
            'email' => $d['email'] ?? ($d['userName'].'@yimai.local'),
            'password' => $d['password'],
            'role' => $d['roleCode'],
            'venue' => $venue,
            'venues' => $venues,
            'status' => '启用',
        ]);
        $roleMap = ['R_SUPER' => '超管', 'R_MANAGER' => '店长', 'R_TEACHER' => '老师', 'R_MEDIA' => '新媒体'];
        $roleLabel = $roleMap[$d['roleCode']] ?? $d['roleCode'];
        audit($r, '新增', '人员管理', $user->id, $user->name, is_string($venue) ? $venue : '双店', "开通账号：{$user->name} ({$roleLabel}) / 门店[".implode('、', $venues).']');

        return ok(['key' => $user->username]);
    });

    // 停用 / 启用 / 改角色 / 重置密码（同一接口按动作处理）
    Route::patch('/accounts/{key}', function (Request $r, string $key) {
        requireSuper($r);
        $user = User::where('username', $key)->firstOrFail();
        if ($user->name === $r->user()->name) abort(422, '不能修改自己的账号');

        $action = $r->input('action', 'update');
        $allowed = ['update', 'disable', 'enable', 'resetPassword'];
        abort_unless(in_array($action, $allowed, true), 422, '未知操作');

        $d = $r->validate([
            'roleCode' => 'nullable|string|in:R_SUPER,R_MANAGER,R_TEACHER,R_MEDIA',
            'venues' => 'nullable|array|min:1',
            'venues.*' => 'string|in:绿地店,东部店',
            'password' => 'nullable|string|min:8|max:64',
        ]);
        $detail = '';
        if ($action === 'disable' || $action === 'enable') {
            $user->update(['status' => $action === 'disable' ? '停用' : '启用']);
            $detail = $action === 'disable' ? '停用账号' : '启用账号';
        } elseif ($action === 'resetPassword') {
            abort_unless(! empty($d['password']), 422, '请输入新密码');
            $user->update(['password' => $d['password']]);
            $detail = '重置密码';
        } else {
            $patch = [];
            if (! empty($d['roleCode']) && $d['roleCode'] !== $user->role) {
                $patch['role'] = $d['roleCode'];
                // 店长/老师必须有门店范围；超管/新媒体为双店
                $patch['venue'] = in_array($d['roleCode'], ['R_MANAGER', 'R_TEACHER'], true) ? (($d['venues'] ?? $user->venues)[0] ?? null) : null;
                $detail = '角色调整';
            }
            if (! empty($d['venues'])) $patch['venues'] = $d['venues'];
            if (! empty($patch)) $user->update($patch);
            $detail = $detail ?: '资料更新';
        }
        audit($r, $action === 'resetPassword' ? '重置' : '修改', '人员管理', $user->id, $user->name, is_string($user->venue) ? $user->venue : '双店', $detail);

        return ok(['ok' => true]);
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

    // ---------- Legacy browser import (retained only for old clients) ----------
    Route::post('/customers/import', function (Request $r) {
        requireSuper($r);
        return response()->json(['code' => 1, 'message' => '该接口已废弃，请使用 /ky/import 执行会员、卡项和出勤多表同步'], 410);
    });

    Route::get('/sync-jobs', function (Request $r) {
        requireSuper($r);
        $q = SyncJob::query();
        if ($status = $r->query('status')) $q->where('status', $status);
        if ($type = $r->query('dataType')) $q->where('data_type', 'like', "%{$type}%");
        $current = max(1, (int) $r->query('current', 1));
        $size = min(100, max(1, (int) $r->query('size', 20)));
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('id')->forPage($current, $size)->get()->map(fn ($x) => camel($x));

        return ok(['records' => $rows, 'total' => $total, 'current' => $current, 'size' => $size]);
    });

    // ---------- 今日工作台汇总（服务端计算） ----------
    // ---------- 快照（今日预约等，落库供工作台读取） ----------
    Route::get('/today/snapshot', function (Request $r) {
        $snap = AppSetting::first()?->snapshot ?? [];

        return ok($snap);
    });

    Route::put('/today/snapshot', function (Request $r) {
        requireSuper($r);
        $d = $r->validate([
            'todayBookings' => 'required|array',
            'todayBookings.绿地店' => 'required|integer|min:0',
            'todayBookings.东部店' => 'required|integer|min:0',
            'trialBookings' => 'required|array',
            'trialBookings.绿地店' => 'required|integer|min:0',
            'trialBookings.东部店' => 'required|integer|min:0',
        ]);
        $s = AppSetting::firstOrCreate([]);
        $snap = $s->snapshot ?? [];
        $snap['todayBookings'] = [
            '绿地店' => (int) ($d['todayBookings']['绿地店'] ?? 0),
            '东部店' => (int) ($d['todayBookings']['东部店'] ?? 0),
        ];
        $snap['trialBookings'] = [
            '绿地店' => (int) ($d['trialBookings']['绿地店'] ?? 0),
            '东部店' => (int) ($d['trialBookings']['东部店'] ?? 0),
        ];
        $snap['fetchedAt'] = now()->format('Y-m-d H:i:s');
        $snap['fetchedBy'] = $r->user()->name;
        $s->update(['snapshot' => $snap]);

        return ok($snap);
    });

    // ---------- 经营看板指标（基于现有业务数据实时计算） ----------
    Route::get('/analytics/summary', function (Request $r) {
        $u = $r->user();

        $custQ = Customer::query();
        if ($u->role !== 'R_SUPER') $custQ->where('venue', $u->venue);
        $customers = $custQ->get();
        $totalMembers = $customers->filter(fn ($c) => $c->layer !== 'P5' || str_starts_with((string) $c->external_id, 'ky:'))->count();
        // 待分配以随心瑜顾问字段为准，本地负责人只是后续执行归属。
        $unassigned = $customers->filter(fn ($c) => trim((string) $c->consultant) === '')->count();

        $leadQ = Lead::query();
        if ($u->role !== 'R_SUPER' && $u->venue) $leadQ->where('venue', $u->venue);
        $leads = $leadQ->get();
        $newLeads = $leads->where('status', '新留资')->count();
        $leadsWithTeacher = $leads->filter(fn ($l) => $l->service_teacher !== '')->count();
        $assignRate = $leads->count() > 0 ? round($leadsWithTeacher / $leads->count() * 100) : 0;

        $custWithAction = $customers->filter(fn ($c) => $c->owner !== '未分配' && $c->next_action !== null && $c->next_action !== '')->count();
        $closureRate = $totalMembers > 0 ? round($custWithAction / $totalMembers * 100) : 0;

        $renewalIds = filteredIds('待续课');
        $renewing = $customers->whereIn('id', $renewalIds);
        $renewalTouched = $renewing->filter(fn ($c) => $c->renewal_plan !== null && $c->renewal_plan !== '')->count();
        $renewalRate = $renewing->count() > 0 ? round($renewalTouched / $renewing->count() * 100) : 0;

        $taskQ = Task::query();
        if ($u->role !== 'R_SUPER' && $u->venue) $taskQ->where('venue', $u->venue);
        $tasks = $taskQ->get();
        $taskRate = $tasks->count() > 0 ? round($tasks->where('status', '已完成')->count() / $tasks->count() * 100) : 0;

        return ok([
            'totalCustomers' => $customers->count(),
            'totalMembers' => $totalMembers,
            'unassigned' => $unassigned,
            'assignRate' => $assignRate,           // 留资分配率
            'closureRate' => $closureRate,          // 客户闭环率
            'renewalTasks' => $renewing->count(),   // 待续课数
            'renewalRate' => $renewalRate,          // 续费已处理率
            'taskRate' => $taskRate,                // 任务完成率
            'doneTasks' => $tasks->where('status', '已完成')->count(),
            'totalTasks' => $tasks->count(),
        ]);
    });

    // 经营看板趋势（真实数据版）：按日留资新增 + 成交转化；按最近三个月的签到聚合
    Route::get('/analytics/trends', function (Request $r) {
        $u = $r->user();
        $leadQ = Lead::query();
        if ($u->role !== 'R_SUPER' && $u->venue) $leadQ->where('venue', $u->venue);

        $start = $r->query('start') ?: now()->startOfMonth()->toDateString();
        $end = $r->query('end') ?: now()->toDateString();
        $leads = $leadQ->whereBetween('created_at', [$start.' 00:00:00', $end.' 23:59:59'])->get();

        $byDate = [];
        $bucket = function (string $date, string $venue, $l) use (&$byDate): void {
            $byDate[$date][$venue]['leads'] = ($byDate[$date][$venue]['leads'] ?? 0) + 1;
            // 已约体验及以上 → 计入约客/预约
            if (in_array($l->status, ['已约体验', '已体验', '已成交'], true)) {
                $byDate[$date][$venue]['booked'] = ($byDate[$date][$venue]['booked'] ?? 0) + 1;
            }
            // 已体验/已成交 → 计入体验
            if (in_array($l->status, ['已体验', '已成交'], true)) {
                $byDate[$date][$venue]['experienced'] = ($byDate[$date][$venue]['experienced'] ?? 0) + 1;
            }
            if ($l->status === '已成交') $byDate[$date][$venue]['deals'] = ($byDate[$date][$venue]['deals'] ?? 0) + 1;
            if ($l->deal_amount) $byDate[$date][$venue]['amount'] = ($byDate[$date][$venue]['amount'] ?? 0) + (int) $l->deal_amount;
            if ($l->redeem_amount) $byDate[$date][$venue]['redeem'] = ($byDate[$date][$venue]['redeem'] ?? 0) + (int) $l->redeem_amount;
        };
        foreach ($leads as $l) {
            $d = $l->created_at ? $l->created_at->toDateString() : $l->lead_date;
            $bucket($d, $l->venue ?: '双店', $l);
        }

        // 客户到店分布（最近30天有到店记录）
        $custQ = Customer::query();
        if ($u->role !== 'R_SUPER') $custQ->where('venue', $u->venue);
        $visitQ = (clone $custQ)->where('last_visit', '>=', now()->subDays(30)->toDateString());
        $visit30 = $visitQ->count();
        $activeCustomers = (clone $custQ)->where('attend_m3', '>', 0)->count();
        $memberTotal = (clone $custQ)->where('layer', '!=', 'P5')->orWhere('external_id', 'like', 'ky:%')->count();

        $sumKey = fn ($k) => collect($byDate)->flatMap(fn ($vs) => collect($vs)->pluck($k))->sum();
        $totalLeads = $sumKey('leads');
        $totalBooked = $sumKey('booked');
        $totalDeals = $sumKey('deals');
        $totalAmount = $sumKey('amount');
        $totalRedeem = $sumKey('redeem');

        return ok([
            'daily' => array_values(collect($byDate)->sortKeys()->map(function ($venues, $date) {
                $out = ['date' => $date];
                foreach ($venues as $v => $c) {
                    $out[$v] = [
                        'leads' => $c['leads'] ?? 0,
                        'booked' => $c['booked'] ?? 0,
                        'experienced' => $c['experienced'] ?? 0,
                        'deals' => $c['deals'] ?? 0,
                        'amount' => $c['amount'] ?? 0,
                        'redeem' => $c['redeem'] ?? 0,
                    ];
                }
                return $out;
            })->all()),
            'summary' => [
                'memberTotal' => $memberTotal,
                'leadCount' => $totalLeads,
                'bookingCount' => $totalBooked,
                'visitCount' => $totalBooked,
                'trialCount' => $sumKey('experienced'),
                'dealCount' => $totalDeals,
                'dealAmount' => $totalAmount,
                'redeemAmount' => $totalRedeem,
                'dealRate' => $totalBooked > 0 ? round($totalDeals / $totalBooked * 100, 1) : 0,
                'leadToVisitRate' => $totalLeads > 0 ? round($totalBooked / $totalLeads * 100, 1) : 0,
            ],
            'visit30' => $visit30,
            'activeCustomers' => $activeCustomers,
            'attendanceSummary' => [
                'm1' => (clone $custQ)->where('attend_m1', '>', 0)->count(),
                'm2' => (clone $custQ)->where('attend_m2', '>', 0)->count(),
                'm3' => $activeCustomers,
            ],
            'period' => ['start' => $start, 'end' => $end],
        ]);
    });

    // 经营看板来源分布（真实数据版）：按留资来源渠道统计
    Route::get('/analytics/channels', function (Request $r) {
        $u = $r->user();
        $leadQ = Lead::query();
        if ($u->role !== 'R_SUPER' && $u->venue) $leadQ->where('venue', $u->venue);
        $start = $r->query('start') ?: now()->startOfMonth()->toDateString();
        $end = $r->query('end') ?: now()->toDateString();
        $leads = $leadQ->whereBetween('created_at', [$start.' 00:00:00', $end.' 23:59:59'])->get();

        $channels = [];
        foreach ($leads as $l) {
            $src = trim((string) $l->source) !== '' ? $l->source : '其他';
            $channels[$src] = ($channels[$src] ?? 0) + 1;
        }
        arsort($channels);

        $rows = [];
        foreach ($channels as $name => $leadsCount) {
            $rows[] = ['channel' => $name, 'leads' => $leadsCount];
        }

        return ok(['rows' => $rows, 'total' => $leads->count()]);
    });

    // 经营看板平台金额：按下单平台（缺失时回退来源渠道）聚合核销/成交金额，并给出本月成交与核销汇总
    Route::get('/analytics/platforms', function (Request $r) {
        $u = $r->user();
        $leadQ = Lead::query();
        if ($u->role !== 'R_SUPER' && $u->venue) $leadQ->where('venue', $u->venue);
        $start = $r->query('start') ?: now()->startOfMonth()->toDateString();
        $end = $r->query('end') ?: now()->toDateString();
        $leads = $leadQ->whereBetween('created_at', [$start.' 00:00:00', $end.' 23:59:59'])->get();

        $platforms = [];
        foreach ($leads as $l) {
            $platform = trim((string) $l->order_platform) !== '' ? $l->order_platform : (trim((string) $l->source) !== '' ? $l->source : '其他');
            $platforms[$platform] = $platforms[$platform] ?? ['redeem' => 0, 'deal' => 0, 'leads' => 0];
            $platforms[$platform]['redeem'] += (int) $l->redeem_amount;
            $platforms[$platform]['deal'] += (int) $l->deal_amount;
            $platforms[$platform]['leads'] += 1;
        }

        $rows = [];
        foreach ($platforms as $name => $v) {
            $rows[] = ['platform' => $name, 'redeem' => $v['redeem'], 'deal' => $v['deal'], 'leads' => $v['leads']];
        }
        usort($rows, fn ($a, $b) => $b['deal'] + $b['redeem'] <=> $a['deal'] + $a['redeem']);

        return ok([
            'rows' => $rows,
            'totalDeal' => (int) $leads->sum('deal_amount'),
            'totalRedeem' => (int) $leads->sum('redeem_amount'),
            'dealCount' => $leads->where('status', '已成交')->count(),
        ]);
    });

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

    // ---------- 版本更新（仅超管） ----------
    Route::get('/system/version', function () {
        return ok(systemVersionInfo());
    });

    Route::get('/system/changelog', function () {
        $candidates = [
            base_path().'/CHANGELOG.md',                 // 后端站根（部署时随包复制）
            dirname(base_path()).'/CHANGELOG.md',        // 上一级（git 仓库根）
            base_path().'/public/CHANGELOG.md',
        ];
        $file = null;
        foreach ($candidates as $f) {
            if (is_file($f)) { $file = $f; break; }
        }
        if ($file === null) return ok(['content' => "# 更新日志\n\n暂无可用更新日志。"]);

        $content = file_get_contents($file);
        return ok(['content' => is_string($content) && trim($content) !== '' ? $content : "# 更新日志\n\n暂无可用更新日志。"]);
    });

    Route::post('/system/update', function (Request $r) {
        requireSuper($r);
        $remote = systemVersionInfo();
        if ($remote['remote']['error'] !== '') return response()->json(['code' => 1, 'message' => '远端不可达：'.$remote['remote']['error']], 503);
        if ($remote['upToDate']) return ok(['updated' => false, 'message' => '当前已是最新版本']);
        $script = base_path('../update.sh');
        if (! is_file($script)) return response()->json(['code' => 1, 'message' => '服务器未配置受控更新脚本（/www/wwwroot/oa.nbyimai.com/update.sh 不存在），请先安装 update.sh'], 503);
        $result = runShell('bash '.escapeshellarg($script), 900);
        if (! $result['ok']) {
            $lines = array_slice($result['output'], -15);
            return response()->json(['code' => 1, 'message' => '更新脚本执行失败', 'output' => $lines], 500);
        }
        audit($r, '更新', '版本更新', 0, '系统版本', '双店', implode('；', array_slice($result['output'], -30)));
        return ok(['updated' => true, 'output' => $result['output']]);
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
});

// ---------- helpers ----------
function ok($data) { return response()->json(['code' => 0, 'data' => $data]); }

function requireSuper(Request $request): void
{
    abort_unless($request->user()?->role === 'R_SUPER', 403, '仅超管可执行此操作');
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
        'ip' => (string) $r->ip(),
        'user_agent' => mb_substr((string) $r->header('User-Agent'), 0, 300),
    ]);
}

function rules(): array
{
    $s = AppSetting::first();
    return $s?->rules ?? ['renewalThreshold' => 10, 'vipThreshold' => 100, 'declineMode' => 'strict', 'predropMin' => 15, 'predropMax' => 30, 'reviveDays' => 30];
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
    $predropMin = (int) ($rules['predropMin'] ?? 15);
    $predropMax = (int) ($rules['predropMax'] ?? 30);
    $reviveDays = (int) ($rules['reviveDays'] ?? 30);
    $days = fn ($d) => $d ? (int) ((time() - strtotime($d)) / 86400) : null;

    return Customer::query()->get()
        ->filter(function (Customer $c) use ($list, $threshold, $vip, $strict, $predropMin, $predropMax, $reviveDays, $days) {
            $m1 = $c->attend_m1; $m2 = $c->attend_m2; $m3 = $c->attend_m3;
            $dd = $days($c->last_visit);
            $hasAsset = $c->main_card !== null && ! in_array($c->main_card, ['', '—', '待同步卡项'], true);
            $expireDays = $c->expire_date ? now()->startOfDay()->diffInDays($c->expire_date, false) : null;
            $revive = (bool) $c->in_revive || ($dd !== null && $dd > $reviveDays && $hasAsset);
            $preLoss = ! $revive && $dd !== null && (($m2 > 0 && $m3 === 0) || ($dd >= $predropMin && $dd <= $predropMax));
            $declining = ! $revive && ! $preLoss && ($strict ? ($m1 > $m2 && $m2 > $m3) : ($m2 > $m3));
            return match ($list) {
                '待续课' => $hasAsset && (($m3 > 0 && $c->remain_times !== null && $c->remain_times <= $threshold)
                    || ($expireDays !== null && $expireDays >= 0 && $expireDays <= 30)),
                '出勤降低' => $declining,
                'VIP' => $c->total_purchased >= $vip,
                '预流失' => $preLoss,
                '待复活' => $revive,
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

/** 执行一段 shell 脚本（在线更新用），返回 [ok, output[]] */
function runShell(string $script, int $timeout = 180): array
{
    $process = new Symfony\Component\Process\Process(['bash', '-c', $script], base_path());
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
                $p = new Symfony\Component\Process\Process(['git', ...$args], base_path());
                $p->setTimeout(30)->run();
                return trim($p->isSuccessful() ? $p->getOutput() : '');
            } catch (\Throwable) {
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
        $resp = Illuminate\Support\Facades\Http::timeout(20)->get(
            'https://gitee.com/api/v5/repos/meng-taoo/yimai-workbench/commits/main',
            $giteeToken !== '' ? ['access_token' => $giteeToken] : []
        );
        if ($resp->successful() && ($resp->json('sha') ?? false)) {
            $remoteSha = (string) $resp->json('sha');
        } else {
            $fallback = Illuminate\Support\Facades\Http::timeout(20)->get('https://api.github.com/repos/a6828464/yimai-workbench/commits/main');
            if ($fallback->successful() && ($fallback->json('sha') ?? false)) {
                $remoteSha = (string) $fallback->json('sha');
            } else {
                $remoteErr = 'Gitee/GitHub API 均不可达';
            }
        }
    } catch (\Throwable $e) {
        $remoteErr = mb_substr($e->getMessage(), 0, 200);
    }

    return [
        'local' => $local,
        'remote' => ['commit' => $remoteSha, 'error' => $remoteErr],
        'upToDate' => $remoteSha !== '' && str_starts_with($remoteSha, $local['commit']),
    ];
}
