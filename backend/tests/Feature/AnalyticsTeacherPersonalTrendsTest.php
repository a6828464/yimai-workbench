<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 老师侧 30 天走势必须是**个人口径**（2026-11 真机反馈）。
 *
 * 事故现象：服务老师打开工作台，看到的是全品牌的留资/预约/到店/成交；
 * 本人名下只有 3 条客资，页面却显示几十条 —— 同一屏上「我的客户」与「我的走势」互相打脸。
 *
 * 本文件钉住：
 *  - 授课老师：客资按本人（服务老师/体验课老师/私教学员），排课按 teacher_name；
 *  - 服务老师：客资按本人名下，排课按本人名下会员手机号；
 *  - 两个老师的数字**互不串**（同一门店、同一时间窗，只差人）；
 *  - 超管仍然是全店口径（改的是老师侧，不是所有人）。
 */
class AnalyticsTeacherPersonalTrendsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $username, string $name, string $role, string $venue = '绿地店'): User
    {
        return User::factory()->create([
            'username' => $username,
            'name' => $name,
            'role' => $role,
            'roles' => [$role],
            'venue' => $venue,
            'venues' => [$venue],
            'status' => '启用',
        ]);
    }

    private function lead(string $name, string $phone, array $extra = []): Lead
    {
        return Lead::create(array_merge([
            'lead_date' => now()->toDateString(),
            'name' => $name,
            'phone' => $phone,
            'source' => '到店',
            'venue' => '绿地店',
            'status' => '已体验',
        ], $extra));
    }

    private function booking(string $teacher, string $phone, string $venue = '绿地店', string $status = 'signed', bool $isTrial = true): KyBooking
    {
        $this->seq++;

        return KyBooking::create([
            'source_key' => 'test:'.$this->seq,
            'venue' => $venue,
            'booking_type' => '私教',
            'course_kind' => 'private',
            'member_name' => '学员'.$this->seq,
            'phone' => $phone,
            'start_at' => now()->setTime(10, 0),
            'course_name' => '私教课',
            'teacher_name' => $teacher,
            'status_raw' => $status,
            'status' => $status,
            'is_trial' => $isTrial,
            'raw' => ['course_type' => '1'],
        ]);
    }

    private function trends(User $u): array
    {
        Sanctum::actingAs($u);
        Cache::flush();

        $today = now()->toDateString();

        return $this->getJson("/api/analytics/trends?start={$today}&end={$today}")
            ->assertOk()
            ->json('data.summary');
    }

    public function test_coach_trends_only_count_his_own_leads_and_classes(): void
    {
        $coachA = $this->user('t9-coach-a', '授课老师甲', 'R_TEACHER');
        $coachB = $this->user('t9-coach-b', '授课老师乙', 'R_TEACHER');

        $this->lead('甲的体验课学员', '13900010001', [
            'trial_teacher' => '授课老师甲', 'trial_teacher_user_id' => $coachA->id,
        ]);
        $this->lead('乙的体验课学员', '13900010002', [
            'trial_teacher' => '授课老师乙', 'trial_teacher_user_id' => $coachB->id,
        ]);
        // 未认领的客资谁都不算（防止「本店全部」偷偷回来）
        $this->lead('无人认领客资', '13900010003');

        $this->booking('授课老师甲', '13900010001');
        $this->booking('授课老师乙', '13900010002');
        // 取消的课不算「今日有课」
        $this->booking('授课老师甲', '13900010009', status: 'cancelled');

        $a = $this->trends($coachA);
        $this->assertSame(1, (int) $a['leadCount'], '甲只应看到自己那条体验课客资');
        $this->assertSame(1, (int) $a['bookingCount'], '甲的今日预约只算本人排课');
        $this->assertSame(1, (int) $a['classCount'], '甲只上了一节课');
        $this->assertSame(1, (int) $a['visitCount'], '到店人数只能来自本人预约/本人客资');
        $this->assertSame(1, (int) $a['privateBookingCount']);

        $b = $this->trends($coachB);
        $this->assertSame(1, (int) $b['leadCount']);
        $this->assertSame(1, (int) $b['bookingCount']);
        $this->assertSame(1, (int) $b['classCount']);

        // 超管仍是全店口径：两条有人认领的客资 + 一条无人认领的
        $super = $this->user('t9-trends-super', '超管', 'R_SUPER');
        $s = $this->trends($super);
        $this->assertSame(3, (int) $s['leadCount'], '超管口径不变（全店）');
        // booked 取「客资状态已约体验」与「预约去重人数」的较大值（本仓既有口径）：
        // 3 条客资都是已体验 → 3
        $this->assertSame(3, (int) $s['bookingCount']);
        // 班次数只看已签到的预约行：取消的那条不计 → 2
        $this->assertSame(2, (int) $s['classCount'], '超管看全店已上课班次（取消不计）');
    }

    public function test_service_teacher_trends_use_member_phones_for_schedule(): void
    {
        $service = $this->user('t9-svc', '服务老师甲', 'R_SERVICE');
        $otherService = $this->user('t9-svc-other', '服务老师乙', 'R_SERVICE');

        // 名下会员：手机号是排课关联键
        Customer::create([
            'name' => '我的会员', 'phone' => '13900020001', 'venue' => '绿地店',
            'consultant' => '服务老师甲', 'consultant_user_id' => $service->id,
            'owner' => '服务老师甲', 'layer' => 'P2',
        ]);
        Customer::create([
            'name' => '同事的会员', 'phone' => '13900020002', 'venue' => '绿地店',
            'consultant' => '服务老师乙', 'consultant_user_id' => $otherService->id,
            'owner' => '服务老师乙', 'layer' => 'P2',
        ]);

        $this->lead('我的客资', '13900020001', [
            'service_teacher' => '服务老师甲', 'service_teacher_user_id' => $service->id,
        ]);
        $this->lead('同事的客资', '13900020002', [
            'service_teacher' => '服务老师乙', 'service_teacher_user_id' => $otherService->id,
        ]);

        $this->booking('授课老师丙', '13900020001');   // 我的会员的课
        $this->booking('授课老师丙', '13900020002');   // 同事会员的课
        $this->booking('授课老师丙', '13900029999');   // 陌生人的课

        $mine = $this->trends($service);
        $this->assertSame(1, (int) $mine['leadCount'], '服务老师只看到本人名下客资');
        $this->assertSame(1, (int) $mine['bookingCount'], '排课只算本人名下会员的预约');
        $this->assertSame(1, (int) $mine['visitCount']);

        $theirs = $this->trends($otherService);
        $this->assertSame(1, (int) $theirs['leadCount']);
        $this->assertSame(1, (int) $theirs['bookingCount']);
    }

    public function test_media_trends_use_department_leads_and_no_classes(): void
    {
        $mediaA = $this->user('t9-media-a', '新媒体小王', 'R_MEDIA');
        $mediaB = $this->user('t9-media-b', '新媒体小李', 'R_MEDIA');

        $this->lead('小王登记的线上客资', '13900030001', [
            'source' => '小红书', 'created_by' => '新媒体小王', 'created_by_user_id' => $mediaA->id,
        ]);
        $this->lead('小李登记的线上客资', '13900030002', [
            'source' => '抖音', 'created_by' => '新媒体小李', 'created_by_user_id' => $mediaB->id,
        ]);
        $this->lead('店长登记的到店客资', '13900030003', ['created_by' => '绿地店长']);
        // 店里的课不属于新媒体
        $this->booking('授课老师丁', '13900030001');

        $trends = $this->trends($mediaB);

        // 部门共享：两位新媒体登记的客资都算，店长登记的不算
        $this->assertSame(2, (int) $trends['leadCount']);
        // 新媒体没有「自己的排课」：班级数只能来自本人排课 → 0（店里那节课不算）
        $this->assertSame(0, (int) $trends['classCount'], '新媒体没有自己的排课');
        $this->assertSame(0, (int) $trends['privateBookingCount'], '店里那节私教课不属于新媒体');
        // 到店来自「部门客资的状态」（已体验），不是从店里排课蹭来的：2 条客资 → 2 人
        $this->assertSame(2, (int) $trends['visitCount']);
    }
}
