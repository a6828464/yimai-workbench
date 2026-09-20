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
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** 按姓名取已建账号的 id（多数用例里用户是先经 makeUser 建的） */
    private function managerId(string $name): int
    {
        return (int) User::where('name', $name)->orderBy('id')->value('id');
    }

    /**
     * 断言公开响应里不含某段文本 —— 对**解码后**的结构断言。
     *
     * 直接对 `$res->getContent()` 搜中文是**空洞断言**：Laravel 的 JSON 响应把中文
     * 转义成 `\uXXXX`，于是连确实返回了的内容也搜不到，测试永远绿而零判别力。
     * 这里先把 data 解码回 PHP 值再以 JSON_UNESCAPED_UNICODE 重新编码，中文保持原样。
     *
     * @param  \Illuminate\Testing\TestResponse  $res
     */
    private function assertResponseLacks($res, string $needle, string $message = ''): void
    {
        $decoded = json_encode($res->json('data'), JSON_UNESCAPED_UNICODE);
        $this->assertIsString($decoded);
        $this->assertStringNotContainsString(
            $needle,
            $decoded,
            $message !== '' ? $message : "公开响应（解码后）不应包含：{$needle}"
        );
    }

    /** 同上的正向版本：解码后**必须**包含（用于证明断言本身有判别力） */
    private function assertResponseContains($res, string $needle, string $message = ''): void
    {
        $decoded = json_encode($res->json('data'), JSON_UNESCAPED_UNICODE);
        $this->assertIsString($decoded);
        $this->assertStringContainsString(
            $needle,
            $decoded,
            $message !== '' ? $message : "公开响应（解码后）应包含：{$needle}"
        );
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

    #[DataProvider('falsyEnabledValues')]
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

    #[DataProvider('truthyEnabledValues')]
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

        // 解码后断言（直接搜原始响应体是空洞断言：中文被转义成 \uXXXX）
        $this->assertResponseLacks($res, '产后核心恢复');
        $this->assertResponseLacks($res, '子宫恢复情况');
        // 证明断言有判别力：授权案例的文案确实在响应里
        $this->assertResponseContains($res, '改善骨盆前倾');
    }

    public function test_server_filters_unauthorized_cases_in_stored_snapshot(): void
    {
        $token = 'c1b2c3d4e5f60718';
        $this->seedTrusted($token);

        $res = $this->getJson("/api/public/sales/{$token}")->assertOk();
        $res->assertJsonCount(1, 'data.cases');
        $this->assertResponseLacks($res, '未授权案例敏感原文');
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
        $this->assertArrayNotHasKey('internalNote', $stored->payload['cases'][0], '案例内层字段必须被白名单挡住');
    }

    /** 顶层白名单：未知字段一律丢弃（前端提交的是任意结构） */
    public function test_publish_drops_unknown_top_level_fields(): void
    {
        $payload = $this->salesPayload();
        $payload['internal'] = ['secret' => '内部备注不该出网'];
        $payload['memberPhones'] = ['13800000000'];

        $token = $this->publishSales($this->makeUser(), $payload);

        $res = $this->getJson("/api/public/sales/{$token}")->assertOk();
        $this->assertResponseLacks($res, '内部备注不该出网');
        $this->assertResponseLacks($res, '13800000000');
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
        $this->assertResponseLacks($res, '历史脏字段');
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

    /**
     * 超管跨归属停用必须显式指定目标（token 或 ownerUserId）。
     *
     * 上一轮 disable 对超管不加归属条件 → 超管在页面上关一次开关（前端不传 token）
     * 会一次性停用全库所有门店的分享。现在无目标时只按本人归属处理，
     * 且本人无记录时返回可读 422，而不是静默 200 / 全库命中。
     */
    public function test_super_disable_without_explicit_target_only_touches_own_rows(): void
    {
        // 两家门店各有链接（上一轮用例只有 1 行数据，assertJsonPath('data.affected',1) 无判别力）
        $tokenA = $this->publishSales($this->makeUser('甲店长', 'R_MANAGER'));
        $tokenB = $this->publishSales($this->makeUser('乙店长', 'R_MANAGER'));

        $super = $this->makeUser('老板', 'R_SUPER');
        $ownToken = $this->publishSales($super);

        Sanctum::actingAs($super);
        $res = $this->postJson('/api/shares/disable', ['type' => 'sales'])->assertOk();
        // 只停了自己那一条
        $this->assertSame(1, (int) $res->json('data.affected'), '超管不带目标时只应停用本人记录');

        $this->getJson("/api/public/sales/{$ownToken}")->assertStatus(404);
        $this->getJson("/api/public/sales/{$tokenA}")->assertOk();
        $this->getJson("/api/public/sales/{$tokenB}")->assertOk();
    }

    /** 超管本人没有记录时，不带目标的 disable 返回可读 422（而不是静默 200） */
    public function test_super_disable_without_target_and_without_own_row_returns_readable_error(): void
    {
        $tokenA = $this->publishSales($this->makeUser('甲店长', 'R_MANAGER'));

        Sanctum::actingAs($this->makeUser('老板', 'R_SUPER'));
        $res = $this->postJson('/api/shares/disable', ['type' => 'sales']);
        $res->assertStatus(422);
        $this->assertStringContainsString('token', (string) $res->json('emsg'));

        // 他人记录不受影响
        $this->getJson("/api/public/sales/{$tokenA}")->assertOk();
    }

    /** 超管指定 token 可跨归属停用（保留运维兜底能力） */
    public function test_super_can_disable_other_by_explicit_token(): void
    {
        $tokenA = $this->publishSales($this->makeUser('甲店长', 'R_MANAGER'));

        Sanctum::actingAs($this->makeUser('老板', 'R_SUPER'));
        $this->postJson('/api/shares/disable', ['type' => 'sales', 'token' => $tokenA])
            ->assertOk()
            ->assertJsonPath('data.affected', 1);
        $this->getJson("/api/public/sales/{$tokenA}")->assertStatus(404);
    }

    /** 超管指定 ownerUserId 可停用某人的全部分享 */
    public function test_super_can_disable_other_by_explicit_owner_user_id(): void
    {
        $other = $this->makeUser('甲店长', 'R_MANAGER');
        $tokenA = $this->publishSales($other);

        Sanctum::actingAs($this->makeUser('老板', 'R_SUPER'));
        $this->postJson('/api/shares/disable', ['type' => 'sales', 'ownerUserId' => $other->id])
            ->assertOk()
            ->assertJsonPath('data.affected', 1);
        $this->getJson("/api/public/sales/{$tokenA}")->assertStatus(404);
    }

    /** 非超管带他人目标：明确 403/422，而不是静默按自己处理 */
    public function test_non_super_cannot_target_other_owner(): void
    {
        $tokenA = $this->publishSales($this->makeUser('甲店长', 'R_MANAGER'));
        $other = $this->makeUser('乙店长', 'R_MANAGER');

        Sanctum::actingAs($other);
        $this->postJson('/api/shares/disable', ['type' => 'sales', 'token' => $tokenA])
            ->assertStatus(403);
        $this->postJson('/api/shares/disable', ['type' => 'sales', 'ownerUserId' => 1])
            ->assertStatus(403);

        $this->getJson("/api/public/sales/{$tokenA}")->assertOk();
    }

    /**
     * t23-01 核心回归：店长先发布、超管后发布。
     *
     * 上一轮 publishSales 用「读可见范围」选址（超管不过滤）→ 超管的发布命中
     * 店长最新那条并 update() 改写：复用店长 token、覆盖内容、改归属，
     * 店长既看不到也停不掉自己已发出的链接。现在写路径只按本人选址。
     */
    public function test_super_publish_does_not_steal_manager_row(): void
    {
        $mgr = $this->makeUser('甲店长', 'R_MANAGER');
        $mgrPayload = $this->salesPayload();
        $mgrPayload['info']['name'] = '甲店内容';
        $mgrToken = $this->publishSales($mgr, $mgrPayload);

        // 超管发布自己的（不带 ownerUserId）
        $super = $this->makeUser('老板', 'R_SUPER');
        $superPayload = $this->salesPayload();
        $superPayload['info']['name'] = '超管内容';
        Sanctum::actingAs($super);
        $superToken = (string) $this->postJson('/api/shares/publish', [
            'type' => 'sales', 'payload' => $superPayload,
        ])->assertOk()->json('data.token');

        // 各占一行，互不夺走
        $this->assertNotSame($mgrToken, $superToken);
        $this->assertSame(2, PublishedShare::where('type', 'sales')->count(), '店长与超管应各占一行');

        $mgrRow = PublishedShare::where('token', $mgrToken)->firstOrFail();
        $superRow = PublishedShare::where('token', $superToken)->firstOrFail();
        $this->assertSame($mgr->id, (int) $mgrRow->created_by_user_id);
        $this->assertSame($super->id, (int) $superRow->created_by_user_id);

        // 店长链接内容不变
        $this->getJson("/api/public/sales/{$mgrToken}")
            ->assertOk()
            ->assertJsonPath('data.info.name', '甲店内容');

        // 店长 current 仍是自己的码，且能停用自己那条
        Sanctum::actingAs($mgr);
        $this->getJson('/api/shares/current?type=sales')
            ->assertOk()
            ->assertJsonPath('data.token', $mgrToken)
            ->assertJsonPath('data.viewingOther', false);
        $this->postJson('/api/shares/disable', ['type' => 'sales'])
            ->assertOk()
            ->assertJsonPath('data.affected', 1);
        $this->getJson("/api/public/sales/{$mgrToken}")->assertStatus(404);
        // 超管的链接不受影响
        $this->getJson("/api/public/sales/{$superToken}")->assertOk();
    }

    /**
     * 超管 current 默认只回本人（不得把他人 token 当自己的码）。
     *
     * 上一轮 current 用读可见范围查询 → 超管拿到全表最新一条（常是他人行），
     * 前端 onMounted 会把它 setShareCode 落本地并预览 —— 超管看到的、预览的都是别人的链接。
     */
    public function test_super_current_returns_own_row_not_latest_other(): void
    {
        $mgrToken = $this->publishSales($this->makeUser('甲店长', 'R_MANAGER'));

        $super = $this->makeUser('老板', 'R_SUPER');
        Sanctum::actingAs($super);

        // 超管自己还没发布过 → 应为空，而不是店长的码
        $this->getJson('/api/shares/current?type=sales')
            ->assertOk()
            ->assertJsonPath('data.token', null)
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.viewingOther', false);

        // 超管显式查看他人：明确标注 viewingOther + ownerName
        $this->getJson('/api/shares/current?type=sales&ownerUserId='.$this->managerId('甲店长'))
            ->assertOk()
            ->assertJsonPath('data.token', $mgrToken)
            ->assertJsonPath('data.viewingOther', true)
            ->assertJsonPath('data.ownerName', '甲店长');
    }

    /** 非超管不能借 ownerUserId 看他人 */
    public function test_non_super_cannot_view_other_via_owner_user_id(): void
    {
        $this->publishSales($this->makeUser('甲店长', 'R_MANAGER'));

        Sanctum::actingAs($this->makeUser('乙店长', 'R_MANAGER'));
        $this->getJson('/api/shares/current?type=sales&ownerUserId='.$this->managerId('甲店长'))
            ->assertStatus(403);
    }

    /** 超管可显式替某人发布：归属仍记在被替者名下，而不是超管自己 */
    public function test_super_can_publish_on_behalf_of_other_with_explicit_target(): void
    {
        $mgr = $this->makeUser('甲店长', 'R_MANAGER');

        Sanctum::actingAs($this->makeUser('老板', 'R_SUPER'));
        $token = (string) $this->postJson('/api/shares/publish', [
            'type' => 'sales', 'payload' => $this->salesPayload(), 'ownerUserId' => $mgr->id,
        ])->assertOk()->json('data.token');

        $row = PublishedShare::where('token', $token)->firstOrFail();
        $this->assertSame($mgr->id, (int) $row->created_by_user_id, '代发后归属应记在被替者名下');
        $this->assertSame('甲店长', $row->created_by);

        // 被替者自己能停用它
        Sanctum::actingAs($mgr);
        $this->postJson('/api/shares/disable', ['type' => 'sales'])
            ->assertOk()
            ->assertJsonPath('data.affected', 1);
    }

    /** 非超管不能替他人发布 */
    public function test_non_super_cannot_publish_for_other(): void
    {
        $other = $this->makeUser('甲店长', 'R_MANAGER');
        Sanctum::actingAs($this->makeUser('乙店长', 'R_MANAGER'));

        $this->postJson('/api/shares/publish', [
            'type' => 'sales', 'payload' => $this->salesPayload(), 'ownerUserId' => $other->id,
        ])->assertStatus(403);
    }

    public function test_publish_on_behalf_of_unknown_user_is_rejected(): void
    {
        Sanctum::actingAs($this->makeUser('老板', 'R_SUPER'));
        $this->postJson('/api/shares/publish', [
            'type' => 'sales', 'payload' => $this->salesPayload(), 'ownerUserId' => 999999,
        ])->assertStatus(422);
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
        $this->assertStringContainsString('发布者声明已授权1', (string) $log->detail);
        // 「授权」的语义必须写明是操作者声明，而不是被当成核验结论
        $this->assertStringContainsString(
            ShareController::SALES_AUTHORIZATION_SOURCE,
            (string) $log->detail,
            '审计必须标注授权标记的来源（当前只有操作者声明这一档）'
        );
        $this->assertStringNotContainsString('子宫恢复情况', (string) $log->detail);
        // 正面断言：摘要确实写进了交付记录（否则上面的「不含」可能是日志根本没写）
        $this->assertStringContainsString('H5分享', (string) $log->module);
    }

    /**
     * 授权标记目前只是「操作者声明」：客户端把 authorized 标成 true，
     * 服务端就会放行 —— 这不是缺陷，而是必须如实标注的边界。
     * 本用例把该语义钉住，避免后来者以为服务端已核验过授权。
     */
    public function test_authorization_is_operator_declared_not_server_verified(): void
    {
        $this->assertSame('operator_declared', ShareController::SALES_AUTHORIZATION_SOURCE);

        // 无任何服务端授权凭据，仅凭请求体布尔即可发布（记录在案的事实）
        $payload = $this->salesPayload();
        $payload['cases'] = [[
            'id' => 99, 'goal' => '仅凭客户端声明', 'desc' => '无服务端授权凭据',
            'authorized' => true, 'stages' => [['duration' => '第1周']],
        ]];
        $token = $this->publishSales($this->makeUser(), $payload);

        $this->getJson("/api/public/sales/{$token}")
            ->assertOk()
            ->assertJsonCount(1, 'data.cases')
            ->assertJsonPath('data.cases.0.authorized', true);
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

    // ============================================================
    // 11. 同名账号：写路径不按姓名选址（t23-03）
    // ============================================================

    /**
     * 两个同名账号各自发布：互不夺走、互不改写归属、互不停用。
     *
     * 上一轮写路径用 `staffOwnerFilter`（id **OR** 姓名/别名并集）选址，
     * 姓名这一路让同名者命中对方的历史行 → B 发布后复用 A 的码、把归属从 A 改写成 B、
     * A 的链接内容变成 B 的；A 又凭姓名并集能停同一行。
     * 现在写路径只认 created_by_user_id 单键，姓名并集只留在读侧。
     */
    public function test_same_name_accounts_publish_without_stealing_each_other(): void
    {
        // 同名、不同 id、不同门店（经工厂创建，等价于线上两个同名账号）
        $a = User::factory()->create([
            'name' => '张三', 'username' => 'same-a', 'role' => 'R_MANAGER',
            'venue' => '绿地店', 'venues' => ['绿地店'], 'status' => '启用',
        ]);
        $b = User::factory()->create([
            'name' => '张三', 'username' => 'same-b', 'role' => 'R_MANAGER',
            'venue' => '东部店', 'venues' => ['东部店'], 'status' => '启用',
        ]);

        $payloadA = $this->salesPayload();
        $payloadA['info']['name'] = '绿地店内容';
        $tokenA = $this->publishSales($a, $payloadA);

        $payloadB = $this->salesPayload();
        $payloadB['info']['name'] = '东部店内容';
        $tokenB = $this->publishSales($b, $payloadB);

        // 各占一行、各归其主
        $this->assertNotSame($tokenA, $tokenB, '同名账号不得复用对方的分享码');
        $this->assertSame(2, PublishedShare::where('type', 'sales')->count(), '同名账号应各占一行');
        $this->assertSame($a->id, (int) PublishedShare::where('token', $tokenA)->value('created_by_user_id'));
        $this->assertSame($b->id, (int) PublishedShare::where('token', $tokenB)->value('created_by_user_id'));

        // A 的链接内容未被 B 改写
        $this->getJson("/api/public/sales/{$tokenA}")
            ->assertOk()
            ->assertJsonPath('data.info.name', '绿地店内容');
        $this->getJson("/api/public/sales/{$tokenB}")
            ->assertOk()
            ->assertJsonPath('data.info.name', '东部店内容');

        // 互不停用
        Sanctum::actingAs($a);
        $this->postJson('/api/shares/disable', ['type' => 'sales'])
            ->assertOk()
            ->assertJsonPath('data.affected', 1);
        $this->getJson("/api/public/sales/{$tokenA}")->assertStatus(404);
        $this->getJson("/api/public/sales/{$tokenB}")->assertOk();

        // A 的 current 是自己的码（不是 B 的）
        $this->getJson('/api/shares/current?type=sales')
            ->assertOk()
            ->assertJsonPath('data.token', $tokenA);
    }

    /**
     * 同名场景下的历史命名行：**歧义行不归任何人**（读侧仍看得见，但不当成自己的码）。
     *
     * 一条 `created_by='李四'`、`created_by_user_id=null` 的历史行，在两个李四之间
     * 无法判定归属。若 current 按姓名并集把它当成「我的」，就又回到「同名者互相接管」：
     * 双方都会把它显示成自己的链接、都能改它。这里明确选「谁都先不当成自己的」——
     * 该行会出现在孤儿清单（缺少归属 user_id），由管理员用
     * POST /shares/orphans/repair 指派给真正的本人。
     *
     * 无歧义时（姓名唯一）仍按姓名回退，见
     * test_ownership_name_union_still_works_for_legacy_rows_without_user_id。
     */
    public function test_ambiguous_same_name_legacy_row_is_not_claimed_by_either_account(): void
    {
        $a = User::factory()->create([
            'name' => '李四', 'username' => 'same-c', 'role' => 'R_MANAGER',
            'venue' => '绿地店', 'venues' => ['绿地店'], 'status' => '启用',
        ]);
        $b = User::factory()->create([
            'name' => '李四', 'username' => 'same-d', 'role' => 'R_MANAGER',
            'venue' => '东部店', 'venues' => ['东部店'], 'status' => '启用',
        ]);

        PublishedShare::create([
            'type' => 'sales', 'token' => 'aa11bb22cc33dd44', 'created_by' => '李四',
            'created_by_user_id' => null, 'payload' => $this->salesPayload(),
            'enabled' => true, 'token_source' => 'server',
        ]);

        // 双方都不把它当自己的码（避免互相接管）
        foreach ([$a, $b] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/shares/current?type=sales')
                ->assertOk()
                ->assertJsonPath('data.token', null)
                ->assertJsonPath('data.enabled', false);
        }

        // 但该行确实被识别为「归属可疑」，可被管理员看见并处置
        Sanctum::actingAs($this->makeUser('老板', 'R_SUPER'));
        $records = collect($this->getJson('/api/shares/orphans')->assertOk()->json('data.records'));
        $hit = $records->firstWhere('token', 'aa11bb22cc33dd44');
        $this->assertNotNull($hit);
        $this->assertSame('缺少归属 user_id', $hit['reason']);

        // 管理员指派给 A 之后，A 就能正常看到并管理它
        $this->postJson('/api/shares/orphans/repair', [
            'id' => $hit['id'], 'mode' => 'reassign', 'userId' => $a->id,
        ])->assertOk();

        Sanctum::actingAs($a);
        $this->getJson('/api/shares/current?type=sales')
            ->assertOk()
            ->assertJsonPath('data.token', 'aa11bb22cc33dd44');
    }

    // ============================================================
    // 12. 内层结构白名单（t23-05）：换个容器也带不出未授权文本
    // ============================================================

    /** stages 阶段只保留 duration；藏在阶段键里的文本不出网 */
    public function test_stages_are_whitelisted_per_stage_key(): void
    {
        $payload = $this->salesPayload();
        $payload['cases'][0]['stages'] = [
            ['duration' => '第4周', 'internalNote' => '未授权文本藏在阶段里：子宫恢复情况'],
            ['note' => '另一个容器：会员手机 13800000000'],
            '第12周', // 纯字符串也接受（等价 duration）
        ];

        $token = $this->publishSales($this->makeUser(), $payload);
        $stored = PublishedShare::where('token', $token)->firstOrFail();

        $stages = $stored->payload['cases'][0]['stages'];
        $this->assertCount(3, $stages);
        foreach ($stages as $stage) {
            $this->assertSame(['duration'], array_keys($stage), '每个阶段只应有 duration 键');
        }
        $this->assertSame('第4周', $stages[0]['duration']);
        $this->assertSame('第12周', $stages[2]['duration']);

        $res = $this->getJson("/api/public/sales/{$token}")->assertOk();
        $this->assertResponseLacks($res, '子宫恢复情况');
        $this->assertResponseLacks($res, '13800000000');
        $this->assertResponseContains($res, '第4周');
    }

    /** 内层容器（info/products/coaches）里的未知键同样被丢掉 */
    public function test_nested_container_fields_are_whitelisted(): void
    {
        $payload = $this->salesPayload();
        $payload['info']['secret'] = 'info 内层未授权文本';
        $payload['products'][0]['internalCost'] = '成本价 1234';
        $payload['coaches'][0]['phone'] = '13800000001';

        $token = $this->publishSales($this->makeUser(), $payload);

        $res = $this->getJson("/api/public/sales/{$token}")->assertOk();
        $this->assertResponseLacks($res, 'info 内层未授权文本');
        $this->assertResponseLacks($res, '成本价 1234');
        $this->assertResponseLacks($res, '13800000001');
        // 白名单内的字段仍在
        $this->assertResponseContains($res, '一麦瑜伽（绿地店）');
        $this->assertResponseContains($res, '婷婷');
    }

    /** 历史快照里夹带的内层未知键，下发时也被丢掉（入库+下发两层白名单） */
    public function test_stored_snapshot_nested_fields_are_stripped_on_read(): void
    {
        $payload = $this->salesPayload();
        $payload['cases'][0]['stages'] = [['duration' => '第4周', 'leak' => '历史阶段脏字段']];
        $payload['info']['leak'] = '历史 info 脏字段';

        $token = 'bb11cc22dd33ee44';
        $this->seedTrusted($token, $payload);

        $res = $this->getJson("/api/public/sales/{$token}")->assertOk();
        $this->assertResponseLacks($res, '历史阶段脏字段');
        $this->assertResponseLacks($res, '历史 info 脏字段');
    }

    // ============================================================
    // 13. needsRepublish（t23-04）：启用中但码不可信要显式告知
    // ============================================================

    /**
     * 升级瞬间存量链接被标 legacy → 读侧 404，但 `enabled` 仍是 true。
     * 若 current 只回 enabled=true 而 token=null，界面会显示「分享中」而客户打开是 404。
     * needsRepublish 让前端能提示「分享码已失效，请重新开启分享」。
     */
    public function test_current_flags_needs_republish_for_untrusted_enabled_rows(): void
    {
        $user = $this->makeUser('张店长');
        // 存量行：启用中，但来源是 legacy（升级后未重新发布）
        PublishedShare::create([
            'type' => 'sales', 'token' => 'cc11dd22ee33ff44', 'created_by' => '张店长',
            'created_by_user_id' => $user->id, 'payload' => $this->salesPayload(),
            'enabled' => true, 'token_source' => 'legacy',
        ]);

        Sanctum::actingAs($user);
        $this->getJson('/api/shares/current?type=sales')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.token', null)
            ->assertJsonPath('data.needsRepublish', true);
    }

    /** 可信且启用 → 不需要重新发布 */
    public function test_current_does_not_flag_needs_republish_for_trusted_rows(): void
    {
        $user = $this->makeUser('张店长');
        $token = $this->publishSales($user);

        Sanctum::actingAs($user);
        $this->getJson('/api/shares/current?type=sales')
            ->assertOk()
            ->assertJsonPath('data.token', $token)
            ->assertJsonPath('data.needsRepublish', false);
    }

    /** 已停用且不可信：不需要重新发布（用户没开） */
    public function test_current_does_not_flag_needs_republish_when_disabled(): void
    {
        $user = $this->makeUser('张店长');
        PublishedShare::create([
            'type' => 'sales', 'token' => 'dd11ee22ff33aa44', 'created_by' => '张店长',
            'created_by_user_id' => $user->id, 'payload' => $this->salesPayload(),
            'enabled' => false, 'token_source' => 'legacy',
        ]);

        Sanctum::actingAs($user);
        $this->getJson('/api/shares/current?type=sales')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.needsRepublish', false);
    }

    // ============================================================
    // 14. type 分流门禁（t23-07）：training 保持原行为
    // ============================================================

    /** sales 走 MGMT；training 发布保持原行为（不因 sales 的门禁被一起收窄） */
    public function test_training_publish_is_not_narrowed_by_sales_mgmt_gate(): void
    {
        // R_TEACHER 不在 MGMT（上一轮把门禁加在 publish 入口，会把它一并 403）
        $teacher = $this->makeUser('王教练', 'R_TEACHER');
        Sanctum::actingAs($teacher);

        $this->postJson('/api/shares/publish', [
            'type' => 'training', 'token' => 'train-teacher-1',
            'payload' => ['memberName' => '训练学员'],
        ])->assertOk()->assertJsonPath('data.token', 'train-teacher-1');

        // 但同一账号发 sales 仍被 MGMT 挡住
        $this->postJson('/api/shares/publish', ['type' => 'sales', 'payload' => $this->salesPayload()])
            ->assertStatus(403);
    }

    // ============================================================
    // 15. 孤儿判定覆盖「悬挂非空 id」（t23-09）
    // ============================================================

    /** 归属 id 非空但指向不存在的账号 → 也算孤儿，并给出第三档 reason */
    public function test_orphan_list_detects_dangling_owner_user_id(): void
    {
        // created_by 是在职者的名字（姓名这一路「认得」），但 id 指向 999999
        $this->seedTrusted('ee11ff22aa33bb44', null, [
            'created_by' => '张店长',
            'created_by_user_id' => 999999,
        ]);

        Sanctum::actingAs($this->makeUser('老板', 'R_SUPER'));
        $records = $this->getJson('/api/shares/orphans')->assertOk()->json('data.records');

        $hit = collect($records)->firstWhere('token', 'ee11ff22aa33bb44');
        $this->assertNotNull($hit, '悬挂 id 的行必须出现在孤儿清单里');
        $this->assertSame('归属 user_id 悬挂', $hit['reason']);
        $this->assertSame(999999, $hit['created_by_user_id']);
    }

    /** 三档 reason 互斥且可区分 */
    public function test_orphan_reasons_distinguish_three_cases(): void
    {
        // 「张店长」必须真的在职，否则第 2/3 条会因姓名也对不上而落进第一档
        // （三档判定互斥：悬挂 id > 姓名查无此人 > 缺少 id，按严重度取第一个命中）
        $this->makeUser('张店长');

        $this->seedTrusted('1111aaaa2222bbbb', null, ['created_by' => '查无此人', 'created_by_user_id' => null]);
        $this->seedTrusted('3333cccc4444dddd', null, ['created_by' => '张店长', 'created_by_user_id' => null]);
        $this->seedTrusted('5555eeee6666ffff', null, ['created_by' => '张店长', 'created_by_user_id' => 999999]);

        Sanctum::actingAs($this->makeUser('老板', 'R_SUPER'));
        $records = collect($this->getJson('/api/shares/orphans')->assertOk()->json('data.records'));

        $this->assertSame('姓名查无此人', $records->firstWhere('token', '1111aaaa2222bbbb')['reason']);
        $this->assertSame('缺少归属 user_id', $records->firstWhere('token', '3333cccc4444dddd')['reason']);
        $this->assertSame('归属 user_id 悬挂', $records->firstWhere('token', '5555eeee6666ffff')['reason']);
    }

    /** 归属完好且姓名在职的行不进孤儿清单 */
    public function test_healthy_rows_are_not_listed_as_orphans(): void
    {
        $user = $this->makeUser('张店长');
        $this->seedTrusted('7777aaaa8888bbbb', null, [
            'created_by' => '张店长', 'created_by_user_id' => $user->id,
        ]);

        Sanctum::actingAs($this->makeUser('老板', 'R_SUPER'));
        $records = collect($this->getJson('/api/shares/orphans')->assertOk()->json('data.records'));

        $this->assertNull($records->firstWhere('token', '7777aaaa8888bbbb'));
    }
}
