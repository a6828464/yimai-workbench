<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 小程序配置的顶层 `theme`：现行枚举 **editorial | oriental**，默认 **editorial**。
 *
 * 历史：t43 时是 `classic | editorial` 且默认 classic；馆主 2026-11 拍板弃用 classic，
 * 客户端会**删掉**经典主题的渲染代码。所以这里除了常规的「默认值 / 合并 / 校验」，
 * 还多了一类必须成立的行为：**库里的 classic 必须回落 editorial**。
 *
 * 为什么这条不能用「兼容旧值」的思路做：返回 classic 不是「保守」，而是把一个客户端
 * 已经不认识的值发过去 → 页面渲染成无主题的裸样式。对顾客来说比任何一套主题都糟。
 */
class MiniappThemeTest extends TestCase
{
    use RefreshDatabase;

    /** ① 全新库：GET 默认必须是 editorial。 */
    public function test_default_theme_is_editorial(): void
    {
        $data = $this->getJson('/api/miniapp/config')->assertOk()->json('data');

        $this->assertSame('editorial', $data['theme'], '默认主题必须是 editorial（classic 已弃用）');
        // 顶层键顺序即契约：theme 在最前，其后 homeHero（t50）
        $this->assertSame(['theme', 'homeHero', 'venues', 'popup', 'updatedAt'], array_keys($data));
    }

    /** ② 更旧的配置（连 theme 键都没有）：补 editorial，且其它字段一个不丢。 */
    public function test_legacy_config_without_theme_falls_back_to_editorial(): void
    {
        AppSetting::create(['miniapp_config' => [
            'venues' => [
                [
                    'key' => '绿地店', 'sub' => '旧副标题', 'address' => '旧地址', 'phone' => '0574-11111111',
                    'latitude' => 29.9, 'longitude' => 121.6, 'channelsUser' => '', 'channelsLive' => '',
                    'images' => [['src' => '/static/old.jpg', 'caption' => '旧图']],
                    'videoUrl' => '/static/old.mp4',
                    'routes' => [
                        'drive' => ['note' => '旧说明', 'videoUrl' => '', 'images' => []],
                        'transit' => ['note' => '', 'videoUrl' => '', 'images' => []],
                        'walk' => ['note' => '', 'videoUrl' => '', 'images' => []],
                    ],
                ],
                ['key' => '东部店'],
            ],
            'popup' => ['enabled' => true, 'title' => '旧弹窗', 'content' => '', 'imageUrl' => '', 'frequency' => 'always'],
            'updatedAt' => '2026-11-01 10:00:00',
            // 故意没有 theme 键
        ]]);

        $data = $this->getJson('/api/miniapp/config')->assertOk()->json('data');

        $this->assertSame('editorial', $data['theme'], '缺 theme 键必须回落 editorial');
        // 旧值原样保留（证明「补默认值」不是「重建配置」）
        $this->assertSame('旧副标题', $data['venues'][0]['sub']);
        $this->assertSame('/static/old.mp4', $data['venues'][0]['videoUrl']);
        $this->assertSame('旧说明', $data['venues'][0]['routes']['drive']['note']);
        $this->assertSame('旧弹窗', $data['popup']['title']);
        $this->assertTrue($data['popup']['enabled']);
        $this->assertSame('2026-11-01 10:00:00', $data['updatedAt']);
    }

    /**
     * ③ **存量 classic → editorial**（本任务的核心兼容点）。
     *
     * t43 那轮默认值就是 classic，线上很可能真存了这个值。它不是「旧版本值」这么简单：
     * 客户端会把 classic 的渲染代码删掉，所以必须在这里吃掉。
     */
    public function test_stored_classic_falls_back_to_editorial(): void
    {
        AppSetting::create(['miniapp_config' => [
            'theme' => 'classic',
            'venues' => [['key' => '绿地店', 'sub' => '存量配置里的门店'], ['key' => '东部店']],
            'popup' => ['enabled' => true, 'title' => '存量弹窗'],
            'updatedAt' => '2026-11-02 09:00:00',
        ]]);

        $data = $this->getJson('/api/miniapp/config')->assertOk()->json('data');

        $this->assertSame('editorial', $data['theme'], '库里的 classic 必须回落 editorial，不能原样返回');
        $this->assertNotSame('classic', $data['theme']);
        // 回落只作用于 theme —— 其它存量内容照样读得出来
        $this->assertSame('存量配置里的门店', $data['venues'][0]['sub']);
        $this->assertSame('存量弹窗', $data['popup']['title']);

        // 库里仍保留原值（回落发生在读取时，不写库）：回滚代码时还能认出这行是 classic
        $this->assertSame('classic', AppSetting::query()->oldest('id')->firstOrFail()->miniapp_config['theme']);
    }

