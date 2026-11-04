<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\WxSubscription;
use App\Models\WxSubscriptionLog;
use App\Services\WeChatSubscribeMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 微信订阅消息（模板通知）：授权账本接口 + 下发服务。
 *
 * 钉死的四件事：
 *  1. 未登录 401、登录后「未配置模板」也是 200 而不是报错（页面要能正常进来）；
 *  2. 授权一次 = remain +1（一次性授权，账本记错就等于乱发/漏发）；
 *  3. 未配置模板时 test 接口给 422 + 可读中文，send() 走 skipped 且不碰 remain；
 *  4. 43101（用户拒收）把 remain 归零 —— 不归零会一直重试同一个人。
 *
 * 本机 .env 里有真实小程序凭据，setUp 里强制清空：否则一个手滑的用例就会拿
 * 生产 appid 去打微信接口（测试必须与外部网络无关）。
 */
class WxSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 凭据与模板 id 都清空：测试行为只由各用例自己 config() 决定
        config([
            'services.wechat.appid' => '',
            'services.wechat.secret' => '',
            'services.wechat.templates.leadAssigned.template_id' => '',
            'services.wechat.templates.memberAssigned.template_id' => '',
            'services.wechat.templates.todoReminder.template_id' => '',
        ]);
        Cache::flush();
    }

    private function makeUser(string $name, array $extra = []): User
    {
        static $n = 0;
        $n++;

        // wx_openid 不在 User::$fillable 里（防伪造微信身份登录，见 User 模型注释），
        // create() 会静默丢掉它 —— 这里必须 forceFill 才真的绑上。
        $openid = $extra['wx_openid'] ?? null;
        unset($extra['wx_openid']);

        $user = User::create(array_merge([
            'name' => $name,
            'username' => 'wxsub'.$n,
            'email' => 'wxsub'.$n.'@yimai.local',
            'password' => 'Passw0rd!',
            'role' => 'R_TEACHER',
            'roles' => ['R_TEACHER'],
            'venue' => '绿地店',
            'venues' => ['绿地店'],
            'status' => '启用',
        ], $extra));

        if ($openid !== null) {
            $user->forceFill(['wx_openid' => $openid, 'wx_bound_at' => now()])->save();
        }

        return $user->refresh();
    }

    public function test_guest_cannot_read_subscriptions(): void
    {
        $this->getJson('/api/wx/subscriptions')->assertStatus(401);
    }

    public function test_logged_in_user_gets_200_even_when_templates_are_not_configured(): void
    {
        $user = $this->makeUser('未配置老师');
        Sanctum::actingAs($user);

        $resp = $this->getJson('/api/wx/subscriptions')->assertOk();

        $resp->assertJsonPath('code', 0)
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.bound', false)
            ->assertJsonPath('data.updatedAt', null);

        // 三个模板键一个都不能少：前端按 catalog 渲染「待管理员配置模板」
        $templates = $resp->json('data.templates');
        $this->assertCount(3, $templates);
        $this->assertSame(['leadAssigned', 'memberAssigned', 'todoReminder'], array_column($templates, 'key'));
        foreach ($templates as $tpl) {
            $this->assertFalse($tpl['enabled']);
            $this->assertSame(0, $tpl['remain']);
            $this->assertFalse($tpl['accepted']);
            // fields 是「参数名清单」（与 miniapp/src/api/notify.ts 的 fields?: string[] 对齐），
            // 不是 keyword→中文名的对象：前端按数组处理，形状错了页面拿到的类型直接不符
            $this->assertIsArray($tpl['fields']);
            $this->assertContainsOnly('string', $tpl['fields']);
            $this->assertNotEmpty($tpl['fields']);
        }
        $leadFields = collect($templates)->firstWhere('key', 'leadAssigned')['fields'];
        // 线上实号「客户分配提醒」的参数名（方案 §6.5 的关键词必须逐字一致，否则 47003）
        $this->assertSame(['name3', 'phone_number4', 'name2', 'time6', 'thing5'], $leadFields);
    }

    public function test_accepting_a_template_adds_one_remain(): void
    {
        $user = $this->makeUser('授权老师');
        Sanctum::actingAs($user);

        $resp = $this->postJson('/api/wx/subscriptions', [
            'accepts' => ['leadAssigned', 'memberAssigned'],
            'denies' => ['todoReminder', 'notARealKey'],
        ])->assertOk();

        $resp->assertJsonPath('code', 0);

        $this->assertDatabaseHas('wx_subscriptions', [
            'user_id' => $user->id,
            'template_key' => 'leadAssigned',
            'remain' => 1,
            'total_accepted' => 1,
        ]);
        $this->assertDatabaseHas('wx_subscriptions', [
            'user_id' => $user->id,
            'template_key' => 'memberAssigned',
            'remain' => 1,
        ]);
        // 拒收/未登记的键不建账本
        $this->assertDatabaseMissing('wx_subscriptions', [
            'user_id' => $user->id,
            'template_key' => 'todoReminder',
        ]);
        $this->assertSame(0, WxSubscription::where('user_id', $user->id)->where('template_key', 'notARealKey')->count());

        // 响应里的 templates 必须反映刚落库的授权状态（页面不刷新就能变）
        $row = collect($resp->json('data.templates'))->firstWhere('key', 'leadAssigned');
        $this->assertTrue($row['accepted']);
        $this->assertSame(1, $row['remain']);
        $this->assertNotNull($row['lastAcceptedAt']);

        // 再授权一次 = 再 +1（一次性授权可以累积，发一条扣一条）
        $this->postJson('/api/wx/subscriptions', ['accepts' => ['leadAssigned']])->assertOk();
        $this->assertSame(2, (int) WxSubscription::where('user_id', $user->id)
            ->where('template_key', 'leadAssigned')->value('remain'));
    }

    public function test_test_endpoint_returns_422_when_template_not_configured(): void
    {
        $user = $this->makeUser('测试老师', ['wx_openid' => 'OPENID_TEST_1']);
        Sanctum::actingAs($user);

        $resp = $this->postJson('/api/wx/subscriptions/test', ['key' => 'leadAssigned'])
            ->assertStatus(422);

        $this->assertStringContainsString('未配置', (string) $resp->json('message'));
        // 未配置时连微信接口都不该碰
        $this->assertDatabaseMissing('wx_subscription_logs', ['user_id' => $user->id]);
    }

    public function test_test_endpoint_returns_422_when_user_has_no_openid(): void
    {
        config(['services.wechat.templates.leadAssigned.template_id' => 'TPL_LEAD_ASSIGNED']);
        $user = $this->makeUser('未绑定老师');
        Sanctum::actingAs($user);

        $resp = $this->postJson('/api/wx/subscriptions/test', ['key' => 'leadAssigned'])
            ->assertStatus(422);

        $this->assertStringContainsString('绑定微信', (string) $resp->json('message'));
    }

    public function test_send_is_skipped_when_template_id_is_missing(): void
    {
        Http::preventStrayRequests();

        $user = $this->makeUser('未配模板老师', ['wx_openid' => 'OPENID_TEST_2']);

        $result = app(WeChatSubscribeMessage::class)->send($user, 'leadAssigned', [
            'customerName' => '姚女士',
            'staffName' => $user->name,
            'venue' => '绿地店',
            'time' => now(),
        ]);

        $this->assertSame(WxSubscriptionLog::STATUS_SKIPPED, $result['status']);
        $this->assertNull($result['errcode']);
        $this->assertStringContainsString('未配置', $result['message']);

        // skipped 也要留流水：否则「为什么没收到」无从查起
        $this->assertDatabaseHas('wx_subscription_logs', [
            'user_id' => $user->id,
            'template_key' => 'leadAssigned',
            'status' => WxSubscriptionLog::STATUS_SKIPPED,
        ]);
        // 不能因为「发不出去」动账本
        $this->assertSame(0, WxSubscription::where('user_id', $user->id)->count());
    }

    public function test_successful_send_consumes_one_remain(): void
    {
        config([
            'services.wechat.appid' => 'APPID_TEST',
            'services.wechat.secret' => 'SECRET_TEST',
            'services.wechat.templates.leadAssigned.template_id' => 'TPL_LEAD_ASSIGNED',
        ]);
        Http::fake([
            'https://api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'TOKEN_TEST', 'expires_in' => 7200]),
            'https://api.weixin.qq.com/cgi-bin/message/subscribe/send*' => Http::response(['errcode' => 0, 'errmsg' => 'ok']),
        ]);

        $user = $this->makeUser('成功老师', ['wx_openid' => 'OPENID_TEST_3']);
        $sub = WxSubscription::create([
            'user_id' => $user->id,
            'template_key' => 'leadAssigned',
            'remain' => 2,
            'total_accepted' => 2,
            'last_accepted_at' => now(),
        ]);

        $result = app(WeChatSubscribeMessage::class)->send($user, 'leadAssigned', [
            'customerName' => '姚女士',
            'phone' => '13800001234',
            'staffName' => $user->name,
            'venue' => '绿地店',
            'remark' => '测试',
            'time' => now(),
        ]);

        $this->assertSame(WxSubscriptionLog::STATUS_SENT, $result['status']);
        $this->assertSame(1, (int) $sub->fresh()->remain);
        $this->assertNotNull($sub->fresh()->last_sent_at);
        $this->assertDatabaseHas('wx_subscription_logs', [
            'user_id' => $user->id,
            'status' => WxSubscriptionLog::STATUS_SENT,
            'errcode' => 0,
        ]);
    }

    public function test_43101_zeroes_the_remain_counter(): void
    {
        config([
            'services.wechat.appid' => 'APPID_TEST',
            'services.wechat.secret' => 'SECRET_TEST',
            'services.wechat.templates.leadAssigned.template_id' => 'TPL_LEAD_ASSIGNED',
        ]);
        Http::fake([
            'https://api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'TOKEN_TEST', 'expires_in' => 7200]),
            'https://api.weixin.qq.com/cgi-bin/message/subscribe/send*' => Http::response([
                'errcode' => 43101,
                'errmsg' => 'user refuse to accept the msg',
            ]),
        ]);

        $user = $this->makeUser('拒收老师', ['wx_openid' => 'OPENID_TEST_4']);
        $sub = WxSubscription::create([
            'user_id' => $user->id,
            'template_key' => 'leadAssigned',
            'remain' => 3,
            'total_accepted' => 3,
            'last_accepted_at' => now(),
        ]);

        $result = app(WeChatSubscribeMessage::class)->send($user, 'leadAssigned', [
            'customerName' => '姚女士',
            'phone' => '13800001234',
            'staffName' => $user->name,
            'remark' => '测试',
            'time' => now(),
        ]);

        $this->assertSame(WxSubscriptionLog::STATUS_FAILED, $result['status']);
        $this->assertSame(43101, $result['errcode']);
        // 归零：否则页面永远显示「剩余 3 次」并反复重试一个已经拒收的人
        $this->assertSame(0, (int) $sub->fresh()->remain);
        $this->assertDatabaseHas('wx_subscription_logs', [
            'user_id' => $user->id,
            'status' => WxSubscriptionLog::STATUS_FAILED,
            'errcode' => 43101,
        ]);
    }

    /**
     * 触发点 1：新客资填了服务老师 → 该老师收到待发流水。
     *
     * 本用例凭据留空，所以落的是 skipped（credentials_missing）——
     * 断言的是「触发点真的把通知排进了队列」，不是「微信收到了」。
     * 这样既证明触发链路通，又保证测试不碰外网。
     */
    public function test_lead_assignment_queues_a_notification_for_the_teacher(): void
    {
        config(['services.wechat.templates.leadAssigned.template_id' => 'TPL_LEAD_ASSIGNED']);

        $teacher = $this->makeUser('分配老师');
        $manager = $this->makeUser('绿地店长', ['role' => 'R_MANAGER', 'roles' => ['R_MANAGER']]);
        Sanctum::actingAs($manager);

        $this->postJson('/api/leads', [
            'name' => '新客甲',
            'source' => '转介绍',
            'venue' => '绿地店',
            'serviceTeacher' => '分配老师',
        ])->assertOk();

        $this->assertDatabaseHas('wx_subscription_logs', [
            'user_id' => $teacher->id,
            'template_key' => 'leadAssigned',
            'status' => WxSubscriptionLog::STATUS_SKIPPED,
        ]);
    }

    /**
     * 别名/旧名/昵称写法的归属，也要能把提醒送到本人。
     *
     * 「谁能看到这条客资」与「提醒发给谁」必须是同一个判断（都走 StaffDirectory）。
     * 否则会出现最难解释的故障：列表里明明看得到自己的客资，微信却从来没收到分配提醒。
     */
    public function test_lead_assignment_resolves_owner_written_as_alias(): void
    {
        config(['services.wechat.templates.leadAssigned.template_id' => 'TPL_LEAD_ASSIGNED']);

        $teacher = $this->makeUser('苏米');
        \App\Models\StaffAlias::create([
            'user_id' => $teacher->id, 'alias' => '苏小米', 'source' => 'manual',
        ]);
        $manager = $this->makeUser('绿地店长', ['role' => 'R_MANAGER', 'roles' => ['R_MANAGER']]);
        Sanctum::actingAs($manager);

        $this->postJson('/api/leads', [
            'name' => '别名归属的新客',
            'source' => '转介绍',
            'venue' => '绿地店',
            'serviceTeacher' => '苏小米',
        ])->assertOk();

        $this->assertDatabaseHas('wx_subscription_logs', [
            'user_id' => $teacher->id,
            'template_key' => 'leadAssigned',
            'status' => WxSubscriptionLog::STATUS_SKIPPED,
        ]);
    }

    /**
     * 触发点 2：会员会籍顾问改到某人名下 → 新归属人收到待发流水。
     *
     * 同时钉住「原样回传不算变更」：同一请求里先改一次归属、再原样回传一次，
     * 第二次不该再排一条通知（否则前端保存备注也会给对方发「你被分配了」）。
     */
    public function test_member_assignment_queues_a_notification_only_when_owner_actually_changes(): void
    {
        // 会员归属复用「客户分配提醒」模板（线上只申请到一个模板），因此配置 leadAssigned
        config(['services.wechat.templates.leadAssigned.template_id' => 'TPL_LEAD_ASSIGNED']);

        $manager = $this->makeUser('绿地店长二', ['role' => 'R_MANAGER', 'roles' => ['R_MANAGER']]);
        $newOwner = $this->makeUser('新归属老师');
        $customer = Customer::create([
            'name' => '会员甲',
            'phone' => '13800001234',
            'phone_tail' => '1234',
            'venue' => '绿地店',
            'status' => '在籍',
            'owner' => '未分配',
            'consultant' => '未分配',
        ]);
        Sanctum::actingAs($manager);

        $this->patchJson("/api/customers/{$customer->id}", ['consultant' => '新归属老师'])->assertOk();
        $this->assertSame('新归属老师', $customer->fresh()->consultant);
        $this->assertSame($newOwner->id, (int) $customer->fresh()->consultant_user_id);

        $countAfterChange = WxSubscriptionLog::where('user_id', $newOwner->id)
            ->where('template_key', 'leadAssigned')->count();
        $this->assertSame(1, $countAfterChange);

        // 原样回传（前端整个对象保存）：不重复通知、不要求归属权限
        $this->patchJson("/api/customers/{$customer->id}", ['consultant' => '新归属老师', 'lastTouch' => '2026-11-04'])->assertOk();
        $this->assertSame(1, WxSubscriptionLog::where('user_id', $newOwner->id)
            ->where('template_key', 'leadAssigned')->count());
    }

    /**
     * 会员事件：独立模板配了就用自己的 key，没配就回退 leadAssigned（线上目前唯一在用的模板）。
     *
     * 同时断言「一份 payload 两个模板都能用」：先按 memberAssigned 的字段映射发一条，
     * 再回退到 leadAssigned（真实模板 730：name3 / phone_number4 / name2 / time6 / thing5）
     * 发一条，两次下发的占位符都必须被正确填上 —— 否则切模板时会出现「字段全空」的通知。
     */
    public function test_member_assignment_switches_template_by_config_and_payload_covers_both(): void
    {
        config([
            'services.wechat.appid' => 'APPID_TEST',
            'services.wechat.secret' => 'SECRET_TEST',
            'services.wechat.templates.memberAssigned.template_id' => 'TPL_MEMBER_ASSIGNED',
            // 回退目标：线上唯一在用的「客户分配提醒」
            'services.wechat.templates.leadAssigned.template_id' => 'TPL_LEAD_ASSIGNED',
        ]);
        Http::fake([
            'https://api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'TOKEN_TEST', 'expires_in' => 7200]),
            'https://api.weixin.qq.com/cgi-bin/message/subscribe/send*' => Http::response(['errcode' => 0, 'errmsg' => 'ok']),
        ]);

        $user = $this->makeUser('会员切换老师', ['wx_openid' => 'OPENID_SWITCH']);
        foreach (['memberAssigned', 'leadAssigned'] as $key) {
            WxSubscription::create([
                'user_id' => $user->id,
                'template_key' => $key,
                'remain' => 1,
                'total_accepted' => 1,
                'last_accepted_at' => now(),
            ]);
        }

        $svc = app(WeChatSubscribeMessage::class);

        // ① 配了独立模板 → 走 memberAssigned
        $first = $svc->notifyMemberAssigned('会员切换老师', '会员乙', '绿地店', '会籍顾问', '13900001111');
        $this->assertSame(WxSubscriptionLog::STATUS_SENT, $first['status']);
        $this->assertDatabaseHas('wx_subscription_logs', [
            'user_id' => $user->id,
            'template_key' => 'memberAssigned',
            'status' => WxSubscriptionLog::STATUS_SENT,
        ]);

        // ② 清空独立模板 id → 回退 leadAssigned（线上可用路径不变）
        config(['services.wechat.templates.memberAssigned.template_id' => '']);
        $second = $svc->notifyMemberAssigned('会员切换老师', '会员乙', '绿地店', '会籍顾问', '13900001111');
        $this->assertSame(WxSubscriptionLog::STATUS_SENT, $second['status']);
        $this->assertDatabaseHas('wx_subscription_logs', [
            'user_id' => $user->id,
            'template_key' => 'leadAssigned',
            'status' => WxSubscriptionLog::STATUS_SENT,
        ]);

        $bodies = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->data())
            ->filter(fn ($body) => isset($body['data']))
            ->values();
        $this->assertCount(2, $bodies);

        // 会员关联通知（模板 5394）实号：name1=昵称 / date2=关联时间 / thing3=温馨提示
        $memberData = $bodies[0]['data'];
        $this->assertSame('会员乙', $memberData['name1']['value']);
        $this->assertNotSame('', $memberData['date2']['value']);
        $this->assertStringContainsString('会员归属', $memberData['thing3']['value']);

        // leadAssigned（真实模板 730）的字段映射：name3=客户姓名 / phone_number4=手机 /
        // name2=被分配人 / time6=时间 / thing5=温馨提示 —— 同一份 payload 全部填得上
        $leadData = $bodies[1]['data'];
        $this->assertSame('会员乙', $leadData['name3']['value']);
        $this->assertSame('13900001111', $leadData['phone_number4']['value']);
        $this->assertSame('会员切换老师', $leadData['name2']['value']);
        $this->assertNotSame('', $leadData['time6']['value']);
        $this->assertNotSame('', $leadData['thing5']['value']);
    }

    /**
     * 待办提醒：创建任务并指派给某人 → 负责人收到 todoReminder（事项/截止/门店三个参数都填上）。
     *
     * 走真实链路（POST /api/tasks → defer → 服务层），既验证触发点存在，
     * 也验证 tasks.deadline 这个自由文本列被格式化成「2026年11月5日 00:00」。
     */
    public function test_task_assignment_queues_a_todo_reminder(): void
    {
        config([
            'services.wechat.appid' => 'APPID_TEST',
            'services.wechat.secret' => 'SECRET_TEST',
            'services.wechat.templates.todoReminder.template_id' => 'TPL_TODO_REMINDER',
        ]);
        Http::fake([
            'https://api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'TOKEN_TEST', 'expires_in' => 7200]),
            'https://api.weixin.qq.com/cgi-bin/message/subscribe/send*' => Http::response(['errcode' => 0, 'errmsg' => 'ok']),
        ]);

        $super = $this->makeUser('待办超管', ['role' => 'R_SUPER', 'roles' => ['R_SUPER']]);
        $owner = $this->makeUser('待办负责人', ['wx_openid' => 'OPENID_TODO']);
        WxSubscription::create([
            'user_id' => $owner->id,
            'template_key' => 'todoReminder',
            'remain' => 1,
            'total_accepted' => 1,
            'last_accepted_at' => now(),
        ]);
        Sanctum::actingAs($super);

        $this->postJson('/api/tasks', [
            'title' => '回访会员甲',
            'customerName' => '会员甲',
            'venue' => '绿地店',
            'owner' => '待办负责人',
            'deadline' => '2026-11-05',
        ])->assertOk();

        $this->assertDatabaseHas('wx_subscription_logs', [
            'user_id' => $owner->id,
            'template_key' => 'todoReminder',
            'status' => WxSubscriptionLog::STATUS_SENT,
        ]);

        $body = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->data())
            ->first(fn ($b) => isset($b['data']));
        $this->assertNotNull($body, '没有发出 todoReminder 请求');
        $this->assertSame('TPL_TODO_REMINDER', $body['template_id']);
        // 任务接收通知（模板 802）实号：thing3=任务标题 / name5=执行人 /
        // date6=截止时间（date 类型只给日期）/ thing24=温馨提示
        $this->assertSame('回访会员甲', $body['data']['thing3']['value']);
        $this->assertSame('待办负责人', $body['data']['name5']['value']);
        $this->assertStringStartsWith('2026年11月5日', $body['data']['date6']['value']);
    }
}
