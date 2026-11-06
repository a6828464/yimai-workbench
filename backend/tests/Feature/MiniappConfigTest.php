<?php

namespace Tests\Feature;

use App\Http\Controllers\MiniappConfigController;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `/api/miniapp/config`（小程序到店指引）+ ROLE_PRECEDENCE 主角色口径（t28）。
 *
 * 这个接口是**免登录**的（顾客看得到店指引），写又是**仅超管**的 —— 权限两侧都要钉死；
 * 另外它有一套「库里没有就用代码里的默认值」的回落逻辑，且默认坐标必须是
 * 29.8746/121.5496（小程序靠这对占位值判定「没有真实坐标 → 不导航、改复制地址」）。
 */
class MiniappConfigTest extends TestCase
{
    use RefreshDatabase;

    // ============================================ 1. GET：免登录 + 默认值回落

    public function test_get_is_public_and_falls_back_to_builtin_defaults(): void
    {
        // 库里连 app_settings 行都没有 → 必须回默认值，且**不能顺手建行**（读路径不写库）
        $this->assertSame(0, AppSetting::query()->count());

        $resp = $this->getJson('/api/miniapp/config')->assertOk();
        $data = $resp->json('data');

        $this->assertSame(0, $resp->json('code'));
        $this->assertArrayHasKey('venues', $data);
        $this->assertArrayHasKey('updatedAt', $data);
        $this->assertSame('', $data['updatedAt'], '从未保存过时 updatedAt 给空串，不伪造时间点');

        $this->assertCount(2, $data['venues']);
        $this->assertSame(['绿地店', '东部店'], array_column($data['venues'], 'key'));

        $green = $data['venues'][0];
        $east = $data['venues'][1];

        // 与 guide.vue 的 GUIDE_VENUES 逐字一致
        $this->assertSame('绿地缤纷城 · 4 楼', $green['sub']);
        $this->assertSame('宁波市江北区绿地缤纷城1号楼4楼', $green['address']);
        $this->assertSame('0574-87214863', $green['phone']);
        $this->assertSame('江东 · 场馆', $east['sub']);
        $this->assertSame('', $east['address'], '东部店档案里没有地址，默认必须是空串（页面自己降级）');
        $this->assertSame('', $east['phone'], '东部店电话同理，空串时前端不渲染拨号按钮');

        // 占位坐标必须原样保留：小程序 PLACEHOLDER_COORDS 用这对数字判定「不导航」
        $this->assertSame(29.8746, $green['latitude']);
        $this->assertSame(121.5496, $green['longitude']);
        $this->assertSame(29.8746, $east['latitude']);
        $this->assertSame(121.5496, $east['longitude']);

        // 图文引导：1 张，src 是站内路径
        $this->assertCount(1, $green['images']);
        $this->assertSame('/static/hero.jpg', $green['images'][0]['src']);
        $this->assertNotSame('', $green['images'][0]['caption']);

        // 每家店的键集合固定（前端零判空）
        foreach ($data['venues'] as $venue) {
            $this->assertSame(
                // v2（t32）在 images 之后追加 videoUrl / routes；t50 又在 key 之后插入 heroImage（门店顶图）
                ['key', 'heroImage', 'sub', 'address', 'phone', 'latitude', 'longitude', 'channelsUser',
                    'channelsLive', 'images', 'videoUrl', 'routes'],
                array_keys($venue),
                '门店对象的键（含顺序）必须与契约一致'
            );
        }

        // 读路径不写库：GET 之后仍然没有 app_settings 行
        $this->assertSame(0, AppSetting::query()->count(), 'GET 不得创建配置行');
    }

    /** 列存了 null（不是没行）时同样回落默认值。 */
    public function test_get_falls_back_when_column_is_null(): void
    {
        AppSetting::create(['miniapp_config' => null]);

        $data = $this->getJson('/api/miniapp/config')->assertOk()->json('data');

        $this->assertSame(['绿地店', '东部店'], array_column($data['venues'], 'key'));
        $this->assertSame(29.8746, $data['venues'][0]['latitude']);
        $this->assertSame('', $data['updatedAt']);
    }

    // ============================================ 2. PUT：权限

    public function test_put_requires_super(): void
    {
        // 未登录
        $this->putJson('/api/miniapp/config', ['venues' => []])->assertStatus(401);

        // 店长也不行（requireSuper 只认 R_SUPER，与备份/薪酬入口同口径）
        Sanctum::actingAs($this->user('mc-manager', '店长甲', 'R_MANAGER', '绿地店'));
        $this->putJson('/api/miniapp/config', ['venues' => []])->assertStatus(403);

        // 服务老师更不行
        Sanctum::actingAs($this->user('mc-teacher', '老师乙', 'R_SERVICE', '绿地店'));
        $this->putJson('/api/miniapp/config', ['venues' => []])->assertStatus(403);
    }

