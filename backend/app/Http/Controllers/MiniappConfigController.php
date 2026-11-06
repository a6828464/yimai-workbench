<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use Illuminate\Http\Request;

/**
 * 小程序「到店指引」配置（`/miniapp/config`）。
 *
 * 存在意义：这部分内容原本硬编码在小程序 `pkg-public/guide/guide.vue` 的 `GUIDE_VENUES` 里
 * （地址 / 电话 / 视频号 / 直播 / 图文引导），改一次要发一次小程序版本。现在搬到服务端，
 * 馆主在网页端保存即生效。
 *
 * ## 两条硬口径
 *
 * 1. **GET 免登录**：到店指引是给**顾客**看的（可能由顾问直接发链接给新客），不能要求 token。
 *    只读、无 PII，仍挂 `throttle:60,1`（公开接口不设限流就是一张免费压测门票）。
 * 2. **默认值必须与小程序逐字一致**，尤其是那对**占位坐标 29.8746 / 121.5496**。
 *    小程序用 `PLACEHOLDER_COORDS` 识别这对数字来决定「不导航、改复制地址」——
 *    后端若把默认坐标改成 null，前端的降级逻辑就失效，顾客会被导到城市中心。
 *    所以 `miniapp_config` 为 NULL 时回落的默认值 = guide.vue 里那套原样拷贝（含占位坐标）。
 *
 * ## 存储
 * `app_settings.miniapp_config`（json, nullable），与 `rules` / `snapshot` 同一行的全局单例。
 * 值为 NULL 表示「从未保存过」→ 读时回落默认值；迁移里不 seed（见该迁移的注释）。
 *
 * ## 结构（v2：门店视频 + 三种出行方式 + 首页弹窗）
 *
 * ```
 * { venues: [ { key, sub, address, phone, latitude, longitude, channelsUser, channelsLive,
 *               images[], videoUrl, routes{drive,transit,walk} ← 各自 {videoUrl, images[]} } ],
 *   popup: { enabled, title, content, imageUrl, frequency },
 *   updatedAt }
 * ```
 *
 * **向后兼容**：线上存量配置只有前 8 个字段（v1），读时缺的键一律补默认值
 * （`payload()` 里逐层 `pick()`），不会报错、也不会返回 null。旧数据不需要迁移。
 *
 * ## PUT 的合并语义（为什么不是整块覆盖）
 * 提交的字段**存在即覆盖**（`''` / `[]` 是合法的「清空」），**缺省则保留当前值**
 * （当前 = 已保存值，没有保存过则取默认值）。**嵌套层同样递归合并**：
 * `routes.walk.videoUrl` 这样的深路径只覆盖到那一格，不会把 `routes.drive` 冲掉。
 * 另外 `venues` 与 `popup` **都可选**：只改弹窗不必把两家店一起传，反之亦然。
 */
final class MiniappConfigController extends Controller
{
    /** 三种出行方式（顺序即输出顺序）：drive 自驾 / transit 公交 / walk 步行 */
    private const ROUTE_KEYS = ['drive', 'transit', 'walk'];

    /** 单组图片上限（门店 images 与 routes.*.images 共用；馆主 2026-11 把 3 提到 9） */
    private const MEDIA_LIMIT = 9;

    /** 弹窗展示频率枚举（once=用户看过一次就不再弹；always=每次进首页都弹） */
    private const POPUP_FREQUENCIES = ['once', 'always'];

    /** 单个出行方式的空壳（默认值，也是 routes 合并的基底） */
    private const EMPTY_ROUTE = ['videoUrl' => '', 'images' => []];

    /** 三个出行方式的空壳集合（默认值，也是 routes 合并的基底） */
    public const EMPTY_ROUTES = [
        'drive' => self::EMPTY_ROUTE,
        'transit' => self::EMPTY_ROUTE,
        'walk' => self::EMPTY_ROUTE,
    ];

