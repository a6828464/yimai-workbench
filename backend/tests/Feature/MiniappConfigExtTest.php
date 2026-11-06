<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * v2 结构扩展（t32）：门店视频 + 三种出行路线 + 首页弹窗，图片上限 3→9，旧配置向后兼容。
 *
 * 这一轮的核心风险不是「新字段能不能存」，而是三件容易写错、且出错不会报错的事：
 *  1. **旧配置读不出来**：线上存量只有 8 个字段，若用 `$cfg['videoUrl']` 直取就是 undefined index；
 *  2. **嵌套合并退化成整段替换**：只改 `routes.walk` 却把 `drive`/`transit` 冲掉；
 *  3. **两个上限**：门店 images 与 `routes.*.images` 都必须是 9，只改一处就会「门店能传 9、路线只能传 3」。
 */
class MiniappConfigExtTest extends TestCase
{
    use RefreshDatabase;

    // ================================================== 1. 旧配置向后兼容

    /**
     * v1 存量配置（只有前 8 个字段）读出来必须补齐新键，且**旧值一个不丢**。
     *
     * 这是升级路径的关键：生产库里现在就是这份形状，GET 不能报错、不能返回 null。
     */
    public function test_legacy_v1_config_is_read_with_new_keys_filled_in(): void
    {
        AppSetting::create(['miniapp_config' => [
            'venues' => [
                [
                    'key' => '绿地店', 'sub' => '旧副标题', 'address' => '旧地址', 'phone' => '0574-11111111',
                    'latitude' => 29.9, 'longitude' => 121.6, 'channelsUser' => 'sphOld', 'channelsLive' => '',
                    'images' => [['src' => '/static/old.jpg', 'caption' => '旧图']],
                    // 故意不带 videoUrl / routes
                ],
                [
                    'key' => '东部店', 'sub' => '东部旧', 'address' => '', 'phone' => '',
                    'latitude' => null, 'longitude' => null, 'channelsUser' => '', 'channelsLive' => '',
                    'images' => [],
                ],
            ],
            'updatedAt' => '2026-11-01 10:00:00',
            // 故意不带 popup
        ]]);

        $data = $this->getJson('/api/miniapp/config')->assertOk()->json('data');

        // 旧值原样保留
        $green = $data['venues'][0];
        $this->assertSame('旧副标题', $green['sub']);
        $this->assertSame('旧地址', $green['address']);
        $this->assertSame('0574-11111111', $green['phone']);
        $this->assertSame(29.9, $green['latitude']);
        $this->assertSame('sphOld', $green['channelsUser']);
        $this->assertSame('/static/old.jpg', $green['images'][0]['src']);
        $this->assertSame('2026-11-01 10:00:00', $data['updatedAt']);

        // 新键全部补默认值（不是 null、不是缺键）
        foreach ($data['venues'] as $venue) {
            $this->assertSame('', $venue['videoUrl'], 'v1 配置没有门店视频时必须补空串');
            $this->assertSame(['drive', 'transit', 'walk'], array_keys($venue['routes']), 'routes 必须补齐三种出行方式');
            foreach ($venue['routes'] as $kind => $route) {
                $this->assertSame('', $route['videoUrl'], "{$kind}.videoUrl 必须是空串");
                $this->assertSame([], $route['images'], "{$kind}.images 必须是空数组");
            }
        }

        // 顶层 popup 同样补默认值
        $this->assertSame([
            'enabled' => false,
            'title' => '',
            'content' => '',
            'imageUrl' => '',
            'frequency' => 'once',
        ], $data['popup'], 'v1 配置没有 popup 时必须回落默认值');
    }

    /** 全新库（没有 app_settings 行）也必须是同一套完整结构。 */
    public function test_default_payload_has_empty_routes_and_disabled_popup(): void
    {
        $data = $this->getJson('/api/miniapp/config')->assertOk()->json('data');

        // t43 加 theme、t50 加 homeHero（契约顺序：theme → homeHero → venues → popup → updatedAt）
        $this->assertSame(['theme', 'homeHero', 'venues', 'popup', 'updatedAt'], array_keys($data));
        $this->assertFalse($data['popup']['enabled']);
        $this->assertSame('once', $data['popup']['frequency']);

        foreach ($data['venues'] as $venue) {
            $this->assertArrayHasKey('videoUrl', $venue);
            $this->assertArrayHasKey('routes', $venue);
            $this->assertSame(['drive', 'transit', 'walk'], array_keys($venue['routes']));
            // t39：route 对象多了 note（纯文本说明），键序为 note → videoUrl → images
            $this->assertSame(['note' => '', 'videoUrl' => '', 'images' => []], $venue['routes']['walk']);
        }
    }

