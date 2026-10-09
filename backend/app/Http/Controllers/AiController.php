<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\ModelGenerationRecord;
use App\Models\User;
use App\Support\VisionAttachments;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class AiController extends Controller
{
    /** POST /ai/chat */
    public function chat(Request $r)
    {
        // 请求体被服务器丢弃（PHP post_max_size / 前置代理截断）时 $request->all() 与原始 body 都是空的。
        // 此时若照常跑校验，只会回一句「messages 必填」，把人带向错的方向（用户明明带了 messages）。
        // 声明了 Content-Length 的情况由全局 ValidatePostSize 中间件先拦下（渲染见 bootstrap/app.php），
        // 这里兜的是「分块传输 / SAPI 未回填 CONTENT_LENGTH」这一档 —— 两处共用同一句文案。
        // 完全空 body 的老行为（422 messages 必填）保持不变。
        if ($r->all() === [] && (string) $r->getContent() === ''
            && (int) $r->server('CONTENT_LENGTH') <= 0
            && (string) $r->server('HTTP_TRANSFER_ENCODING') !== '') {
            return response()->json([
                'code' => 1,
                'message' => VisionAttachments::OVERSIZE_BODY_MESSAGE,
            ], 413);
        }

        $d = $r->validate([
            'messages' => 'required|array|min:1|max:30',
            'messages.*.role' => 'required|in:system,user,assistant',
            'messages.*.content' => 'required|string|max:20000',
            // 图片素材（可选）：内联 dataURL，最多 3 张。
            // 规则写成数组形式而非 '|' 拼接：正则里的 (jpeg|png|webp) 含竖线，
            // ValidationRuleParser 会按 '|' 拆规则串，把分组拆坏（拆完报 validatePng 不存在）。
            // bail：attachments 不是数组时不再跑 max:3（那条会把字符串长度当图片数，产出
            // 「一次最多上传 3 张图片」+「格式不正确」两条互相矛盾的话，用户不知道信哪条）。
            'attachments' => 'bail|nullable|array|max:'.VisionAttachments::MAX_IMAGES,
            'attachments.*' => [
                'required',
                'string',
                'regex:'.VisionAttachments::DATA_URL_REGEX,
            ],
            'temperature' => 'nullable|numeric|min:0|max:2',
            'maxTokens' => 'nullable|integer|min:16|max:32768',
            'stream' => 'nullable|boolean',
            'featureType' => 'nullable|string|in:chat,marketing_moments,marketing_anti_fold,marketing_xhs,training_plan',
        ], [
            'attachments.array' => '图片参数格式不正确，请重新选择图片',
            'attachments.max' => '一次最多上传 '.VisionAttachments::MAX_IMAGES.' 张图片，请减少图片数量后重试',
            'attachments.*.required' => '第 :position 张图片内容为空，请重新选择图片',
            'attachments.*.string' => '第 :position 张图片格式不正确，请重新选择图片',
            'attachments.*.regex' => '第 :position 张图片格式不支持，请上传 JPG/PNG/WebP 图片',
        ]);
        // 图片素材：逐张校验（能否解码 / 解码后字节数 / 是否真是图片），不合法即 422 + 中文提示。
        // 校验只在内存里过，dataURL 原样透传上游，不落库也不进日志。
        $attachments = VisionAttachments::inspect((array) ($d['attachments'] ?? []));
        // 按账号每日配额，防止共用同一把付费 key 时被单账号刷爆（throttle 仅限瞬时频率）
        $dailyQuota = (int) (config('services.ai.daily_quota') ?: 200);
        abort_if(
            ModelGenerationRecord::where('user_id', $r->user()->id)
                ->where('created_at', '>=', now()->startOfDay())->count() >= $dailyQuota,
            429, '今日 AI 生成次数已达上限，请明天再试或联系管理员'
        );
        $saved = (array) (AppSetting::oldest('id')->first()?->ai ?? []);
        $baseUrl = (string) ($saved['baseUrl'] ?? '');
        $apiKey = (string) ($saved['apiKey'] ?? '');
        $model = (string) ($saved['model'] ?? '');
        $record = ModelGenerationRecord::create([
            'request_id' => (string) Str::uuid(),
            'user_id' => $r->user()->id,
            'operator_name' => $r->user()->name,
            'operator_role' => primaryRole(userRoles($r->user())),
            'feature_type' => $d['featureType'] ?? 'chat',
            'source' => 'llm',
            'provider' => (string) ($saved['providerLabel'] ?? ''),
            'model' => $model,
            'status' => 'pending',
            'input_summary' => VisionAttachments::summary((string) last($d['messages'])['content']),
        ]);
        $startedAt = microtime(true);
        if (empty($saved['enabled']) || $baseUrl === '' || $apiKey === '' || $model === '') {
            finishModelRecord($record, 'failed', $startedAt, '', '尚未配置 API Key');

            return response()->json(['code' => 1, 'message' => '尚未配置 API Key'], 422);
        }
        try {
            assertPublicHttpsUrl($baseUrl);
        } catch (Throwable $e) {
            finishModelRecord($record, 'failed', $startedAt, '', $e->getMessage());
            throw $e;
        }

        // 推理模型（reasoner / r1 / o1 / o3 / thinking）不支持 temperature，
        // 且 max_tokens 需要足够大来容纳推理过程
        $isReasoning = (bool) preg_match('/(reasoner|^r1|deepseek-r1|^o1|^o3|-thinking|viz|thinking)/i', $model);
        $payload = [
            'model' => $model,
            // 无图片时 appendMessage() 原样返回，payload 与改造前逐字节一致
            'messages' => VisionAttachments::appendMessage((array) $d['messages'], $attachments),
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
        if ($stream) {
            $payload['stream'] = true;
        }

        try {
            $resp = Http::withToken($apiKey)
                ->timeout(120)
                ->withOptions(array_merge(['allow_redirects' => false], $stream ? ['stream' => true] : []))
                ->post(rtrim($baseUrl, '/').'/chat/completions', $payload);
        } catch (Throwable $e) {
            finishModelRecord($record, 'failed', $startedAt, '', $e->getMessage());

            return response()->json(['code' => 1, 'message' => '无法连接大模型接口: '.mb_substr($e->getMessage(), 0, 160)]);
        }
        if (! $resp->successful()) {
            // 上游响应体只落内部记录，不回传前端，避免泄露服务商内部报错/请求头回显
            finishModelRecord($record, 'failed', $startedAt, '', 'HTTP '.$resp->status().': '.mb_substr($resp->body(), 0, 500));

            // 本次带图且上游明确表示「读不了图」时，如实说是模型不支持，而不是让用户
            // 对着「接口返回异常」猜。不做静默去图重试：那会产出一段「没看过图」的文案，
            // 用户却以为模型看过图 —— 典型的假绿。
            if ($attachments !== [] && VisionAttachments::looksLikeImageUnsupported($resp->status(), $resp->body())) {
                // 语义化落库：在 /model-generations 能一眼区分「模型不读图」与「上游抽风」，
                // 而不是只能从 500 字上游原文里猜。机器可判的前缀 + 出事的模型名。
                $record->update([
                    'error_message' => 'VISION_UNSUPPORTED model='.$model.' upstream=HTTP '.$resp->status(),
                    'output_preview' => '本次请求带了 '.count($attachments).' 张图片，上游拒绝读图',
                ]);

                return response()->json([
                    'code' => 1,
                    'visionUnsupported' => true,
                    'message' => '当前模型不支持图片输入，请在「模型配置」切换到支持读图的模型后重试（图片已随请求发送，未被本系统保存）',
                ], 422);
            }

            return response()->json(['code' => 1, 'message' => '大模型接口返回异常（HTTP '.$resp->status().'），请稍后重试']);
        }

        if ($stream) {
            // 转发上游 SSE 流（X-Accel-Buffering:no 关闭 nginx 缓冲）
            $upstream = $resp->toPsrResponse()->getBody();

            return response()->stream(function () use ($upstream, $record, $startedAt) {
                $raw = '';
                try {
                    while (! $upstream->eof()) {
                        $chunk = $upstream->read(1024);
                        if ($chunk === '') {
                            break;
                        }
                        $raw .= $chunk;
                        echo $chunk;
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    }
                    finishModelRecord($record, 'success', $startedAt, ssePreview($raw));
                } catch (Throwable $e) {
                    finishModelRecord($record, 'failed', $startedAt, ssePreview($raw), $e->getMessage());
                    throw $e;
                }
            }, 200, [
                'Content-Type' => 'text/event-stream; charset=utf-8',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        $content = $resp->json('choices.0.message.content');
        if ($content === null) {
            finishModelRecord($record, 'failed', $startedAt, '', '大模型响应缺少内容');

            return response()->json(['code' => 1, 'message' => '大模型响应缺少内容，请重试']);
        }

        finishModelRecord($record, 'success', $startedAt, (string) $content, null, (array) ($resp->json('usage') ?? []));

        return ok(['content' => $content]);
    }

    /** POST /model-generations/fallback */
    public function fallbackRecord(Request $r)
    {
        $d = $r->validate([
            'featureType' => 'required|string|in:marketing_moments,marketing_anti_fold,marketing_xhs,training_plan',
            'outputPreview' => 'nullable|string|max:1000',
            'errorMessage' => 'nullable|string|max:500',
        ]);
        $row = ModelGenerationRecord::create([
            'request_id' => (string) Str::uuid(), 'user_id' => $r->user()->id,
            'operator_name' => $r->user()->name, 'operator_role' => primaryRole(userRoles($r->user())),
            'feature_type' => $d['featureType'], 'source' => 'fallback', 'provider' => '本地模板',
            'model' => 'template', 'status' => 'success', 'output_preview' => mb_substr((string) ($d['outputPreview'] ?? ''), 0, 1000),
            'error_message' => isset($d['errorMessage']) ? mb_substr($d['errorMessage'], 0, 500) : null,
            'completed_at' => now(),
        ]);

        return ok(['id' => $row->id]);
    }

    /** GET /model-generations */
    public function generationsIndex(Request $r)
    {
        requireSuper($r);
        $r->validate([
            'operatorId' => 'nullable|integer|min:1',
            'start' => 'nullable|date_format:Y-m-d',
            'end' => 'nullable|date_format:Y-m-d|after_or_equal:start',
            'featureType' => 'nullable|string|max:40',
            'status' => 'nullable|string|max:16',
        ]);
        $q = ModelGenerationRecord::query()->orderByDesc('id');
        if ($r->filled('operatorId')) {
            $q->where('user_id', (int) $r->query('operatorId'));
        }
        if ($r->filled('featureType')) {
            $q->where('feature_type', (string) $r->query('featureType'));
        }
        if ($r->filled('status')) {
            $q->where('status', (string) $r->query('status'));
        }
        if ($r->filled('start')) {
            $q->where('created_at', '>=', CarbonImmutable::parse($r->query('start'))->startOfDay());
        }
        if ($r->filled('end')) {
            $q->where('created_at', '<=', CarbonImmutable::parse($r->query('end'))->endOfDay());
        }
        $current = max(1, (int) $r->query('current', 1));
        $size = min(100, max(1, (int) $r->query('size', 10)));
        $total = (clone $q)->count();
        $records = $q->forPage($current, $size)->get()->map(fn ($row) => camel($row));

        return ok([
            'records' => $records, 'total' => $total, 'current' => $current, 'size' => $size,
            'metadata' => [
                'operators' => User::query()->whereIn('id', ModelGenerationRecord::query()->distinct()->pluck('user_id'))->orderBy('name')->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
                'featureTypes' => ModelGenerationRecord::query()->distinct()->orderBy('feature_type')->pluck('feature_type'),
                'statuses' => ModelGenerationRecord::query()->distinct()->orderBy('status')->pluck('status'),
            ],
        ]);
    }

    /** POST /ai/models */
    public function models(Request $r)
    {
        requireSuper($r);
        $d = $r->validate([
            'baseUrl' => 'required|url',
            'apiKey' => 'required|string',
        ]);
        assertPublicHttpsUrl($d['baseUrl']);

        try {
            $resp = Http::withToken($d['apiKey'])
                ->timeout(30)
                ->withOptions(['allow_redirects' => false])
                ->get(rtrim($d['baseUrl'], '/').'/models');
        } catch (Throwable $e) {
            return response()->json(['code' => 1, 'message' => '无法连接大模型接口: '.mb_substr($e->getMessage(), 0, 160)]);
        }
        if (! $resp->successful()) {
            return response()->json(['code' => 1, 'message' => '获取模型列表失败（HTTP '.$resp->status().'），请检查接口地址与 Key']);
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
    }

    /** POST /ai/test */
    public function test(Request $r)
    {
        requireSuper($r);
        $d = $r->validate([
            'baseUrl' => 'required|url',
            'apiKey' => 'required|string|max:500',
            'model' => 'required|string|max:120',
        ]);
        assertPublicHttpsUrl($d['baseUrl']);
        try {
            $resp = Http::withToken($d['apiKey'])->timeout(30)
                ->withOptions(['allow_redirects' => false])
                ->post(rtrim($d['baseUrl'], '/').'/chat/completions', [
                    'model' => $d['model'],
                    'messages' => [['role' => 'user', 'content' => '只回复OK']],
                    'max_tokens' => 512,
                ]);
        } catch (Throwable $e) {
            return response()->json(['code' => 1, 'message' => '无法连接大模型接口: '.mb_substr($e->getMessage(), 0, 160)]);
        }
        if (! $resp->successful()) {
            return response()->json(['code' => 1, 'message' => '大模型接口返回异常（HTTP '.$resp->status().'），请检查接口地址与 Key']);
        }

        return ok(['content' => (string) ($resp->json('choices.0.message.content') ?? '')]);
    }

    /** GET /ai/config */
    public function configShow(Request $r)
    {
        // 读取接口对全部登录角色开放（不含密钥明文），保证各角色工作台都能水合同一份 AI 配置
        $ai = (array) (AppSetting::oldest('id')->first()?->ai ?? []);

        return ok(collect($ai)->except('apiKey')->put('configured', ! empty($ai['apiKey']))->all());
    }

    /** PUT /ai/config */
    public function configUpdate(Request $r)
    {
        requireSuper($r);
        $d = $r->validate([
            'enabled' => 'required|boolean', 'providerLabel' => 'required|string',
            'baseUrl' => 'required|url', 'apiKey' => 'nullable|string|max:500',
            'model' => 'required|string', 'temperature' => 'required|numeric|min:0|max:2',
        ]);
        assertPublicHttpsUrl($d['baseUrl']);
        $s = setting();
        $ai = (array) ($s->ai ?? []);
        if (! empty($d['apiKey']) && $d['apiKey'] !== 'server-configured') {
            $ai['apiKey'] = $d['apiKey'];
        }
        unset($d['apiKey']);
        $s->update(['ai' => array_merge($ai, $d)]);
        audit($r, '修改', '模型配置', 0, '大模型接入配置', '双店', "服务商[{$d['providerLabel']}] 模型[{$d['model']}] 启用[".($d['enabled'] ? '是' : '否').']');

        return ok(array_merge($d, ['apiKey' => '', 'configured' => ! empty($ai['apiKey'])]));
    }
}
