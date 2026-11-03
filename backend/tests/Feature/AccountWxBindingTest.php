<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 微信绑定的管理端入口（/accounts/{key}/wx-binding）。
 *
 * 这是整条微信登录链路唯一「人为」的一环 —— code2session 只给 openid，
 * 而 openid 与员工身份之间没有天然映射。绑错人 = 员工登进别人账号，
 * 所以这里的权限、冲突、幂等、脱敏四条都必须钉死。
 */
class AccountWxBindingTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $username, string $role = 'R_SUPER', array $extra = []): User
    {
        static $n = 0;
        $n++;

        return User::create(array_merge([
            'name' => '员工'.$n,
            'username' => $username,
            'email' => $username.'@yimai.local',
            'password' => 'Passw0rd!',
            'role' => $role,
            'roles' => [$role],
            'venue' => '绿地店',
            'venues' => ['绿地店'],
            'status' => '启用',
        ], $extra));
    }

    public function test_non_super_cannot_read_binding(): void
    {
        $teacher = $this->makeUser('t1', 'R_TEACHER');
        $target = $this->makeUser('t2', 'R_TEACHER');
        Sanctum::actingAs($teacher);

        $this->getJson('/api/accounts/'.$target->username.'/wx-binding')->assertStatus(403);
    }

    public function test_non_super_cannot_bind(): void
    {
        $teacher = $this->makeUser('t3', 'R_TEACHER');
        $target = $this->makeUser('t4', 'R_TEACHER');
        Sanctum::actingAs($teacher);

        $this->putJson('/api/accounts/'.$target->username.'/wx-binding', ['openid' => 'OPENID_X'])
            ->assertStatus(403);
        $this->assertNull($target->fresh()->wx_openid);
    }

    public function test_super_can_bind_and_read_state(): void
    {
        $super = $this->makeUser('boss');
        $target = $this->makeUser('t5', 'R_TEACHER');
        Sanctum::actingAs($super);

        $this->putJson('/api/accounts/'.$target->username.'/wx-binding', ['openid' => 'OPENID_AAAA1111'])
            ->assertOk()
            ->assertJsonPath('data.bound', true);

        $this->assertSame('OPENID_AAAA1111', $target->fresh()->wx_openid);
        $this->assertNotNull($target->fresh()->wx_bound_at);

        $this->getJson('/api/accounts/'.$target->username.'/wx-binding')
            ->assertOk()
            ->assertJsonPath('data.bound', true);
    }

    public function test_binding_response_never_leaks_openid(): void
    {
        $super = $this->makeUser('boss2');
        $target = $this->makeUser('t6', 'R_TEACHER');
        Sanctum::actingAs($super);

        $this->putJson('/api/accounts/'.$target->username.'/wx-binding', ['openid' => 'OPENID_SECRET_XYZ'])
            ->assertOk();

        $get = $this->getJson('/api/accounts/'.$target->username.'/wx-binding')->assertOk();
        $this->assertStringNotContainsString('OPENID_SECRET_XYZ', $get->getContent());
    }

    public function test_same_openid_cannot_bind_two_accounts(): void
    {
        $super = $this->makeUser('boss3');
        $a = $this->makeUser('t7', 'R_TEACHER');
        $b = $this->makeUser('t8', 'R_TEACHER');
        Sanctum::actingAs($super);

        $this->putJson('/api/accounts/'.$a->username.'/wx-binding', ['openid' => 'OPENID_DUP0001'])->assertOk();

        $this->putJson('/api/accounts/'.$b->username.'/wx-binding', ['openid' => 'OPENID_DUP0001'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, '已绑定'));

        $this->assertNull($b->fresh()->wx_openid);
    }

    public function test_rebinding_same_account_is_idempotent(): void
    {
        $super = $this->makeUser('boss4');
        $target = $this->makeUser('t9', 'R_TEACHER');
        Sanctum::actingAs($super);

        $this->putJson('/api/accounts/'.$target->username.'/wx-binding', ['openid' => 'OPENID_IDEM01'])->assertOk();
        $firstBoundAt = $target->fresh()->wx_bound_at;

        $this->putJson('/api/accounts/'.$target->username.'/wx-binding', ['openid' => 'OPENID_IDEM01'])->assertOk();

        // 重复绑定不应刷新绑定时间（否则审计上看起来像换了设备）
        $this->assertEquals($firstBoundAt, $target->fresh()->wx_bound_at);
    }

    public function test_unbind_clears_openid_and_unionid(): void
    {
        $super = $this->makeUser('boss5');
        $target = $this->makeUser('t10', 'R_TEACHER');
        Sanctum::actingAs($super);

        $this->putJson('/api/accounts/'.$target->username.'/wx-binding', [
            'openid' => 'OPENID_UNBIND1', 'unionid' => 'UNION_1',
        ])->assertOk();

        $this->deleteJson('/api/accounts/'.$target->username.'/wx-binding')
            ->assertOk()
            ->assertJsonPath('data.bound', false);

        $fresh = $target->fresh();
        $this->assertNull($fresh->wx_openid);
        $this->assertNull($fresh->wx_unionid);
        $this->assertNull($fresh->wx_bound_at);
    }

    public function test_bind_requires_min_length_openid(): void
    {
        $super = $this->makeUser('boss6');
        $target = $this->makeUser('t11', 'R_TEACHER');
        Sanctum::actingAs($super);

        $this->putJson('/api/accounts/'.$target->username.'/wx-binding', ['openid' => '短'])
            ->assertStatus(422);
    }

    public function test_bind_unknown_account_is_404(): void
    {
        $super = $this->makeUser('boss7');
        Sanctum::actingAs($super);

        $this->putJson('/api/accounts/'.rawurlencode('不存在的人').'/wx-binding', ['openid' => 'OPENID_404'])
            ->assertStatus(404);
    }

    public function test_unbind_returns_404_for_unknown_account(): void
    {
        $super = $this->makeUser('boss8');
        Sanctum::actingAs($super);

        $this->deleteJson('/api/accounts/'.rawurlencode('鬼').'/wx-binding')->assertStatus(404);
    }

    public function test_bound_account_then_wx_login_succeeds_end_to_end(): void
    {
        $super = $this->makeUser('boss9');
        $staff = $this->makeUser('t12', 'R_TEACHER');
        Sanctum::actingAs($super);

        $this->putJson('/api/accounts/'.$staff->username.'/wx-binding', ['openid' => 'OPENID_E2E0001'])
            ->assertOk();

        // 换成「未登录的微信小程序」视角
        $this->app['auth']->forgetGuards();

        config()->set('services.wechat.appid', 'wx_appid');
        config()->set('services.wechat.secret', 'wx_secret');
        \Illuminate\Support\Facades\Http::fake([
            'api.weixin.qq.com/*' => \Illuminate\Support\Facades\Http::response([
                'openid' => 'OPENID_E2E0001', 'session_key' => 'k',
            ]),
        ]);

        $this->postJson('/api/auth/wx-login', ['code' => 'real-code'])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.userInfo.staffName', $staff->name);
    }
}
