<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Customer;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RenewalEvaluationTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_evaluate_own_member_and_server_creates_linked_task(): void
    {
        $teacher = $this->user('teacher-green', '小周', 'R_TEACHER', '绿地店');
        $this->user('manager-green', '绿地店长', 'R_MANAGER', '绿地店');
        $customer = $this->customer('绿地会员', '绿地店', '小周');
        Sanctum::actingAs($teacher);

        $response = $this->putJson("/api/customers/{$customer->id}/renewal-evaluation", [
            'answers' => [
                'goal' => 'none',
                'feedback' => 'none',
                'wechat' => 'no_response',
                'intent' => 'none',
                'service' => 'unresolved',
                'risks' => ['complaint_unresolved'],
            ],
            'remark' => '需要店长介入',
            'score' => 99,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.evaluation.score', 0)
            ->assertJsonPath('data.evaluation.level', 'low')
            ->assertJsonPath('data.task.customerId', $customer->id)
            ->assertJsonPath('data.task.owner', '绿地店长')
            ->assertJsonPath('data.task.reviewRole', 'R_MANAGER');
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'eval_score' => 0,
            'eval_level' => 'low',
            'eval_by' => '小周',
            'next_action' => '店长介入续费修复',
        ]);
        $this->assertDatabaseHas('tasks', [
            'customer_id' => $customer->id,
            'source_type' => 'renewal_evaluation',
            'title' => '店长介入续费修复',
        ]);
    }

    public function test_teacher_cannot_access_another_teachers_member(): void
    {
        Sanctum::actingAs($this->user('teacher-a', '老师A', 'R_TEACHER', '绿地店'));
        $customer = $this->customer('其他会员', '绿地店', '老师B');

        $this->getJson("/api/customers/{$customer->id}/renewal-evaluation")->assertForbidden();
        $this->patchJson("/api/customers/{$customer->id}", ['needsHelp' => true])->assertForbidden();
    }

    public function test_only_super_admin_can_change_global_member_rules(): void
    {
        Sanctum::actingAs($this->user('manager', '店长', 'R_MANAGER', '绿地店'));

        $this->putJson('/api/member-rules', [
            'renewalThreshold' => 8,
            'vipAmountThreshold' => 30000,
            'declineMode' => 'strict',
            'predropMin' => 15,
            'predropMax' => 30,
            'reviveDays' => 30,
        ])->assertForbidden();
    }

    public function test_task_filters_and_manager_only_verification_are_enforced(): void
    {
        $teacher = $this->user('teacher-task', '执行老师', 'R_TEACHER', '绿地店');
        $manager = $this->user('manager-task', '绿地店长', 'R_MANAGER', '绿地店');
        $task = Task::create([
            'title' => '续费跟进',
            'customer_name' => '会员',
            'venue' => '绿地店',
            'owner' => '执行老师',
            'priority' => '高',
            'deadline' => '2026-08-30 18:00',
            'status' => '进行中',
            'standard' => '完成沟通',
        ]);

        Sanctum::actingAs($teacher);
        $this->patchJson("/api/tasks/{$task->id}", ['status' => '已完成'])->assertStatus(422);
        $this->patchJson("/api/tasks/{$task->id}", ['status' => '待验收'])->assertOk();

        Sanctum::actingAs($manager);
        $this->getJson('/api/tasks?status='.urlencode('待验收').'&venue='.urlencode('绿地店'))
            ->assertOk()->assertJsonPath('data.total', 1);
        $this->patchJson("/api/tasks/{$task->id}", ['status' => '已完成'])->assertOk();
    }

    public function test_lead_create_defaults_lead_date_and_ignores_non_column_fields(): void
    {
        Sanctum::actingAs($this->user('media', '阿玉', 'R_MEDIA', null));

        $this->postJson('/api/leads', [
            'id' => 0,
            'name' => '新增验证',
            'phone' => '13800000002',
            'phoneTail' => '0002',
            'source' => '抖音',
            'venue' => '绿地店',
            'dealAmount' => '',
            'redeemAmount' => '',
            'trialCards' => [['session' => 1, 'time' => '2026-09-01 10:00', 'topic' => '内观流']],
        ])->assertOk()->assertJsonPath('data.id', 1);

        $this->assertDatabaseHas('leads', [
            'id' => 1,
            'name' => '新增验证',
            'lead_date' => now()->toDateString(),
        ]);
    }

    public function test_lead_edit_patch_does_not_500_and_preserves_creator(): void
    {
        Sanctum::actingAs($this->user('media', '阿玉', 'R_MEDIA', null));
        $created = $this->postJson('/api/leads', [
            'name' => '编辑验证',
            'phone' => '13800000003',
            'source' => '美团',
            'venue' => '东部店',
        ])->assertOk()->json('data.id');

        // 编辑时把完整行（含 phoneTail/id/dealAt/createdBy 等非库表字段）回传
        $this->patchJson("/api/leads/{$created}", [
            'id' => $created,
            'name' => '编辑验证',
            'phone' => '13800000003',
            'phoneTail' => '0003',
            'source' => '美团',
            'venue' => '东部店',
            'status' => '已成交',
            'dealAmount' => 3200,
            'redeemAmount' => 0,
            'dealAt' => null,
            'redeemedAt' => null,
            'createdBy' => '别的人',
            'createdAt' => '2026-08-26T02:02:44.000000Z',
            'trialCards' => [],
        ])->assertOk();

        $this->assertDatabaseHas('leads', [
            'id' => $created,
            'status' => '已成交',
            'created_by' => '阿玉',
        ]);
    }

    public function test_teacher_cannot_decide_approval_and_state_machine_is_enforced(): void
    {
        $approval = Approval::create([
            'customer_name' => '测试客户',
            'applicant' => '店长',
            'venue' => '绿地店',
            'card_name' => '年卡',
            'standard_price' => 200,
            'request_price' => 150,
            'status' => '待店长初审',
            'apply_time' => now()->format('Y-m-d H:i'),
        ]);
        $teacher = $this->user('teacher-decide', '老师', 'R_TEACHER', '绿地店');
        Sanctum::actingAs($teacher);

        $this->postJson("/api/approvals/{$approval->id}/decide", ['decision' => '初审通过'])->assertForbidden();
    }

    public function test_manager_cannot_decide_another_venues_approval(): void
    {
        $approval = Approval::create([
            'customer_name' => '东部客户',
            'applicant' => '东部店长',
            'venue' => '东部店',
            'card_name' => '年卡',
            'standard_price' => 200,
            'request_price' => 150,
            'status' => '待店长初审',
            'apply_time' => now()->format('Y-m-d H:i'),
        ]);
        Sanctum::actingAs($this->user('manager-green-approval', '绿地店长', 'R_MANAGER', '绿地店'));

        $this->postJson("/api/approvals/{$approval->id}/decide", ['decision' => '初审通过'])->assertForbidden();
    }

    public function test_approval_list_filters_by_status_and_scopes_manager_to_own_venue(): void
    {
        Approval::create([
            'customer_name' => '绿地客户', 'applicant' => '绿地店长', 'venue' => '绿地店',
            'card_name' => '年卡', 'standard_price' => 200, 'request_price' => 150,
            'status' => '待店长初审', 'apply_time' => now()->format('Y-m-d H:i'),
        ]);
        Approval::create([
            'customer_name' => '东部客户', 'applicant' => '东部店长', 'venue' => '东部店',
            'card_name' => '年卡', 'standard_price' => 200, 'request_price' => 150,
            'status' => '已通过', 'apply_time' => now()->format('Y-m-d H:i'),
        ]);
        Sanctum::actingAs($this->user('manager-approval-list', '绿地店长', 'R_MANAGER', '绿地店'));

        $this->getJson('/api/approvals')->assertOk()->assertJsonCount(1, 'data.records');
        $this->getJson('/api/approvals?status='.urlencode('待店长初审'))
            ->assertOk()
            ->assertJsonPath('data.records.0.customerName', '绿地客户');
        $this->getJson('/api/approvals?status='.urlencode('已通过'))->assertOk()->assertJsonCount(0, 'data.records');
    }

    public function test_super_can_create_approval_with_venue(): void
    {
        Sanctum::actingAs($this->user('approval-owner', '老板', 'R_SUPER', null));
        $this->postJson('/api/approvals', [
            'customerName' => '指定门店客户',
            'cardName' => '私教年卡',
            'standardPrice' => 8800,
            'requestPrice' => 7980,
            'venue' => '东部店',
        ])->assertOk()->assertJsonPath('data.venue', '东部店');
    }

    public function test_customer_workflow_patch_normalizes_string_booleans(): void
    {
        $manager = $this->user('manager-cw', '绿地店长', 'R_MANAGER', '绿地店');
        $customer = $this->customer('工作流会员', '绿地店', '绿地店长');
        Sanctum::actingAs($manager);

        $this->patchJson("/api/customers/{$customer->id}", [
            'lastTouch' => '',
            'needsHelp' => 'false',
            'inRevive' => 'true',
        ])->assertOk();

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'last_touch' => null,
            'needs_help' => 0,
            'in_revive' => 1,
        ]);
    }

    private function user(string $username, string $name, string $role, ?string $venue): User
    {
        return User::factory()->create(compact('username', 'name', 'role', 'venue'));
    }

    private function customer(string $name, string $venue, string $consultant): Customer
    {
        return Customer::create([
            'name' => $name,
            'phone' => '13800000001',
            'phone_tail' => '0001',
            'venue' => $venue,
            'source' => 'KeepYoga',
            'consultant' => $consultant,
            'owner' => $consultant,
            'main_card' => '私教卡',
            'remain_times' => 30,
            'layer' => 'P4',
            'status' => '在籍',
        ]);
    }
}
