<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\Lead;
use App\Models\PostClassReview;
use App\Models\User;
use App\Services\PostClassPlanEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 课后分析（P0）＋ 服务老师 / 授课老师角色拆分。
 */
class PostClassReviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role, string $name, string $username): User
    {
        return User::factory()->create([
            'name' => $name,
            'username' => $username,
            'role' => $role,
            'venue' => '绿地店',
            'venues' => ['绿地店'],
            'status' => '启用',
        ]);
    }

    private function seedBookings(string $teacher = '王教练'): void
    {
        // 私教课的学员（应计入「我的学员」）
        KyBooking::create([
            'source_key' => '77:私教:1001', 'venue' => '绿地店', 'booking_type' => '私教',
            'course_kind' => 'private', 'member_id' => '1001', 'member_name' => '私教学员甲',
            'phone' => '13900001001', 'start_at' => now()->subDays(2), 'teacher_name' => $teacher,
            'status' => 'signed',
        ]);
        // 小班课的学员（不计入）
        KyBooking::create([
            'source_key' => '77:私教:1002', 'venue' => '绿地店', 'booking_type' => '私教',
            'course_kind' => 'small', 'member_id' => '1002', 'member_name' => '小班学员乙',
            'phone' => '13900001002', 'start_at' => now()->subDays(2), 'teacher_name' => $teacher,
            'status' => 'signed',
        ]);
        // 团课课的学员（不计入）
        KyBooking::create([
            'source_key' => '77:团课:1003', 'venue' => '绿地店', 'booking_type' => '团课',
            'course_kind' => 'group', 'member_id' => '1003', 'member_name' => '团课学员丙',
            'phone' => '13900001003', 'start_at' => now()->subDays(2), 'teacher_name' => $teacher,
            'status' => 'signed',
        ]);
    }

    private function seedCustomers(): void
    {
        Customer::create([
            'name' => '私教学员甲', 'phone' => '13900001001', 'venue' => '绿地店',
            'external_id' => 'ky:77:1001', 'layer' => 'P2', 'consultant' => '李顾问',
        ]);
        Customer::create([
            'name' => '小班学员乙', 'phone' => '13900001002', 'venue' => '绿地店',
            'external_id' => 'ky:77:1002', 'layer' => 'P2', 'consultant' => '李顾问',
        ]);
        Customer::create([
            'name' => '团课学员丙', 'phone' => '13900001003', 'venue' => '绿地店',
            'external_id' => 'ky:77:1003', 'layer' => 'P2', 'consultant' => '李顾问',
        ]);
        // 授课老师自己做会籍顾问的会员（无排课关系，也应可见）
        Customer::create([
            'name' => '本人会籍会员丁', 'phone' => '13900001004', 'venue' => '绿地店',
            'external_id' => 'ky:77:1004', 'layer' => 'P2', 'consultant' => '王教练',
        ]);
    }

    public function test_coach_sees_only_private_students_and_own_service_members(): void
    {
        $this->seedBookings();
        $this->seedCustomers();
        $coach = $this->makeUser('R_TEACHER', '王教练', 'coach-wang');
        Sanctum::actingAs($coach);

        $res = $this->getJson('/api/customers?size=50')->assertOk();
        $names = array_column($res->json('data.records'), 'name');

        // 私教课的学员 + 挂在自己名下的会员可见
        $this->assertContains('私教学员甲', $names);
        $this->assertContains('本人会籍会员丁', $names);
        // 小班、团课学员不可见（用户明确口径）
        $this->assertNotContains('小班学员乙', $names);
        $this->assertNotContains('团课学员丙', $names);
    }

    public function test_service_teacher_sees_only_own_consultant_members(): void
    {
        $this->seedBookings();
        $this->seedCustomers();
        $service = $this->makeUser('R_SERVICE', '李顾问', 'service-li');
        Sanctum::actingAs($service);

        $res = $this->getJson('/api/customers?size=50')->assertOk();
        $names = array_column($res->json('data.records'), 'name');

        $this->assertContains('私教学员甲', $names);
        $this->assertContains('小班学员乙', $names);
        $this->assertNotContains('本人会籍会员丁', $names); // 那是王教练名下的
    }

    public function test_teacher_overview_uses_real_schedule_not_estimate(): void
    {
        $this->seedBookings();
        $this->seedCustomers();
        $coach = $this->makeUser('R_TEACHER', '王教练', 'coach-wang');
        Sanctum::actingAs($coach);

        $res = $this->getJson('/api/today/teacher-overview')->assertOk();
        $data = $res->json('data');

        // 真实排课：3 节 signed（私教/小班/团课各 1），不再是「当日预约 × 0.6」
        $this->assertSame(3, $data['classCount']);
        $this->assertSame(1, $data['kindCount']['私教']);
        $this->assertSame(1, $data['kindCount']['小班']);
        $this->assertSame(1, $data['kindCount']['团课']);
        $this->assertSame(3, $data['servedCount']);
        // 私教学员只算 1 人（小班/团课不计入）
        $this->assertSame(1, $data['teachStudentCount']);
        // 挂在本人名下的会籍会员只有 1 位（「私教学员甲」的顾问是李顾问，不算）
        $this->assertSame(1, $data['serviceMemberCount']);
    }

    public function test_red_flag_blocks_plan_and_handoff(): void
    {
        $coach = $this->makeUser('R_TEACHER', '王教练', 'coach-wang');
        Sanctum::actingAs($coach);

        $res = $this->postJson('/api/post-class-reviews/preview', [
            'studentType' => '康复',
            'observations' => [['key' => 'core_weak', 'level' => '中']],
            'redFlags' => ['numb_radiate'],
        ])->assertOk();

        $this->assertTrue($res->json('data.red_flag'));
        $this->assertNull($res->json('data.plan'));
        $this->assertNull($res->json('data.objective'));
        $this->assertTrue($res->json('data.handoff.blocked'));
        $this->assertSame('', $res->json('data.handoff.cardDirection'));
    }

    public function test_same_type_different_observations_produce_different_plans(): void
    {
        $coach = $this->makeUser('R_TEACHER', '王教练', 'coach-wang');
        Sanctum::actingAs($coach);

        $a = $this->postJson('/api/post-class-reviews/preview', [
            'studentType' => '塑形线条',
            'observations' => [['key' => 'glute_weak', 'level' => '中']],
        ])->assertOk()->json('data');
        $b = $this->postJson('/api/post-class-reviews/preview', [
            'studentType' => '塑形线条',
            'observations' => [['key' => 'core_weak', 'level' => '中']],
        ])->assertOk()->json('data');

        // 同一类型下，个体化的阶段重点与对客观察必须不同，否则就是「千人一面」
        $this->assertNotSame($a['plan']['phases'][0]['focus'], $b['plan']['phases'][0]['focus']);
        $this->assertNotSame($a['objective']['observed'], $b['objective']['observed']);
        $this->assertStringContainsString('臀', $a['objective']['observed'][0]);
        $this->assertStringContainsString('核心', $b['objective']['observed'][0]);
        // 阶段基础文案（类型预设）保持一致
        $this->assertSame($a['plan']['phases'][0]['goal'], $b['plan']['phases'][0]['goal']);
    }

    public function test_service_role_cannot_write_review(): void
    {
        $this->seedCustomers();
        $service = $this->makeUser('R_SERVICE', '李顾问', 'service-li');
        Sanctum::actingAs($service);

        $this->postJson('/api/post-class-reviews', [
            'studentName' => '私教学员甲',
            'observations' => [['key' => 'core_weak', 'level' => '中']],
        ])->assertStatus(403);
    }

    public function test_review_lifecycle_and_public_share(): void
    {
        $this->seedCustomers();
        $coach = $this->makeUser('R_TEACHER', '王教练', 'coach-wang');
        Sanctum::actingAs($coach);

        $lead = Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '体验客戊', 'phone' => '13900001005',
            'source' => '美团', 'venue' => '绿地店', 'service_teacher' => '李顾问', 'status' => '已体验',
        ]);

        $create = $this->postJson('/api/post-class-reviews', [
            'scene' => 'trial',
            'studentName' => '体验客戊',
            'studentPhone' => '13900001005',
            'studentType' => '塑形线条',
            'observations' => [['key' => 'glute_weak', 'level' => '中']],
            'goalText' => '想瘦一点',
            'leadId' => $lead->id,
            'venue' => '绿地店',
        ])->assertOk();
        $id = $create->json('data.id');

        // 未确认前不可分享
        $this->postJson("/api/post-class-reviews/{$id}/share")->assertStatus(422);

        $confirm = $this->postJson("/api/post-class-reviews/{$id}/confirm")->assertOk();
        $code = $confirm->json('data.shareCode');
        $this->assertNotEmpty($code);

        // 对客页（免登录）可读
        $public = $this->getJson("/api/public/post-class/{$code}")->assertOk()->json('data');
        $this->assertSame('体验客戊', $public['studentName']);
        $this->assertNotEmpty($public['objective']['plan']);
        $this->assertSame(PostClassPlanEngine::DISCLAIMER, $public['objective']['disclaimer']);

        // 停用后不可读
        $this->postJson("/api/post-class-reviews/{$id}/share", ['enabled' => false])->assertOk();
        $this->getJson("/api/public/post-class/{$code}")->assertStatus(404);
    }

    public function test_red_flag_review_never_exposes_public_page(): void
    {
        $this->seedCustomers();
        $coach = $this->makeUser('R_TEACHER', '王教练', 'coach-wang');
        Sanctum::actingAs($coach);

        $create = $this->postJson('/api/post-class-reviews', [
            'scene' => 'trial',
            'studentName' => '红线学员己',
            'studentType' => '康复',
            'observations' => [['key' => 'core_weak', 'level' => '中']],
            'redFlags' => ['postpartum_pelvic'],
            'venue' => '绿地店',
        ])->assertOk();
        $id = $create->json('data.id');

        $this->assertTrue(PostClassReview::find($id)->red_flag);
        $code = $this->postJson("/api/post-class-reviews/{$id}/confirm")->assertOk()->json('data.shareCode');

        // 即使已确认并生成分享码，红线记录也不对外
        $this->getJson("/api/public/post-class/{$code}")->assertStatus(404);
    }

    public function test_coach_cannot_see_other_coach_reviews(): void
    {
        $other = $this->makeUser('R_TEACHER', '赵教练', 'coach-zhao');
        $mine = $this->makeUser('R_TEACHER', '王教练', 'coach-wang');

        $row = PostClassReview::create([
            'venue' => '绿地店', 'scene' => 'trial', 'teacher_user_id' => $other->id,
            'teacher_name' => '赵教练', 'student_name' => '别人的学员', 'status' => '已确认',
            'red_flag' => false, 'payload' => [],
        ]);

        Sanctum::actingAs($mine);
        $this->getJson("/api/post-class-reviews/{$row->id}")->assertStatus(403);
        $list = $this->getJson('/api/post-class-reviews')->assertOk();
        $this->assertSame(0, $list->json('data.total'));
    }

    public function test_teacher_edited_phase_text_is_persisted_and_served_publicly(): void
    {
        $this->seedCustomers();
        $coach = $this->makeUser('R_TEACHER', '王教练', 'coach-wang');
        Sanctum::actingAs($coach);

        $create = $this->postJson('/api/post-class-reviews', [
            'scene' => 'trial',
            'studentName' => '改稿学员',
            'studentType' => '塑形线条',
            'observations' => [['key' => 'glute_weak', 'level' => '中']],
            'venue' => '绿地店',
            'phases' => [
                ['key' => 'phase1', 'goal' => '老师当场改过的第一阶段文案'],
            ],
        ])->assertOk();
        $id = $create->json('data.id');

        // 落库的生成结果要带上老师改的字，否则当场改完下次重新生成就丢了
        $row = PostClassReview::find($id);
        $this->assertSame('老师当场改过的第一阶段文案', $row->payload['generated']['plan']['phases'][0]['goal']);
        $this->assertSame('老师当场改过的第一阶段文案', $row->payload['generated']['objective']['plan'][0]['goal']);

        $code = $this->postJson("/api/post-class-reviews/{$id}/confirm")->assertOk()->json('data.shareCode');
        $public = $this->getJson("/api/public/post-class/{$code}")->assertOk()->json('data');
        $this->assertSame('老师当场改过的第一阶段文案', $public['objective']['plan'][0]['goal']);
        $this->assertNotEmpty($public['script']['progress']);
    }

    public function test_today_todo_exposes_pending_reviews_for_coach_only(): void
    {
        // 今天已签到的体验课 + 私教课各一节，团课不该进课后分析
        foreach ([
            ['1001', '私教', 'private', true, '私教体验甲'],
            ['1002', '私教', 'private', false, '私教学员乙'],
            ['1003', '团课', 'group', false, '团课学员丙'],
        ] as [$mid, $type, $kind, $isTrial, $name]) {
            KyBooking::create([
                'source_key' => "77:{$type}:{$mid}", 'venue' => '绿地店', 'booking_type' => $type,
                'course_kind' => $kind, 'member_id' => $mid, 'member_name' => $name,
                'phone' => '1390000'.$mid, 'start_at' => now()->setTime(10, 0), 'teacher_name' => '王教练',
                'status' => 'signed', 'is_trial' => $isTrial,
            ]);
        }

        Sanctum::actingAs($this->makeUser('R_TEACHER', '王教练', 'coach-wang'));
        $coach = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $this->assertCount(2, $coach['reviews']);
        $this->assertSame(2, $coach['counts']['reviews']);
        $this->assertEqualsCanonicalizing(
            ['私教体验甲', '私教学员乙'],
            array_column($coach['reviews'], 'name')
        );

        // 服务老师不排训练，课后分析分组为空
        Sanctum::actingAs($this->makeUser('R_SERVICE', '李顾问', 'service-li'));
        $service = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $this->assertSame([], $service['reviews']);
        $this->assertSame(0, $service['counts']['reviews']);

        // 填完之后从待办里消失
        Sanctum::actingAs($this->makeUser('R_TEACHER', '王教练', 'coach-wang2'));
        $bookingId = KyBooking::where('member_id', '1001')->value('id');
        $this->postJson('/api/post-class-reviews', [
            'scene' => 'trial', 'studentName' => '私教体验甲', 'studentType' => '塑形线条',
            'observations' => [['key' => 'glute_weak', 'level' => '中']],
            'venue' => '绿地店', 'bookingId' => $bookingId,
        ])->assertOk();
        $after = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $this->assertCount(1, $after['reviews']);
        $this->assertSame('私教学员乙', $after['reviews'][0]['name']);
    }

    public function test_catalog_exposes_fast_screen_and_red_flags(): void
    {
        Sanctum::actingAs($this->makeUser('R_TEACHER', '王教练', 'coach-wang'));
        $data = $this->getJson('/api/post-class-reviews/catalog')->assertOk()->json('data');

        $fast = [];
        foreach ($data['groups'] as $g) {
            foreach ($g['items'] as $it) {
                if ($it['fast']) {
                    $fast[] = $it['key'];
                }
            }
        }
        // 当场快筛控制在 8–10 项，老师 5 分钟内能填完
        $this->assertGreaterThanOrEqual(8, count($fast));
        $this->assertLessThanOrEqual(10, count($fast));
        $this->assertCount(5, $data['types']);
        $this->assertCount(5, $data['redFlags']);
    }
}