    /** ④ 其它脏数据同样回落 editorial（数字 / 空串 / 未知词 / null / 数组 / 大小写）。 */
    public function test_dirty_theme_values_fall_back_to_editorial(): void
    {
        foreach (['classic', 'weird', 'dark', 123, null, ['editorial'], '', 'EDITORIAL'] as $dirty) {
            AppSetting::query()->delete();
            AppSetting::create(['miniapp_config' => ['theme' => $dirty]]);

            $theme = $this->getJson('/api/miniapp/config')->assertOk()->json('data.theme');
            $this->assertSame('editorial', $theme, '脏数据 '.json_encode($dirty, JSON_UNESCAPED_UNICODE).' 必须回落 editorial');
        }
    }

    /** ⑤ PUT oriental → 200 且真的落库。 */
    public function test_put_oriental_saves(): void
    {
        Sanctum::actingAs($this->super());

        $this->putJson('/api/miniapp/config', ['theme' => 'oriental'])
            ->assertOk()
            ->assertJsonPath('data.theme', 'oriental');

        $this->assertSame('oriental', $this->getJson('/api/miniapp/config')->json('data.theme'));
        $this->assertSame('oriental', AppSetting::query()->oldest('id')->firstOrFail()->miniapp_config['theme']);
    }

    /** ⑥ PUT classic → 422，且提示「已不再支持」而不是泛泛的「无效」。 */
    public function test_put_classic_is_rejected_as_retired(): void
    {
        Sanctum::actingAs($this->super());

        $resp = $this->putJson('/api/miniapp/config', ['theme' => 'classic'])->assertStatus(422);
        $message = (string) $resp->json('message');

        $this->assertStringContainsString('已不再支持', $message, '要明确说经典主题被弃用，而不是「无效」');
        $this->assertStringContainsString('editorial', $message);
        $this->assertStringContainsString('oriental', $message);

        // 被拒后库里的主题仍是默认值（没被写坏）
        $this->assertSame('editorial', $this->getJson('/api/miniapp/config')->json('data.theme'));
    }

    /** ⑦ 其它非法值 422（含数字、未知词）；两个合法值都能保存。 */
    public function test_other_invalid_themes_are_rejected(): void
    {
        Sanctum::actingAs($this->super());

        foreach (['dark', 'weird', 123, true] as $bad) {
            $this->putJson('/api/miniapp/config', ['theme' => $bad])->assertStatus(422);
        }

        foreach (['editorial', 'oriental'] as $ok) {
            $this->putJson('/api/miniapp/config', ['theme' => $ok])->assertOk();
        }
    }

    /** ⑧ 合并语义：只传 theme 不动 venues/popup；只传 venues 不动 theme。 */
    public function test_theme_and_other_sections_do_not_clobber_each_other(): void
    {
        Sanctum::actingAs($this->super());

        $this->putJson('/api/miniapp/config', [
            'venues' => [['key' => '绿地店', 'address' => '只改主题时不许丢'], ['key' => '东部店']],
            'popup' => ['enabled' => true, 'title' => '别把我清掉'],
        ])->assertOk();

        // 只改主题
        $this->putJson('/api/miniapp/config', ['theme' => 'oriental'])->assertOk();
        $data = $this->getJson('/api/miniapp/config')->json('data');
        $this->assertSame('oriental', $data['theme']);
        $this->assertSame('只改主题时不许丢', $data['venues'][0]['address'], '只提交 theme 时 venues 必须原样保留');
        $this->assertSame('别把我清掉', $data['popup']['title']);

        // 只改 venues
        $this->putJson('/api/miniapp/config', [
            'venues' => [['key' => '绿地店', 'phone' => '0574-33333333'], ['key' => '东部店']],
        ])->assertOk();
        $data = $this->getJson('/api/miniapp/config')->json('data');
        $this->assertSame('oriental', $data['theme'], '只提交 venues 时 theme 必须沿用当前生效值');
        $this->assertSame('0574-33333333', $data['venues'][0]['phone']);
    }

    /** ⑨ 主题变更留审计（字段名级，不含值）。 */
    public function test_theme_change_is_audited(): void
    {
        Sanctum::actingAs($this->super());

        $this->putJson('/api/miniapp/config', ['theme' => 'oriental'])->assertOk();

        $detail = (string) AuditLog::query()->where('module', '小程序设置')->latest('id')->firstOrFail()->detail;
        $this->assertStringContainsString('主题', $detail);
    }

    private function super(): User
    {
        return User::factory()->create([
            'username' => uniqid('theme-super-'),
            'name' => '馆长',
            'role' => 'R_SUPER',
            'roles' => ['R_SUPER'],
            'status' => '启用',
            'venue' => null,
            'venues' => ['绿地店', '东部店'],
        ]);
    }
}
