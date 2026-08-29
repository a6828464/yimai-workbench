<?php

namespace Tests\Feature;

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
            'vipThreshold' => 100,
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
