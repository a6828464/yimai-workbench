<?php

namespace App\Services;

use App\Models\User;
use App\Models\WxSubscription;
use App\Models\WxSubscriptionLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 微信小程序订阅消息（模板通知）客户端 + 授权账本读写。
 *
 * 为什么单独抽成服务：`subscribeMessage.send` 的失败面很宽（凭据、token、openid、
 * 模板 id、字段长度、用户拒收），混在控制器里既测不了也改不动 —— 与本仓
 * WeChatSession（code2session）保持同一分层口径。
 *
 * ## 一次性授权的记账纪律（本文件最重要的一件事）
 * 订阅消息不是「关注了就一直推」：用户在 `wx.requestSubscribeMessage` 里点一次允许，
 * 只换到**一次**下发机会；发完即失效，微信侧没有任何查询接口。所以：
 *  - 只有 `remain > 0` 才发，发成功才 `remain--`；
 *  - 43101（用户拒收 / 已无授权）把 remain 归零 —— 不归零就会一直重试同一个人，
 *    既发不出去又刷日志，还会让页面上的「剩余 n 次」长期说谎。
 *
 * ## 安全口径
 *  - appsecret 只从 `config('services.wechat.secret')` 读，绝不进日志/响应体；
 *  - 下发日志的 payload 只存请求体与微信响应，**不存 access_token**（它在查询串里，
 *    属于可冒充服务端调接口的凭据，落库即扩大泄露面）。
 */
class WeChatSubscribeMessage
{
    /** access_token 端点（官方固定地址，不接受外部注入） */
    private const TOKEN_ENDPOINT = 'https://api.weixin.qq.com/cgi-bin/token';

    /** 订阅消息下发端点（同上） */
    private const SEND_ENDPOINT = 'https://api.weixin.qq.com/cgi-bin/message/subscribe/send';

    private const TOKEN_CACHE_KEY = 'wx:subscribe:access_token';

    /**
     * token 缓存 7000 秒：微信给的有效期是 7200 秒，留 200 秒余量。
     * 不留余量的话，一个刚好在过期边界取到的 token 会在下发时失效（42001），
     * 而那一次下发就白丢了 —— 订阅消息的次数是一次性的，丢一次就是真丢。
     */
    private const TOKEN_TTL = 7000;

    /**
     * 模板关键词前缀 → 字符上限。
     *
     * 微信对每个参数按「类型前缀」限长（超了回 47003「模板参数不准确」，
     * 且错误信息里不会告诉你是哪个字段超长）。这里按下发前截断，宁可少一个字，
     * 也不要整条消息发不出去。不在表里的前缀（time/date/amount/phone_number）
     * 按格式下发，不做截断 —— 截断日期只会让它变成非法值。
     */
    private const FIELD_LIMITS = [
        'thing' => 20,
        'name' => 10,
        'phrase' => 5,
        'character_string' => 32,
        'letter' => 32,
        'symbol' => 5,
        'number' => 32,
    ];

    /**
     * 微信侧错误码 → 面向运营/用户的中文说明。
     *
     * 只覆盖订阅消息链路上真实会遇到的那些；未列出的统一「微信返回错误」并把
     * 原始 errcode/errmsg 写进流水，排障时按关键词 grep 表即可。
     */
    private const ERRCODE_MESSAGES = [
        40001 => 'access_token 无效，请检查小程序凭据',
        40003 => 'openid 无效，请让员工重新绑定微信',
        40013 => 'AppID 无效，请检查 WECHAT_MINI_APPID',
        40037 => '模板 id 不正确，请检查 WECHAT_TPL_* 与后台模板是否一致',
        41030 => '模板跳转页面路径不正确（page 不存在）',
        42001 => 'access_token 已过期，请稍后重试',
        43101 => '用户未授权或已拒收该模板，请重新点击「开启提醒」',
        45009 => '接口调用超过频率限制，请稍后再试',
        47003 => '模板参数不准确（字段超长或日期格式不对），请检查模板字段映射',
        40125 => 'AppSecret 无效，请检查 WECHAT_MINI_SECRET',
    ];

    /** token 失效类错误码：换新 token 重试一次就能成功 */
    private const TOKEN_INVALID_CODES = [40001, 42001];

