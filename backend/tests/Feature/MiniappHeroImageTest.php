<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * t50：两张可替换图片 —— 顶层 `homeHero`（首页背景图）+ `venues[].heroImage`（门店顶图）。
 *
 * 三条容易做错的语义，都在这里钉住：
 *  1. **空串就是空串**：`heroImage` 为空表示「没单独配」，由**客户端**回落 `images[0]`。
 *     后端若「贴心」地替客户端回落，馆主就永远改不回「用第一张图」这个状态了。
 *  2. **同一套媒体白名单**：与 `images[].src` / `videoUrl` / `popup.imageUrl` 共用
 *     `isAllowedMediaUrl()`（上传端点返回的站内路径必须能直接填进来）。
 *  3. **递归合并**：只传 `homeHero` 不动 venues；只改一家店的 `heroImage` 不动另一家。
 */
class MiniappHeroImageTest extends TestCase
{
    use RefreshDatabase;

    /** ① 默认值：顶层 homeHero 与每家店 heroImage 都是空串。 */
    public function test_default_hero_fields_are_empty_strings(): void
    {
        $data = $this->getJson('/api/miniapp/config')->assertOk()->json('data');

        $this->assertSame('', $data['homeHero'], '首页背景图默认空串（客户端决定兜底）');
        $this->assertSame(['theme', 'homeHero', 'venues', 'popup', 'blog', 'updatedAt'], array_keys($data));

        foreach ($data['venues'] as $venue) {
            $this->assertArrayHasKey('heroImage', $venue, '每家店都必须有 heroImage 键（不是缺键）');
            $this->assertSame('', $venue['heroImage']);
            // 门店对象键序：heroImage 紧跟 key
            $this->assertSame(['key', 'heroImage'], array_slice(array_keys($venue), 0, 2));
        }
    }