    // ================================================== 2. routes 三种方式互相独立

    /**
     * 只改 `walk` 不得冲掉 `drive` / `transit`（嵌套层递归合并的核心用例）。
     *
     * 若哪天有人把 `routes` 写成整段替换，这条会立刻红。
     */
    public function test_each_route_kind_is_merged_independently(): void
    {
        Sanctum::actingAs($this->super());

        // 第一版：drive 与 transit 都有内容，walk 先不配
        $this->putJson('/api/miniapp/config', ['venues' => [
            [
                'key' => '绿地店',
                'routes' => [
                    'drive' => [
                        'videoUrl' => 'https://cdn.example.com/drive.mp4',
                        'images' => [['src' => '/static/drive1.jpg', 'caption' => '地库入口']],
                    ],
                    'transit' => [
                        'videoUrl' => '/static/transit.mp4',
                        'images' => [['src' => '/static/transit1.jpg', 'caption' => '地铁 2 号线']],
                    ],
                ],
            ],
            ['key' => '东部店'],
        ]])->assertOk();

        // 第二版：只提交 walk
        $this->putJson('/api/miniapp/config', ['venues' => [
            [
                'key' => '绿地店',
                'routes' => ['walk' => [
                    'videoUrl' => 'https://cdn.example.com/walk.mp4',
                    'images' => [['src' => '/static/walk1.jpg', 'caption' => '从商场西门进来']],
                ]],
            ],
            ['key' => '东部店'],
        ]])->assertOk();

        $routes = $this->getJson('/api/miniapp/config')->json('data.venues.0.routes');

        $this->assertSame('https://cdn.example.com/drive.mp4', $routes['drive']['videoUrl'], 'drive 不得被 walk 的提交冲掉');
        $this->assertSame('/static/drive1.jpg', $routes['drive']['images'][0]['src']);
        $this->assertSame('/static/transit.mp4', $routes['transit']['videoUrl']);
        $this->assertSame('/static/transit1.jpg', $routes['transit']['images'][0]['src']);
        $this->assertSame('https://cdn.example.com/walk.mp4', $routes['walk']['videoUrl'], 'walk 必须写入成功');
        $this->assertSame('/static/walk1.jpg', $routes['walk']['images'][0]['src']);
    }

