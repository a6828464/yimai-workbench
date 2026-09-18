<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_is_limited_to_own_venue_for_check_update_and_history(): void
    {
        $manager = $this->user('manager-green', '绿地店长', 'R_MANAGER', '绿地店');
        $own = $this->lead('绿地客资', '13800000001', '绿地店');
        $other = $this->lead('东部客资', '13800000001', '东部店');
        $this->auditFor($other);
        Sanctum::actingAs($manager);

        $this->getJson('/api/leads/check?phone=13800000001')
            ->assertOk()
            ->assertJsonPath('data.matches.0.name', '绿地客资')
            ->assertJsonCount(1, 'data.matches');
        $this->patchJson("/api/leads/{$own->id}", ['remark' => '已跟进'])->assertOk();
        $this->patchJson("/api/leads/{$other->id}", ['remark' => '越权'])->assertForbidden();
        $this->getJson("/api/leads/{$other->id}/history")->assertForbidden();
    }

    public function test_manager_create_and_update_force_own_venue(): void
    {
        $manager = $this->user('manager-green-force', '绿地店长', 'R_MANAGER', '绿地店');
        Sanctum::actingAs($manager);

        $id = $this->postJson('/api/leads', [
            'name' => '强制门店',
            'source' => '转介绍',
            'venue' => '东部店',
        ])->assertOk()->json('data.id');
        $this->assertDatabaseHas('leads', ['id' => $id, 'venue' => '绿地店']);

        $this->patchJson("/api/leads/{$id}", ['venue' => '东部店', 'remark' => '更新'])->assertOk();
        $this->assertDatabaseHas('leads', ['id' => $id, 'venue' => '绿地店', 'remark' => '更新']);
    }

    public function test_service_teacher_accesses_own_venue_assigned_or_unassigned_leads(): void
    {
        // 服务老师（会籍顾问）是客资承接主体：本人名下 + 待承接池
        $service = $this->user('service-green', '绿地顾问', 'R_SERVICE', '绿地店');
        $own = $this->lead('本人客资', '13800000002', '绿地店', '绿地顾问');
        $unassigned = $this->lead('待认领客资', '13800000002', '绿地店');
        $colleague = $this->lead('同事客资', '13800000002', '绿地店', '其他顾问');
        $otherVenue = $this->lead('跨店客资', '13800000002', '东部店');
        Sanctum::actingAs($service);

        $this->getJson('/api/leads/check?phone=13800000002')
            ->assertOk()
            ->assertJsonCount(2, 'data.matches');
        $this->patchJson("/api/leads/{$own->id}", ['remark' => '本人跟进'])->assertOk();
        $this->patchJson("/api/leads/{$unassigned->id}", ['serviceTeacher' => '绿地顾问'])->assertOk();
        $this->patchJson("/api/leads/{$colleague->id}", ['remark' => '越权'])->assertForbidden();
        $this->getJson("/api/leads/{$otherVenue->id}/history")->assertForbidden();
    }

    public function test_coach_sees_own_and_taught_leads_but_not_the_unassigned_pool(): void
    {
        // 授课老师（私教主教练）不承接公海：只看本人作为会籍顾问的、本人上过体验课的、本人私教学员的客资
        $coach = $this->user('coach-green', '绿地教练', 'R_TEACHER', '绿地店');
        $own = $this->lead('本人客资', '13800000004', '绿地店', '绿地教练');
        $taught = $this->lead('本人体验课客资', '13800000004', '绿地店', '其他顾问');
        $taught->update(['trial_teacher' => '绿地教练']);
        $unassigned = $this->lead('待认领客资', '13800000004', '绿地店');
        $otherVenue = $this->lead('跨店客资', '13800000004', '东部店', '绿地教练');
        Sanctum::actingAs($coach);

        // 待认领客资不在授课老师的可见范围内
        $this->getJson('/api/leads/check?phone=13800000004')
            ->assertOk()
            ->assertJsonCount(2, 'data.matches');
        $this->patchJson("/api/leads/{$own->id}", ['remark' => '本人跟进'])->assertOk();
        $this->patchJson("/api/leads/{$taught->id}", ['trialTopic' => '肩颈体验'])->assertOk();
        $this->patchJson("/api/leads/{$unassigned->id}", ['serviceTeacher' => '绿地教练'])->assertForbidden();
        $this->getJson("/api/leads/{$otherVenue->id}/history")->assertForbidden();
    }

    public function test_teacher_create_and_patch_reject_privileged_lead_fields(): void
    {
        $teacher = $this->user('teacher-restricted', '限制老师', 'R_TEACHER', '绿地店');
        Sanctum::actingAs($teacher);

        $this->postJson('/api/leads', [
            'name' => '非法成交',
            'source' => '到店',
            'venue' => '东部店',
            'status' => '已成交',
            'dealAmount' => 5000,
        ])->assertForbidden();

        $id = $this->postJson('/api/leads', [
            'name' => '老师录入',
            'source' => '到店',
            'venue' => '东部店',
        ])->assertOk()->json('data.id');
        $this->assertDatabaseHas('leads', ['id' => $id, 'venue' => '绿地店', 'status' => '新留资']);

        $this->patchJson("/api/leads/{$id}", ['dealAmount' => 5000])->assertForbidden();
        $this->patchJson("/api/leads/{$id}", ['serviceTeacher' => '其他老师'])->assertForbidden();
        $this->patchJson("/api/leads/{$id}", [
            'serviceTeacher' => '限制老师',
            'status' => '已联系',
            'demand' => '改善肩颈',
            'trialTopic' => '基础体验',
        ])->assertOk();
    }

    public function test_super_and_media_can_manage_leads_across_both_venues(): void
    {
        $lead = $this->lead('双店客资', '13800000003', '东部店', '东部老师');
        $this->auditFor($lead);

        foreach ([
            $this->user('owner', '老板', 'R_SUPER', null),
            $this->user('media', '新媒体', 'R_MEDIA', null),
        ] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/leads/check?phone=13800000003')->assertJsonCount(1, 'data.matches');
            $this->patchJson("/api/leads/{$lead->id}", ['remark' => $user->name])->assertOk();
            $this->getJson("/api/leads/{$lead->id}/history")->assertOk();
        }
    }

    public function test_lead_amounts_accept_two_decimal_places(): void
    {
        $manager = $this->user('manager-decimal', '小数店长', 'R_MANAGER', '绿地店');
        Sanctum::actingAs($manager);

        $id = $this->postJson('/api/leads', [
            'name' => '体验课核销',
            'source' => '到店',
            'venue' => '绿地店',
            'redeemAmount' => 99.9,
            'dealAmount' => 1280.50,
        ])->assertOk()->json('data.id');
        $this->assertDatabaseHas('leads', ['id' => $id, 'redeem_amount' => 99.9, 'deal_amount' => 1280.50]);

        $this->patchJson("/api/leads/{$id}", ['redeemAmount' => 88.05])->assertOk();
        $this->assertDatabaseHas('leads', ['id' => $id, 'redeem_amount' => 88.05]);

        // 超过两位小数应被拒绝
        $this->patchJson("/api/leads/{$id}", ['redeemAmount' => 88.005])->assertStatus(422);
    }

    public function test_disabled_user_existing_token_is_rejected(): void
    {
        $user = $this->user('disabled-token', '停用用户', 'R_TEACHER', '绿地店');
        $token = $user->createToken('test')->plainTextToken;
        $user->update(['status' => '停用']);

        $this->withToken($token)->getJson('/api/me')->assertForbidden();
    }

    public function test_disabling_or_resetting_an_account_revokes_all_tokens(): void
    {
        $super = $this->user('account-owner', '账号管理员', 'R_SUPER', null);
        $disabled = $this->user('disable-me', '待停用', 'R_TEACHER', '绿地店');
        $reset = $this->user('reset-me', '待重置', 'R_TEACHER', '绿地店');
        $disabled->createToken('one');
        $reset->createToken('one');
        Sanctum::actingAs($super);

        $this->patchJson('/api/accounts/disable-me', ['action' => 'disable'])->assertOk();
        $this->patchJson('/api/accounts/reset-me', [
            'action' => 'resetPassword',
            'password' => 'new-password',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_create_account_with_name_and_delete_it(): void
    {
        Sanctum::actingAs($this->user('account-admin', '账号管理员', 'R_SUPER', null));

        $this->postJson('/api/accounts', [
            'userName' => 'teacher-new',
            'name' => '新老师',
            'roleCode' => 'R_TEACHER',
            'venues' => ['绿地店'],
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('data.key', 'teacher-new');

        $this->assertDatabaseHas('users', [
            'username' => 'teacher-new',
            'name' => '新老师',
        ]);

        $this->patchJson('/api/accounts/teacher-new', ['action' => 'delete'])->assertOk();
        $this->assertDatabaseMissing('users', ['username' => 'teacher-new']);
    }

    public function test_today_followups_and_alerts_follow_role_scopes(): void
    {
        $this->customer('老师会员', '绿地店', '绿地老师', 'P1');
        $this->customer('同事会员', '绿地店', '其他老师', 'P1');
        $this->customer('跨店会员', '东部店', '东部老师', 'P1');
        $this->lead('老师客资', '13800000010', '绿地店', '绿地老师');
        $this->lead('待分配客资', '13800000011', '绿地店');
        $this->lead('同事客资', '13800000012', '绿地店', '其他老师');
        $this->lead('跨店客资', '13800000013', '东部店');
        Task::create([
            'title' => '老师任务', 'customer_name' => '老师会员', 'venue' => '绿地店',
            'owner' => '绿地老师', 'status' => '已逾期',
        ]);
        Task::create([
            'title' => '跨店任务', 'customer_name' => '跨店会员', 'venue' => '东部店',
            'owner' => '未分配', 'status' => '已逾期',
        ]);

        Sanctum::actingAs($this->user('today-service', '绿地老师', 'R_SERVICE', '绿地店'));
        $followups = $this->getJson('/api/today/followups')->assertOk()->json('data');
        $this->assertSame(['老师会员'], collect($followups)->pluck('name')->all());
        $serviceAlerts = collect($this->getJson('/api/today/alerts')->assertOk()->json('data'))->pluck('text')->join(' ');
        $this->assertStringContainsString('老师客资', $serviceAlerts);
        // 服务老师承接待分配池
        $this->assertStringContainsString('待分配客资', $serviceAlerts);
        $this->assertStringNotContainsString('同事客资', $serviceAlerts);
        $this->assertStringNotContainsString('跨店', $serviceAlerts);

        // 授课老师看本人作为会籍顾问的会员与客资，但不承接待分配池
        Sanctum::actingAs($this->user('today-coach', '绿地老师', 'R_TEACHER', '绿地店'));
        $coachFollowups = $this->getJson('/api/today/followups')->assertOk()->json('data');
        $this->assertSame(['老师会员'], collect($coachFollowups)->pluck('name')->all());
        $coachAlerts = collect($this->getJson('/api/today/alerts')->assertOk()->json('data'))->pluck('text')->join(' ');
        $this->assertStringContainsString('老师客资', $coachAlerts);
        $this->assertStringNotContainsString('待分配客资', $coachAlerts);
        $this->assertStringNotContainsString('同事客资', $coachAlerts);
        $this->assertStringNotContainsString('跨店', $coachAlerts);

        Sanctum::actingAs($this->user('today-manager', '绿地店长', 'R_MANAGER', '绿地店'));
        $managerAlerts = collect($this->getJson('/api/today/alerts')->assertOk()->json('data'))->pluck('text')->join(' ');
        $this->assertStringNotContainsString('跨店', $managerAlerts);

        // 新媒体：只看自己录入的客资。别人的客资（含跨店）都不应出现在提醒里。
        $media = $this->user('today-media', '新媒体', 'R_MEDIA', null);
        Sanctum::actingAs($media);
        $this->lead('媒体自己客资', '13800000014', '东部店', '', $media->name);
        $this->getJson('/api/today/followups')->assertOk()->assertJsonCount(0, 'data');
        $mediaAlerts = collect($this->getJson('/api/today/alerts')->assertOk()->json('data'))->pluck('text')->join(' ');
        $this->assertStringContainsString('媒体自己客资', $mediaAlerts);
        $this->assertStringNotContainsString('老师客资', $mediaAlerts);
        $this->assertStringNotContainsString('待分配客资', $mediaAlerts);
        $this->assertStringNotContainsString('跨店客资', $mediaAlerts);
        $this->assertStringNotContainsString('任务', $mediaAlerts);
        $this->assertStringNotContainsString('卡项临近到期', $mediaAlerts);
    }

    /** 新媒体账号的客资列表只返回自己录入的（此前的实现返回双店全量） */
    public function test_media_lead_list_only_shows_own_entries(): void
    {
        $this->lead('自己录入', '13800000021', '绿地店', '', '新媒体小张');
        $this->lead('绿地同事录入', '13800000022', '绿地店', '', '绿地店长');
        $this->lead('东部同事录入', '13800000023', '东部店', '', '东部店长');

        Sanctum::actingAs($this->user('list-media', '新媒体小张', 'R_MEDIA', null));
        $mediaNames = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))->pluck('name')->all();
        $this->assertSame(['自己录入'], $mediaNames);

        // 店长口径不变：本店全部，但不含跨店
        Sanctum::actingAs($this->user('list-manager', '绿地店长', 'R_MANAGER', '绿地店'));
        $managerNames = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))->pluck('name')->all();
        $this->assertContains('自己录入', $managerNames);
        $this->assertContains('绿地同事录入', $managerNames);
        $this->assertNotContains('东部同事录入', $managerNames);
    }

    /** 手机号带分隔符也要能查到重复，且落库归一为纯数字（否则查重静默漏报 → 重复客资） */
    public function test_lead_check_and_store_normalize_phone_separators(): void
    {
        $this->lead('已有客资', '13800000031', '绿地店', '', '绿地店长');
        Sanctum::actingAs($this->user('check-manager', '绿地店长', 'R_MANAGER', '绿地店'));

        $res = $this->getJson('/api/leads/check?phone=138-0000-0031')->assertOk();
        $this->assertTrue($res->json('data.exists'));
        $this->assertSame('已有客资', $res->json('data.matches.0.name'));

        $id = $this->postJson('/api/leads', [
            'name' => '带分隔符录入', 'source' => '到店', 'venue' => '绿地店', 'phone' => '138 0000 0032',
        ])->assertOk()->json('data.id');
        $this->assertDatabaseHas('leads', ['id' => $id, 'phone' => '13800000032']);

        // 归一后再查这条新数据，同样能命中
        $this->assertTrue($this->getJson('/api/leads/check?phone=13800000032')->assertOk()->json('data.exists'));
    }

    public function test_media_tasks_are_personal_and_analytics_follow_allowed_venues(): void
    {
        Task::create(['title' => '东部任务', 'customer_name' => '甲', 'venue' => '东部店', 'owner' => '东部老师', 'status' => '待接收']);
        Task::create(['title' => '新媒体任务', 'customer_name' => '乙', 'venue' => '东部店', 'owner' => '新媒体', 'status' => '待接收']);
        Lead::create(['lead_date' => now()->toDateString(), 'name' => '东部客资', 'source' => '测试', 'venue' => '东部店', 'status' => '已成交', 'deal_amount' => 100]);
        Lead::create(['lead_date' => now()->toDateString(), 'name' => '绿地客资', 'source' => '小红书', 'venue' => '绿地店', 'status' => '新留资']);

        $media = $this->user('media-scope', '新媒体', 'R_MEDIA', null);
        Sanctum::actingAs($media);

        // 任务：只能看到指派给自己的
        $tasks = $this->getJson('/api/tasks')->assertOk()->json('data.records');
        $this->assertSame(['新媒体任务'], collect($tasks)->pluck('title')->all());

        $this->getJson('/api/analytics/channels')->assertOk()->assertJsonPath('data.total', 2);
        $this->getJson('/api/analytics/channels?venue='.urlencode('东部店'))->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/analytics/trends')->assertOk()->assertJsonPath('data.summary.leadCount', 2);
        $this->getJson('/api/analytics/platforms')->assertOk()->assertJsonPath('data.totalDeal', 100);

        $greenOnly = $this->user('media-green', '绿地新媒体', 'R_MEDIA', null);
        $greenOnly->update(['venues' => ['绿地店']]);
        Sanctum::actingAs($greenOnly);
        $this->getJson('/api/analytics/channels?venue='.urlencode('东部店'))->assertForbidden();
        $this->getJson('/api/analytics/trends?venue='.urlencode('东部店'))->assertForbidden();
        $this->getJson('/api/analytics/platforms?venue='.urlencode('东部店'))->assertForbidden();
        $this->getJson('/api/analytics/channels')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/analytics/trends')->assertOk()->assertJsonPath('data.summary.leadCount', 1);
        $this->getJson('/api/analytics/platforms')->assertOk()->assertJsonPath('data.totalDeal', 0);
    }

    private function user(string $username, string $name, string $role, ?string $venue): User
    {
        return User::factory()->create([
            'username' => $username,
            'name' => $name,
            'role' => $role,
            'venue' => $venue,
            'venues' => $venue ? [$venue] : ['绿地店', '东部店'],
            'status' => '启用',
        ]);
    }

    private function lead(string $name, string $phone, string $venue, string $teacher = '', string $createdBy = ''): Lead
    {
        return Lead::create([
            'lead_date' => now()->toDateString(),
            'name' => $name,
            'phone' => $phone,
            'source' => '测试',
            'venue' => $venue,
            'service_teacher' => $teacher,
            'created_by' => $createdBy,
            'status' => '新留资',
        ]);
    }

    private function customer(string $name, string $venue, string $consultant, string $layer): Customer
    {
        return Customer::create([
            'name' => $name,
            'venue' => $venue,
            'consultant' => $consultant,
            'owner' => $consultant,
            'layer' => $layer,
        ]);
    }

    private function auditFor(Lead $lead): void
    {
        AuditLog::create([
            'operator_name' => '测试',
            'operator_role' => '超管',
            'action' => '修改',
            'module' => '前端客资',
            'target_id' => (string) $lead->id,
            'target_label' => $lead->name,
            'venue' => $lead->venue,
        ]);
    }
}
