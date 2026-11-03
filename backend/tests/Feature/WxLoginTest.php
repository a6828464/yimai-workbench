<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\WeChatSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * /auth/wx-login 的契约测试（小程序 P2 合规硬门）。
 *
 * 覆盖的是「改一处就会出事」的分支，不是行覆盖率：
 *  - 未配置 appid/secret 时不得伪造成功（部署忘配 → 所有人登录不了，必须给出可读文案）
 *  - code 无效/已用（40029/40163）与频率限制（45011）文案必须可区分
 *  - 未绑定 openid **不得**自动建号（员工系统开放性红线）
 *  - 停用账号不得凭微信绕过
 *  - 同一 openid 二次登录要落到同一账号，且不产生第二个用户
 *  - unionid 只在为空时补齐，不覆盖
 *  - 响应体与 userInfo 都不含 openid / appsecret
 */
class WxLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.wechat.appid', 'wx_test_appid');
        config()->set('services.wechat.secret', 'wx_test_secret');
    }

    /** 造一个已开通的员工账号 */
    private function makeStaff(array $attrs = []): User
    {
        static $n = 0;
        $n++;

        return User::create(array_merge([
            'name' => '测试老师',
            'username' => 'test_teacher_'.$n,
            'email' => 'staff_'.$n.'@yimai.local',
            'password' => 'Passw0rd!',
            'role' => 'R_TEACHER',
            'roles' => ['R_TEACHER'],
            'venue' => '绿地店',
            'venues' => ['绿地店'],
            'status' => '启用',
        ], $attrs));
    }

    private function fakeOpenid(string $openid, ?string $unionid = null): void
    {
        $body = ['openid' => $openid, 'session_key' => 'k'];
        if ($unionid !== null) {
            $body['unionid'] = $unionid;
        }
        Http::fake(['api.weixin.qq.com/*' => Http::response($body)]);
    }

    public function test_missing_appid_config_returns_readable_message_not_fake_success(): void
    {
        config()->set('services.wechat.appid', '');
        config()->set('services.wechat.secret', '');

        $this->postJson('/api/auth/wx-login', ['code' => 'any'])
            ->assertStatus(422)
            ->assertJsonPath('code', 1)
            ->assertJsonPath('message', '微信登录未开通，请使用账号密码登录');
    }

    public function test_invalid_code_maps_to_retry_message(): void
    {
        Http::fake([
            'api.weixin.qq.com/*' => Http::response(['errcode' => 40029, 'errmsg' => 'invalid code']),
        ]);

        $this->postJson('/api/auth/wx-login', ['code' => 'bad'])
            ->assertStatus(422)
            ->assertJsonPath('message', '微信登录凭证已失效，请重试');
    }

    public function test_rate_limit_message_differs_from_invalid_code(): void
    {
        Http::fake([
            'api.weixin.qq.com/*' => Http::response(['errcode' => 45011, 'errmsg' => 'freq limit']),
        ]);

        $resp = $this->postJson('/api/auth/wx-login', ['code' => 'x'])->assertStatus(422);
        $this->assertSame('登录请求过于频繁，请稍后再试', $resp->json('message'));
        $this->assertNotSame('微信登录凭证已失效，请重试', $resp->json('message'));
    }

    public function test_network_failure_returns_retry_later_not_500(): void
    {
        Http::fake([
            'api.weixin.qq.com/*' => function () {
                throw new ConnectionException('dns fail');
            },
        ]);

        $this->postJson('/api/auth/wx-login', ['code' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('message', '微信服务暂时不可用，请稍后再试');
    }

    public function test_unbound_openid_must_not_create_account(): void
    {
        $before = User::count();
        $this->fakeOpenid('OPENID_STRANGER');

        $this->postJson('/api/auth/wx-login', ['code' => 'ok'])
            ->assertStatus(403)
            ->assertJsonPath('code', 1);

        $this->assertSame($before, User::count(), '未绑定的微信不得自动建号');
        $this->assertFalse(User::where('wx_openid', 'OPENID_STRANGER')->exists());
    }

    public function test_bound_account_gets_token_and_second_login_hits_same_account(): void
    {
        $user = $this->makeStaff();
        $user->forceFill(['wx_openid' => 'OPENID_OK', 'wx_bound_at' => now()])->save();

        $this->fakeOpenid('OPENID_OK');

        $first = $this->postJson('/api/auth/wx-login', ['code' => 'c1'])
            ->assertOk()
            ->assertJsonPath('code', 0);

        $token = $first->json('data.token');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        $this->postJson('/api/auth/wx-login', ['code' => 'c2'])->assertOk()->assertJsonPath('code', 0);

        $this->assertSame(1, User::where('wx_openid', 'OPENID_OK')->count(), '同一 openid 不得分裂成两个账号');
        $this->assertSame('测试老师', $first->json('data.userInfo.staffName'));
        $this->assertStringNotContainsString('OPENID_OK', json_encode($first->json('data.userInfo')));
    }

    public function test_disabled_account_cannot_bypass_via_wechat(): void
    {
        $user = $this->makeStaff(['status' => '停用']);
        $user->forceFill(['wx_openid' => 'OPENID_DISABLED'])->save();
        $this->fakeOpenid('OPENID_DISABLED');

        $this->postJson('/api/auth/wx-login', ['code' => 'c'])
            ->assertStatus(403)
            ->assertJsonPath('message', '账号已停用，请联系管理员');
    }

    public function test_unionid_is_not_overwritten_when_already_set(): void
    {
        $a = $this->makeStaff();
        $a->forceFill(['wx_openid' => 'OPENID_U', 'wx_unionid' => 'OLD_UNION'])->save();

        $this->fakeOpenid('OPENID_U', 'NEW_UNION');
        $this->postJson('/api/auth/wx-login', ['code' => 'c'])->assertOk();

        $this->assertSame('OLD_UNION', $a->fresh()->wx_unionid, '已有 unionid 不得被覆盖');
    }

    public function test_unionid_is_filled_when_empty(): void
    {
        $b = $this->makeStaff();
        $b->forceFill(['wx_openid' => 'OPENID_U2', 'wx_unionid' => null])->save();

        $this->fakeOpenid('OPENID_U2', 'FRESH_UNION');
        $this->postJson('/api/auth/wx-login', ['code' => 'c2'])->assertOk();

        $this->assertSame('FRESH_UNION', $b->fresh()->wx_unionid, '空 unionid 应被补齐');
    }

    public function test_missing_code_hits_validation_without_calling_wechat(): void
    {
        Http::fake();

        $this->postJson('/api/auth/wx-login', [])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_empty_openid_cannot_login(): void
    {
        $this->fakeOpenid('');

        $this->postJson('/api/auth/wx-login', ['code' => 'c'])->assertStatus(422);
    }

    public function test_response_never_leaks_appsecret(): void
    {
        $this->fakeOpenid('OPENID_S');

        $resp = $this->postJson('/api/auth/wx-login', ['code' => 'c']);
        $this->assertStringNotContainsString('wx_test_secret', $resp->getContent());
    }

    /**
     * 错误码映射表逐条验证 —— 这张表是「用户能不能自己解决」的分界线，
     * 40029/40163 让用户重试，45011 让用户等，40226 告诉用户换微信。
     */
    #[DataProvider('errcodeProvider')]
    public function test_errcode_mapping(int $errcode, string $expected): void
    {
        Http::fake([
            'api.weixin.qq.com/*' => Http::response(['errcode' => $errcode, 'errmsg' => 'e']),
        ]);

        $r = WeChatSession::resolve('c');
        $this->assertFalse($r['ok']);
        $this->assertSame($expected, $r['message']);
    }

    public static function errcodeProvider(): array
    {
        return [
            'code 无效' => [40029, '微信登录凭证已失效，请重试'],
            'code 已用' => [40163, '微信登录凭证已被使用，请重试'],
            '频率限制' => [45011, '登录请求过于频繁，请稍后再试'],
            '高风险账号' => [40226, '当前微信账号存在风险，无法登录'],
            '系统繁忙 -1' => [-1, '微信服务繁忙，请稍后再试'],
            '接口超限 45009' => [45009, '微信服务繁忙，请稍后再试'],
            '未知错误码' => [99999, '微信登录失败，请稍后再试'],
            // 以下三条是**我们自己配错**，必须与「稍后再试」区分开 ——
            // 实测（2026-11-04）：真实 appid + 错 secret 时微信回 40125。
            'appsecret 错误' => [40125, '微信登录配置有误，请联系管理员检查小程序密钥'],
            'appid 缺失' => [41002, '微信登录配置有误，请联系管理员检查小程序 AppID'],
            'secret 缺失' => [41004, '微信登录配置有误，请联系管理员检查小程序密钥'],
        ];
    }

    public function test_openid_is_never_serialized(): void
    {
        $user = $this->makeStaff();
        $user->forceFill(['wx_openid' => 'OPENID_HIDDEN'])->save();

        $json = $user->fresh()->toJson();
        $this->assertStringNotContainsString('OPENID_HIDDEN', $json, 'openid 必须留在服务端');
    }
}
