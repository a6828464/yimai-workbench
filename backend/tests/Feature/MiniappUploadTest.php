<?php

namespace Tests\Feature;

use App\Http\Controllers\MiniappConfigController;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * t39：路线文字说明（`routes.*.note`）+ 小程序配置图片上传端点。
 *
 * 两件事的共同点都是「顾客端会直接看到」：
 *  - note 会渲染在到店指引页 → 必须剥掉 HTML/脚本（XSS 防线）；
 *  - 上传的图片会被顾客手机加载 → 类型必须按**真实 MIME** 判、文件名必须服务端生成、
 *    落地目录必须在 docroot 之外（本项目是 PHP，上传目录能解析 PHP 就是 RCE）。
 *
 * 本文件把这几条都变成可执行断言，而不是注释里的承诺。
 */
class MiniappUploadTest extends TestCase
{
    use RefreshDatabase;

    /** 本用例创建的上传文件，跑完删掉（storage/app 虽被 gitignore，也不该越积越多） */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    // ====================================================== 1. 上传成功

    public function test_upload_returns_whitelisted_station_url_usable_in_config(): void
    {
        Sanctum::actingAs($this->super());

        $file = UploadedFile::fake()->image('前台照片.JPG', 320, 240);
        $resp = $this->post('/api/miniapp/config/upload', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk();

        $url = (string) $resp->json('data.url');

        // ① 形如 /api/miniapp/uploads/<32 位 hex>.<MIME 推导的扩展名>
        $this->assertMatchesRegularExpression('#^/api/miniapp/uploads/[a-f0-9]{32}\.(jpg|png|webp|gif)$#', $url);

        // ② 必须能通过现有白名单（这是前端「填进 images[].src 就能用」的前提）
        $guard = new \ReflectionMethod(MiniappConfigController::class, 'isAllowedMediaUrl');
        $guard->setAccessible(true);
        $this->assertTrue($guard->invoke(null, $url), "上传返回的 URL 必须通过 isAllowedMediaUrl：{$url}");

        // ③ 真的落到 storage（docroot 之外），不是 public
        $name = basename($url);
        $path = storage_path('app/miniapp-uploads/'.$name);
        $this->created[] = $path;
        $this->assertFileExists($path);
        $this->assertStringNotContainsString('/public/', $path, '上传文件不得落在 public 下');

        // ④ 端到端：把这个 url 填进配置 → 保存成功 → 详情可读
        $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店', 'images' => [['src' => $url, 'caption' => '前台']]],
            ['key' => '东部店'],
        ]])->assertOk();
        $this->assertSame($url, $this->getJson('/api/miniapp/config')->json('data.venues.0.images.0.src'));

        // ⑤ 回源能拿到图，且带 nosniff
        $this->get($url)
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    // ====================================================== 2. 大小上限

    public function test_upload_rejects_oversize_file(): void
    {
        Sanctum::actingAs($this->super());

        // fake()->size() 指定「上传报上来的字节数」，用来触发 5MB 上限
        $file = UploadedFile::fake()->image('big.jpg', 100, 100)->size(6000); // 6000KB ≈ 5.86MB

        $resp = $this->post('/api/miniapp/config/upload', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertStringContainsString('5MB', (string) $resp->json('message'));
    }

    // ====================================================== 3. 真实 MIME（伪装）

    /**
     * 把 PHP 代码存成 `shell.php.jpg` 并谎报 `Content-Type: image/jpeg` —— 必须被拒。
     *
     * 判据是 finfo 读文件头（`text/x-php`），不看客户端给的类型、也不看扩展名。
     */
    public function test_upload_rejects_php_disguised_as_jpg(): void
    {
        Sanctum::actingAs($this->super());

        $file = UploadedFile::fake()
            ->createWithContent('shell.php.jpg', "<?php echo 'pwned'; ?>")
            ->mimeType('image/jpeg'); // 客户端在撒谎

        $resp = $this->post('/api/miniapp/config/upload', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertStringContainsString('只支持 JPG / PNG / WebP / GIF', (string) $resp->json('message'));
        // 目录里不得出现任何这次上传的东西
        $this->assertSame([], glob(storage_path('app/miniapp-uploads/*.php')) ?: []);
    }

    /** SVG 同样拒（可内嵌脚本）；纯文本也拒。 */
    public function test_upload_rejects_svg_and_plain_text(): void
    {
        Sanctum::actingAs($this->super());

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $this->post('/api/miniapp/config/upload', [
            'file' => UploadedFile::fake()->createWithContent('x.svg', $svg)->mimeType('image/svg+xml'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->post('/api/miniapp/config/upload', [
            'file' => UploadedFile::fake()->createWithContent('x.jpg', 'not an image at all'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    // ====================================================== 4. 权限与回源

    public function test_upload_requires_super(): void
    {
        $this->post('/api/miniapp/config/upload', [
            'file' => UploadedFile::fake()->image('a.jpg', 10, 10),
        ], ['Accept' => 'application/json'])->assertStatus(401);

        Sanctum::actingAs($this->user('up-manager', '店长甲', 'R_MANAGER', '绿地店'));
        $this->post('/api/miniapp/config/upload', [
            'file' => UploadedFile::fake()->image('a.jpg', 10, 10),
        ], ['Accept' => 'application/json'])->assertStatus(403);

        // 超管但没带文件
        Sanctum::actingAs($this->super());
        $this->post('/api/miniapp/config/upload', [], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', '请选择要上传的图片文件');
    }

    /** 回源路由：只认「32 位 hex + 白名单扩展名」，其它一律 404（路径穿越也到不了文件系统）。 */
    public function test_uploaded_file_route_rejects_unknown_names(): void
    {
        $this->get('/api/miniapp/uploads/'.str_repeat('a', 32).'.jpg')->assertStatus(404); // 合法形状但不存在
        $this->get('/api/miniapp/uploads/shell.php')->assertStatus(404);
        $this->get('/api/miniapp/uploads/'.str_repeat('a', 32).'.php')->assertStatus(404);
        $this->get('/api/miniapp/uploads/..%2F..%2F.env')->assertStatus(404);
    }

    // ====================================================== 5. routes.note

    /** note 参与递归合并：只改 walk.note 不会冲掉 drive/transit 的 note 与视频/图片。 */
    public function test_route_note_is_merged_independently(): void
    {
        Sanctum::actingAs($this->super());

        $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店', 'routes' => [
                'drive' => ['note' => '地库 B2 直上 4 楼', 'videoUrl' => '/static/drive.mp4'],
                'transit' => ['note' => '地铁 2 号线 A 口'],
            ]],
            ['key' => '东部店'],
        ]])->assertOk();

        // 只改 walk.note
        $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店', 'routes' => ['walk' => ['note' => '从西门进来右手边']]],
            ['key' => '东部店'],
        ]])->assertOk();

        $routes = $this->getJson('/api/miniapp/config')->json('data.venues.0.routes');
        $this->assertSame('地库 B2 直上 4 楼', $routes['drive']['note'], 'drive.note 不得被 walk 的提交冲掉');
        $this->assertSame('/static/drive.mp4', $routes['drive']['videoUrl']);
        $this->assertSame('地铁 2 号线 A 口', $routes['transit']['note']);
        $this->assertSame('从西门进来右手边', $routes['walk']['note']);
        // 键顺序：note → videoUrl → finderVideo → images（契约；finderVideo 2026-11-07 拆栏新增）
        $this->assertSame(['note', 'videoUrl', 'finderVideo', 'images'], array_keys($routes['walk']));
    }

    /** note ≤200 字；201 字 422。 */
    public function test_note_length_limit(): void
    {
        Sanctum::actingAs($this->super());

        $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店', 'routes' => ['walk' => ['note' => str_repeat('说', 201)]]],
            ['key' => '东部店'],
        ]])->assertStatus(422)->assertJsonPath('message', '路线文字说明最多 200 个字');

        $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店', 'routes' => ['walk' => ['note' => str_repeat('说', 200)]]],
            ['key' => '东部店'],
        ]])->assertOk();
    }

    /** note 里的 HTML/脚本落库前就被剥掉，GET 回来是纯文本。 */
    public function test_note_strips_html_and_script(): void
    {
        Sanctum::actingAs($this->super());

        $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店', 'routes' => ['walk' => [
                'note' => '<script>alert(1)</script><b>从西门进</b>，电梯 4 楼',
            ]]],
            ['key' => '东部店'],
        ]])->assertOk();

        $note = $this->getJson('/api/miniapp/config')->json('data.venues.0.routes.walk.note');
        $this->assertStringNotContainsString('<', $note, '落库的 note 不得含任何标签');
        $this->assertStringNotContainsString('>', $note);
        $this->assertStringContainsString('从西门进', $note, '标签内部的文字要保留');
        $this->assertStringContainsString('电梯 4 楼', $note);
    }

    /** 旧配置（routes 里没有 note）读出来补空串，不报错。 */
    public function test_legacy_routes_without_note_get_empty_string(): void
    {
        AppSetting::create(['miniapp_config' => [
            'venues' => [
                [
                    'key' => '绿地店', 'sub' => '旧', 'address' => '', 'phone' => '',
                    'latitude' => null, 'longitude' => null, 'channelsUser' => '', 'channelsLive' => '',
                    'images' => [],
                    'videoUrl' => '',
                    'routes' => [
                        'drive' => ['videoUrl' => '/static/old.mp4', 'images' => []], // 没有 note
                        'transit' => ['videoUrl' => '', 'images' => []],
                        'walk' => ['videoUrl' => '', 'images' => []],
                    ],
                ],
                ['key' => '东部店'],
            ],
        ]]);

        $routes = $this->getJson('/api/miniapp/config')->assertOk()->json('data.venues.0.routes');
        foreach (['drive', 'transit', 'walk'] as $kind) {
            $this->assertArrayHasKey('note', $routes[$kind], "{$kind}.note 必须补齐");
            $this->assertSame('', $routes[$kind]['note']);
        }
        $this->assertSame('/static/old.mp4', $routes['drive']['videoUrl'], '旧值不得丢');
    }

    /** 默认值里 routes 三项都应该带空 note。 */
    public function test_default_payload_routes_have_empty_note(): void
    {
        $routes = $this->getJson('/api/miniapp/config')->assertOk()->json('data.venues.0.routes');
        foreach (['drive', 'transit', 'walk'] as $kind) {
            $this->assertSame(['note' => '', 'videoUrl' => '', 'finderVideo' => '', 'images' => []], $routes[$kind]);
        }
    }

    // ------------------------------------------------------------- 工具

    private function super(): User
    {
        return User::factory()->create([
            'username' => uniqid('up-super-'),
            'name' => '馆长',
            'role' => 'R_SUPER',
            'roles' => ['R_SUPER'],
            'status' => '启用',
            'venue' => null,
            'venues' => ['绿地店', '东部店'],
        ]);
    }

    private function user(string $username, string $name, string $role, ?string $venue): User
    {
        return User::factory()->create([
            'username' => $username,
            'name' => $name,
            'role' => $role,
            'roles' => [$role],
            'venue' => $venue,
            'venues' => $venue ? [$venue] : ['绿地店', '东部店'],
            'status' => '启用',
        ]);
    }
}
