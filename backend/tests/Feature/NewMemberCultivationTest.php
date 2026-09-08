<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NewMemberCultivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_sees_new_members_with_three_kind_progress_and_health(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'super', 'role' => 'R_SUPER']));
        $today = now()->toDateString();

        // 会员 A：入会 10 天，私教签到 8 节（达标 cultured），小班签到 1 节（待养成 → 因有未达标类别整体 cultivating）
        $a = Customer::create([
            'name' => '新客甲', 'phone' => '13800000001', 'venue' => '绿地店', 'external_id' => 'ky:1:1001',
            'enrolled_at' => now()->subDays(10)->toDateString(), 'layer' => 'P4', 'status' => '在籍',
        ]);
        $this->booking('1001', '绿地店', '13800000001', '2', 'signed', 8, '私教基础');
        $this->booking('1001', '绿地店', '13800000001', '3', 'signed', 1, '小班普拉提');

        // 会员 B：入会 5 天，0 上课 → idle 待激活
        Customer::create([
            'name' => '新客乙', 'phone' => '13800000002', 'venue' => '绿地店', 'external_id' => 'ky:1:1002',
            'enrolled_at' => now()->subDays(5)->toDateString(), 'layer' => 'P4', 'status' => '在籍',
        ]);

        // 会员 C：入会时间早于 90 天窗口，不算新客
        Customer::create([
            'name' => '老会员', 'phone' => '13800000003', 'venue' => '绿地店', 'external_id' => 'ky:1:1003',
            'enrolled_at' => now()->subDays(120)->toDateString(), 'layer' => 'P4', 'status' => '在籍',
        ]);

        $res = $this->getJson('/api/new-members/cultivation')->assertOk()->assertJsonPath('data.summary.total', 2);
        $records = collect($res->json('data.records'))->keyBy('name');

        $this->assertArrayHasKey('新客甲', $records->all());
        $this->assertArrayNotHasKey('老会员', $records->all());
        $this->assertSame('cultivating', $records['新客甲']['health']);
        $this->assertSame(8, $records['新客甲']['categories']['private']['signed']);
        $this->assertSame(8, $records['新客甲']['categories']['private']['target']);
        $this->assertContains('私教基础', $records['新客甲']['themes']);
        $this->assertSame('idle', $records['新客乙']['health']);
        $this->assertSame(1, $res->json('data.summary.idle'));
    }

    public function test_only_enrolled_after_start_and_before_today_are_counted(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'super2', 'role' => 'R_SUPER']));
        Customer::create([
            'name' => '窗口内', 'phone' => '13800000010', 'venue' => '东部店', 'external_id' => 'ky:4250:2001',
            'enrolled_at' => now()->subDays(2)->toDateString(), 'layer' => 'P4', 'status' => '在籍',
        ]);
        // 入会前体验课不应计入养成
        $this->booking('2001', '东部店', '13800000010', '1', 'signed', 3, '团课垫上');
        $this->booking('2001', '东部店', '13800000010', '1', 'signed', 2, '团课垫上', 40);

        $records = $this->getJson('/api/new-members/cultivation')->assertOk()->json('data.records');
        $this->assertCount(1, $records);
        // 入会后仅 3 节计入（2 节是入会前的）
        $this->assertSame(3, $records[0]['categories']['group']['signed']);
    }

    public function test_teacher_only_sees_own_members_and_manager_scoped_to_venue(): void
    {
        Customer::create([
            'name' => '老师名下', 'phone' => '13800000020', 'venue' => '绿地店', 'external_id' => 'ky:1:3001',
            'enrolled_at' => now()->subDays(3)->toDateString(), 'layer' => 'P4', 'status' => '在籍',
            'owner' => '绿地老师', 'consultant' => '绿地老师',
        ]);
        Customer::create([
            'name' => '他人名下', 'phone' => '13800000021', 'venue' => '绿地店', 'external_id' => 'ky:1:3002',
            'enrolled_at' => now()->subDays(3)->toDateString(), 'layer' => 'P4', 'status' => '在籍',
            'owner' => '其他老师', 'consultant' => '其他老师',
        ]);
        Customer::create([
            'name' => '东部新客', 'phone' => '13800000022', 'venue' => '东部店', 'external_id' => 'ky:4250:3003',
            'enrolled_at' => now()->subDays(3)->toDateString(), 'layer' => 'P4', 'status' => '在籍',
        ]);

        Sanctum::actingAs(User::factory()->create(['username' => 't1', 'name' => '绿地老师', 'role' => 'R_TEACHER', 'venue' => '绿地店']));
        $names = collect($this->getJson('/api/new-members/cultivation')->assertOk()->json('data.records'))->pluck('name')->all();
        $this->assertSame(['老师名下'], $names);

        Sanctum::actingAs(User::factory()->create(['username' => 'm1', 'name' => '绿地店长', 'role' => 'R_MANAGER', 'venue' => '绿地店']));
        $names = collect($this->getJson('/api/new-members/cultivation')->assertOk()->json('data.records'))->pluck('name')->all();
        $this->assertContains('老师名下', $names);
        $this->assertContains('他人名下', $names);
        $this->assertNotContains('东部新客', $names);
    }

    private function booking(string $memberId, string $venue, string $phone, string $courseType, string $status, int $count, string $course, int $daysAgo = 1): void
    {
        static $seq = 0;
        for ($i = 0; $i < $count; $i++) {
            $seq++;
            KyBooking::create([
                'source_key' => "ky:{$memberId}:{$courseType}:{$status}:{$seq}",
                'venue' => $venue,
                'booking_type' => $courseType === '2' ? '私教' : '团课',
                'member_id' => $memberId,
                'member_name' => '会员',
                'phone' => $phone,
                'start_at' => now()->subDays($daysAgo)->format('Y-m-d H:i:s'),
                'course_name' => $course,
                'status' => $status,
                'is_trial' => false,
                'raw' => ['course_type' => $courseType],
            ]);
        }
    }
}
