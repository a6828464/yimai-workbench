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

    public function test_teacher_only_accesses_own_venue_assigned_or_unassigned_leads(): void
    {
        $teacher = $this->user('teacher-green', '绿地老师', 'R_TEACHER', '绿地店');
        $own = $this->lead('本人客资', '13800000002', '绿地店', '绿地老师');
        $unassigned = $this->lead('待认领客资', '13800000002', '绿地店');
        $colleague = $this->lead('同事客资', '13800000002', '绿地店', '其他老师');
        $otherVenue = $this->lead('跨店客资', '13800000002', '东部店');
        Sanctum::actingAs($teacher);

        $this->getJson('/api/leads/check?phone=13800000002')
            ->assertOk()
            ->assertJsonCount(2, 'data.matches');
        $this->patchJson("/api/leads/{$own->id}", ['remark' => '本人跟进'])->assertOk();
        $this->patchJson("/api/leads/{$unassigned->id}", ['serviceTeacher' => '绿地老师'])->assertOk();
        $this->patchJson("/api/leads/{$colleague->id}", ['remark' => '越权'])->assertForbidden();
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

        Sanctum::actingAs($this->user('today-teacher', '绿地老师', 'R_TEACHER', '绿地店'));
        $followups = $this->getJson('/api/today/followups')->assertOk()->json('data');
        $this->assertSame(['老师会员'], collect($followups)->pluck('name')->all());
        $teacherAlerts = collect($this->getJson('/api/today/alerts')->assertOk()->json('data'))->pluck('text')->join(' ');
        $this->assertStringContainsString('老师客资', $teacherAlerts);
        $this->assertStringContainsString('待分配客资', $teacherAlerts);
        $this->assertStringNotContainsString('同事客资', $teacherAlerts);
        $this->assertStringNotContainsString('跨店', $teacherAlerts);

        Sanctum::actingAs($this->user('today-manager', '绿地店长', 'R_MANAGER', '绿地店'));
        $managerAlerts = collect($this->getJson('/api/today/alerts')->assertOk()->json('data'))->pluck('text')->join(' ');
        $this->assertStringNotContainsString('跨店', $managerAlerts);

        Sanctum::actingAs($this->user('today-media', '新媒体', 'R_MEDIA', null));
        $this->getJson('/api/today/followups')->assertOk()->assertJsonCount(0, 'data');
        $mediaAlerts = collect($this->getJson('/api/today/alerts')->assertOk()->json('data'))->pluck('text')->join(' ');
        $this->assertStringContainsString('客资', $mediaAlerts);
        $this->assertStringNotContainsString('任务', $mediaAlerts);
        $this->assertStringNotContainsString('卡项临近到期', $mediaAlerts);
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

    private function lead(string $name, string $phone, string $venue, string $teacher = ''): Lead
    {
        return Lead::create([
            'lead_date' => now()->toDateString(),
            'name' => $name,
            'phone' => $phone,
            'source' => '测试',
            'venue' => $venue,
            'service_teacher' => $teacher,
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