    /** ② PUT homeHero 用 https 直链 → 200，且 venues 一个字段都不动。 */
    public function test_put_home_hero_keeps_venues_untouched(): void
    {
        Sanctum::actingAs($this->super());

        // 先落一份有辨识度的门店配置
        $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店', 'address' => '只改首页背景时不许丢', 'images' => [['src' => '/static/a.jpg', 'caption' => 'A']]],
            ['key' => '东部店'],
        ]])->assertOk();
        $before = $this->getJson('/api/miniapp/config')->json('data.venues');

        $this->putJson('/api/miniapp/config', ['homeHero' => 'https://cdn.example.com/home.jpg'])
            ->assertOk()
            ->assertJsonPath('data.homeHero', 'https://cdn.example.com/home.jpg');

        $after = $this->getJson('/api/miniapp/config')->json('data.venues');
        $this->assertEquals($before, $after, '只提交 homeHero 时 venues 必须逐字段不变');
    }

    /** ③ 非法协议（javascript:）→ 422，且不落库。 */
    public function test_home_hero_rejects_unsafe_schemes(): void
    {
        Sanctum::actingAs($this->super());

        foreach (['javascript:alert(1)', 'http://cdn.example.com/a.jpg', '//evil.example.com/a.jpg', 'data:image/png;base64,AAA'] as $bad) {
            $this->putJson('/api/miniapp/config', ['homeHero' => $bad])
                ->assertStatus(422)
                ->assertJsonPath('message', '必须是站内路径（以 / 开头，且不能以 // 开头）或 https 直链');
        }

        $this->assertSame('', $this->getJson('/api/miniapp/config')->json('data.homeHero'), '被拒后不得落库');

        // 门店顶图是同一套规则
        $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店', 'heroImage' => 'javascript:alert(1)'],
            ['key' => '东部店'],
        ]])->assertStatus(422);
    }

    /** ④ 只改一家店的门店顶图 → 另一家不变，且同店其它字段也不变。 */
    public function test_updating_one_venue_hero_image_does_not_touch_the_other(): void
    {
        Sanctum::actingAs($this->super());

        $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店', 'heroImage' => '/static/green-top.jpg', 'sub' => '绿地副标题'],
            ['key' => '东部店', 'heroImage' => '/static/east-top.jpg', 'sub' => '东部副标题'],
        ]])->assertOk();

        // 只提交绿地的 heroImage（东部整段不传）
        $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店', 'heroImage' => 'https://cdn.example.com/green-new.jpg'],
            ['key' => '东部店'],
        ]])->assertOk();

        $venues = $this->getJson('/api/miniapp/config')->json('data.venues');
        $this->assertSame('https://cdn.example.com/green-new.jpg', $venues[0]['heroImage']);
        $this->assertSame('绿地副标题', $venues[0]['sub'], '同店未提交的字段也要保留');
        $this->assertSame('/static/east-top.jpg', $venues[1]['heroImage'], '只改绿地时东部店的顶图不得被动');
        $this->assertSame('东部副标题', $venues[1]['sub']);
    }

    /** ⑤ 旧配置（两个键都没有）→ 读出补空串，其它内容不丢；且 GET 不写库。 */
    public function test_legacy_config_without_hero_fields_reads_as_empty(): void
    {
        AppSetting::create(['miniapp_config' => [
            'theme' => 'editorial',
            'venues' => [
                [
                    'key' => '绿地店', 'sub' => '旧副标题', 'address' => '旧地址', 'phone' => '',
                    'latitude' => null, 'longitude' => null, 'channelsUser' => '', 'channelsLive' => '',
                    'images' => [['src' => '/static/old.jpg', 'caption' => '旧图']],
                    'videoUrl' => '', 'routes' => [],
                    // 故意没有 heroImage
                ],
                ['key' => '东部店'],
            ],
            'popup' => ['enabled' => false, 'title' => '', 'content' => '', 'imageUrl' => '', 'frequency' => 'once'],
            // 故意没有 homeHero
        ]]);
        $updatedAtBefore = AppSetting::query()->oldest('id')->firstOrFail()->updated_at;

        $data = $this->getJson('/api/miniapp/config')->assertOk()->json('data');

        $this->assertSame('', $data['homeHero']);
        foreach ($data['venues'] as $venue) {
            $this->assertSame('', $venue['heroImage']);
        }
        // 旧内容全部保留
        $this->assertSame('旧副标题', $data['venues'][0]['sub']);
        $this->assertSame('/static/old.jpg', $data['venues'][0]['images'][0]['src']);

        // GET 是纯读：库里不该多出这两个键
        $stored = AppSetting::query()->oldest('id')->firstOrFail();
        $this->assertArrayNotHasKey('homeHero', $stored->miniapp_config);
        $this->assertArrayNotHasKey('heroImage', $stored->miniapp_config['venues'][0]);
        $this->assertEquals($updatedAtBefore, $stored->updated_at, 'GET 不得写库');
    }

    /** ⑥ 库内脏值（手改库）→ 读时清洗成空串，不把 javascript: 发给顾客。 */
    public function test_dirty_stored_hero_urls_are_cleaned_on_read(): void
    {
        AppSetting::create(['miniapp_config' => [
            'homeHero' => 'javascript:alert(1)',
            'venues' => [
                ['key' => '绿地店', 'heroImage' => 'http://insecure.example.com/a.jpg'],
                ['key' => '东部店', 'heroImage' => '//evil.example.com/a.jpg'],
            ],
        ]]);

        $data = $this->getJson('/api/miniapp/config')->assertOk()->json('data');

        $this->assertSame('', $data['homeHero']);
        $this->assertSame('', $data['venues'][0]['heroImage']);
        $this->assertSame('', $data['venues'][1]['heroImage']);
    }

    /** ⑦ 上传端点返回的站内路径可以直接当 heroImage / homeHero 用（同一套白名单）。 */
    public function test_uploaded_station_path_is_accepted_as_hero(): void
    {
        Sanctum::actingAs($this->super());

        $url = (string) $this->post('/api/miniapp/config/upload', [
            'file' => UploadedFile::fake()->image('hero.jpg', 800, 600),
        ], ['Accept' => 'application/json'])->assertOk()->json('data.url');

        try {
            $this->putJson('/api/miniapp/config', [
                'homeHero' => $url,
                'venues' => [['key' => '绿地店', 'heroImage' => $url], ['key' => '东部店']],
            ])->assertOk();

            $data = $this->getJson('/api/miniapp/config')->json('data');
            $this->assertSame($url, $data['homeHero']);
            $this->assertSame($url, $data['venues'][0]['heroImage']);
        } finally {
            @unlink(storage_path('app/miniapp-uploads/'.basename($url)));
        }
    }

    /**
     * ⑧ 后端**不做**回落：heroImage 为空时即使 images 有内容，返回的仍是空串。
     *
     * 回落是客户端的职责（`heroImage || images[0]`）。后端一旦替它回落，
     * 馆主就再也表达不出「用第一张图」这个状态了。
     */
    public function test_backend_does_not_fall_back_to_first_image(): void
    {
        AppSetting::create(['miniapp_config' => [
            'venues' => [
                ['key' => '绿地店', 'heroImage' => '', 'images' => [['src' => '/static/first.jpg', 'caption' => '第一张']]],
                ['key' => '东部店'],
            ],
        ]]);

        $venue = $this->getJson('/api/miniapp/config')->assertOk()->json('data.venues.0');
        $this->assertSame('', $venue['heroImage'], '后端不得用 images[0] 顶替空 heroImage');
        $this->assertSame('/static/first.jpg', $venue['images'][0]['src'], 'images 本身当然还在');
    }

    /** ⑨ 两张图的变更都进审计（字段名级）。 */
    public function test_hero_changes_are_audited(): void
    {
        Sanctum::actingAs($this->super());

        $this->putJson('/api/miniapp/config', ['homeHero' => '/static/home.jpg'])->assertOk();
        $this->assertStringContainsString(
            '首页背景图',
            (string) AuditLog::query()->where('module', '小程序设置')->latest('id')->firstOrFail()->detail
        );

        $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店', 'heroImage' => '/static/green.jpg'], ['key' => '东部店'],
        ]])->assertOk();
        $detail = (string) AuditLog::query()->where('module', '小程序设置')->latest('id')->firstOrFail()->detail;
        $this->assertStringContainsString('绿地店', $detail);
        $this->assertStringContainsString('门店顶图', $detail);
    }

    private function super(): User
    {
        return User::factory()->create([
            'username' => uniqid('hero-super-'),
            'name' => '馆长',
            'role' => 'R_SUPER',
            'roles' => ['R_SUPER'],
            'status' => '启用',
            'venue' => null,
            'venues' => ['绿地店', '东部店'],
        ]);
    }
}