    // ============================================ 3. PUT：写入后立即生效 + 留痕

    public function test_super_update_is_visible_immediately_and_audited(): void
    {
        Sanctum::actingAs($this->user('mc-super', '馆长', 'R_SUPER', null));

        $resp = $this->putJson('/api/miniapp/config', [
            'venues' => [
                [
                    'key' => '绿地店',
                    'sub' => '绿地缤纷城 · 4 楼（新）',
                    'address' => '宁波市江北区绿地缤纷城2号楼4楼',
                    'phone' => '0574-00000000',
                    'latitude' => 29.88,
                    'longitude' => 121.56,
                    'channelsUser' => 'sphGreen',
                    'channelsLive' => 'feed-1',
                    'images' => [
                        ['src' => '/static/hero.jpg', 'caption' => '电梯到 4 楼'],
                        ['src' => 'https://cdn.example.com/a.png', 'caption' => '门头'],
                    ],
                ],
                [
                    'key' => '东部店',
                    'sub' => '江东 · 场馆',
                    'address' => '',
                    'phone' => '',
                    'latitude' => null,
                    'longitude' => null,
                    'channelsUser' => '',
                    'channelsLive' => '',
                    'images' => [],
                ],
            ],
        ])->assertOk();

        // PUT 成功后直接返回新结构（前端不必再 GET 一次）
        $this->assertSame('宁波市江北区绿地缤纷城2号楼4楼', $resp->json('data.venues.0.address'));
        $this->assertNotSame('', $resp->json('data.updatedAt'));

        // 免登录 GET 立即反映（同一进程内立刻可读，走的是同一份 DB）
        $data = $this->getJson('/api/miniapp/config')->assertOk()->json('data');
        $green = $data['venues'][0];
        $east = $data['venues'][1];

        $this->assertSame('宁波市江北区绿地缤纷城2号楼4楼', $green['address']);
        $this->assertSame('0574-00000000', $green['phone']);
        $this->assertSame(29.88, $green['latitude']);
        $this->assertSame(121.56, $green['longitude']);
        $this->assertSame('sphGreen', $green['channelsUser']);
        $this->assertSame('feed-1', $green['channelsLive']);
        $this->assertCount(2, $green['images']);
        $this->assertSame('https://cdn.example.com/a.png', $green['images'][1]['src']);

        // null 坐标 → 落 null（不是 0，也不是占位值）
        $this->assertNull($east['latitude']);
        $this->assertNull($east['longitude']);
        $this->assertSame('', $east['address']);

        // 审计留痕
        $log = AuditLog::query()->where('module', '小程序设置')->latest('id')->first();
        $this->assertNotNull($log, 'PUT 必须写审计留痕');
        $this->assertSame('修改', $log->action);
        $this->assertSame('0', (string) $log->target_id, '全局单例没有行 id，target_id 用 0');
    }

    /** 部分字段提交时，未提交的字段保留原值（不会把已配的图文冲回默认）。 */
    public function test_partial_update_keeps_untouched_fields(): void
    {
        Sanctum::actingAs($this->user('mc-super2', '馆长2', 'R_SUPER', null));

        // 先存一版：只改绿地店地址
        $this->putJson('/api/miniapp/config', [
            'venues' => [
                ['key' => '绿地店', 'address' => '新地址'],
                ['key' => '东部店'],
            ],
        ])->assertOk();

        $green = $this->getJson('/api/miniapp/config')->json('data.venues.0');
        $this->assertSame('新地址', $green['address']);
        // 未提交的字段保留默认值（不是空、不是被清空）
        $this->assertSame('0574-87214863', $green['phone']);
        $this->assertCount(1, $green['images'], '未提交 images 时不得清空');
    }

    // ============================================ 4. PUT：约束（422）