    // ---------------------------------------------------------------------
    // 凭据与目录
    // ---------------------------------------------------------------------

    /**
     * 取 access_token（带 7000 秒缓存）。
     *
     * 注意「失败不缓存」：拿不到就返回 null，下一次调用会重新尝试。若把 null 也缓存
     * 7000 秒，一次网络抖动就会让模板通知静默死两个小时。
     */
    public function accessToken(): ?string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $appid = (string) config('services.wechat.appid', '');
        $secret = (string) config('services.wechat.secret', '');

        // 配置缺失属于部署问题，不是用户问题：记 warning 后按「未配置」处理，
        // 让上层走 skipped 分支，而不是伪造成功或抛 500。
        if ($appid === '' || $secret === '') {
            Log::warning('[wx-subscribe] 未配置 WECHAT_MINI_APPID / WECHAT_MINI_SECRET，订阅消息不可用');

            return null;
        }

        try {
            $resp = Http::timeout(8)->get(self::TOKEN_ENDPOINT, [
                'grant_type' => 'client_credential',
                'appid' => $appid,
                'secret' => $secret,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[wx-subscribe] 获取 access_token 请求异常', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $resp->ok()) {
            Log::warning('[wx-subscribe] 获取 access_token HTTP 非 2xx', ['status' => $resp->status()]);

            return null;
        }

        $data = (array) $resp->json();
        $token = (string) ($data['access_token'] ?? '');

        if ($token === '') {
            // 微信把业务错误放在 200 响应体里：40125/40013 这类「我们配错了」必须 error 级别，
            // 否则会混在 warning 里永远没人看（与 WeChatSession 同一口径）。
            $errcode = (int) ($data['errcode'] ?? 0);
            $isConfigError = in_array($errcode, [40013, 40125], true);
            $isConfigError
                ? Log::error('[wx-subscribe] 获取 access_token 失败：小程序凭据有误', [
                    'errcode' => $errcode,
                    'errmsg' => (string) ($data['errmsg'] ?? ''),
                ])
                : Log::warning('[wx-subscribe] 获取 access_token 失败', [
                    'errcode' => $errcode,
                    'errmsg' => (string) ($data['errmsg'] ?? ''),
                ]);

            return null;
        }

        Cache::put(self::TOKEN_CACHE_KEY, $token, self::TOKEN_TTL);

        return $token;
    }

    /**
     * 模板目录（对外形状与方案 §6.3 一致）。
     *
     * `fields` 下发的是**参数名清单**（如 `["thing1","name2","time3","thing4"]`），
     * 与小程序 `src/api/notify.ts` 的 `fields?: string[]` 对齐；中文名只留在配置与文档里，
     * 页面不渲染，排障时按这里的键名去 `wx_subscription_logs.payload.data` 里比对即可。
     *
     * @return array<int, array{key: string, title: string, desc: string, templateId: string, enabled: bool, fields: array<int, string>}>
     */
    public function catalog(): array
    {
        $out = [];
        foreach ((array) config('services.wechat.templates', []) as $key => $tpl) {
            $tpl = (array) $tpl;
            $templateId = trim((string) ($tpl['template_id'] ?? ''));
            $out[] = [
                'key' => (string) $key,
                'title' => (string) ($tpl['title'] ?? $key),
                'desc' => (string) ($tpl['desc'] ?? ''),
                'templateId' => $templateId,
                // 启用 = 配了 template_id 且没有被配置显式停用（enabled=false 只是「临时关掉」，
                // 不必清 template_id —— 清了要重新抄一遍 id，反而更容易配错）
                'enabled' => $templateId !== '' && (bool) ($tpl['enabled'] ?? true),
                'fields' => array_map('strval', array_keys((array) ($tpl['fields'] ?? []))),
            ];
        }

        return $out;
    }

    /** 模板目录里是否存在该键（即使没配 template_id 也算「已登记」） */
    public function has(string $key): bool
    {
        return array_key_exists($key, (array) config('services.wechat.templates', []));
    }

    /** 该模板是否可发（配了 template_id 且未被停用） */
    public function enabled(string $key): bool
    {
        $tpl = (array) config('services.wechat.templates.'.$key, []);

        return trim((string) ($tpl['template_id'] ?? '')) !== '' && (bool) ($tpl['enabled'] ?? true);
    }

    /** 是否有任意模板已配置 —— 接口顶层 `configured` 字段的口径 */
    public function isConfigured(): bool
    {
        foreach ($this->catalog() as $item) {
            if ($item['enabled']) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------------
    // 下发
    // ---------------------------------------------------------------------

    /**
     * 给某位员工发一条订阅消息。
     *
     * 返回固定形状，供控制器直接拼响应：
     * `['status' => 'sent'|'skipped'|'failed', 'errcode' => ?int, 'errmsg' => string, 'message' => string]`
     *
     * `$data` 是按配置里语义 key 传的扁平数组，例如：
     * `['customerName' => '姚女士', 'staffName' => '张三', 'time' => now(), 'venue' => '绿地店']`
     * 到微信侧的字段名由 config('services.wechat.templates.<key>.fields') 决定，
     * 换模板只改配置、不改代码。
     *
     * @param  array<string, mixed>  $data
     * @return array{status: string, errcode: int|null, errmsg: string, message: string}
     */
    public function send(User $u, string $key, array $data): array
    {
        $tpl = (array) config('services.wechat.templates.'.$key, []);

        // 1) 模板未登记 / 未配置 template_id：本地就不该发
        if ($tpl === [] || trim((string) ($tpl['template_id'] ?? '')) === '') {
            return $this->skip($u, $key, '模板未配置（config services.wechat.templates.'.$key.' 或对应 WECHAT_TPL_* 为空）', [
                'reason' => 'template_not_configured',
            ]);
        }

        // 2) 管理员临时停用了这个模板（enabled=false）：保留 template_id 但先不发
        if (! (bool) ($tpl['enabled'] ?? true)) {
            return $this->skip($u, $key, '模板已停用（config enabled=false），如需恢复请改为 true', [
                'reason' => 'template_disabled',
            ]);
        }

        // 3) 小程序凭据缺失：连 token 都取不到，直接判未配置
        if ((string) config('services.wechat.appid', '') === '' || (string) config('services.wechat.secret', '') === '') {
            return $this->skip($u, $key, '小程序凭据未配置（WECHAT_MINI_APPID / WECHAT_MINI_SECRET 为空）', [
                'reason' => 'credentials_missing',
            ]);
        }

        // 4) 未绑微信：openid 是唯一收件地址，没它一定发不出去
        $openid = trim((string) ($u->wx_openid ?? ''));
        if ($openid === '') {
            return $this->skip($u, $key, '账号尚未绑定微信（openid 为空）', ['reason' => 'openid_missing']);
        }

        // 5) 一次性授权已用完
        $sub = WxSubscription::firstOrCreate(
            ['user_id' => $u->id, 'template_key' => $key],
            ['remain' => 0, 'total_accepted' => 0],
        );
        if ((int) $sub->remain <= 0) {
            return $this->skip($u, $key, '订阅次数已用完，请在小程序「模板通知」里重新开启提醒', [
                'reason' => 'no_remain',
                'remain' => (int) $sub->remain,
            ]);
        }

        $templateId = (string) $tpl['template_id'];
        $built = $this->buildData($tpl, $data);
        // 任一必填关键词取不到值（典型：客户没留手机号）→ 在发 HTTP 前跳过。
        // 微信对缺字段/占位符一律 47003，重试也不会成功，只会白耗一次性授权。
        $emptyKeyword = null;
        foreach ($built as $kw => $item) {
            if ((string) ($item['value'] ?? '') === '') {
                $emptyKeyword = $kw;
                break;
            }
        }
        if ($emptyKeyword !== null) {
            return $this->skip($u, $key, '模板必填字段为空（'.$emptyKeyword.'），本次未发送', [
                'reason' => 'field_empty',
                'keyword' => $emptyKeyword,
            ]);
        }

        $body = [
            'touser' => $openid,
            'template_id' => $templateId,
            // page 留空 = 用户点通知进小程序首页。写死一个不存在的路径会直接 41030，
            // 整条消息发不出去，所以宁可空着也不猜。
            'page' => (string) ($tpl['page'] ?? ''),
            'data' => $built,
            // 开发版/体验版调试时改成 developer/trial；生产固定 formal
            'miniprogram_state' => (string) ($tpl['miniprogram_state'] ?? 'formal'),
            'lang' => 'zh_CN',
        ];

        $result = $this->post($body);

        // 6) 记录流水并处理 errcode
        if ($result['ok']) {
            // 成功才扣次数：失败（含 43101）不在这里扣，见下
            $sub->decrement('remain');
            $sub->forceFill(['last_sent_at' => now()])->save();

            $this->log($u, $key, WxSubscriptionLog::STATUS_SENT, 0, 'ok', $body);

            return [
                'status' => WxSubscriptionLog::STATUS_SENT,
                'errcode' => 0,
                'errmsg' => 'ok',
                'message' => '已发送，请在微信服务通知中查看',
            ];
        }

        $errcode = $result['errcode'];
        $errmsg = $result['errmsg'];

        // 43101 = 用户拒收 / 已无有效授权。把本地记账归零，否则页面会一直显示
        // 「剩余 n 次」并反复重试同一个人（永远失败）。
        if ($errcode === 43101) {
            $sub->forceFill(['remain' => 0])->save();
        }

        $this->log($u, $key, WxSubscriptionLog::STATUS_FAILED, $errcode, $errmsg, $body + ['response' => $result['raw']]);

        return [
            'status' => WxSubscriptionLog::STATUS_FAILED,
            'errcode' => $errcode,
            'errmsg' => $errmsg,
            'message' => $errcode !== null && isset(self::ERRCODE_MESSAGES[$errcode])
                ? self::ERRCODE_MESSAGES[$errcode]
                : '微信返回错误，请稍后再试',
        ];
    }

    /**
     * 「新客资分配」通知：只在按姓名解析到唯一账号时发。
     *
     * 解析不到（重名 / 姓名对不上账号）**不报错、不抛异常** —— 客资分配本身是主流程，
     * 不能因为某个历史姓名对不上账号就让保存失败。返回 null 表示「没有可通知的人」。
     *
     * @return array{status: string, errcode: int|null, errmsg: string, message: string}|null
     */
    public function notifyLeadAssigned(
        string $teacherName,
        string $customerName,
        string $venue,
        string $phone = '',
        string $remark = '新客资已分配，请24小时内首响'
    ): ?array {
        $user = $this->resolveStaff($teacherName);
        if (! $user) {
            return null;
        }

        return $this->send($user, 'leadAssigned', [
            'customerName' => $customerName,
            'phone' => $phone,
            'staffName' => $teacherName,
            'venue' => $venue,
            'remark' => $remark,
            'time' => now(),
        ]);
    }

    /**
     * 「会员归属变更」通知：`$staffName` 是**变更后的新归属人**。
     *
     * 与 leadAssigned 同一套解析纪律：解析不到就静默跳过。
     *
     * @return array{status: string, errcode: int|null, errmsg: string, message: string}|null
     */
    public function notifyMemberAssigned(
        string $staffName,
        string $customerName,
        string $venue,
        string $role = '会籍顾问',
        string $phone = ''
    ): ?array {
        $user = $this->resolveStaff($staffName);
        if (! $user) {
            return null;
        }

        // 模板选择：memberAssigned 配了 template_id（且未被 enabled=false 停用）就走独立键，
        // 否则回退 leadAssigned（线上目前唯一在用的「客户分配提醒」730）。
        //
        // 为什么「按配置切换」而不是写死：一次性订阅是「一次授权一条消息」，两个 key
        // 抢同一份授权会让其中一边永远发不出去，所以过渡期共用 leadAssigned；等会员模板
        // 真正申请下来（填了 WECHAT_TPL_MEMBER_ASSIGNED）自动切过去，代码不用再改。
        // 切换后小程序「模板通知」页会多出一项，用户需要单独授权该模板。
        $key = $this->enabled('memberAssigned') ? 'memberAssigned' : 'leadAssigned';

        return $this->send($user, $key, [
            // 语义键取「两个模板都能用」的并集：send() 只下发 config 里登记过的关键词，
            // 多传的键不会进微信请求，所以同一份 payload 对两种模板都成立。
            'customerName' => $customerName,
            'phone' => $phone,
            'staffName' => $staffName,
            'venue' => $venue,
            'role' => $role,
            'remark' => '会员归属已变更，请及时跟进',
            'time' => now(),
        ]);
    }

    /**
     * 「待办提醒」通知（todoReminder）：任务指派给某人时发一条。
     *
     * 参数都是任务表里的原始值：
     *  - `$title`    任务标题（thing 类字段 20 字上限，服务层自动截断，不会 47003）
     *  - `$deadline` 截止时间。`tasks.deadline` 是**自由文本列**（`''` / `2026-11-05` /
     *                `2026-11-05 18:00` 都可能），所以先尝试解析成「2026年11月5日 18:00」，
     *                解析不了就原样下发、空值给「待定」—— 不能因为一条格式怪异的截止时间
     *                把整条通知打挂（该模板尚未申请，字段类型也可能与预期不同）。
     *  - `$venue`    门店
     *
     * 与其它 notify* 同一纪律：按姓名解析不到唯一账号就返回 null（不报错、不抛异常）。
     *
     * @return array{status: string, errcode: int|null, errmsg: string, message: string}|null
     */
    public function notifyTodoReminder(
        string $ownerName,
        string $title,
        string $deadline = '',
        string $venue = ''
    ): ?array {
        $user = $this->resolveStaff($ownerName);
        if (! $user) {
            return null;
        }

        $deadline = trim($deadline);
        $deadlineValue = '待定';
        if ($deadline !== '') {
            try {
                // 传 Carbon 实例而不是格式化字符串：date6 是 date 类型，微信只接受
                // 「2019年10月1日」这种纯日期，带时分会被判参数不准确（47003）。
                // formatValue() 会按关键词前缀 date* 只输日期。
                $deadlineValue = Carbon::parse($deadline);
            } catch (\Throwable) {
                // 自由文本（如「本周内」「下次到店」）：原样下发，由 thing 字段承载
                $deadlineValue = $deadline;
            }
        }

        return $this->send($user, 'todoReminder', [
            'title' => $title,
            'staffName' => $ownerName,
            'deadline' => $deadlineValue,
            'remark' => '您有新的待办任务，请及时处理',
            'venue' => $venue,
            // 兼容把 thing1 绑到 customerName、time2 绑到 time 的旧映射
            'customerName' => $title,
            'time' => now(),
        ]);
    }

    /**
     * 按 `users.name` 解析在职账号。
     *
     * 只认**唯一命中**：同名两个账号时宁可不发（父账号/离职重建的重复姓名在本仓真实存在，
     * 发错人的代价比不发大）。与 helpers.php 的 staffUserId() 同口径，只是这里需要 User 实例。
     */
    private function resolveStaff(string $name): ?User
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $hits = User::where('name', $name)->limit(2)->get();
        if ($hits->count() !== 1) {
            if ($hits->count() > 1) {
                Log::warning('[wx-subscribe] 姓名对应多个账号，跳过通知', ['name' => $name]);
            }

            return null;
        }

        return $hits->first();
    }

    // ---------------------------------------------------------------------
    // 内部实现
    // ---------------------------------------------------------------------

    /**
     * 业务语义值 → 微信模板字段（`{keyword: {value: string}}`）。
     *
     * @param  array<string, mixed>  $tpl
     * @param  array<string, mixed>  $data
     * @return array<string, array{value: string}>
     */
    private function buildData(array $tpl, array $data): array
    {
        $out = [];
        foreach ((array) ($tpl['fields'] ?? []) as $keyword => $field) {
            $keyword = (string) $keyword;
            $sourceKey = is_array($field) ? (string) ($field['key'] ?? '') : '';
            $value = $sourceKey !== '' ? ($data[$sourceKey] ?? null) : null;

            $out[$keyword] = ['value' => $this->formatValue($keyword, $value)];
        }

        return $out;
    }

    /** 按关键词类型做格式化与截断（空值给「—」：微信不接受空字符串参数） */
    private function formatValue(string $keyword, mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            // time 类型接受「2019年10月1日 15:01」；date 类型只要日期部分。
            $carbon = Carbon::instance($value);
            $value = str_starts_with($keyword, 'date')
                ? $carbon->format('Y年n月j日')
                : $carbon->format('Y年n月j日 H:i');
        }

        $text = trim((string) $value);
        if ($text === '') {
            // phone_number 类型不接受占位符：给「—」会被微信判 47003 并把整条消息打回，
            // 而其他文本类型给「—」是无害的兜底。返回 '' 让 send() 在发 HTTP 前跳过。
            return str_starts_with($keyword, 'phone_number') ? '' : '—';
        }

        foreach (self::FIELD_LIMITS as $prefix => $limit) {
            if (str_starts_with($keyword, $prefix)) {
                return mb_substr($text, 0, $limit);
            }
        }

        return $text;
    }

    /**
     * 真正发 HTTP。返回归一化结果，**从不抛异常**（订阅消息永远不能影响主流程）。
     *
     * @return array{ok: bool, errcode: int|null, errmsg: string, raw: array}
     */
    private function post(array $body): array
    {
        $token = $this->accessToken();
        if ($token === null) {
            return ['ok' => false, 'errcode' => null, 'errmsg' => '获取 access_token 失败（检查小程序凭据或微信可达性）', 'raw' => []];
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $resp = Http::timeout(8)
                    ->asJson()
                    // token 走查询串是微信规定的形态；日志里绝不能带上它（见 recordLog）。
                    ->post(self::SEND_ENDPOINT.'?access_token='.urlencode($token), $body);
            } catch (\Throwable $e) {
                Log::warning('[wx-subscribe] 下发请求异常', ['error' => $e->getMessage(), 'template_id' => $body['template_id'] ?? '']);

                return ['ok' => false, 'errcode' => null, 'errmsg' => '请求微信接口异常：'.$e->getMessage(), 'raw' => []];
            }

            if (! $resp->ok()) {
                Log::warning('[wx-subscribe] 下发 HTTP 非 2xx', ['status' => $resp->status()]);

                return ['ok' => false, 'errcode' => null, 'errmsg' => 'HTTP '.$resp->status(), 'raw' => []];
            }

            $data = (array) $resp->json();
            $errcode = (int) ($data['errcode'] ?? 0);

            if ($errcode === 0) {
                return ['ok' => true, 'errcode' => 0, 'errmsg' => 'ok', 'raw' => $data];
            }

            // token 过期/无效：清缓存换新 token 重试一次。订阅消息次数是一次性的，
            // 这种「本来能发出去」的失败必须救回来，不能不重试直接记 failed。
            if (in_array($errcode, self::TOKEN_INVALID_CODES, true) && $attempt === 1) {
                Cache::forget(self::TOKEN_CACHE_KEY);
                $token = $this->accessToken();
                if ($token === null) {
                    return ['ok' => false, 'errcode' => $errcode, 'errmsg' => 'access_token 失效且刷新失败', 'raw' => $data];
                }

                continue;
            }

            Log::warning('[wx-subscribe] 下发业务错误', [
                'errcode' => $errcode,
                'errmsg' => (string) ($data['errmsg'] ?? ''),
                'template_id' => $body['template_id'] ?? '',
            ]);

            return ['ok' => false, 'errcode' => $errcode, 'errmsg' => (string) ($data['errmsg'] ?? ''), 'raw' => $data];
        }
    }

    /** 本地不该发的分支：记 skipped 流水后统一返回（不碰 remain） */
    private function skip(User $u, string $key, string $reason, array $payload): array
    {
        $this->log($u, $key, WxSubscriptionLog::STATUS_SKIPPED, null, $reason, $payload);

        return [
            'status' => WxSubscriptionLog::STATUS_SKIPPED,
            'errcode' => null,
            'errmsg' => $reason,
            'message' => $reason,
        ];
    }

    /**
     * 写一条下发流水。
     *
     * 写库失败不能反过来炸主流程（触发点在 defer 里、也可能被直接调用），
     * 所以这里自己对异常兜底，降级成一条日志。
     */
    private function log(User $u, string $key, string $status, ?int $errcode, string $errmsg, array $payload): void
    {
        try {
            WxSubscriptionLog::create([
                'user_id' => $u->id,
                'template_key' => $key,
                'status' => $status,
                'errcode' => $errcode,
                // 微信 errmsg 也可能很长；列宽 500，超出会截断而不是报错
                'errmsg' => mb_substr($errmsg, 0, 480),
                'payload' => $payload,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[wx-subscribe] 流水写入失败', [
                'user_id' => $u->id,
                'template_key' => $key,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
