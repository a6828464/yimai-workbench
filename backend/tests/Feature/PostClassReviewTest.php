<?php

namespace Tests\Feature;

use App\Models\BodyTestReport;
use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\Lead;
use App\Models\PostClassReview;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\BodyTestReportService;
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

    /** 多角色叠加：服务老师 + 授课老师 = 可见范围并集 */
    public function test_multi_role_union_of_service_and_coach(): void
    {
        $this->seedBookings('王教练');
        $this->seedCustomers();

        // 只挂「王教练」名下会籍、但没上过私教课的会员
        Customer::create([
            'name' => '仅会籍会员', 'phone' => '13900001009', 'venue' => '绿地店',
            'external_id' => 'ky:77:1009', 'layer' => 'P2', 'consultant' => '王教练',
        ]);

        // 单角色：服务老师 → 只看名下会籍会员（会籍会员丁 + 仅会籍会员），看不到私教学员甲
        Sanctum::actingAs($this->makeUser('R_SERVICE', '王教练', 'svc-wang'));
        $serviceOnly = array_column($this->getJson('/api/customers?size=50')->json('data.records'), 'name');
        $this->assertContains('本人会籍会员丁', $serviceOnly);
        $this->assertContains('仅会籍会员', $serviceOnly);
        $this->assertNotContains('私教学员甲', $serviceOnly);

        // 单角色：授课老师 → 私教课学员 + 名下会籍会员
        Sanctum::actingAs($this->makeUser('R_TEACHER', '王教练', 'coach-wang'));
        $coachOnly = array_column($this->getJson('/api/customers?size=50')->json('data.records'), 'name');
        $this->assertContains('私教学员甲', $coachOnly);
        $this->assertNotContains('小班学员乙', $coachOnly);

        // 双角色：并集（私教学员甲 ∪ 名下会籍会员）
        $both = $this->makeUser('R_SERVICE', '王教练', 'both-wang');
        $both->update(['roles' => ['R_SERVICE', 'R_TEACHER']]);
        Sanctum::actingAs($both->fresh());
        $union = array_column($this->getJson('/api/customers?size=50')->json('data.records'), 'name');
        $this->assertContains('私教学员甲', $union);
        $this->assertContains('本人会籍会员丁', $union);
        $this->assertContains('仅会籍会员', $union);
        $this->assertNotContains('小班学员乙', $union);   // 小班仍不计入
    }

    /** 叠加店长：本店全部（并集被更大的范围吸收） */
    public function test_manager_role_absorbs_smaller_scopes(): void
    {
        $this->seedBookings('王教练');
        $this->seedCustomers();

        $u = $this->makeUser('R_TEACHER', '王教练', 'mgr-wang');
        $u->update(['roles' => ['R_TEACHER', 'R_SERVICE', 'R_MANAGER']]);
        Sanctum::actingAs($u->fresh());

        $names = array_column($this->getJson('/api/customers?size=50')->json('data.records'), 'name');
        // 店长范围是本店全部，小班学员乙这种跟自己毫无关系的也在内
        $this->assertContains('小班学员乙', $names);
        $this->assertContains('团课学员丙', $names);
        $this->assertContains('私教学员甲', $names);
    }

    /** 多角色写权限：任一角色够用即可（授课老师能录课后分析） */
    public function test_multi_role_write_permission_follows_any_role(): void
    {
        $this->seedCustomers();
        $u = $this->makeUser('R_SERVICE', '王教练', 'multi-wang');
        $u->update(['roles' => ['R_SERVICE', 'R_TEACHER']]);
        Sanctum::actingAs($u->fresh());

        $this->postJson('/api/post-class-reviews', [
            'scene' => 'trial', 'studentName' => '私教学员甲', 'studentType' => '塑形线条',
            'observations' => [['key' => 'glute_weak', 'level' => '中']], 'venue' => '绿地店',
        ])->assertOk();
    }

    /** 账号管理支持多角色；改角色要清 token 让新权限立即生效 */
    public function test_account_multi_role_create_and_update(): void
    {
        Sanctum::actingAs($this->makeUser('R_SUPER', '老板', 'boss'));

        $this->postJson('/api/accounts', [
            'userName' => 'multi-1',
            'name' => '多角色账号',
            'roles' => ['R_SERVICE', 'R_TEACHER'],
            'venues' => ['绿地店'],
            'password' => 'password123',
        ])->assertOk();

        $created = User::where('username', 'multi-1')->firstOrFail();
        $this->assertSame(['R_SERVICE', 'R_TEACHER'], userRoles($created));
        // 含门店绑定角色 → 锁定单一门店
        $this->assertSame('绿地店', $created->venue);

        $listed = collect($this->getJson('/api/accounts')->json('data'))->firstWhere('key', 'multi-1');
        $this->assertSame(['R_SERVICE', 'R_TEACHER'], $listed['roles']);
        $this->assertSame('授课老师 + 服务老师', $listed['roleLabel']);

        // 改成店长：范围放大到本店，且旧 token 作废
        $created->createToken('old');
        $this->patchJson('/api/accounts/multi-1', ['roles' => ['R_MANAGER']])->assertOk();
        $fresh = $created->fresh();
        $this->assertSame(['R_MANAGER'], userRoles($fresh));
        $this->assertSame('R_MANAGER', $fresh->role);      // 主角色同步回写
        $this->assertSame(0, $fresh->tokens()->count());
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

    /** 服务老师工作台：「我的客资」与逐日客资趋势必须来自真实数据（此前分别为恒 0 与缺字段） */
    public function test_service_teacher_overview_counts_own_leads_and_daily_series(): void
    {
        $service = $this->makeUser('R_SERVICE', '李顾问', 'service-li');
        Sanctum::actingAs($service);

        $today = now()->toDateString();
        $twoDaysAgo = now()->subDays(2)->toDateString();
        Lead::create([
            'lead_date' => $today, 'name' => '今日客资', 'phone' => '13900002001',
            'source' => '小红书', 'venue' => '绿地店', 'service_teacher' => '李顾问', 'status' => '新留资',
        ]);
        Lead::create([
            'lead_date' => $twoDaysAgo, 'name' => '两天前客资', 'phone' => '13900002002',
            'source' => '大众点评', 'venue' => '绿地店', 'service_teacher' => '李顾问', 'status' => '已体验',
        ]);
        // 待承接池与同事名下都不算「我的客资」
        Lead::create([
            'lead_date' => $today, 'name' => '待承接客资', 'phone' => '13900002003',
            'source' => '到店', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '新留资',
        ]);
        Lead::create([
            'lead_date' => $today, 'name' => '同事客资', 'phone' => '13900002004',
            'source' => '到店', 'venue' => '绿地店', 'service_teacher' => '张三', 'status' => '新留资',
        ]);

        $data = $this->getJson('/api/today/teacher-overview?startDate='.$twoDaysAgo.'&endDate='.$today)
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['myLeadCount']);
        $byDate = collect($data['series'])->keyBy('date');
        $this->assertSame(1, $byDate[$today]['leads']);
        $this->assertSame(1, $byDate[$twoDaysAgo]['leads']);
    }

    /**
     * 老师侧漏斗与全店看板同源（VisitMetrics）：只勾了体验课卡片、整条留资状态没推进的
     * 到店也要算进来。此前老师侧只认 status，同一份数据在老板看板与老师工作台上是两个数。
     */
    public function test_teacher_funnel_uses_same_visit_sources_as_dashboard(): void
    {
        $service = $this->makeUser('R_SERVICE', '李顾问', 'service-li');
        Sanctum::actingAs($service);

        $today = now()->toDateString();
        Lead::create([
            'lead_date' => $today, 'name' => '只勾卡片的客人', 'phone' => '13900003001',
            'source' => '小红书', 'venue' => '绿地店', 'service_teacher' => '李顾问', 'status' => '新留资',
            'trial_cards' => [['time' => $today.' 10:00', 'attended' => true, 'teacher' => '王教练']],
        ]);
        Lead::create([
            'lead_date' => $today, 'name' => '成交的客人', 'phone' => '13900003002',
            'source' => '小红书', 'venue' => '绿地店', 'service_teacher' => '李顾问', 'status' => '已成交',
            'deal_at' => now(), 'deal_amount' => 1200,
        ]);

        $data = $this->getJson('/api/today/teacher-overview?startDate='.$today.'&endDate='.$today)
            ->assertOk()
            ->json('data');

        // 到店 2 人（一个靠卡片命中、一个靠状态命中），成交 1 人 → 50%
        $this->assertSame(2, $data['visitCount']);
        $this->assertSame(1, $data['dealCount']);
        $this->assertSame(50.0, (float) $data['dealRate']);
        $this->assertSame(1200.0, (float) $data['dealAmount']);
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

    /** 体测报告解析：档位判定要按报告自带的 type_name + range 走，不能硬编码 */
    public function test_body_test_report_analysis_uses_device_bands(): void
    {
        $raw = [
            'score' => 71,
            'nick_name' => 'Miyako',
            'create_time' => '2026.09.15 09:45',
            'yoga_gym_name' => '一麦瑜伽',
            'shoulder_slope' => 1,
            'shoulder_slope_risk' => '双肩峰不在同一水平高度。',
            'shoulder_slope_suggest' => '避免长期单侧背包。',
            'highlow_pelvis' => 1,
            'highlow_pelvis_risk' => '两髂骨不在同一水平高度。',
            // 设备原文含门店的表达禁忌词「矫正」，对客侧不得原样引用
            'highlow_pelvis_suggest' => '骨盆带活化+激活，静力性复位矫正。',
            'x_leg' => 1, 'x_leg_risk' => '膝盖内扣。', 'x_leg_suggest' => '建立足弓支撑。',
            'o_leg' => 0,
            'body_test_posture' => [],
            'user_disease_record' => [
                'train_directions' => '康复,产后恢复',
                'maternity_bool' => 1, 'maternity' => '已产后 6 个月',
                'joint' => '', 'operation_bool' => 0, 'medication_bool' => 0, 'vertigo_bool' => 0,
                'heart' => '', 'blood_pressure' => '',
            ],
            'body_base' => [
                // range 在接口里是 JSON 字符串，必须能解析
                'age' => 38, 'sex' => 2, 'height' => 155, 'weight' => 53.8,
                'bmi' => 22.3, 'bmi_range' => '[10.0, 18.5, 24.0, 50.0]',
                'fat' => 32.9, 'fat_range' => '[13.0, 18.0, 28.0, 33.0]',
                'visceral_fat' => 7, 'visceral_fat_range' => '[0.0, 1.0, 9.0, 13.0]',
                'intro' => [
                    'bmi' => ['flag_name' => 'BMI', 'type_name' => '["偏瘦","标准","偏胖"]'],
                    'fat' => ['flag_name' => '脂肪率', 'type_name' => '["低于标准","标准","高于标准"]'],
                    // 正常档在下标 0，不是中间档
                    'visceral_fat' => ['flag_name' => '内脏脂肪等级', 'type_name' => '["标准","偏差","很差"]'],
                ],
            ],
        ];

        $a = BodyTestReportService::analyze($raw);

        // 档位判定：BMI 22.3 落在 18.5~24 → 标准
        $bmi = collect($a['composition'])->firstWhere('key', 'bmi');
        $this->assertSame('标准', $bmi['bandLabel']);
        $this->assertTrue($bmi['isNormal']);
        $this->assertSame([18.5, 24.0], $bmi['normalRange']);

        // 脂肪率 32.9 落在 28~33 → 高于标准（不能因为档位名里含「标准」就判成正常）
        $fat = collect($a['composition'])->firstWhere('key', 'fat');
        $this->assertSame('高于标准', $fat['bandLabel']);
        $this->assertFalse($fat['isNormal']);

        // 内脏脂肪：正常档在下标 0，标准区间是 0~1
        $vf = collect($a['composition'])->firstWhere('key', 'visceral_fat');
        $this->assertSame('偏差', $vf['bandLabel']);
        $this->assertSame([0.0, 1.0], $vf['normalRange']);

        // 体态映射出训练观察项
        $this->assertContains('knee_valgus', $a['observations']);
        $this->assertContains('pelvis_lateral', $a['observations']);

        // 产后登记进红线（需老师当面确认盆底症状，报告本身不做诊断）
        $this->assertContains('postpartum_pelvic', $a['redFlags']);
        $this->assertSame(['康复', '产后恢复'], $a['directions']);

        // 含禁忌词的设备原文标记出来，且对客摘要里完全不带设备文案
        $pelvis = collect($a['posture'])->firstWhere('key', 'highlow_pelvis');
        $this->assertFalse($pelvis['deviceTextSafe']);
        $customer = BodyTestReportService::toCustomerView($a);
        $this->assertStringNotContainsString('矫正', json_encode($customer, JSON_UNESCAPED_UNICODE));
        $this->assertNotNull($customer['abnormal'][0]['normalRange']);
    }

    /** 完整链路：体测 → 课后分析（自动带出观察项）→ 训练计划 */
    public function test_chain_from_body_test_to_training_plan(): void
    {
        $coach = $this->makeUser('R_TEACHER', '王教练', 'coach-wang');
        Sanctum::actingAs($coach);

        $report = BodyTestReport::create([
            'venue' => '绿地店',
            'body_test_id' => '340218',
            'member_name' => 'Miyako',
            'tested_at' => '2026-09-15 09:45',
            'score' => 71,
            'profile' => ['age' => 38, 'sex' => '女', 'height' => 155, 'weight' => 53.8, 'bodyFatRate' => 32.9, 'score' => 71, 'testedAt' => '2026.09.15 09:45'],
            'composition' => [], 'abnormal' => [['key' => 'fat', 'name' => '脂肪率', 'bandLabel' => '高于标准', 'value' => 32.9, 'unit' => '%', 'normalRange' => [18, 28]]],
            'posture' => [['key' => 'x_leg', 'name' => '膝盖内扣', 'risk' => '', 'suggest' => '', 'observations' => ['knee_valgus']]],
            'observations' => ['knee_valgus', 'pelvis_lateral', 'core_weak'],
            'directions' => ['康复'],
            'health' => [], 'red_flags' => [], 'raw' => [],
        ]);

        // ① 建课后分析：体测观察项自动并入
        $create = $this->postJson('/api/post-class-reviews', [
            'scene' => 'trial',
            'studentName' => 'Miyako',
            'studentType' => '体态调整',
            'observations' => [['key' => 'breath_shallow', 'level' => '中']],
            'venue' => '绿地店',
            'bodyTestReportId' => $report->id,
        ])->assertOk();
        $id = $create->json('data.id');

        $row = PostClassReview::find($id);
        $keys = array_column($row->payload['observations'], 'key');
        $this->assertContains('breath_shallow', $keys);   // 老师手选的
        $this->assertContains('knee_valgus', $keys);      // 体测带出的
        $this->assertContains('core_weak', $keys);
        $this->assertSame($report->id, $row->payload['bodyTestReportId']);

        // ② 确认后流转训练计划
        $this->postJson("/api/post-class-reviews/{$id}/confirm")->assertOk();
        $planId = $this->postJson("/api/post-class-reviews/{$id}/to-plan")->assertOk()->json('data.planId');

        $plan = TrainingPlan::find($planId);
        $this->assertNotNull($plan);
        $this->assertSame($id, $plan->source_review_id);
        $this->assertSame($report->id, $plan->source_body_test_id);
        $this->assertSame('待老师确认', $plan->status);
        $this->assertSame('Miyako', $plan->member_name);
        // 体成分带进档案，关注要点用体测偏离项
        $this->assertSame('38', $plan->payload['age']);
        $this->assertSame('32.9', $plan->payload['bodyFat']);
        $this->assertStringContainsString('脂肪率', $plan->payload['focus']);
        $this->assertNotEmpty($plan->payload['content']['phases']);

        // ③ 训练计划列表能读到，且 id 是真实主键（payload 不能覆盖）
        $list = $this->getJson('/api/training-plans')->assertOk()->json('data');
        $found = collect($list)->firstWhere('id', $planId);
        $this->assertNotNull($found);
        $this->assertSame('Miyako', $found['memberName']);
    }

    /**
     * 前端整表提交不能把服务端生成（课后分析流转）的计划连带删掉。
     *
     * 旧实现是「删光本人全部 + 重建」，只要前端手里是一份较早的列表
     * （训练计划页在另一个标签页开着、或本会话早先加载过），一次提交就会清掉刚生成的计划，
     * 表现为「点了转训练计划，到训练计划里却找不到」。
     */
    public function test_bulk_save_keeps_server_created_plans(): void
    {
        $coach = $this->makeUser('R_TEACHER', '王教练', 'coach-wang');
        Sanctum::actingAs($coach);

        // ① 前端手里先有一份旧列表（此时还没有服务端生成的那份）
        $this->putJson('/api/training-plans/bulk', ['plans' => [[
            'id' => 1, 'memberName' => '旧计划', 'status' => '待老师确认',
            'coreGoal' => '改善骨盆前倾', 'content' => ['summary' => 's', 'phases' => [], 'cautions' => []],
        ]]])->assertOk();

        // ② 服务端由课后分析生成一份新计划
        $id = $this->postJson('/api/post-class-reviews', [
            'scene' => 'trial', 'studentName' => '流转学员', 'studentType' => '体态调整',
            'observations' => [['key' => 'round_shoulder', 'level' => '中']], 'venue' => '绿地店',
        ])->assertOk()->json('data.id');
        $this->postJson("/api/post-class-reviews/{$id}/confirm")->assertOk();
        $planId = $this->postJson("/api/post-class-reviews/{$id}/to-plan")->assertOk()->json('data.planId');

        // ③ 前端拿着「只有旧计划」的列表再提交一次（模拟另一个标签页/早先加载的状态）
        $this->putJson('/api/training-plans/bulk', ['plans' => [[
            'id' => 1, 'memberName' => '旧计划', 'status' => '待老师确认',
            'coreGoal' => '改善骨盆前倾', 'content' => ['summary' => 's', 'phases' => [], 'cautions' => []],
        ]]])->assertOk();

        $this->assertDatabaseHas('training_plans', ['id' => $planId]);
        $plan = TrainingPlan::find($planId);
        $this->assertSame($id, $plan->source_review_id);   // 上游来源未被覆盖丢失

        // 删除改为显式：空列表不再等于「全部删除」
        $this->putJson('/api/training-plans/bulk', ['plans' => []])->assertOk();
        $this->assertDatabaseHas('training_plans', ['id' => 1]);

        // 显式指定 deletedIds 时才删
        $this->putJson('/api/training-plans/bulk', ['plans' => [], 'deletedIds' => [1]])->assertOk();
        $this->assertDatabaseMissing('training_plans', ['id' => 1]);
        $this->assertDatabaseHas('training_plans', ['id' => $planId]);
    }

    /** 红线记录不允许流转成训练计划 */
    public function test_red_flag_review_cannot_become_a_plan(): void
    {
        $coach = $this->makeUser('R_TEACHER', '王教练', 'coach-wang');
        Sanctum::actingAs($coach);

        $id = $this->postJson('/api/post-class-reviews', [
            'scene' => 'trial', 'studentName' => '红线学员', 'studentType' => '康复',
            'observations' => [['key' => 'core_weak', 'level' => '中']],
            'redFlags' => ['numb_radiate'], 'venue' => '绿地店',
        ])->assertOk()->json('data.id');

        $this->postJson("/api/post-class-reviews/{$id}/to-plan")->assertStatus(422);
    }

    public function test_candidates_return_three_people_sources(): void
    {
        // ① 上过课：预约行刻意不带手机号，只有姓名 + 门店
        KyBooking::create([
            'source_key' => '77:私教:2001', 'venue' => '绿地店', 'booking_type' => '私教',
            'course_kind' => 'private', 'member_id' => '2001', 'member_name' => '无手机号学员',
            'phone' => '', 'start_at' => now()->subDay(), 'teacher_name' => '王教练',
            'status' => 'signed', 'is_trial' => false,
        ]);
        Customer::create([
            'name' => '无手机号学员', 'phone' => '13900002001', 'venue' => '绿地店',
            'external_id' => 'ky:77:2001', 'layer' => 'P2', 'consultant' => '李顾问',
        ]);
        Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '无手机号学员', 'phone' => '13900002001',
            'source' => '美团', 'venue' => '绿地店', 'service_teacher' => '李顾问', 'status' => '已体验',
        ]);
        // ② 分配给他的留资（没有课次）
        Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '待约课客资', 'phone' => '13900002002',
            'source' => '大众点评', 'venue' => '绿地店', 'service_teacher' => '王教练', 'status' => '已联系',
        ]);
        // ③ 会籍归属他的会员
        Customer::create([
            'name' => '名下会员', 'phone' => '13900002003', 'venue' => '绿地店',
            'external_id' => 'ky:77:2003', 'layer' => 'P2', 'consultant' => '王教练',
        ]);

        Sanctum::actingAs($this->makeUser('R_TEACHER', '王教练', 'coach-wang'));
        $data = $this->getJson('/api/post-class-reviews/candidates?days=7')->assertOk()->json('data');

        // 三类来源都在
        $this->assertSame(['无手机号学员'], array_column($data['records'], 'studentName'));
        $this->assertSame(['待约课客资'], array_column($data['leads'], 'studentName'));
        $this->assertSame(['名下会员'], array_column($data['members'], 'studentName'));

        // 客资状态：预约行没有手机号，靠「姓名 + 门店」也能匹配上留资，不再是空值
        $this->assertSame('已体验', $data['records'][0]['leadStatus']);
        $this->assertSame('13900002001', $data['records'][0]['phone']);
    }

    /**
     * 来源渠道要能区分「老会员」与「新建客资」。
     *
     * 老会员在会员系统已建档、本来就没有留资记录；旧实现只看留资，
     * 于是所有老会员都显示成「未建客资」，看起来像数据缺失。
     */
    public function test_candidates_distinguish_member_and_lead_channels(): void
    {
        // ① 老会员：会员系统有档案 + 上过私教课，没有任何留资
        KyBooking::create([
            'source_key' => '77:私教:3001', 'venue' => '绿地店', 'booking_type' => '私教',
            'course_kind' => 'private', 'member_id' => '3001', 'member_name' => '老会员甲',
            'phone' => '13900003001', 'start_at' => now()->subDay(), 'teacher_name' => '王教练',
            'status' => 'signed', 'is_trial' => false,
        ]);
        Customer::create([
            'name' => '老会员甲', 'phone' => '13900003001', 'venue' => '绿地店',
            'external_id' => 'ky:77:3001', 'layer' => 'P2', 'consultant' => '李顾问',
            'main_card' => '私教年卡', 'remain_times' => 7,
        ]);

        // ② 新建客资：只有留资，也上过课，但会员系统里没有档案
        KyBooking::create([
            'source_key' => '77:私教:3002', 'venue' => '绿地店', 'booking_type' => '私教',
            'course_kind' => 'private', 'member_id' => '3002', 'member_name' => '新客乙',
            'phone' => '13900003002', 'start_at' => now()->subDay(), 'teacher_name' => '王教练',
            'status' => 'signed', 'is_trial' => true,
        ]);
        Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '新客乙', 'phone' => '13900003002',
            'source' => '美团', 'venue' => '绿地店', 'service_teacher' => '王教练', 'status' => '已体验',
        ]);

        Sanctum::actingAs($this->makeUser('R_TEACHER', '王教练', 'coach-wang'));
        $rows = collect(
            $this->getJson('/api/post-class-reviews/candidates?days=7')->assertOk()->json('data.records')
        )->keyBy('studentName');

        // 老会员：来源=会员系统，带主卡与剩余节数；没有留资也不算「未建档」
        $this->assertSame('member', $rows['老会员甲']['personType']);
        $this->assertSame('私教年卡', $rows['老会员甲']['memberCard']);
        $this->assertSame(7, $rows['老会员甲']['memberRemain']);

        // 新建客资：来源=留资管理
        $this->assertSame('lead', $rows['新客乙']['personType']);
        $this->assertNull($rows['新客乙']['memberCard'] ?: null);
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
