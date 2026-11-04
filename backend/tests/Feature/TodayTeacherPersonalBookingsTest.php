<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * /today/summary 的「今日预约」在老师侧必须是**本人排课**（2026-11 真机反馈）。
 *
 * 事故现象：服务老师端显示的是全店今日预约（快照是「店」的聚合，被当成了「我的」）；
 * 新媒体账号因为 venue 为空，两个门店键都命中快照，直接看到双店全量预约。
 *
 * 本文件钉住四种口径：
 *  授课老师 → teacher_name 命中本人的今日排课；
 *  服务老师 → 本人名下会员手机号匹配的今日排课；
 *  新媒体   → 0（没有自己的课），scopeLabel 仍是「双店」；
 *  店长/超管 → 仍是同步快照的全店数字（未受本次改动影响）。
 */
class TodayTeacherPersonalBookingsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $username, string $name, string $role, ?string $venue = '绿地店'): User
    {
        return User::factory()->create([
            'username' => $username,
            'name' => $name,
            'role' => $role,
            'roles' => [$role],
            'venue' => $venue,
            'venues' => $venue ? [$venue] : ['绿地店', '东部店'],
            'status' => '启用',
        ]);
    }

    private function booking(string $teacher, string $phone, string $venue = '绿地店', string $kind = 'private', bool $isTrial = false, string $status = 'signed'): KyBooking
    {
        $this->seq++;

        return KyBooking::create([
            'source_key' => 'today:'.$this->seq,
            'venue' => $venue,
            'booking_type' => $kind === 'private' ? '私教' : '团课',
            'course_kind' => $kind,
            'member_name' => '学员'.$this->seq,
            'phone' => $phone,
            'start_at' => now()->setTime(9 + ($this->seq % 8), 0),
            'course_name' => '课程'.$this->seq,
            'teacher_name' => $teacher,
            'status_raw' => $status,
            'status' => $status,
            'is_trial' => $isTrial,
            'raw' => ['course_type' => $kind === 'private' ? '1' : '3'],
        ]);
    }

    private function snapshot(): void
    {
        AppSetting::create(['snapshot' => [
            'todayBookings' => ['绿地店' => 10, '东部店' => 8],
            'trialBookings' => ['绿地店' => 4, '东部店' => 3],
            'todayKinds' => [
                '绿地店' => ['私教' => 6, '小班' => 2, '团课' => 2],
                '东部店' => ['私教' => 5, '小班' => 1, '团课' => 2],
            ],
            'fetchedAt' => ['绿地店' => '2026-11-04 09:00:00', '东部店' => '2026-11-04 09:00:00'],
        ]]);
    }

    private function summaryOf(User $u): array
    {
        Sanctum::actingAs($u);

        return $this->getJson('/api/today/summary')->assertOk()->json('data');
    }

    public function test_coach_only_sees_his_own_today_classes(): void
    {
        $this->snapshot();
        $coachA = $this->user('t9-today-coach-a', '授课老师甲', 'R_TEACHER');
        $this->user('t9-today-coach-b', '授课老师乙', 'R_TEACHER');

        $this->booking('授课老师甲', '13900050001', kind: 'private');
        $this->booking('授课老师甲', '13900050002', kind: 'small', isTrial: true);
        $this->booking('授课老师乙', '13900050003', kind: 'group');
        // 取消/爽约不计（与同步快照同口径）
        $this->booking('授课老师甲', '13900050004', status: 'cancelled');
        // 门店外（东部店）的本人课，也要落到东部店键上
        $this->booking('授课老师甲', '13900050005', venue: '东部店', kind: 'group');

        $s = $this->summaryOf($coachA);

        // 绿地店 3 条里有 1 条 cancelled（不计）→ 2；东部店 1 条
        $this->assertSame(2, (int) $s['todayBookings']['绿地店']);
        $this->assertSame(1, (int) $s['todayBookings']['东部店']);
        $this->assertSame(1, (int) $s['trialBookings']['绿地店']);
        $this->assertSame(0, (int) $s['trialBookings']['东部店']);
        $this->assertSame(['私教' => 1, '小班' => 1, '团课' => 0], $s['todayKinds']['绿地店']);
        $this->assertSame(['私教' => 0, '小班' => 0, '团课' => 1], $s['todayKinds']['东部店']);
        $this->assertSame('我的 · 绿地店', $s['scopeLabel']);
        // 数据来自预约表事实，不存在「未同步」歧义
        $this->assertTrue($s['snapshotAvailable']);
        // 老师不该看到快照时间（那是店长/超管的信息）
        $this->assertSame('', $s['snapshotTime']);
    }

    public function test_service_teacher_sees_classes_of_own_members_only(): void
    {
        $this->snapshot();
        $service = $this->user('t9-today-svc', '服务老师甲', 'R_SERVICE');

        Customer::create([
            'name' => '我的会员', 'phone' => '13900060001', 'venue' => '绿地店',
            'consultant' => '服务老师甲', 'consultant_user_id' => $service->id,
            'owner' => '服务老师甲', 'layer' => 'P2',
        ]);
        Customer::create([
            'name' => '别人的会员', 'phone' => '13900060002', 'venue' => '绿地店',
            'consultant' => '服务老师乙', 'layer' => 'P2',
        ]);

        $this->booking('授课老师丙', '13900060001', kind: 'private', isTrial: true);
        $this->booking('授课老师丙', '13900060002', kind: 'group');

        $s = $this->summaryOf($service);

        $this->assertSame(1, (int) $s['todayBookings']['绿地店'], '只算本人名下会员的预约');
        $this->assertSame(0, (int) $s['todayBookings']['东部店']);
        $this->assertSame(1, (int) $s['trialBookings']['绿地店']);
        $this->assertSame(['私教' => 1, '小班' => 0, '团课' => 0], $s['todayKinds']['绿地店']);
        $this->assertSame('我的 · 绿地店', $s['scopeLabel']);
    }

    public function test_media_account_gets_zeros_not_store_totals(): void
    {
        $this->snapshot();
        $media = $this->user('t9-today-media', '新媒体小李', 'R_MEDIA', null);
        $this->booking('授课老师丁', '13900070001');

        $s = $this->summaryOf($media);

        $this->assertSame(['绿地店' => 0, '东部店' => 0], $s['todayBookings']);
        $this->assertSame(['绿地店' => 0, '东部店' => 0], $s['trialBookings']);
        $this->assertSame(['私教' => 0, '小班' => 0, '团课' => 0], $s['todayKinds']['绿地店']);
        $this->assertSame(['私教' => 0, '小班' => 0, '团课' => 0], $s['todayKinds']['东部店']);
        $this->assertSame('双店', $s['scopeLabel']);
    }

    public function test_manager_still_sees_store_snapshot(): void
    {
        $this->snapshot();
        $manager = $this->user('t9-today-manager', '绿地店长', 'R_MANAGER');
        // 店里真实有课，但店长读的是同步快照（口径不变）
        $this->booking('授课老师戊', '13900080001');

        $s = $this->summaryOf($manager);

        $this->assertSame(['绿地店' => 10, '东部店' => 0], $s['todayBookings']);
        $this->assertSame(['绿地店' => 4, '东部店' => 0], $s['trialBookings']);
        $this->assertSame(['私教' => 6, '小班' => 2, '团课' => 2], $s['todayKinds']['绿地店']);
        $this->assertSame('本店 · 绿地店', $s['scopeLabel']);
        $this->assertTrue($s['snapshotAvailable']);
        $this->assertNotSame('', $s['snapshotTime']);
    }
}
