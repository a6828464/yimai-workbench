<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PostClassReview;
use App\Models\TrainingPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 客户分析 / 训练计划单份内容的对外分享 token（t12 / v3.20.0）。
 *
 * 馆主的担心是「客户点到别人的内容」。所以两道锁一起测：
 *  ① token 是 32 字节随机、与 kind+id 绑死 —— 它只指向一份内容，天然不会串；
 *  ② 手机号后四位（`?code=`）是第二道门，错误码要能区分「链接失效 / 要输码 / 码不对」。
 *
 * 本文件还守着两件容易回退的事：
 *  - **白名单**：公开响应里不许出现手机号全号、归属账号、内部备注、金额；
 *  - **可见性复用**：签发端的权限判定必须与详情接口同口径（越权必须 403）。
 */
class ShareTokenTest extends TestCase
{
    use RefreshDatabase;

    /** ① 签发客户分析 token：32 字节随机、与内容绑定、写审计。 */
    public function test_issue_review_token_returns_random_token_bound_to_the_row(): void
    {
        $coach = $this->user('王教练', 'coach-share', 'R_TEACHER');
        $review = $this->review($coach);

        Sanctum::actingAs($coach);
        $res = $this->postJson("/api/share/review/{$review->id}/token")->assertOk()->json('data');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $res['token'], '32 字节随机 → hex 64 位');
        $this->assertSame('review', $res['kind']);
        $this->assertSame($review->id, $res['id']);
        $this->assertTrue($res['needCode'], '学员手机号齐全 → 需要校验后四位');

        $fresh = $review->fresh();
        $this->assertSame($res['token'], $fresh->share['token'], 'token 落在内容行自带的 share JSON 里');
        $this->assertSame('1111', $fresh->share['tokenTail'], '校验码在签发时固化（不随后来改号失效）');
        $this->assertDatabaseHas('audit_logs', ['action' => '生成分享', 'module' => '课后分析']);