    /**
     * 内置默认值 —— **与 `miniapp/pkg-public/guide/guide.vue` 的 `GUIDE_VENUES` 逐字一致**。
     *
     * 门店键固定为「绿地店」「东部店」，且顺序固定（前端按数组顺序渲染门店选择）。
     * 东部店的 address / phone 故意是空串：随心瑜档案里没有这两个值，页面会降级成
     * 「地址待补，可复制门店名」/ 不渲染拨号按钮 —— 不要随手编一个让顾客照着跑空。
     *
     * 两家店的 latitude / longitude 都是**占位值**（宁波三江口附近的近似点，非门店位置），
     * 与前端 `PLACEHOLDER_COORDS` 成对存在。填真实坐标时要同时删掉前端那份白名单。
     *
     * `videoUrl` / `routes` 默认全是空壳：前端据此显示「暂无视频 / 暂无路线」的占位，
     * 不需要判 null。
     *
     * @var array<int, array<string, mixed>>
     */
    public const DEFAULT_VENUES = [
        [
            'key' => '绿地店',
            'sub' => '绿地缤纷城 · 4 楼',
            'address' => '宁波市江北区绿地缤纷城1号楼4楼',
            'phone' => '0574-87214863',
            'latitude' => 29.8746,
            'longitude' => 121.5496,
            'channelsUser' => '',
            'channelsLive' => '',
            'images' => [
                ['src' => '/static/hero.jpg', 'caption' => '到店后沿门厅指示牌进电梯，4 楼出电梯右手边即前台'],
            ],
            'videoUrl' => '',
            'routes' => self::EMPTY_ROUTES,
        ],
        [
            'key' => '东部店',
            'sub' => '江东 · 场馆',
            'address' => '',
            'phone' => '',
            'latitude' => 29.8746,
            'longitude' => 121.5496,
            'channelsUser' => '',
            'channelsLive' => '',
            'images' => [
                ['src' => '/static/hero.jpg', 'caption' => '到店后前台出示本条指引，顾问带你熟悉场地'],
            ],
            'videoUrl' => '',
            'routes' => self::EMPTY_ROUTES,
        ],
    ];

    /** 首页弹窗默认值（enabled=false ⇒ 前端不弹；frequency=once ⇒ 用户看过一次就不再弹） */
    public const DEFAULT_POPUP = [
        'enabled' => false,
        'title' => '',
        'content' => '',
        'imageUrl' => '',
        'frequency' => 'once',
    ];

    /** 门店键白名单（顺序即输出顺序）：服务端拒绝新增或删除门店，只允许改内容 */
    private const VENUE_KEYS = ['绿地店', '东部店'];

    /**
     * GET /api/miniapp/config —— 免登录。
     *
     * 返回 `{ venues: [...], popup: {...}, updatedAt: 'Y-m-d H:i:s' | '' }`。
     * 从未保存过（列为 NULL）时返回内置默认值，`updatedAt` 给空串（不伪造时间点）。
     */
    public function show()
    {
        return ok($this->payload($this->storedConfig()));
    }

    /**
     * PUT /api/miniapp/config —— 仅超管。
     *
     * `requireSuper()` 按 `BackupController` / `PayrollController` 的既有模式写在**控制器首行**
     * （路由只挂 auth:sanctum），这样「谁能写」这件事与其它超管端点同源，不会被路由分组漂移。
     *
     * `venues` 与 `popup` **都是可选的**：只提交其中一块时另一块保持原样（合并语义），
     * 所以「只改首页弹窗」不必把两家店也一起传上来。
     */
    public function update(Request $r)
    {
        requireSuper($r);

        $data = $r->validate($this->rules(), $this->messages(), $this->attributes());

        // 以「当前生效配置」为基底逐层合并（当前 = 已保存；没保存过 = 默认值）。
        // 若以 DEFAULT 为基底，只改地址的一次 PUT 会把已上传的图文引导冲回默认图。
        $current = $this->payload($this->storedConfig());

        // ---- venues：只有提交了才处理；每店按键合并，未提交的键保留 ----
        $venues = $current['venues'];
        if (array_key_exists('venues', $data)) {
            $byKey = [];
            foreach ($data['venues'] as $row) {
                $byKey[(string) $row['key']] = $row;
            }
            $venues = [];
            foreach ($current['venues'] as $base) {
                $venues[] = $this->normalizeVenue($base, $byKey[$base['key']] ?? []);
            }
        }

        // ---- popup：只有提交了才处理；字段级合并 ----
        $popup = array_key_exists('popup', $data)
            ? $this->normalizePopup($current['popup'], (array) $data['popup'])
            : $current['popup'];

        $cfg = [
            'venues' => $venues,
            'popup' => $popup,
            // 时间戳写进 JSON 而不是读 app_settings.updated_at：后者会被「保存清单阈值 / AI 配置」
            // 等任何一次设置写入刷新，用它会让「小程序配置更新时间」显示成别处的修改时间。
            'updatedAt' => now()->format('Y-m-d H:i:s'),
        ];

        // 写路径用 setting()（带锁的 firstOrCreate）：保证并发保存时只有一行，不会造出重复行
        setting()->update(['miniapp_config' => $cfg]);

        // 留痕：记到「哪个门店的哪几项」这一级（粒度判断见 describeChanges 的注释）。
        // target_id 用 0 —— 这是全局单例，没有行 id。
        audit(
            $r,
            '修改',
            '小程序设置',
            0,
            '到店指引配置',
            '双店',
            $this->describeChanges($current, $cfg)
        );

        return ok($this->payload($cfg));
    }

