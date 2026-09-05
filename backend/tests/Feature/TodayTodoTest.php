<?php

namespace Tests\Feature;

use App\Models\KyBooking;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TodayTodoTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_todo_aggregates_bookings_renewals_risks_birthdays_tasks_and_leads(): void
    {
        Sanctum::actingAs($this->user('manager-green', '绿地店长', 'R_MANAGER', '绿地店'));

        $renewing = Customer::create($this->customerAttributes('待续会员', '13800000001', [
            'main_card' => '私教卡', 'remain_times' => 5, 'attend_m3' => 4,
            'expire_date' => now()->addDays(5)->toDateString(),
        ]));
        $preLoss = Customer::create($this->customerAttributes('流失会员', '13800000002', [
            'main_card' => '团课卡', 'last_visit' => now()->subDays(20)->toDateString(),
            'attend_m2' => 3, 'attend_m3' => 0,
        ]));
        $birthday = Customer::create($this->customerAttributes('生日会员', '13800000003', [
            'main_card' => '私教卡', 'birthday' => date('Y').'-'.date('m-d'),
        ]));

        $this->booking('待续会员', '13800000001', '09:00', 'signed', ['course_type' => '2']);
        $this->booking('体验客小张', '', '10:30', 'booked', ['course_type' => '1'], true);
        $this->booking('已取消会员', '', '11:00', 'cancelled', ['course_type' => '2']);

        Task::create([
            'title' => '续费方案确认', 'customer_name' => '待续会员', 'venue' => '绿地店',
            'owner' => '绿地店长', 'priority' => '中', 'deadline' => date('Y-m-d 18:00'),
            'status' => '进行中', 'standard' => '确认续费方案',
        ]);
        Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '新客小刘', 'phone' => '13900000001',
            'source' => '大众点评', 'venue' => '绿地店', 'service_teacher' => '小周', 'status' => '新留资',
        ]);

        $res = $this->getJson('/api/today/todo')->assertOk()->json('data');

        $this->assertSame(2, $res['counts']['bookings']);
        $booking = collect($res['bookings']['items'])->firstWhere('memberName', '待续会员');
        $this->assertSame('09:00', $booking['time']);
        $this->assertSame('私教', $booking['kind']);
        $this->assertSame($renewing->id, $booking['customerId']);
        $this->assertContains('待续课', $booking['lists']);

        $renewal = collect($res['renewals'])->firstWhere('name', '待续会员');
        $this->assertSame(5, $renewal['remainTimes']);
        $this->assertTrue($renewal['urgent']);

        $risk = collect($res['churnRisks'])->firstWhere('name', '流失会员');
        $this->assertContains('预流失', $risk['lists']);
        $this->assertSame(20, $risk['lastVisitDays']);

        $bd = collect($res['birthdays'])->firstWhere('name', '生日会员');
        $this->assertTrue($bd['isToday']);
        $this->assertSame(0, $bd['daysLater']);

        $this->assertSame(1, $res['counts']['trials']);
        $this->assertSame('体验客小张', $res['trials'][0]['name']);

        $this->assertSame(1, $res['counts']['tasks']);
        $this->assertSame('续费方案确认', $res['tasks'][0]['title']);

        $this->assertSame(1, $res['counts']['newLeads']);
        $this->assertSame('新客小刘', $res['newLeads'][0]['name']);
    }

    public function test_manager_todo_is_venue_scoped_and_super_sees_all_venues(): void
    {
        $manager = $this->user('manager-green', '绿地店长', 'R_MANAGER', '绿地店');
        Sanctum::actingAs($manager);

        Customer::create($this->customerAttributes('绿地到期', '13800000011', [
            'main_card' => '私教卡', 'remain_times' => 3, 'attend_m3' => 2,
        ]));
        Customer::create(array_merge($this->customerAttributes('东部到期', '13800000012', [
            'main_card' => '私教卡', 'remain_times' => 3, 'attend_m3' => 2,
        ]), ['venue' => '东部店']));
        $this->booking('东部客人', '13800000012', '08:00', 'booked', ['course_type' => '2'], false, '东部店');

        $res = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $this->assertSame(0, $res['counts']['bookings']);
        $this->assertSame(1, $res['counts']['renewals']);
        $this->assertSame('绿地到期', $res['renewals'][0]['name']);

        Sanctum::actingAs($this->user('super', '超管', 'R_SUPER', null));
        $res = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $this->assertSame(1, $res['counts']['bookings']);
        $this->assertSame(2, $res['counts']['renewals']);
    }

    public function test_media_todo_only_contains_leads_scope(): void
    {
        Sanctum::actingAs($this->user('media', '新媒体小李', 'R_MEDIA', '绿地店'));

        Customer::create($this->customerAttributes('在籍会员', '13800000021', [
            'main_card' => '私教卡', 'remain_times' => 3, 'attend_m3' => 2,
        ]));
        $this->booking('在籍会员', '13800000021', '09:00', 'booked', ['course_type' => '2']);
        Lead::create([
            'lead_date' => now()->subDays(2)->toDateString(), 'name' => '媒体客小王', 'phone' => '13900000002',
            'source' => '小红书', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '新留资',
            'created_at' => now()->subDays(2),
        ]);

        $res = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $this->assertSame(0, $res['counts']['bookings']);
        $this->assertSame(0, $res['counts']['renewals']);
        $this->assertSame(0, $res['counts']['churnRisks']);
        $this->assertSame(0, $res['counts']['birthdays']);
        $this->assertSame(1, $res['counts']['newLeads']);
        $this->assertTrue($res['newLeads'][0]['stale']);
    }

    public function test_customer_birthday_can_be_maintained_through_member_api(): void
    {
        Sanctum::actingAs($this->user('manager-green', '绿地店长', 'R_MANAGER', '绿地店'));
        $customer = Customer::create($this->customerAttributes('生日维护', '13800000031', [
            'main_card' => '私教卡',
        ]));

        $this->patchJson("/api/customers/{$customer->id}", ['birthday' => '1992-08-15'])
            ->assertOk()
            ->assertJsonPath('data.birthday', '1992-08-15');
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'birthday' => '1992-08-15']);

        $this->patchJson("/api/customers/{$customer->id}", ['birthday' => ''])->assertOk();
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'birthday' => null]);
    }

    private function user(string $username, string $name, string $role, ?string $venue): User
    {
        return User::factory()->create(compact('username', 'name', 'role', 'venue'));
    }

    private function customerAttributes(string $name, string $phone, array $overrides): array
    {
        return array_merge([
            'name' => $name,
            'phone' => $phone,
            'phone_tail' => substr($phone, -4),
            'venue' => '绿地店',
            'source' => 'KeepYoga',
            'owner' => '绿地店长',
            'consultant' => '绿地店长',
            'main_card' => '—',
            'layer' => 'P4',
            'status' => '在籍',
        ], $overrides);
    }

    private function booking(
        string $memberName,
        string $phone,
        string $time,
        string $status,
        array $raw = [],
        bool $isTrial = false,
        string $venue = '绿地店'
    ): void {
        KyBooking::create([
            'source_key' => uniqid('t-'),
            'venue' => $venue,
            'booking_type' => ($raw['course_type'] ?? '1') === '2' ? '私教' : '团课',
            'member_id' => 'm-'.md5($memberName),
            'member_name' => $memberName,
            'phone' => $phone,
            'start_at' => date('Y-m-d').' '.$time.':00',
            'course_name' => $isTrial ? '体验课' : '普拉提私教',
            'teacher_name' => '小周',
            'status_raw' => '',
            'status' => $status,
            'is_trial' => $isTrial,
            'raw' => $raw,
        ]);
    }
}
