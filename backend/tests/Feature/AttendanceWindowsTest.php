<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KyBooking;
use App\Services\KyMemberSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 出勤口径：三个连续且等长的 30 天滚动窗口。
 *
 * 背景：原实现按「近三个完整自然月」统计，本月完全不计入，导致
 *  - 会员本月天天来，出勤列仍显示 0；
 *  - 「待续课」要求 M3 > 0（最近一个月有出勤）时，上月休假、本月恢复训练的会员被漏掉。
 * 本测试锁住新口径，以及依赖它的三条清单规则的语义。
 */
class AttendanceWindowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_windows_are_three_consecutive_equal_length_30_day_buckets(): void
    {
        $today = Carbon::parse('2026-09-15');
        [$m1, $m2, $m3] = KyMemberSyncService::attendanceWindows($today);

        // M3 必须含今天（这是本次改动的核心：当期计入）
        $this->assertSame('2026-08-17', $m3[0]->toDateString());
        $this->assertSame('2026-09-15', $m3[1]->toDateString());
        // M2 / M1 依次往前接续，不重叠
        $this->assertSame('2026-07-18', $m2[0]->toDateString());
        $this->assertSame('2026-08-16', $m2[1]->toDateString());
        $this->assertSame('2026-06-18', $m1[0]->toDateString());
        $this->assertSame('2026-07-17', $m1[1]->toDateString());

        // 三个窗口等长且首尾相接：这是「M1 > M2 > M3」可比的前提
        foreach ([$m1, $m2, $m3] as [$from, $to]) {
            $days = (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;
            $this->assertSame(30, $days);
        }
        // M2 的结束日 +1 天 = M3 的起始日（M1 同理），即三窗首尾相接不重叠
        $this->assertSame(1, (int) $m2[1]->startOfDay()->diffInDays($m3[0]->startOfDay()));
        $this->assertSame(1, (int) $m1[1]->startOfDay()->diffInDays($m2[0]->startOfDay()));
    }

    public function test_renewal_now_counts_members_who_attended_this_month(): void
    {
        // 用户场景：上月休假没来、本月恢复训练、课时快用完。
        // 自然月口径下 M3（上月）= 0 → 不进待续课；滚动口径下「近 30 天有出勤」→ 应进清单。
        $c = $this->customer('本月恢复训练', ['attend_m1' => 0, 'attend_m2' => 0, 'attend_m3' => 4], [
            'countResidue' => 3, 'countBound' => 30, 'daysLeft' => null, 'daysTotal' => null,
        ]);

        $this->assertContains($c->id, memberListIds()['待续课'] ?? []);
    }

    public function test_renewal_excludes_members_with_no_attendance_in_last_30_days(): void
    {
        // 近 30 天没来（只在 30~60 天前来过）→ 不判待续课，交给预流失那条规则
        $c = $this->customer('近30天没来', ['attend_m1' => 0, 'attend_m2' => 4, 'attend_m3' => 0], [
            'countResidue' => 3, 'countBound' => 30, 'daysLeft' => null, 'daysTotal' => null,
        ]);

        $lists = memberListIds();
        $this->assertNotContains($c->id, $lists['待续课'] ?? []);
    }

    public function test_declining_uses_three_equal_windows(): void
    {
        // 出勤在三个连续 30 天窗口里逐档下降 → 出勤降低
        $declining = $this->customer('逐档下降', ['attend_m1' => 8, 'attend_m2' => 4, 'attend_m3' => 1], null, [
            'last_visit' => now()->subDays(3)->toDateString(),
        ]);
        // 稳定出勤 → 不进清单
        $steady = $this->customer('稳定出勤', ['attend_m1' => 5, 'attend_m2' => 5, 'attend_m3' => 5], null, [
            'last_visit' => now()->subDays(3)->toDateString(),
        ]);

        $decline = memberListIds()['出勤降低'] ?? [];
        $this->assertContains($declining->id, $decline);
        $this->assertNotContains($steady->id, $decline);
    }

    public function test_preloss_flags_members_who_stopped_in_last_30_days(): void
    {
        // 前 30 天来过、近 30 天完全没来 → 预流失。
        // 注：持卡会员距上次到店 > 30 天会先被判进「待复活」，所以这条路径主要由
        // 无卡会员走到；这里用无卡场景验证「M2 > 0 且 M3 = 0」这个窗口判断本身生效。
        $c = $this->customer('近期停了', ['attend_m1' => 0, 'attend_m2' => 3, 'attend_m3' => 0], null, [
            'main_card' => '—',
            'last_visit' => now()->subDays(35)->toDateString(),
        ]);

        $lists = memberListIds();
        $this->assertContains($c->id, $lists['预流失'] ?? []);
        $this->assertNotContains($c->id, $lists['待复活'] ?? []);
    }

    /** 真实预约数据 → 三个窗口的分桶是否正确（直接打私有方法） */
    public function test_attendance_bucketing_against_real_bookings(): void
    {
        // 会员在各窗口的签到分布：M3 三节、M2 两节、M1 一节；另有两条窗口外的旧记录
        $member = 'm-1001';
        foreach ([
            [3, 1], [10, 1], [28, 1],        // 近 30 天 → 3 节（含今天附近）
            [31, 1], [55, 1],                // 前 30 天 → 2 节
            [61, 1], [88, 1],                // 再前 30 天 → 2 节
            [95, 1], [200, 1],               // 窗口外，不应计数
        ] as $i => [$daysAgo, $_]) {
            KyBooking::create([
                'source_key' => "77:私教:wk{$i}", 'venue' => '绿地店', 'booking_type' => '私教',
                'course_kind' => 'private', 'member_id' => $member, 'member_name' => '窗口测试会员',
                'phone' => '13900001234', 'start_at' => now()->subDays($daysAgo),
                'teacher_name' => '王教练', 'status' => 'signed', 'is_trial' => false,
            ]);
        }
        // 未签到的预约不计入
        KyBooking::create([
            'source_key' => '77:私教:wk-booked', 'venue' => '绿地店', 'booking_type' => '私教',
            'course_kind' => 'private', 'member_id' => $member, 'member_name' => '窗口测试会员',
            'phone' => '13900001234', 'start_at' => now()->subDays(5),
            'teacher_name' => '王教练', 'status' => 'booked', 'is_trial' => false,
        ]);

        $method = new \ReflectionMethod(KyMemberSyncService::class, 'attendanceFromFacts');
        $attendance = $method->invoke(null, '绿地店', KyMemberSyncService::attendanceWindows());

        $this->assertSame(2, $attendance[$member]['attend_m1'] ?? 0);
        $this->assertSame(2, $attendance[$member]['attend_m2'] ?? 0);
        $this->assertSame(3, $attendance[$member]['attend_m3'] ?? 0);
        // 最近到店日取窗口内最大值（3 天前那条）
        $this->assertSame(now()->subDays(3)->toDateString(), $attendance[$member]['last_visit']);
    }

    /** 近 30 天必须包含今天：今天上的课不能漏 */
    public function test_today_booking_counts_into_recent_window(): void
    {
        KyBooking::create([
            'source_key' => '77:私教:today', 'venue' => '绿地店', 'booking_type' => '私教',
            'course_kind' => 'private', 'member_id' => 'm-today', 'member_name' => '今天来',
            'phone' => '13900005678', 'start_at' => now()->setTime(10, 0),
            'teacher_name' => '王教练', 'status' => 'signed', 'is_trial' => false,
        ]);

        $method = new \ReflectionMethod(KyMemberSyncService::class, 'attendanceFromFacts');
        $attendance = $method->invoke(null, '绿地店', KyMemberSyncService::attendanceWindows());

        // 这正是本次修复的点：自然月口径下「本月」不计入，今天的到店会漏掉
        $this->assertSame(1, $attendance['m-today']['attend_m3'] ?? 0);
        $this->assertSame(0, $attendance['m-today']['attend_m1'] ?? 0);
    }

    private function customer(string $name, array $overrides, ?array $cardStats, array $extra = []): Customer
    {
        return Customer::create(array_merge([
            'name' => $name,
            'phone' => '1380000'.random_int(1000, 9999),
            'phone_tail' => '0000',
            'venue' => '绿地店',
            'source' => 'KeepYoga',
            'owner' => '店长',
            'consultant' => '店长',
            'main_card' => '私教卡',
            'layer' => 'P4',
            'status' => '在籍',
            'last_visit' => now()->subDays(3)->toDateString(),
        ], $overrides, $extra, $cardStats !== null ? ['card_stats' => $cardStats] : []));
    }
}