    public function test_put_rejects_adding_or_removing_venues(): void
    {
        Sanctum::actingAs($this->user('mc-super3', '馆长3', 'R_SUPER', null));

        // 3 家店（新增门店）
        $three = $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店'], ['key' => '东部店'], ['key' => '绿地店'],
        ]])->assertStatus(422);
        $this->assertStringContainsString('门店数量必须是 2 家', (string) $three->json('message'));

        // 改 key（改名/新增）
        $renamed = $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店'], ['key' => '新店'],
        ]])->assertStatus(422);
        $this->assertStringContainsString('只能是「绿地店」或「东部店」', (string) $renamed->json('message'));

        // 只给 1 家（删除门店）
        $one = $this->putJson('/api/miniapp/config', ['venues' => [['key' => '绿地店']]])->assertStatus(422);
        $this->assertStringContainsString('门店数量必须是 2 家', (string) $one->json('message'));

        // 两家都写绿地店（重复）
        $dup = $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店'], ['key' => '绿地店'],
        ]])->assertStatus(422);
        $this->assertStringContainsString('门店键', (string) $dup->json('message'));
    }

    public function test_put_rejects_out_of_range_coordinates(): void
    {
        Sanctum::actingAs($this->user('mc-super4', '馆长4', 'R_SUPER', null));

        $lat = $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店', 'latitude' => 99.1],
            ['key' => '东部店'],
        ]])->assertStatus(422);
        $this->assertStringContainsString('纬度超出合理范围', (string) $lat->json('message'));

        $lng = $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店'],
            ['key' => '东部店', 'longitude' => 10.5],
        ]])->assertStatus(422);
        $this->assertStringContainsString('经度超出合理范围', (string) $lng->json('message'));
    }

    public function test_put_rejects_too_many_images_and_unsafe_src(): void
    {
        Sanctum::actingAs($this->user('mc-super5', '馆长5', 'R_SUPER', null));

        // t32：上限从 3 提到 9 → 超限用例改成 10 张（4 张现在合法）
        $ten = $this->putJson('/api/miniapp/config', ['venues' => [
            ['key' => '绿地店', 'images' => array_fill(0, 10, ['src' => '/static/a.jpg', 'caption' => 'x'])],
            ['key' => '东部店'],
        ]])->assertStatus(422);
        $this->assertStringContainsString('最多 9 张', (string) $ten->json('message'));

        foreach (['javascript:alert(1)', '//evil.example.com/a.png', 'http://cdn.example.com/a.png'] as $bad) {
            $resp = $this->putJson('/api/miniapp/config', ['venues' => [
                ['key' => '绿地店', 'images' => [['src' => $bad, 'caption' => '']]],
                ['key' => '东部店'],
            ]])->assertStatus(422);
            $this->assertStringContainsString('站内路径', (string) $resp->json('message'), "{$bad} 必须被拒");
        }
    }

    // ============================================ 5. 主角色优先级

    /**
     * 馆主 2026-11 口径：同时是服务老师与授课老师时，**服务老师优先**。
     *
     * 这决定 `primaryRole()` 的返回，而 `primaryRole()` 会回写 `users.role` 单值列、
     * 并出现在审计日志的 `operator_role` 里 —— 是可见口径，不是内部细节。
     */
    public function test_service_role_precedes_teacher_in_primary_role(): void
    {
        $this->assertSame(
            ['R_SUPER', 'R_MANAGER', 'R_SERVICE', 'R_TEACHER', 'R_MEDIA'],
            ROLE_PRECEDENCE,
            '常量顺序即产品口径：服务老师排在授课老师之前'
        );

        $this->assertSame('R_SERVICE', primaryRole(['R_TEACHER', 'R_SERVICE']), '双身份时主角色必须是服务老师');
        $this->assertSame('R_SERVICE', primaryRole(['R_SERVICE', 'R_TEACHER']));
        $this->assertSame('R_MANAGER', primaryRole(['R_SERVICE', 'R_TEACHER', 'R_MANAGER']), '店长仍然压倒老师侧');
        $this->assertSame('R_SUPER', primaryRole(['R_SUPER', 'R_SERVICE']));
        $this->assertSame('R_TEACHER', primaryRole(['R_TEACHER']));
        $this->assertSame('', primaryRole([]));

        // 顺序不得影响「角色全集」判定（isKnownRoleUser 只做集合交集）
        $teacherOnly = $this->user('mc-t', '只有授课', 'R_TEACHER', '绿地店');
        $this->assertTrue(isKnownRoleUser($teacherOnly));
        $this->assertTrue(userHasAnyRole($teacherOnly, ROLE_PRECEDENCE));

        $unknown = User::factory()->create([
            'username' => 'mc-legacy', 'name' => '旧角色', 'role' => 'R_LEGACY',
            'roles' => ['R_LEGACY'], 'status' => '启用',
        ]);
        $this->assertFalse(isKnownRoleUser($unknown), '角色全集判定与顺序无关：旧角色码仍不被认作已知角色');
    }

    // ------------------------------------------------------------- 工具

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

    /** 默认值常量与 guide.vue 的一致性锚点（改常量时这条会提醒同步前端）。 */
    public function test_default_venues_constant_is_the_documented_shape(): void
    {
        $this->assertCount(2, MiniappConfigController::DEFAULT_VENUES);
        $this->assertSame(['绿地店', '东部店'], array_column(MiniappConfigController::DEFAULT_VENUES, 'key'));
        $this->assertSame(29.8746, MiniappConfigController::DEFAULT_VENUES[0]['latitude']);
        $this->assertSame(121.5496, MiniappConfigController::DEFAULT_VENUES[0]['longitude']);
    }
}