    /** 未知的出行方式（如 bike）必须 422，而不是静默丢弃。 */
    public function test_unknown_route_kind_is_rejected(): void
    {
        Sanctum::actingAs($this->super());

        $resp = $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店', 'routes' => ['bike' => ['videoUrl' => '', 'images' => []]]],
            ['key' => '东部店'],
        ]])->assertStatus(422);

        $this->assertStringContainsString('drive', (string) $resp->json('message'));
    }

    // ================================================== 3. popup

    public function test_popup_frequency_enum_and_partial_merge(): void
    {
        Sanctum::actingAs($this->super());

        // once / always 都通过
        foreach (['once', 'always'] as $frequency) {
            $this->putJson('/api/miniapp/config', ['popup' => [
                'enabled' => true, 'title' => '双十一活动', 'content' => '私教课 8 折',
                'imageUrl' => '/static/popup.jpg', 'frequency' => $frequency,
            ]])->assertOk();

            $popup = $this->getJson('/api/miniapp/config')->json('data.popup');
            $this->assertSame($frequency, $popup['frequency']);
            $this->assertTrue($popup['enabled']);
            $this->assertSame('双十一活动', $popup['title']);
        }

        // 非法频率 422
        $bad = $this->putJson('/api/miniapp/config', ['popup' => ['frequency' => 'daily']])->assertStatus(422);
        $this->assertStringContainsString('once', (string) $bad->json('message'));
        $this->assertStringContainsString('always', (string) $bad->json('message'));

        // 非法图片地址 422（与 videoUrl 同一套白名单）
        $this->putJson('/api/miniapp/config', ['popup' => ['imageUrl' => 'javascript:alert(1)']])->assertStatus(422);
        // http 明文同样拒
        $this->putJson('/api/miniapp/config', ['popup' => ['imageUrl' => 'http://cdn.example.com/a.png']])->assertStatus(422);

        // 字段级合并：只改频率，标题/内容不动
        $this->putJson('/api/miniapp/config', ['popup' => ['frequency' => 'always']])->assertOk();
        $popup = $this->getJson('/api/miniapp/config')->json('data.popup');
        $this->assertSame('双十一活动', $popup['title'], '只提交 frequency 时 title 必须保留');
        $this->assertSame('私教课 8 折', $popup['content']);
        $this->assertSame('/static/popup.jpg', $popup['imageUrl']);
        $this->assertTrue($popup['enabled']);
    }

    public function test_popup_limits_and_boolean(): void
    {
        Sanctum::actingAs($this->super());

        $this->putJson('/api/miniapp/config', ['popup' => ['title' => str_repeat('标', 41)]])
            ->assertStatus(422)
            ->assertJsonPath('message', '弹窗标题最多 40 个字');

        $this->putJson('/api/miniapp/config', ['popup' => ['content' => str_repeat('文', 501)]])
            ->assertStatus(422)
            ->assertJsonPath('message', '弹窗内容最多 500 个字');

        $this->putJson('/api/miniapp/config', ['popup' => ['enabled' => '不是布尔']])
            ->assertStatus(422);

        // 边界值必须通过（40 / 500）
        $this->putJson('/api/miniapp/config', ['popup' => [
            'title' => str_repeat('标', 40), 'content' => str_repeat('文', 500),
        ]])->assertOk();
    }

    // ================================================== 4. images 上限 9

    public function test_images_limit_is_nine_for_venue_and_routes(): void
    {
        Sanctum::actingAs($this->super());
        $nine = array_map(
            fn (int $i) => ['src' => "/static/g{$i}.jpg", 'caption' => "图{$i}"],
            range(1, 9)
        );

        // 门店 images：9 张通过
        $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店', 'images' => $nine],
            ['key' => '东部店'],
        ]])->assertOk();
        $this->assertCount(9, $this->getJson('/api/miniapp/config')->json('data.venues.0.images'));

        // 门店 images：10 张 422
        $ten = array_merge($nine, [['src' => '/static/g10.jpg', 'caption' => '第10张']]);
        $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店', 'images' => $ten],
            ['key' => '东部店'],
        ]])->assertStatus(422)->assertJsonPath('message', '每家店最多 9 张图片');

        // routes.*.images：9 张通过
        $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店', 'routes' => ['drive' => ['images' => $nine]]],
            ['key' => '东部店'],
        ]])->assertOk();
        $this->assertCount(9, $this->getJson('/api/miniapp/config')->json('data.venues.0.routes.drive.images'));

        // routes.*.images：10 张 422
        $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店', 'routes' => ['drive' => ['images' => $ten]]],
            ['key' => '东部店'],
        ]])->assertStatus(422)->assertJsonPath('message', '每种出行方式最多 9 张图片');
    }

    // ================================================== 5. videoUrl 五种形态

    /**
     * videoUrl / 图片 src 共用同一个白名单（空 / 站内 / https 收，javascript: / http: / //host 拒）。
     *
     * 门店 videoUrl、routes.*.videoUrl、popup.imageUrl 三处都要过同一套规则 ——
     * 本用例把「门店」与「路线」两处都跑一遍，popup 在 frequency 用例里已覆盖。
     */
    public function test_video_url_whitelist_shapes(): void
    {
        Sanctum::actingAs($this->super());

        $allowed = ['', '/static/venue.mp4', 'https://cdn.example.com/venue.mp4',
            // 视频号「复制视频 ID」拿到的两种常见形态，要能直接填进视频链接
            'export/UzFfAgtgekIEAQAAAAAA', 'UzFfAgtgekIEAQAAAAAA'];
        foreach ($allowed as $url) {
            $this->putJson('/api/miniapp/config', ['venues' => [
                ['key' => '绿地店', 'videoUrl' => $url, 'routes' => ['walk' => ['videoUrl' => $url]]],
                ['key' => '东部店'],
            ]])->assertOk();

            $venue = $this->getJson('/api/miniapp/config')->json('data.venues.0');
            $this->assertSame($url, $venue['videoUrl'], "允许的地址应原样保存：{$url}");
            $this->assertSame($url, $venue['routes']['walk']['videoUrl']);
        }

        $rejected = ['javascript:alert(1)', 'http://cdn.example.com/venue.mp4', '//evil.example.com/venue.mp4', 'data:video/mp4;base64,AAA', 'relative/path.mp4'];
        foreach ($rejected as $url) {
            $this->putJson('/api/miniapp/config', ['venues' => [
                ['key' => '绿地店', 'videoUrl' => $url],
                ['key' => '东部店'],
            ]])->assertStatus(422)->assertJsonPath('message', '必须是站内路径（/ 开头）、https 直链，或视频号视频 ID');

            // 路线的视频同样受管
            $this->putJson('/api/miniapp/config', ['venues' => [
                ['key' => '绿地店', 'routes' => ['drive' => ['videoUrl' => $url]]],
                ['key' => '东部店'],
            ]])->assertStatus(422);
        }
    }

    // ================================================== 6. venues / popup 互不影响

    /** 只传 popup 不得清掉 venues；只传 venues 不得清掉 popup。 */
    public function test_venues_and_popup_do_not_clobber_each_other(): void
    {
        Sanctum::actingAs($this->super());

        // 先落一份有辨识度的 venues
        $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店', 'address' => '绿地-只传 venues 时不许丢'],
            ['key' => '东部店'],
        ]])->assertOk();

        // 只传 popup
        $this->putJson('/api/miniapp/config', ['popup' => ['enabled' => true, 'title' => '只改弹窗']])->assertOk();
        $data = $this->getJson('/api/miniapp/config')->json('data');
        $this->assertSame('绿地-只传 venues 时不许丢', $data['venues'][0]['address'], '只提交 popup 时 venues 必须原样保留');
        $this->assertSame('只改弹窗', $data['popup']['title']);

        // 只传 venues
        $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店', 'phone' => '0574-99999999'],
            ['key' => '东部店'],
        ]])->assertOk();
        $data = $this->getJson('/api/miniapp/config')->json('data');
        $this->assertSame('0574-99999999', $data['venues'][0]['phone']);
        $this->assertSame('只改弹窗', $data['popup']['title'], '只提交 venues 时 popup 必须原样保留');
        $this->assertTrue($data['popup']['enabled']);
    }

    // ================================================== 7. 审计粒度

    /** 审计 detail 记到「哪个门店的哪一项」这一级（不是只写「更新了小程序设置」）。 */
    public function test_audit_detail_names_the_changed_sections(): void
    {
        Sanctum::actingAs($this->super());

        $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店', 'address' => '新地址 A', 'routes' => ['walk' => ['videoUrl' => '/static/w.mp4']]],
            ['key' => '东部店'],
        ]])->assertOk();
        $this->putJson('/api/miniapp/config', ['popup' => ['enabled' => true, 'frequency' => 'always']])->assertOk();

        $detail = (string) AuditLog::query()->where('module', '小程序设置')->latest('id')->firstOrFail()->detail;
        $this->assertStringContainsString('弹窗', $detail);
        $this->assertStringContainsString('弹窗开关', $detail);

        // 上一条（venues 那次）应点名门店与具体项
        $venueDetail = (string) AuditLog::query()->where('module', '小程序设置')
            ->orderByDesc('id')->skip(1)->firstOrFail()->detail;
        $this->assertStringContainsString('绿地店', $venueDetail);
        $this->assertStringContainsString('地址', $venueDetail);
        $this->assertStringContainsString('步行路线', $venueDetail);
    }

    // ------------------------------------------------------------- 工具

    private function super(): User
    {
        return User::factory()->create([
            'username' => uniqid('mcx-super-'),
            'name' => '馆长',
            'role' => 'R_SUPER',
            'roles' => ['R_SUPER'],
            'status' => '启用',
            'venue' => null,
            'venues' => ['绿地店', '东部店'],
        ]);
    }
}