    // ------------------------------------------------------------------ 校验

    /**
     * 媒体地址白名单（**唯一实现**：写入校验与读时清洗都调它，避免「能存不能读」的双标）。
     *
     * 允许：
     *  - 空串（= 未设置）；
     *  - 站内路径：`/` 开头且**不**以 `//` 开头（`//evil.com/x` 是协议相对外链，要拒）；
     *  - https 直链。
     * 拒绝：`javascript:` / `data:` / `http://`（明文）/ 相对路径 / 任何其它形态。
     */
    private static function isAllowedMediaUrl(string $url): bool
    {
        $value = trim($url);
        if ($value === '') {
            return true;
        }
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return true;
        }

        return (bool) preg_match('#^https://[^\s]+$#i', $value);
    }

    /** 校验规则（PUT）。字段顺序与契约一致，便于对照。 */
    private function rules(): array
    {
        $media = function (string $attribute, mixed $value, \Closure $fail): void {
            if (! self::isAllowedMediaUrl((string) $value)) {
                $fail('必须是站内路径（以 / 开头，且不能以 // 开头）或 https 直链');
            }
        };

        return [
            // venues 与 popup 都可选：只传一块时另一块保持原样（合并语义）
            'venues' => ['nullable', 'array', 'size:2'],
            // distinct + in + size:2 ⇒ 只能是「绿地店」「东部店」各一次（顺序随意，服务端会归一）
            'venues.*.key' => ['required', 'string', 'distinct', 'in:'.implode(',', self::VENUE_KEYS)],
            'venues.*.sub' => ['nullable', 'string', 'max:40'],
            // 允许空串：东部店地址/电话本来就没配，页面按空值降级
            'venues.*.address' => ['nullable', 'string', 'max:120'],
            'venues.*.phone' => ['nullable', 'string', 'max:30'],
            // 接受 number 或 null；范围是「宁波在哪儿」级别的粗校验，只拦明显填错（如 121 写成 21）
            'venues.*.latitude' => ['nullable', 'numeric', 'between:12,55'],
            'venues.*.longitude' => ['nullable', 'numeric', 'between:70,140'],
            'venues.*.channelsUser' => ['nullable', 'string', 'max:120'],
            'venues.*.channelsLive' => ['nullable', 'string', 'max:120'],
            'venues.*.images' => ['nullable', 'array', 'max:'.self::MEDIA_LIMIT],
            'venues.*.images.*.src' => ['required', 'string', 'max:200', $media],
            'venues.*.images.*.caption' => ['nullable', 'string', 'max:120'],
            // 门店视频：与图片 src 同一段白名单（$media），不写第二套
            'venues.*.videoUrl' => ['nullable', 'string', 'max:300', $media],
            // 只认这三种出行方式：传 bike 之类的键会被 array: 规则拒掉（而不是静默丢弃）
            'venues.*.routes' => ['nullable', 'array:'.implode(',', self::ROUTE_KEYS)],
            'venues.*.routes.*.videoUrl' => ['nullable', 'string', 'max:300', $media],
            'venues.*.routes.*.images' => ['nullable', 'array', 'max:'.self::MEDIA_LIMIT],
            'venues.*.routes.*.images.*.src' => ['required', 'string', 'max:200', $media],
            'venues.*.routes.*.images.*.caption' => ['nullable', 'string', 'max:120'],
            // 首页弹窗（全局，不在 venues 里）
            'popup' => ['nullable', 'array'],
            'popup.enabled' => ['nullable', 'boolean'],
            'popup.title' => ['nullable', 'string', 'max:40'],
            'popup.content' => ['nullable', 'string', 'max:500'],
            'popup.imageUrl' => ['nullable', 'string', 'max:300', $media],
            'popup.frequency' => ['nullable', 'string', 'in:'.implode(',', self::POPUP_FREQUENCIES)],
        ];
    }

    /** 中文文案（顶层 message 只取第一条，所以每种失败形态都要有自己的句子） */
    private function messages(): array
    {
        return [
            'venues.array' => '门店配置格式不正确',
            'venues.size' => '门店数量必须是 2 家（绿地店、东部店），不支持新增或删除门店',
            'venues.*.key.required' => '每家店都必须带门店键（绿地店 / 东部店）',
            'venues.*.key.distinct' => '门店键不能重复：绿地店、东部店必须各出现一次',
            'venues.*.key.in' => '门店键只能是「绿地店」或「东部店」，不支持新增门店或改名',
            'venues.*.latitude.between' => '纬度超出合理范围（应在 12–55 之间），请检查是否填错',
            'venues.*.longitude.between' => '经度超出合理范围（应在 70–140 之间），请检查是否填错',
            'venues.*.latitude.numeric' => '纬度必须是数字（或留空表示未配置坐标）',
            'venues.*.longitude.numeric' => '经度必须是数字（或留空表示未配置坐标）',
            'venues.*.images.array' => '引导图格式不正确',
            'venues.*.images.max' => '每家店最多 '.self::MEDIA_LIMIT.' 张图片',
            'venues.*.images.*.src.required' => '引导图缺少图片地址',
            'venues.*.routes.array' => '出行方式只能是 drive（自驾）/ transit（公交）/ walk（步行）三种',
            'venues.*.routes.*.images.array' => '该出行方式的图片格式不正确',
            'venues.*.routes.*.images.max' => '每种出行方式最多 '.self::MEDIA_LIMIT.' 张图片',
            'venues.*.routes.*.images.*.src.required' => '该出行方式的图片缺少图片地址',
            'popup.array' => '首页弹窗格式不正确',
            'popup.enabled.boolean' => '弹窗开关必须是 true 或 false',
            'popup.title.max' => '弹窗标题最多 40 个字',
            'popup.content.max' => '弹窗内容最多 500 个字',
            'popup.frequency.in' => '弹窗展示频率只能是 once（只弹一次）或 always（每次打开都弹）',
        ];
    }

    /** 字段中文名（`:attribute` 占位符用；否则报错里会出现 venues.0.routes.drive.videoUrl 这种路径） */
    private function attributes(): array
    {
        return [
            'venues' => '门店配置',
            'venues.*.key' => '门店键',
            'venues.*.sub' => '门店副标题',
            'venues.*.address' => '门店地址',
            'venues.*.phone' => '门店电话',
            'venues.*.latitude' => '纬度',
            'venues.*.longitude' => '经度',
            'venues.*.channelsUser' => '视频号',
            'venues.*.channelsLive' => '直播 id',
            'venues.*.images' => '引导图',
            'venues.*.videoUrl' => '门店视频',
            'venues.*.routes' => '出行路线',
            'venues.*.routes.*.videoUrl' => '出行视频',
            'venues.*.routes.*.images' => '出行图片',
            'popup' => '首页弹窗',
            'popup.enabled' => '弹窗开关',
            'popup.title' => '弹窗标题',
            'popup.content' => '弹窗内容',
            'popup.imageUrl' => '弹窗图片',
            'popup.frequency' => '弹窗展示频率',
        ];
    }

    // ------------------------------------------------------------------ 内部

    /** 已保存的配置（未保存过返回 []，由 payload() 回落默认值） */
    private function storedConfig(): array
    {
        $cfg = AppSetting::oldest('id')->first()?->miniapp_config;

        return is_array($cfg) ? $cfg : [];
    }

    /**
     * 对外响应体：`{ venues: [...], popup: {...}, updatedAt: string }`。
     *
     * 逐层用「已保存值 → 默认值」兜底，保证**任何情况下结构完整**（前端不必判 undefined）：
     * v1 存量配置只有前 8 个字段，读出来会自动补上 `videoUrl` / `routes` / `popup`。
     * 兜底判据是 `array_key_exists` 而不是 `??`：`''` / `[]`（用户清空）是有效值，
     * 不能被默认值顶回去。
     *
     * @param  array<string, mixed>  $cfg
     * @return array{venues: array<int, array<string, mixed>>, popup: array<string, mixed>, updatedAt: string}
     */
    private function payload(array $cfg): array
    {
        $saved = [];
        foreach ((array) ($cfg['venues'] ?? []) as $row) {
            if (is_array($row) && isset($row['key'])) {
                $saved[(string) $row['key']] = $row;
            }
        }

        $venues = [];
        foreach (self::DEFAULT_VENUES as $default) {
            $venues[] = $this->normalizeVenue($default, $saved[$default['key']] ?? []);
        }

        return [
            'venues' => $venues,
            // 旧配置没有 popup 键 → 回落 DEFAULT_POPUP（enabled=false）
            'popup' => $this->normalizePopup(self::DEFAULT_POPUP, (array) ($cfg['popup'] ?? [])),
            'updatedAt' => (string) ($cfg['updatedAt'] ?? ''),
        ];
    }

    /**
     * 单店归一：`$base` 提供默认值与固定 key，`$submitted` 里**存在**的键覆盖它。
     *
     * 嵌套层（routes）走 `normalizeRoutes()` 继续递归，**不是整段替换** ——
     * 只提交 `routes.walk.videoUrl` 时，`routes.drive` / `routes.transit` 原样保留。
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    private function normalizeVenue(array $base, array $submitted): array
    {
        $pick = fn (string $key) => $this->pick($submitted, $base, $key);

        return [
            // key 永远取默认表里的值：门店键是固定集合，不接受改名/新增
            'key' => (string) $base['key'],
            'sub' => (string) ($pick('sub') ?? ''),
            'address' => (string) ($pick('address') ?? ''),
            'phone' => (string) ($pick('phone') ?? ''),
            'latitude' => $this->coord($pick('latitude')),
            'longitude' => $this->coord($pick('longitude')),
            'channelsUser' => (string) ($pick('channelsUser') ?? ''),
            'channelsLive' => (string) ($pick('channelsLive') ?? ''),
            'images' => $this->normalizeImages($pick('images')),
            'videoUrl' => $this->normalizeUrl($pick('videoUrl')),
            'routes' => $this->normalizeRoutes(
                (array) ($base['routes'] ?? self::EMPTY_ROUTES),
                (array) ($pick('routes') ?? [])
            ),
        ];
    }

    /**
     * 出行路线归一：三种方式**各自独立**合并。
     *
     * 这是「只改 walk 不会冲掉 drive」的实现位置：外层按 kind 遍历、内层只覆盖提交到的字段，
     * 任何一层都不做整段替换。
     *
     * @param  array<string, mixed>  $baseRoutes
     * @param  array<string, mixed>  $submittedRoutes
     * @return array<string, array{videoUrl: string, images: array<int, array{src: string, caption: string}>}>
     */
    private function normalizeRoutes(array $baseRoutes, array $submittedRoutes): array
    {
        $out = [];
        foreach (self::ROUTE_KEYS as $kind) {
            $base = (array) ($baseRoutes[$kind] ?? self::EMPTY_ROUTE);
            $sub = (array) ($submittedRoutes[$kind] ?? []);
            $out[$kind] = [
                'videoUrl' => $this->normalizeUrl($this->pick($sub, $base, 'videoUrl')),
                'images' => $this->normalizeImages($this->pick($sub, $base, 'images')),
            ];
        }

        return $out;
    }

    /**
     * 弹窗归一：字段级合并（只提交 frequency 时 title/content 保留）。
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $submitted
     * @return array{enabled: bool, title: string, content: string, imageUrl: string, frequency: string}
     */
    private function normalizePopup(array $base, array $submitted): array
    {
        $frequency = (string) ($this->pick($submitted, $base, 'frequency') ?? 'once');

        return [
            'enabled' => (bool) $this->pick($submitted, $base, 'enabled'),
            'title' => (string) ($this->pick($submitted, $base, 'title') ?? ''),
            'content' => (string) ($this->pick($submitted, $base, 'content') ?? ''),
            'imageUrl' => $this->normalizeUrl($this->pick($submitted, $base, 'imageUrl')),
            // 脏数据（手改库、老版本）一律回落到 once，不把非法值发给前端
            'frequency' => in_array($frequency, self::POPUP_FREQUENCIES, true) ? $frequency : 'once',
        ];
    }

    /**
     * 图片列表归一：丢弃没有 src 的项、上限 9 张、src 过一遍白名单。
     *
     * 读时也过白名单（不只是写入校验）：如果库里被手改成 `javascript:`，宁可少一张图，
     * 也不把它发给顾客的手机去执行。
     *
     * @return array<int, array{src: string, caption: string}>
     */
    private function normalizeImages(mixed $raw): array
    {
        $out = [];
        foreach ((array) $raw as $image) {
            if (! is_array($image)) {
                continue;
            }
            $src = trim((string) ($image['src'] ?? ''));
            if ($src === '' || ! self::isAllowedMediaUrl($src)) {
                continue;
            }
            $out[] = ['src' => $src, 'caption' => (string) ($image['caption'] ?? '')];
            if (count($out) >= self::MEDIA_LIMIT) {
                break;
            }
        }

        return $out;
    }

    /** 媒体地址归一：非法形态一律落空串（不让 `javascript:` 之类的值流出接口） */
    private function normalizeUrl(mixed $raw): string
    {
        $url = trim((string) ($raw ?? ''));

        return self::isAllowedMediaUrl($url) ? $url : '';
    }

    /** 坐标归一：接受 number 或 null/''；非数字一律落 null（不猜、不用 0 冒充「有坐标」） */
    private function coord(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 6);
    }

    /**
     * 「提交了就用提交值，否则用基底值」—— 合并语义的唯一判据。
     *
     * 必须用 `array_key_exists`：`??` 会把「提交了空串」也当成没提交，导致用户清不掉字段。
     *
     * @param  array<string, mixed>  $submitted
     * @param  array<string, mixed>  $base
     */
    private function pick(array $submitted, array $base, string $key): mixed
    {
        return array_key_exists($key, $submitted) ? $submitted[$key] : ($base[$key] ?? null);
    }

    // ------------------------------------------------------------------ 审计

    /**
     * 审计说明：记到「哪个门店的哪几项」这一级，**不记具体值**。
     *
     * 粒度判断：只记「更新了小程序设置」在真出问题时（顾客照着旧地址跑空、弹窗配错时间）
     * 查不出改了什么；而逐值 diff 会把整段 JSON 塞进 audit_logs（图文/视频 URL 很长，
     * 且没有额外信息量 —— 想看具体值直接查 `app_settings.miniapp_config` 的当前值即可）。
     * 所以取中间粒度：**字段名级别**（地址 / 坐标 / 门店视频 / 自驾路线 / 弹窗开关…）。
     *
     * @param  array<string, mixed>  $before  改动前的完整结构（已补默认值）
     * @param  array<string, mixed>  $after   改动后的完整结构
     */
    private function describeChanges(array $before, array $after): string
    {
        $labels = [
            'sub' => '副标题',
            'address' => '地址',
            'phone' => '电话',
            'latitude' => '纬度',
            'longitude' => '经度',
            'channelsUser' => '视频号',
            'channelsLive' => '直播',
            'images' => '图片',
            'videoUrl' => '门店视频',
        ];
        $routeLabels = ['drive' => '自驾路线', 'transit' => '公交路线', 'walk' => '步行路线'];
        $popupLabels = [
            'enabled' => '弹窗开关',
            'title' => '弹窗标题',
            'content' => '弹窗内容',
            'imageUrl' => '弹窗图片',
            'frequency' => '弹窗频率',
        ];

        $parts = [];
        $beforeVenues = [];
        foreach ((array) ($before['venues'] ?? []) as $venue) {
            $beforeVenues[(string) ($venue['key'] ?? '')] = $venue;
        }

        foreach ((array) ($after['venues'] ?? []) as $venue) {
            $key = (string) ($venue['key'] ?? '');
            $old = (array) ($beforeVenues[$key] ?? []);
            $changed = [];
            foreach ($labels as $field => $label) {
                if (json_encode($old[$field] ?? null) !== json_encode($venue[$field] ?? null)) {
                    $changed[] = $label;
                }
            }
            // routes 拆到「哪种出行方式」，否则只改一张图也只会说「出行路线改了」
            foreach ($routeLabels as $kind => $label) {
                if (json_encode($old['routes'][$kind] ?? null) !== json_encode($venue['routes'][$kind] ?? null)) {
                    $changed[] = $label;
                }
            }
            if ($changed !== []) {
                $parts[] = $key.'('.implode('、', $changed).')';
            }
        }

        $popupChanged = [];
        $oldPopup = (array) ($before['popup'] ?? []);
        $newPopup = (array) ($after['popup'] ?? []);
        foreach ($popupLabels as $field => $label) {
            if (json_encode($oldPopup[$field] ?? null) !== json_encode($newPopup[$field] ?? null)) {
                $popupChanged[] = $label;
            }
        }
        if ($popupChanged !== []) {
            $parts[] = '弹窗('.implode('、', $popupChanged).')';
        }

        $detail = $parts === []
            ? '保存成功，但没有任何字段发生变化（仅刷新保存时间）'
            : '更新：'.implode('；', $parts);

        // audit_logs.detail 是 text，但没必要写成长篇；门店或图片多起来后截断更稳妥
        return mb_substr($detail, 0, 300);
    }
}
