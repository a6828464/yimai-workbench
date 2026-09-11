<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KyBooking;
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

    public function test_mark_trial_no_show_flows_lead_status_and_marks_card(): void
    {
        Sanctum::actingAs($this->user('manager-green', '绿地店长', 'R_MANAGER', '绿地店'));
        $lead = Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '体验小王', 'phone' => '13900000011',
            'source' => '大众点评', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '已约体验',
            'trial_cards' => [
                ['session' => 1, 'time' => now()->format('Y-m-d 10:00'), 'topic' => '普拉提体验', 'teacher' => '老师A', 'couponName' => '', 'voucherCode' => '', 'platform' => '美团'],
                ['session' => 2, 'time' => now()->addDay()->format('Y-m-d 10:00'), 'topic' => '二次体验', 'teacher' => '老师A', 'couponName' => '', 'voucherCode' => '', 'platform' => '美团'],
            ],
        ]);

        // 待办体验课项携带留资定位信息
        $todo = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $item = collect($todo['trials'])->firstWhere('key', 'trial:lead-'.$lead->id.'-1');
        $this->assertNotNull($item);
        $this->assertSame($lead->id, $item['leadId']);
        $this->assertSame(1, $item['session']);

        // 标记爽约 → 留资状态流转为「爽约」，第1节卡片标记已爽约，第2节不受影响（跟进时限不受爽约影响）
        $this->postJson('/api/today/todo/action', [
            'type' => 'trials', 'key' => 'trial:lead-'.$lead->id.'-1', 'action' => '爽约',
        ])->assertOk()->assertJsonPath('data.done', true);

        $fresh = $lead->fresh();
        $this->assertSame('爽约', $fresh->status);
        $this->assertTrue($fresh->trial_cards[0]['noShow']);
        $this->assertArrayNotHasKey('noShow', $fresh->trial_cards[1]);
    }

    public function test_mark_trial_attended_flows_lead_status(): void
    {
        Sanctum::actingAs($this->user('manager-green', '绿地店长', 'R_MANAGER', '绿地店'));
        $lead = Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '体验小李', 'phone' => '13900000012',
            'source' => '美团', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '已联系',
            'trial_cards' => [
                ['session' => 1, 'time' => now()->format('Y-m-d 15:00'), 'topic' => '体态评估', 'teacher' => '老师B', 'couponName' => '', 'voucherCode' => '', 'platform' => '大众点评'],
            ],
        ]);

        $this->postJson('/api/today/todo/action', [
            'type' => 'trials', 'key' => 'trial:lead-'.$lead->id.'-1', 'action' => '已接待',
        ])->assertOk();

        $fresh = $lead->fresh();
        $this->assertSame('已体验', $fresh->status);
        $this->assertTrue($fresh->trial_cards[0]['attended']);
    }

    public function test_mark_trial_does_not_overwrite_terminal_lead_status(): void
    {
        Sanctum::actingAs($this->user('manager-green', '绿地店长', 'R_MANAGER', '绿地店'));
        $lead = Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '已成单客户', 'phone' => '13900000013',
            'source' => '小红书', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '已成交',
            'trial_cards' => [
                ['session' => 1, 'time' => now()->format('Y-m-d 11:00'), 'topic' => '加练体验', 'teacher' => '老师A', 'couponName' => '', 'voucherCode' => '', 'platform' => ''],
            ],
        ]);

        $this->postJson('/api/today/todo/action', [
            'type' => 'trials', 'key' => 'trial:lead-'.$lead->id.'-1', 'action' => '爽约',
        ])->assertOk();

        // 终态（已成交/已流失）不被回退，但卡片结果仍如实记录
        $fresh = $lead->fresh();
        $this->assertSame('已成交', $fresh->status);
        $this->assertTrue($fresh->trial_cards[0]['noShow']);
        $this->assertDatabaseHas('todo_actions', ['todo_key' => 'trial:lead-'.$lead->id.'-1']);
    }

    public function test_ky_trial_links_unique_lead_and_skips_on_ambiguous_phone(): void
    {
        Sanctum::actingAs($this->user('manager-green', '绿地店长', 'R_MANAGER', '绿地店'));
        $booking = KyBooking::create([
            'source_key' => 'GV:团课:test-1', 'venue' => '绿地店', 'booking_type' => '团课',
            'member_name' => 'KY体验客', 'phone' => '13900000022',
            'start_at' => now()->format('Y-m-d 10:00'), 'course_name' => '体验团课',
            'status' => 'booked', 'is_trial' => true,
        ]);
        $unique = Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '唯一留资', 'phone' => '13900000022',
            'source' => '大众点评', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '已约体验',
        ]);

        // 手机号唯一命中一条进行中留资 → 状态联动
        $this->postJson('/api/today/todo/action', [
            'type' => 'trials', 'key' => 'trial:ky-'.$booking->id, 'action' => '爽约',
        ])->assertOk();
        $this->assertSame('爽约', $unique->fresh()->status);

        // 同手机号两条进行中留资 → 视为歧义跳过联动，当日标记仍生效
        $ambiguous = KyBooking::create([
            'source_key' => 'GV:团课:test-2', 'venue' => '绿地店', 'booking_type' => '团课',
            'member_name' => '共用号码', 'phone' => '13900000033',
            'start_at' => now()->format('Y-m-d 14:00'), 'course_name' => '体验团课',
            'status' => 'booked', 'is_trial' => true,
        ]);
        Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '留资甲', 'phone' => '13900000033',
            'source' => '大众点评', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '已约体验',
        ]);
        $leadB = Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '留资乙', 'phone' => '13900000033',
            'source' => '美团', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '已联系',
        ]);

        $this->postJson('/api/today/todo/action', [
            'type' => 'trials', 'key' => 'trial:ky-'.$ambiguous->id, 'action' => '爽约',
        ])->assertOk();
        $this->assertSame('已约体验', Lead::where('name', '留资甲')->value('status'));
        $this->assertSame('已联系', $leadB->fresh()->status);
        $this->assertDatabaseHas('todo_actions', ['todo_key' => 'trial:ky-'.$ambiguous->id]);
    }

    private function user(string $username, string $name, string $role, ?string $venue): User
    {
        return User::factory()->create(compact('username', 'name', 'role', 'venue'));
    }
}