        // 换发：再签一次必须换新码（旧链接不能永久有效）
        Sanctum::actingAs($coach);
        $again = $this->postJson("/api/share/review/{$review->id}/token")->assertOk()->json('data.token');
        $this->assertNotSame($res['token'], $again);
    }

    /** ② 免登录读取：白名单字段齐、且不含任何 PII 全量值与内部字段。 */
    public function test_public_read_returns_whitelisted_fields_only(): void
    {
        $coach = $this->user('王教练', 'coach-share', 'R_TEACHER');
        $review = $this->review($coach);
        $token = $this->issue($coach, 'review', $review->id);

        $res = $this->getJson("/api/public/share/{$token}?code=1111")->assertOk();

        $data = $res->json('data');
        $this->assertSame('review', $data['kind']);
        $this->assertSame('王教练', $data['teacherName']);
        $this->assertSame('骨盆前倾、核心弱', $data['conclusion'], '结论');
        $this->assertSame('核心弱', $data['dimensions'][0]['label'], '维度评分/评级');
        $this->assertSame('中', $data['dimensions'][0]['level']);
        $this->assertSame('第一阶段', $data['suggestions'][0]['name'], '建议');

        // 白名单之外一律不下发（逐条断言，防止有人日后「顺手」把整行 payload 塞进来）
        foreach (['phone', 'studentPhone', 'studentName', 'email', 'createdBy', 'createdByUserId',
            'remark', 'dealAmount', 'redeemAmount', 'payload'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data, "公开响应不得包含 {$forbidden}");
        }
        // 手机号全号一个字都不许出现在响应里
        $this->assertStringNotContainsString('13900001111', $res->getContent());
    }

    /** ③ 三种失败口径必须能区分：410 失效 / 401 要输码 / 403 码不对。 */
    public function test_public_read_error_codes_are_distinguishable(): void
    {
        $coach = $this->user('王教练', 'coach-share', 'R_TEACHER');
        $review = $this->review($coach);
        $token = $this->issue($coach, 'review', $review->id);

        $this->getJson("/api/public/share/{$token}")
            ->assertStatus(401)->assertJsonPath('errno', 401);
        $this->getJson("/api/public/share/{$token}?code=9999")
            ->assertStatus(403)->assertJsonPath('errno', 403);
        // 不存在的 token：与「已停用」同一个响应，避免变成存在性探测
        $this->getJson('/api/public/share/'.str_repeat('a', 64))
            ->assertStatus(410)->assertJsonPath('errno', 410);
        // 形态都不对：同样 410（不泄露「这个串不是我们的码」之外的任何信息）
        $this->getJson('/api/public/share/not-a-token')->assertStatus(410);
    }

    /** ④ token 与 kind 绑定：review 的码查不到 plan，反之亦然（不可跨用）。 */
    public function test_token_cannot_be_reused_across_kinds(): void
    {
        $coach = $this->user('王教练', 'coach-share', 'R_TEACHER');
        $review = $this->review($coach);
        $plan = $this->plan($coach, $review);

        $reviewToken = $this->issue($coach, 'review', $review->id);
        $planToken = $this->issue($coach, 'plan', $plan->id);

        $this->assertSame('review', $this->getJson("/api/public/share/{$reviewToken}?code=1111")
            ->assertOk()->json('data.kind'));
        $this->assertSame('plan', $this->getJson("/api/public/share/{$planToken}?code=1111")
            ->assertOk()->json('data.kind'));

        // 结构层证据：review 的码从未写进 plans 行，plan 的码也不在 reviews 行
        $this->assertTrue(TrainingPlan::where('share->token', $reviewToken)->doesntExist());
        $this->assertTrue(PostClassReview::where('share->token', $planToken)->doesntExist());
    }

    /** ⑤ 停用后立即失效（410）—— 馆主的后悔药。 */
    public function test_disabled_token_is_gone(): void
    {
        $coach = $this->user('王教练', 'coach-share', 'R_TEACHER');
        $review = $this->review($coach);
        $token = $this->issue($coach, 'review', $review->id);

        $share = $review->fresh()->share;
        $share['tokenEnabled'] = false;
        $review->update(['share' => $share]);

        $this->getJson("/api/public/share/{$token}?code=1111")->assertStatus(410);
    }

    /** ⑥ 越权签发一律 403（可见性判定与详情接口同口径）。 */
    public function test_issue_requires_same_visibility_as_detail(): void
    {
        $coach = $this->user('王教练', 'coach-share', 'R_TEACHER');
        $colleague = $this->user('别的教练', 'coach-other', 'R_TEACHER');
        $otherVenueManager = $this->user('东部店长', 'mgr-east', 'R_MANAGER', '东部店');
        $review = $this->review($coach);
        $plan = $this->plan($coach, $review);

        // 同店但既不是授课老师、也没有归属关系 → 看不到详情，也就不能发链接
        Sanctum::actingAs($colleague);
        $this->postJson("/api/share/review/{$review->id}/token")->assertForbidden();
        $this->postJson("/api/share/plan/{$plan->id}/token")->assertForbidden();

        // 跨店店长同理
        Sanctum::actingAs($otherVenueManager);
        $this->postJson("/api/share/review/{$review->id}/token")->assertForbidden();
        $this->postJson("/api/share/plan/{$plan->id}/token")->assertForbidden();
    }

    /** ⑦ 红线 / 未确认的课后分析不对外分享（与对客页同一条红线）。 */
    public function test_red_flag_and_draft_reviews_cannot_be_shared(): void
    {
        $coach = $this->user('王教练', 'coach-share', 'R_TEACHER');
        $red = $this->review($coach, ['red_flag' => true]);
        $draft = $this->review($coach, ['status' => '草稿']);

        Sanctum::actingAs($coach);
        $this->postJson("/api/share/review/{$red->id}/token")->assertStatus(422);
        $this->postJson("/api/share/review/{$draft->id}/token")->assertStatus(422);
    }

    /** ⑧ 训练计划：白名单为阶段/动作/组次/注意事项/教练名；校验码可沿来源分析取得。 */
    public function test_plan_public_view_is_whitelisted_and_uses_linked_review_phone(): void
    {
        $coach = $this->user('王教练', 'coach-share', 'R_TEACHER');
        $review = $this->review($coach);
        $plan = $this->plan($coach, $review);

        $issued = $this->issue($coach, 'plan', $plan->id, true);
        $this->assertTrue($issued['needCode'], '计划本身无手机号 → 沿 source_review_id 取学员手机号');

        $data = $this->getJson("/api/public/share/{$issued['token']}?code=1111")->assertOk()->json('data');

        $this->assertSame('plan', $data['kind']);
        $this->assertSame('王教练', $data['teacherName']);
        $this->assertSame('改善骨盆前倾', $data['coreGoal']);
        $this->assertSame('第一阶段', $data['phases'][0]['name']);
        $this->assertSame('臀桥', $data['phases'][0]['actions'][0]['name'], '动作');
        $this->assertSame('3', $data['phases'][0]['actions'][0]['sets'], '组数');
        $this->assertSame('12', $data['phases'][0]['actions'][0]['reps'], '次数');
        $this->assertSame('训练后拉伸', $data['cautions'][0], '注意事项');

        foreach (['memberName', 'phone', 'memberPhone', 'createdByUserId', 'remark', 'share', 'payload'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data, "公开响应不得包含 {$forbidden}");
        }
    }

    /** ⑨ 每次成功访问都留审计（便于馆主追溯「谁什么时候看过」）。 */
    public function test_successful_public_read_is_audited(): void
    {
        $coach = $this->user('王教练', 'coach-share', 'R_TEACHER');
        $review = $this->review($coach);
        $token = $this->issue($coach, 'review', $review->id);

        $this->assertSame(0, AuditLog::where('module', '公开分享')->count());

        $this->getJson("/api/public/share/{$token}?code=1111")->assertOk();

        $log = AuditLog::where('module', '公开分享')->first();
        $this->assertNotNull($log, '访问必须留痕');
        $this->assertSame('公开访问', $log->action);
        $this->assertSame((string) $review->id, $log->target_id);
        $this->assertSame('访客', $log->operator_name);
        // 审计日志本身不许成为 PII 泄漏面：不记 code、不记手机号
        $this->assertStringNotContainsString('1111', (string) $log->detail);
        $this->assertStringNotContainsString('13900001111', (string) $log->detail);
    }

    /** ⑩ 不带手机号的计划：不设校验码（needCode=false），仅靠 token 保护。 */
    public function test_plan_without_any_phone_skips_the_code_gate(): void
    {
        $coach = $this->user('王教练', 'coach-share', 'R_TEACHER');
        $plan = TrainingPlan::create([
            'member_name' => '无号学员', 'status' => '已确认', 'created_by' => '王教练',
            'created_by_user_id' => $coach->id, 'venue' => '绿地店', 'source_review_id' => null,
            'payload' => ['memberName' => '无号学员', 'coreGoal' => '体态', 'content' => ['phases' => [], 'cautions' => []]],
        ]);

        $issued = $this->issue($coach, 'plan', $plan->id, true);
        $this->assertFalse($issued['needCode']);

        $this->assertSame('plan', $this->getJson("/api/public/share/{$issued['token']}")
            ->assertOk()->json('data.kind'));
    }

    // ------------------------------------------------------------- 数据准备

    private function issue(User $u, string $kind, int $id, bool $asArray = false): mixed
    {
        Sanctum::actingAs($u);
        $res = $this->postJson("/api/share/{$kind}/{$id}/token")->assertOk()->json('data');

        return $asArray ? $res : $res['token'];
    }

    /** 一条已确认、无红线的课后分析 */
    private function review(User $coach, array $overrides = []): PostClassReview
    {
        return PostClassReview::create(array_merge([
            'venue' => '绿地店',
            'scene' => 'trial',
            'teacher_user_id' => $coach->id,
            'teacher_name' => '王教练',
            'student_name' => '张学员',
            'student_phone' => '13900001111',
            'class_at' => now()->subDay(),
            'course_name' => '体验课',
            'student_type' => '体态调整',
            'red_flag' => false,
            'status' => '已确认',
            'confirmed_at' => now()->subDay(),
            'payload' => [
                'observations' => [
                    ['key' => 'core_weak', 'label' => '核心弱', 'level' => '中'],
                ],
                'objective' => [
                    'observed' => '骨盆前倾、核心弱',
                    'plan' => [
                        ['name' => '第一阶段', 'duration' => '4 周', 'goal' => '激活核心', 'focus' => '呼吸'],
                    ],
                    'nextStep' => '进入私教常规课',
                ],
                // 内部备注：绝不能出现在公开响应里
                'remark' => '这位客户砍价很凶',
            ],
        ], $overrides));
    }

    private function plan(User $coach, PostClassReview $review): TrainingPlan
    {
        return TrainingPlan::create([
            'member_name' => '张学员',
            'status' => '已确认',
            'created_by' => '王教练',
            'created_by_user_id' => $coach->id,
            'venue' => '绿地店',
            'source_review_id' => $review->id,
            'confirmed_at' => now()->subDay(),
            'payload' => [
                'memberName' => '张学员',
                'coreGoal' => '改善骨盆前倾',
                'content' => [
                    'summary' => '以核心稳定为主',
                    'phases' => [[
                        'name' => '第一阶段', 'goal' => '激活核心', 'weeks' => '4',
                        'actions' => [[
                            'name' => '臀桥', 'sets' => '3', 'reps' => '12', 'rest' => '60s', 'note' => '收紧臀部',
                        ]],
                    ]],
                    'cautions' => ['训练后拉伸'],
                ],
                'remark' => '内部备注：客户膝盖有旧伤',
            ],
        ]);
    }

    private function user(string $name, string $username, string $role, string $venue = '绿地店'): User
    {
        return User::factory()->create([
            'name' => $name, 'username' => $username, 'role' => $role, 'roles' => [$role],
            'venue' => $venue, 'venues' => [$venue], 'status' => '启用',
        ]);
    }
}
