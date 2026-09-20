<?php

namespace Tests\Feature;

use App\Http\Controllers\ShareController;
use App\Models\AuditLog;
use App\Models\PublishedShare;
use App\Models\TrainingPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 销售分享对客 H5（GET /public/sales/{token}）的信任根与权限边界。
 *
 * 修复前的形态：分享码是前端编译期常量 'yimai-lvdi'（可猜），公开接口只按
 * (type, token) 命中就整包下发，且表里没有 enabled 列 —— 「停用」既无从表达，
 * 未授权学员案例也会随 payload 原文出网。
 *
 * 本文件锁死的不只是「三个漏洞」，还有第二轮加固引入的边界：
 *  1. 分享码由服务端签发、**来源由 token_source 标记**（形似不等于可信）；
 *  2. enabled 显式真值判定，'false'/'off'/'no' 等一律 fail-closed；
 *  3. 字段白名单：未知顶层字段/案例字段不出网；
 *  4. 归属按 user_id 为准、姓名并集兼容；超管有停用通道；孤儿行可清理；
 *  5. 三个管理端点有显式角色判定（与前端 MGMT 对齐：R_SUPER / R_MANAGER）；
 *  6. type=training 的停用/查询明确 422（其状态在 training_plans，不在本表）。
 */
class PublicShareSalesTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $name = '张店长', string $role = 'R_MANAGER'): User
    {
        return User::factory()->create([
            'name' => $name,
            'username' => 'u-'.md5($name.$role),
            'role' => $role,
            'venue' => '绿地店',
            'venues' => ['绿地店'],
            'status' => '启用',
        ]);
    }

    private function salesPayload(): array
    {
        return [
            'share' => ['enabled' => true, 'code' => 'yimai-lvdi', 'views' => 0],
            'info' => ['name' => '一麦瑜伽（绿地店）', 'industry' => '瑜伽 · 普拉提'],
            'products' => [['id' => 1, 'name' => '精品白领年卡', 'showPrice' => true]],
            'coaches' => [['id' => 1, 'name' => '婷婷']],
            'cases' => [
                [
                    'id' => 1, 'coachId' => 1, 'goal' => '改善骨盆前倾',
                    'desc' => '授权案例正文', 'authorized' => true, 'stages' => [['duration' => '']],
                ],
                [
                    'id' => 2, 'coachId' => 2, 'goal' => '产后核心恢复',
                    'desc' => '未授权案例敏感原文：产后8个月，子宫恢复情况…',
                    'authorized' => false, 'stages' => [['duration' => '第6周']],
                ],
            ],
        ];
    }

    /** 发布销售分享，返回服务端权威 token */
    private function publishSales(User $user, ?array $payload = null): string
    {
        Sanctum::actingAs($user);
        $res = $this->postJson('/api/shares/publish', [
            'type' => 'sales',
            // 故意带上历史上那个可猜常量，断言它不是权威值
            'token' => 'yimai-lvdi',
            'payload' => $payload ?? $this->salesPayload(),
        ])->assertOk()->json('data');

        return (string) $res['token'];
    }

    /** 直接落一条可信的服务端签发记录（绕过 HTTP，用于构造特定 DB 状态） */
    private function seedTrusted(string $token, ?array $payload = null, array $overrides = []): PublishedShare
    {
        return PublishedShare::create(array_merge([
            'type' => 'sales',
            'token' => $token,
            'created_by' => '张店长',
            'payload' => $payload ?? $this->salesPayload(),
            'enabled' => true,
            'token_source' => 'server',
        ], $overrides));
    }

    // ============================================================
    // 1. 服务端签发不可猜测的 token（来源标记，非形态）
    // ============================================================

    public function test_publish_issues_server_token_and_ignores_client_constant(): void
    {
        $token = $this->publishSales($this->makeUser());

        $this->assertNotSame('yimai-lvdi', $token);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $token);
        $this->assertSame('server', PublishedShare::where('token', $token)->value('token_source'));

        $this->assertDatabaseMissing('published_shares', ['type' => 'sales', 'token' => 'yimai-lvdi']);
        $this->getJson("/api/public/sales/{$token}")->assertOk();
        $this->getJson('/api/public/sales/yimai-lvdi')->assertStatus(404);
    }

    public function test_issued_tokens_are_unique(): void
    {
        $tokens = [];
        for ($i = 0; $i < 8; $i++) {
            $tokens[] = ShareController::issueToken();
        }
        $this->assertCount(8, array_unique($tokens), '签发的分享码必须互不相同');
    }

    /**
     * 核心边界：形态像服务端码 ≠ 来源可信。
     *
     * 16hex 是可伪造的形状。若读侧只判形态，任何人写一条 16hex 的 token
     * 就能通过公开接口 —— 来源必须看服务端写入的 token_source。
     */
    public function test_token_shape_alone_is_not_trusted(): void
    {
        $lookalike = 'deadbeefdeadbeef'; // 合法 16hex，但不是服务端签发
        $this->seedTrusted($lookalike, null, ['token_source' => 'legacy']);

        $this->assertTrue(ShareController::looksLikeServerToken($lookalike), '形态判定应通过');
        $this->getJson("/api/public/sales/{$lookalike}")->assertStatus(404);
    }

    public function test_publish_reissues_legacy_token_but_keeps_server_token(): void
    {
        $user = $this->makeUser();

        // legacy 行（即便形态像服务端码）→ 换发
        $this->seedTrusted('deadbeefdeadbeef', null, ['created_by' => '张店长', 'token_source' => 'legacy']);
        $rotated = $this->publishSales($user);
        $this->assertNotSame('deadbeefdeadbeef', $rotated);
        $this->assertDatabaseMissing('published_shares', ['token' => 'deadbeefdeadbeef']);

        // server 行 → 沿用，避免已发出的二维码作废
        $again = $this->postJson('/api/shares/publish', [
            'type' => 'sales', 'payload' => $this->salesPayload(),
        ])->assertOk()->json('data.token');
        $this->assertSame($rotated, $again);
        $this->assertSame(1, PublishedShare::where('type', 'sales')->count());
    }

    public function test_management_endpoints_require_login(): void
    {
        $this->postJson('/api/shares/publish', ['type' => 'sales', 'payload' => $this->salesPayload()])
            ->assertStatus(401);
        $this->getJson('/api/shares/current?type=sales')->assertStatus(401);
        $this->postJson('/api/shares/disable', ['type' => 'sales'])->assertStatus(401);
    }

    // ============================================================
    // 2. enabled=false 停用生效（含显式真值 fail-closed）
    // ============================================================

    public function test_disabled_sales_share_returns_404(): void
    {
        $user = $this->makeUser();
        $token = $this->publishSales($user);
        $this->getJson("/api/public/sales/{$token}")->assertOk();

        $this->postJson('/api/shares/disable', ['type' => 'sales'])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $res = $this->getJson("/api/public/sales/{$token}");
        $res->assertStatus(404);
        $res->assertJsonPath('errno', 404);
        $res->assertJsonPath('emsg', '分享不存在或已停用');

        $this->assertSame(0, (int) PublishedShare::where('token', $token)->value('enabled'));

        // 重新开启可恢复
        $this->postJson('/api/shares/publish', ['type' => 'sales', 'payload' => $this->salesPayload()])
            ->assertOk();
        $this->getJson("/api/public/sales/{$token}")->assertOk();
    }

    /**
     * 参数化 fail-closed：一切非真值都必须 404。
     *
     * `(bool) 'false'` 在 PHP 里是 true —— 若读侧用 (bool) 或 Eloquent 的 boolean cast
     * （其实现就是 (bool)），这些写法会把「已停用」判成启用并继续对外下发。
     */
    public static function falsyEnabledValues(): array
    {
        return [
            'integer 0' => [0],
            'string 0' => ['0'],
            'string false' => ['false'],
            'string off' => ['off'],
            'string no' => ['no'],
            'empty string' => [''],
        ];
    }

    /** @dataProvider falsyEnabledValues */
    public function test_non_truthy_enabled_values_fail_closed(mixed $stored): void
    {
        $token = 'a1b2c3d4e5f60718';
        $this->seedTrusted($token);
        // 绕过 model 直接写列（模拟驱动差异/手工改库/脏数据）
        DB::table('published_shares')->where('token', $token)->update(['enabled' => $stored]);

        $this->getJson("/api/public/sales/{$token}")
            ->assertStatus(404)
            ->assertJsonPath('emsg', '分享不存在或已停用');
    }

    public static function truthyEnabledValues(): array
    {
        return [
            'integer 1' => [1],
            'string 1' => ['1'],
            'string true' => ['true'],
            'string on' => ['on'],
        ];
    }

    /** @dataProvider truthyEnabledValues */
    public function test_truthy_enabled_values_stay_visible(mixed $stored): void
    {
        $token = 'b1b2c3d4e5f60718';
        $this->seedTrusted($token);
        DB::table('published_shares')->where('token', $token)->update(['enabled' => $stored]);

        $this->getJson("/api/public/sales/{$token}")->assertOk();
    }

    // ============================================================
    // 3. 字段白名单 + 未授权案例过滤
    // ============================================================

    public function test_public_payload_only_contains_authorized_cases(): void
    {
        $token = $this->publishSales($this->makeUser());

        $res = $this->getJson("/api/public/sales/{$token}")->assertOk();
        $res->assertJsonCount(1, 'data.cases');
        $res->assertJsonPath('data.cases.0.id', 1);
        $res->assertJsonPath('data.cases.0.authorized', true);

        $body = $res->getContent();
        $this->assertStringNotContainsString('产后核心恢复', $body);
        $this->assertStringNotContainsString('子宫恢复情况', $body);
    }

    public function test_server_filters_unauthorized_cases_in_stored_snapshot(): void
    {
        $token = 'c1b2c3d4e5f60718';
        $this->seedTrusted($token);

        $res = $this->getJson("/api/public/sales/{$token}")->assertOk();
        $res->assertJsonCount(1, 'data.cases');
        $this->assertStringNotContainsString('未授权案例敏感原文', $res->getContent());
    }

    public function test_publish_strips_unauthorized_cases_and_extra_cases_fields(): void
    {
        $payload = $this->salesPayload();
        $payload['cases'][0]['internalNote'] = '内部备注：会员手机号 13800000000';
        $payload['cases'][0]['memberId'] = 998877;

        $token = $this->publishSales($this->makeUser(), $payload);
        $stored = PublishedShare::where('token', $token)->firstOrFail();

        $this->assertCount(1, $stored->payload['cases']);
        $this->assertArrayNotHasKey('internalNote', $stored->payload['cases'][0]);
        $this->assertArrayNotHasKey('memberId', $stored->payload['cases'][0]);
        $this->assertStringNotContainsString(
            '13800000000',
            (string) json_encode($stored->payload, JSON_UNESCAPED_UNICODE)
        );
    }

    /** 顶层白名单：未知字段一律丢弃（前端提交的是任意结构） */
    public function test_publish_drops_unknown_top_level_fields(): void
    {
        $payload = $this->salesPayload();
        $payload['internal'] = ['secret' => '内部备注不该出网'];
        $payload['memberPhones'] = ['13800000000'];

        $token = $this->publishSales($this->makeUser(), $payload);

        $res = $this->getJson("/api/public/sales/{$token}")->assertOk();
        $body = $res->getContent();
        $this->assertStringNotContainsString('内部备注不该出网', $body);
        $this->assertStringNotContainsString('13800000000', $body);
        $this->assertSame(
            ['share', 'info', 'products', 'coaches', 'cases'],
            array_keys($res->json('data')),
            '公开响应顶层字段必须只有白名单内的五个'
        );
    }

    /** 历史快照里夹带的未知顶层字段，下发时同样被丢弃 */
    public function test_public_response_drops_unknown_top_level_fields_from_stored_snapshot(): void
    {
        $payload = $this->salesPayload();
        $payload['internal'] = ['secret' => '历史脏字段'];
        $token = 'd1b2c3d4e5f60718';
        $this->seedTrusted($token, $payload);

        $res = $this->getJson("/api/public/sales/{$token}")->assertOk();
        $this->assertStringNotContainsString('历史脏字段', $res->getContent());
        $this->assertSame(['share', 'info', 'products', 'coaches', 'cases'], array_keys($res->json('data')));
    }

    // ============================================================
    // 4. share.code 与权威 token 一致（公开页可正常打开）
    // ============================================================

    public function test_payload_share_code_equals_authoritative_token(): void
    {
        $token = $this->publishSales($this->makeUser());

        $stored = PublishedShare::where('token', $token)->firstOrFail();
        $this->assertSame($token, $stored->payload['share']['code']);

        $this->getJson("/api/public/sales/{$token}")
            ->assertOk()
            ->assertJsonPath('data.share.code', $token)
            ->assertJsonPath('data.share.enabled', true)
            ->assertJsonPath('data.info.name', '一麦瑜伽（绿地店）');
    }

    public function test_unknown_token_returns_404(): void
    {
        $this->getJson('/api/public/sales/'.bin2hex(random_bytes(8)))
            ->assertStatus(404)
            ->assertJsonPath('emsg', '分享不存在或已停用');
    }

    // ============================================================
    // 5. views：首次固定 0，不接受客户端首值
    // ============================================================

    public function test_first_publish_ignores_client_supplied_views(): void
    {
        $payload = $this->salesPayload();
        $payload['share']['views'] = 99999;

        $token = $this->publishSales($this->makeUser(), $payload);
        $stored = PublishedShare::where('token', $token)->firstOrFail();

        $this->assertSame(0, (int) $stored->payload['share']['views'], '首次发布必须固定 0');
        $this->getJson("/api/public/sales/{$token}")->assertOk()->assertJsonPath('data.share.views', 0);
    }

    public function test_republish_keeps_stored_views_not_client_value(): void
    {
        $user = $this->makeUser();
        $token = $this->publishSales($user);

        // 模拟已有访问量
        $row = PublishedShare::where('token', $token)->firstOrFail();
        $payload = $row->payload;
        $payload['share']['views'] = 42;
        $row->update(['payload' => $payload]);

        $again = $this->salesPayload();
        $again['share']['views'] = 777;
        $this->postJson('/api/shares/publish', ['type' => 'sales', 'payload' => $again])->assertOk();

        $stored = PublishedShare::where('token', $token)->firstOrFail();
        $this->assertSame(42, (int) $stored->payload['share']['views'], '沿用库内值，不采信客户端');
    }

    // ============================================================
    // 6. 归属：user_id 为准、姓名并集兼容、超管通道、孤儿行
    // ============================================================

    public function test_ownership_follows_user_id_even_after_rename(): void
    {
        $user = $this->makeUser('张店长');
        $token = $this->publishSales($user);

        // 账号改名（姓名列变、id 不变）：id 为准，本人仍能看到自己的分享。
        // 用 DB 直改而不是 $user->update()：helpers.php 的 staffNames() 会把
        // staffNamesCache 挂成模型的动态属性，一旦在同一个实例上 save()，
        // Eloquent 会尝试把它当列写入（no such column: staffNamesCache）。
        // 那属于 helpers.php 的既有缺陷（不在本任务 inScope），这里只关心改名后的归属口径。
        DB::table('users')->where('id', $user->id)->update(['name' => '张店长（新）']);

        Sanctum::actingAs(User::findOrFail($user->id));
        $this->getJson('/api/shares/current?type=sales')
            ->assertOk()
            ->assertJsonPath('data.token', $token)
            ->assertJsonPath('data.enabled', true);
    }

    public function test_ownership_name_union_still_works_for_legacy_rows_without_user_id(): void
    {
        // 历史行：只有姓名、没有 created_by_user_id（姓名并集这一路必须兜住）
        $this->seedTrusted('e1b2c3d4e5f60718', null, ['created_by' => '李店长']);

        Sanctum::actingAs($this->makeUser('李店长'));
        $this->getJson('/api/shares/current?type=sales')
            ->assertOk()
            ->assertJsonPath('data.token', 'e1b2c3d4e5f60718');
    }

    public function test_disable_only_affects_own_shares_and_super_can_disable_any(): void
    {
        $token = $this->publishSales($this->makeUser('张店长'));

        // 他人（非超管）停用：影响 0 条，记录仍可见
        Sanctum::actingAs($this->makeUser('李店长'));
        $this->postJson('/api/shares/disable', ['type' => 'sales'])
            ->assertOk()
            ->assertJsonPath('data.affected', 0);
        $this->assertSame(1, (int) PublishedShare::where('token', $token)->value('enabled'));
        $this->getJson("/api/public/sales/{$token}")->assertOk();

        // 超管通道：可停用任意记录
        Sanctum::actingAs($this->makeUser('老板', 'R_SUPER'));
        $this->postJson('/api/shares/disable', ['type' => 'sales'])
            ->assertOk()
            ->assertJsonPath('data.affected', 1);
        $this->getJson("/api/public/sales/{$token}")->assertStatus(404);
    }

    /** 运维入口：按 token 精确停用单条（处置具体泄漏链接） */
    public function test_disable_by_token_stops_that_single_link(): void
    {
        $user = $this->makeUser('张店长');
        $token = $this->publishSales($user);

        Sanctum::actingAs($user);
        $this->postJson('/api/shares/disable', ['type' => 'sales', 'token' => $token])
            ->assertOk()
            ->assertJsonPath('data.affected', 1);
        $this->getJson("/api/public/sales/{$token}")->assertStatus(404);
    }

    public function test_orphan_shares_are_listed_and_repairable_by_super(): void
    {
        $token = 'f1b2c3d4e5f60718';
        $this->seedTrusted($token, null, ['created_by' => '早已注销的人']);

        $super = $this->makeUser('老板', 'R_SUPER');
        Sanctum::actingAs($super);

        $list = $this->getJson('/api/shares/orphans')->assertOk()->json('data.records');
        $this->assertNotEmpty($list);
        $this->assertSame('早已注销的人', $list[0]['created_by']);
        $this->assertSame('姓名查无此人', $list[0]['reason']);

        // 重新归属
        $this->postJson('/api/shares/orphans/repair', [
            'id' => $list[0]['id'], 'mode' => 'reassign', 'userId' => $super->id,
        ])->assertOk();

        $row = PublishedShare::where('token', $token)->firstOrFail();
        $this->assertSame('老板', $row->created_by);
        $this->assertSame($super->id, (int) $row->created_by_user_id);
    }

    public function test_orphan_endpoints_are_super_only(): void
    {
        Sanctum::actingAs($this->makeUser('张店长'));
        $this->getJson('/api/shares/orphans')->assertStatus(403);
        $this->postJson('/api/shares/orphans/repair', ['id' => 1, 'mode' => 'disable'])
            ->assertStatus(403);
    }

    // ============================================================
    // 7. 显式角色判定（与前端 MGMT 对齐）
    // ============================================================

    public function test_share_management_requires_mgmt_role(): void
    {
        // 服务老师不在 MGMT（前端菜单 roles=[R_SUPER,R_MANAGER]），后端必须一致
        Sanctum::actingAs($this->makeUser('王老师', 'R_SERVICE'));

        $this->postJson('/api/shares/publish', ['type' => 'sales', 'payload' => $this->salesPayload()])
            ->assertStatus(403);
        $this->getJson('/api/shares/current?type=sales')->assertStatus(403);
        $this->postJson('/api/shares/disable', ['type' => 'sales'])->assertStatus(403);
    }

    public function test_super_and_manager_can_manage_shares(): void
    {
        foreach ([['老板', 'R_SUPER'], ['张店长', 'R_MANAGER']] as [$name, $role]) {
            $user = $this->makeUser($name, $role);
            Sanctum::actingAs($user);
            $this->getJson('/api/shares/current?type=sales')->assertOk();
            $this->postJson('/api/shares/publish', ['type' => 'sales', 'payload' => $this->salesPayload()])
                ->assertOk();
        }
    }

    // ============================================================
    // 8. 审计：授权状态与快照摘要可事后追责
    // ============================================================

    public function test_publish_writes_audit_with_authorization_summary(): void
    {
        $this->publishSales($this->makeUser('张店长'));

        $log = AuditLog::where('module', 'H5分享')->orderByDesc('id')->first();
        $this->assertNotNull($log, '发布必须留审计');
        $this->assertSame('发布', $log->action);
        // 摘要含条数与授权数，便于事后回答「发出去了什么」；但不含案例原文。
        // 提交 2 条、实际发布 1 条 —— 摘要同时记这两个数，过滤发生时才可事后核对。
        $this->assertStringContainsString('快照摘要', (string) $log->detail);
        $this->assertStringContainsString('案例2条', (string) $log->detail);
        $this->assertStringContainsString('已授权1', (string) $log->detail);
        $this->assertStringNotContainsString('子宫恢复情况', (string) $log->detail);
    }

    // ============================================================
    // 9. type=training 既有行为不受影响；disable/current 明确 422
    // ============================================================

    public function test_training_publish_and_public_read_unchanged(): void
    {
        $plan = TrainingPlan::create([
            'member_name' => '训练学员', 'status' => '已确认', 'created_by' => '王教练',
            'share' => ['enabled' => true, 'code' => 'train-code-1', 'views' => 0],
            'payload' => ['memberName' => '训练学员', 'status' => '已确认'],
        ]);

        Sanctum::actingAs($this->makeUser('张店长'));
        $this->postJson('/api/shares/publish', [
            'type' => 'training', 'token' => 'train-code-1',
            'payload' => ['memberName' => '训练学员'],
        ])->assertOk()->assertJsonPath('data.token', 'train-code-1');

        $this->getJson("/api/public/training/{$plan->share['code']}")
            ->assertOk()
            ->assertJsonPath('data.share.code', 'train-code-1');
    }

    public function test_training_public_read_still_requires_enabled_and_confirmed(): void
    {
        $plan = TrainingPlan::create([
            'member_name' => '未确认学员', 'status' => '待老师确认', 'created_by' => '王教练',
            'share' => ['enabled' => true, 'code' => 'train-code-2', 'views' => 0],
        ]);
        $this->getJson("/api/public/training/{$plan->share['code']}")->assertStatus(404);

        $plan->update(['status' => '已确认', 'share' => ['enabled' => false, 'code' => 'train-code-2', 'views' => 0]]);
        $this->getJson('/api/public/training/train-code-2')->assertStatus(404);
    }

    /**
     * disable/current 对 training 明确 422。
     *
     * 训练分享状态存在 training_plans.share（JSON），与 published_shares 是两套存储。
     * 此前 disable(type=training) 也去改 published_shares，返回 affected=0 却报
     * enabled=false —— 前端据「成功」显示已停用，而训练分享其实还开着，返回值与事实不一致。
     */
    public function test_training_disable_and_current_are_explicitly_rejected(): void
    {
        Sanctum::actingAs($this->makeUser('张店长'));

        $this->postJson('/api/shares/disable', ['type' => 'training'])
            ->assertStatus(422)
            ->assertJsonPath('errno', 422);
        $this->getJson('/api/shares/current?type=training')
            ->assertStatus(422)
            ->assertJsonPath('errno', 422);
    }

    public function test_training_publish_still_rejects_invalid_token_and_requires_it(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->postJson('/api/shares/publish', ['type' => 'training', 'payload' => ['memberName' => 'x']])
            ->assertStatus(422);
        $this->postJson('/api/shares/publish', [
            'type' => 'training', 'token' => 'bad token!', 'payload' => ['memberName' => 'x'],
        ])->assertStatus(422);
    }

    // ============================================================
    // 10. 迁移窗口兜底：enabled / token_source 列缺失时的行为
    // ============================================================

    /**
     * enabled 列缺失时：读侧按启用处理（等价修复前语义，避免既有链接全 404），
     * disable 返回明确 503 而非 500。
     *
     * 场景来源：update.sh 先 rsync 覆盖代码、再执行 migrate，中间存在「新代码 + 旧表结构」
     * 的真实窗口。这段兜底就是为了让那个窗口里的行为可预期。
     */
    public function test_missing_enabled_column_degrades_safely(): void
    {
        $token = 'a1a2c3d4e5f60718';
        $this->seedTrusted($token);

        Schema::table('published_shares', function ($t) {
            $t->dropColumn('enabled');
        });

        // 读侧：列缺失 → 视为启用（不因结构未就绪而让链接集体失效）
        $this->getJson("/api/public/sales/{$token}")->assertOk();

        // 停用侧：明确 503，而不是抛「列不存在」的 500
        Sanctum::actingAs($this->makeUser('张店长'));
        $this->postJson('/api/shares/disable', ['type' => 'sales'])
            ->assertStatus(503)
            ->assertJsonPath('errno', 503);

        // 发布侧：仍可发布，只是不写该列（不抛错）
        $this->postJson('/api/shares/publish', ['type' => 'sales', 'payload' => $this->salesPayload()])
            ->assertOk();
    }

    /**
     * token_source 列缺失时：保守判为来源不可信（宁可贵重新发布一次，
     * 也不下发来源不明的码）。
     */
    public function test_missing_token_source_column_is_conservative(): void
    {
        $token = 'b1a2c3d4e5f60718';
        $this->seedTrusted($token);

        Schema::table('published_shares', function ($t) {
            $t->dropColumn('token_source');
        });

        $this->getJson("/api/public/sales/{$token}")->assertStatus(404);
    }
}
