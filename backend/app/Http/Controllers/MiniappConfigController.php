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
 * ## PUT 的合并语义（为什么不是整块覆盖）
 * 提交的字段**存在即覆盖**（`''` 是合法的「清空」），**缺省则保留当前值**（当前 = 已保存值，
 * 没有保存过则取默认值）。这样部分提交（例如只改地址）不会把图文引导悄悄重置回默认图；
 * 而前端按契约提交完整结构时，效果与整块覆盖完全一致。
 */
final class MiniappConfigController extends Controller
{
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
        ],
    ];

    /** 门店键白名单（顺序即输出顺序）：服务端拒绝新增或删除门店，只允许改内容 */
    private const VENUE_KEYS = ['绿地店', '东部店'];

    /**
     * GET /api/miniapp/config —— 免登录。
     *
     * 返回 `{ venues: [...], updatedAt: 'Y-m-d H:i:s' | '' }`。
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
     */
    public function update(Request $r)
    {
        requireSuper($r);

        $data = $r->validate([
            'venues' => ['required', 'array', 'size:2'],
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
            'venues.*.images' => ['nullable', 'array', 'max:3'],
            'venues.*.images.*.src' => [
                'required', 'string', 'max:200',
                // 只放行「站内路径」与 https 外链：`javascript:` / `data:` / `http:` / 协议相对 `//host`
                // 都可能变成别处的取图或注入入口，一律不收。
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $v = trim((string) $value);
                    $isStationPath = str_starts_with($v, '/') && ! str_starts_with($v, '//');
                    $isHttps = (bool) preg_match('#^https://[^\s]+$#i', $v);
                    if (! $isStationPath && ! $isHttps) {
                        $fail('图片地址必须是站内路径（以 / 开头）或 https 外链');
                    }
                },
            ],
            'venues.*.images.*.caption' => ['nullable', 'string', 'max:120'],
        ], [
            // 顶层 message 只取第一条，所以每种失败形态都要有自己的中文句子
            'venues.required' => '请提交两家门店的配置',
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
            'venues.*.images.max' => '每家店最多 3 张引导图',
            'venues.*.images.*.src.required' => '引导图缺少图片地址',
        ], [
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
        ]);

        // 以「当前生效配置」为基底逐店合并：提交了哪一项就改哪一项，没提交的保持原样。
        // （如果以 DEFAULT_VENUES 为基底，只改地址的一次 PUT 会把已上传的图文引导冲回默认图。）
        $current = $this->payload($this->storedConfig())['venues'];
        $byKey = [];
        foreach ($data['venues'] as $row) {
            $byKey[(string) $row['key']] = $row;
        }

        $venues = [];
        foreach ($current as $base) {
            $venues[] = $this->normalizeVenue($base, $byKey[$base['key']] ?? []);
        }

        $cfg = [
            'venues' => $venues,
            // 时间戳写进 JSON 而不是读 app_settings.updated_at：后者会被「保存清单阈值 / AI 配置」
            // 等任何一次设置写入刷新，用它会让「小程序配置更新时间」显示成别处的修改时间。
            'updatedAt' => now()->format('Y-m-d H:i:s'),
        ];

        // 写路径用 setting()（带锁的 firstOrCreate）：保证并发保存时只有一行，不会造出重复行
        setting()->update(['miniapp_config' => $cfg]);

        // 留痕（与 CustomerController::update 同一写法）：target_id 用 0 —— 这是全局单例，没有行 id
        audit(
            $r,
            '修改',
            '小程序设置',
            0,
            '到店指引配置',
            '双店',
            '更新两家店的门店信息与图文引导（地址/电话/坐标/视频号/引导图）'
        );

        return ok($this->payload($cfg));
    }

    // ------------------------------------------------------------------ 内部

    /** 已保存的配置（未保存过返回 []，由 payload() 回落默认值） */
    private function storedConfig(): array
    {
        $cfg = AppSetting::oldest('id')->first()?->miniapp_config;

        return is_array($cfg) ? $cfg : [];
    }

    /**
     * 对外响应体：`{ venues: [...], updatedAt: string }`。
     *
     * 逐店、逐字段用「已保存值 → 默认值」兜底，保证**任何情况下结构完整**（前端不必判 undefined）。
     * 兜底判据是 `array_key_exists` 而不是 `??`：`''`（用户清空）是有效值，不能被默认值顶回去。
     *
     * @param  array<string, mixed>  $cfg
     * @return array{venues: array<int, array<string, mixed>>, updatedAt: string}
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
            'updatedAt' => (string) ($cfg['updatedAt'] ?? ''),
        ];
    }

    /**
     * 单店归一：`$base` 提供默认值与固定 key，`$submitted` 里**存在**的键覆盖它。
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    private function normalizeVenue(array $base, array $submitted): array
    {
        $pick = function (string $key) use ($base, $submitted) {
            if (array_key_exists($key, $submitted)) {
                return $submitted[$key];
            }

            return $base[$key] ?? null;
        };

        // 坐标：接受 number 或 null/''；非数字一律落 null（不猜、不用 0 冒充「有坐标」）
        $coord = function (string $key) use ($pick): ?float {
            $value = $pick($key);
            if ($value === null || $value === '' || ! is_numeric($value)) {
                return null;
            }

            return round((float) $value, 6);
        };

        $images = [];
        foreach ((array) ($pick('images') ?? []) as $image) {
            if (! is_array($image) || ! isset($image['src']) || trim((string) $image['src']) === '') {
                continue;
            }
            $images[] = [
                'src' => trim((string) $image['src']),
                'caption' => (string) ($image['caption'] ?? ''),
            ];
        }

        return [
            // key 永远取默认表里的值：门店键是固定集合，不接受改名/新增
            'key' => (string) $base['key'],
            'sub' => (string) ($pick('sub') ?? ''),
            'address' => (string) ($pick('address') ?? ''),
            'phone' => (string) ($pick('phone') ?? ''),
            'latitude' => $coord('latitude'),
            'longitude' => $coord('longitude'),
            'channelsUser' => (string) ($pick('channelsUser') ?? ''),
            'channelsLive' => (string) ($pick('channelsLive') ?? ''),
            // 最多 3 张（校验层已拦，这里再兜一次，防止历史脏数据把页面撑爆）
            'images' => array_slice($images, 0, 3),
        ];
    }
}
