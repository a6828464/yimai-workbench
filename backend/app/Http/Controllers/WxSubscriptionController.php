<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WxSubscription;
use App\Services\WeChatSubscribeMessage;
use Illuminate\Http\Request;

/**
 * 小程序「我的 → 模板通知」后端（方案 docs/miniapp-migration/11 §6.3）。
 *
 * 三个接口的分工：
 *  - index  告诉前端「现在能不能发、你绑没绑、每个模板还剩几次」（页面首屏）
 *  - store  落用户在 `wx.requestSubscribeMessage` 里点下的授权（一次 = 一条机会）
 *  - test   自测发一条，用来区分「配置错了」和「用户没授权」
 *
 * 口径：`accepted` = 曾经授权过（total_accepted > 0），`remain` = 现在还能发几条。
 * 两者分开是因为「从没授权」和「授权过但已用完」要给完全不同的引导文案：
 * 前者去开启提醒，后者要先点「允许」再发（一次性授权用完即失效，微信侧没有查询接口）。
 */
final class WxSubscriptionController extends Controller
{
    public function __construct(private readonly WeChatSubscribeMessage $wx) {}

    /** GET /wx/subscriptions */
    public function index(Request $r)
    {
        return ok($this->state($r->user()));
    }

    /**
     * POST /wx/subscriptions  `{accepts?: string[], denies?: string[]}`
     *
     * 每收到一个 accept，对应模板 `remain + 1`、`total_accepted + 1`。
     * 未登记的键静默忽略（前端可能带着旧版本模板键），不因此报错 —— 授权事件
     * 已经发生，拒收一个键不代表整次请求失败。
     */
    public function store(Request $r)
    {
        $data = $r->validate([
            'accepts' => 'nullable|array|max:10',
            'accepts.*' => 'string|max:40',
            'denies' => 'nullable|array|max:10',
            'denies.*' => 'string|max:40',
        ]);

        $user = $r->user();
        $now = now();
        $accepted = [];

        foreach (array_unique((array) ($data['accepts'] ?? [])) as $key) {
            if (! $this->wx->has($key)) {
                continue;
            }
            $sub = WxSubscription::firstOrCreate(
                ['user_id' => $user->id, 'template_key' => $key],
                ['remain' => 0, 'total_accepted' => 0],
            );
            $sub->forceFill([
                'remain' => (int) $sub->remain + 1,
                'total_accepted' => (int) $sub->total_accepted + 1,
                'last_accepted_at' => $now,
            ])->save();
            $accepted[] = $key;
        }

        $state = $this->state($user->refresh());

        return ok([
            'templates' => $state['templates'],
            // 额外回执：前端可用它给「已开启 n 项」的 toast，不影响方案约定的主形状
            'accepted' => $accepted,
        ]);
    }

    /**
     * POST /wx/subscriptions/test  `{key}`
     *
     * 未配置 / 未绑定 → 422 + 可读中文（前端 http.ts 对非 2xx 会直接 toast `message`）。
     * 其余情况一律 200 `{sent, message}`：发送失败（43101/47003…）是业务结果，
     * 让用户看到原因就行，不该当成接口错误。
     */
    public function test(Request $r)
    {
        $data = $r->validate(['key' => 'required|string|max:40']);
        $key = (string) $data['key'];
        $user = $r->user();

        if (! $this->wx->has($key)) {
            abort(422, '未知的模板：'.$key);
        }
        if (! $this->wx->enabled($key)) {
            abort(422, '模板通知未配置或已停用（'.$key.'），请联系管理员检查 WECHAT_TPL_* 与 config/services.php 的 enabled');
        }
        if (trim((string) $user->wx_openid) === '') {
            abort(422, '尚未绑定微信，请先在「我的」页完成微信绑定');
        }

        // 测试数据用本人姓名/门店：既能让收到的通知看起来像真的，也不会泄露他人信息。
        $result = $this->wx->send($user, $key, [
            'customerName' => '测试客户',
            'staffName' => (string) $user->name,
            'venue' => (string) ($user->venue ?: '—'),
            'role' => '会籍顾问',
            'time' => now(),
        ]);

        return ok([
            'sent' => $result['status'] === 'sent',
            'message' => $result['message'],
        ]);
    }

    /**
     * 首屏状态（§6.3 的 `ok({configured, bound, templates:[...], updatedAt})`）。
     *
     * `updatedAt` 取本人订阅记录的**最后更新时间**（授权或发送都会刷新它），
     * 用于前端显示「状态更新于 …」；没有任何记录时为 null。
     *
     * @return array{configured: bool, bound: bool, templates: array<int, array<string, mixed>>, updatedAt: string|null}
     */
    private function state(User $user): array
    {
        $subs = WxSubscription::where('user_id', $user->id)
            ->get()
            ->keyBy('template_key');

        $templates = [];
        foreach ($this->wx->catalog() as $item) {
            /** @var WxSubscription|null $sub */
            $sub = $subs->get($item['key']);
            $templates[] = [
                'key' => $item['key'],
                'title' => $item['title'],
                'desc' => $item['desc'],
                'enabled' => $item['enabled'],
                'templateId' => $item['templateId'],
                'accepted' => (int) ($sub->total_accepted ?? 0) > 0,
                'remain' => (int) ($sub->remain ?? 0),
                'lastAcceptedAt' => $sub?->last_accepted_at?->toIso8601String(),
                'fields' => $item['fields'],
            ];
        }

        $updatedAt = $subs->max(fn (WxSubscription $s) => $s->updated_at?->getTimestamp() ?? 0);

        return [
            'configured' => $this->wx->isConfigured(),
            'bound' => trim((string) $user->wx_openid) !== '',
            'templates' => $templates,
            'updatedAt' => $updatedAt ? now()->setTimestamp((int) $updatedAt)->toIso8601String() : null,
        ];
    }
}
