<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Models\TodoAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TodoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_lead_todo_updates_lead_status_and_hides_from_pending(): void
    {
        Sanctum::actingAs($this->user('manager-green', '绿地店长', 'R_MANAGER', '绿地店'));
        $lead = Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '新客小刘', 'phone' => '13900000001',
            'source' => '大众点评', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '新留资',
        ]);

        // 待办里出现该客资（未处理）
        $todo = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $item = collect($todo['newLeads'])->firstWhere('id', $lead->id);
        $this->assertSame('lead:'.$lead->id, $item['key']);
        $this->assertFalse($item['done']);
        $this->assertSame('13900000001', $item['phone']);

        // 标记已首响 → 留资状态流转为已联系（离开新留资分组，进入下一步）
        $this->postJson('/api/today/todo/action', [
            'type' => 'newLeads', 'key' => 'lead:'.$lead->id, 'action' => '已首响',
            'leadId' => $lead->id, 'remark' => '电话已沟通',
        ])->assertOk()->assertJsonPath('data.done', true);

        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'status' => '已联系']);
        $this->assertDatabaseHas('todo_actions', [
            'todo_key' => 'lead:'.$lead->id, 'action' => '已首响', 'user_name' => '绿地店长',
        ]);

        // 再次拉取：该客资已流转出「新客首响」分组
        $todo = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $this->assertNull(collect($todo['newLeads'])->firstWhere('id', $lead->id));
    }

    public function test_mark_renewal_todo_touches_customer_last_touch(): void
    {
        Sanctum::actingAs($this->user('manager-green', '绿地店长', 'R_MANAGER', '绿地店'));
        $c = Customer::create([
            'name' => '待续会员', 'phone' => '13800000001', 'phone_tail' => '0001',
            'venue' => '绿地店', 'source' => 'KeepYoga', 'owner' => '绿地店长', 'consultant' => '绿地店长',
            'main_card' => '私教卡', 'remain_times' => 4, 'attend_m3' => 3, 'layer' => 'P4', 'status' => '在籍',
        ]);
        Task::create([
            'title' => '续费方案确认', 'customer_name' => '待续会员', 'venue' => '绿地店',
            'owner' => '绿地店长', 'priority' => '中', 'deadline' => date('Y-m-d 18:00'),
            'status' => '进行中', 'standard' => '确认续费方案',
        ]);

        $this->postJson('/api/today/todo/action', [
            'type' => 'renewals', 'key' => 'renewal:'.$c->id, 'action' => '已沟通待跟进',
            'customerId' => $c->id, 'touch' => true,
        ])->assertOk();

        $this->assertDatabaseHas('customers', ['id' => $c->id, 'last_touch' => now()->toDateString()]);
        $this->assertDatabaseHas('todo_actions', ['todo_key' => 'renewal:'.$c->id, 'action' => '已沟通待跟进']);
    }

    public function test_teacher_cannot_mark_lead_assigned_to_other_teacher(): void
    {
        Sanctum::actingAs($this->user('teacher-b', '老师B', 'R_TEACHER', '绿地店'));
        $lead = Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '他人客资', 'phone' => '13900000002',
            'source' => '小红书', 'venue' => '绿地店', 'service_teacher' => '老师A', 'status' => '新留资',
        ]);

        $this->postJson('/api/today/todo/action', [
            'type' => 'newLeads', 'key' => 'lead:'.$lead->id, 'action' => '已首响',
            'leadId' => $lead->id,
        ])->assertForbidden();
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'status' => '新留资']);
        $this->assertDatabaseMissing('todo_actions', ['todo_key' => 'lead:'.$lead->id]);
    }

    public function test_repeated_mark_updates_the_same_trace_row(): void
    {
        Sanctum::actingAs($this->user('manager-green', '绿地店长', 'R_MANAGER', '绿地店'));
        $c = Customer::create([
            'name' => '生日会员', 'phone' => '13800000003', 'phone_tail' => '0003',
            'venue' => '绿地店', 'source' => 'KeepYoga', 'owner' => '绿地店长',
            'main_card' => '私教卡', 'layer' => 'P4', 'status' => '在籍',
            'birthday' => date('Y').'-'.date('m-d'),
        ]);

        $this->postJson('/api/today/todo/action', [
            'type' => 'birthdays', 'key' => 'birthday:'.$c->id, 'action' => '已送祝福',
        ])->assertOk();
        $this->postJson('/api/today/todo/action', [
            'type' => 'birthdays', 'key' => 'birthday:'.$c->id, 'action' => '已邀约到店',
        ])->assertOk();

        $this->assertSame(1, TodoAction::where('todo_key', 'birthday:'.$c->id)->count());
        $this->assertDatabaseHas('todo_actions', ['todo_key' => 'birthday:'.$c->id, 'action' => '已邀约到店']);
    }

    private function user(string $username, string $name, string $role, ?string $venue): User
    {
        return User::factory()->create(compact('username', 'name', 'role', 'venue'));
    }
}
