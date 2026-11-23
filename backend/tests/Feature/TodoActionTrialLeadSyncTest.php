<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\KyBooking;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 体验课「已接待 / 爽约」→ 留资管理状态的同步（t3 排查 + 修复的回归测试）。
 *
 * 馆主真机反馈：「已接待 / 爽约这种标签要能点击后同步反馈到留资管理同步更新」，但现场看到
 * 留资管理里的状态没变。排查结论（详见 TodayController::todoAction 业务流转 3 的注释）：
 *
 *  ① KY（随心瑜）体验预约与「留资」是**两套独立事实**，前台排体验课不会生成留资 →
 *     没有 lead 时本来就无从同步（本文件 test_ky_trial_without_any_lead_... 固化这一点）；
 *  ② 号码形态不一致（**主因，已修**）：`normalizePhone()` 只去分隔符，不处理 +86 / 长途 0 前缀，
 *     KY 里的 `+8613900000051` 归一成 13 位，而留资是 11 位，等值比较永远不命中
 *     （test_ky_trial_matches_lead_across_country_code_forms）；
 *  ③ 终态不回退、多命中不联动、无权限不写 —— 三条都是**设计如此**，但以前静默，
 *     现在每次不联动都把原因写进审计详情（本文件逐条断言那句原因）。
 *
 * 另：`trial:ky-Y` 以前**永远不碰** `leads.trial_cards`（`$session > 0` 的守卫），
 * 于是留资管理里那张体验卡一直显示未上课。现在按「留资侧恰好一张今天的未取消卡」收敛回写。
 */
class TodoActionTrialLeadSyncTest extends TestCase
{
    use RefreshDatabase;

    /** 已接待（留资卡片分支）→ leads.status=已体验 + 卡片 attended，且审计留痕。 */
    public function test_lead_trial_attended_writes_status_and_card(): void
    {
        Sanctum::actingAs($this->manager());
        $lead = $this->lead('留资卡片客', '13900000071', ['status' => '已约体验', 'trial_cards' => [
            ['session' => 1, 'time' => now()->format('Y-m-d 10:00'), 'topic' => '体验第一节', 'teacher' => '老师A'],
            ['session' => 2, 'time' => now()->addDay()->format('Y-m-d 10:00'), 'topic' => '体验第二节', 'teacher' => '老师A'],
        ]]);

        $this->postJson('/api/today/todo/action', [
            'type' => 'trials', 'key' => 'trial:lead-'.$lead->id.'-1', 'action' => '已接待',
        ])->assertOk()->assertJsonPath('data.done', true);

        $fresh = $lead->fresh();
        $this->assertSame('已体验', $fresh->status, '已接待 → 留资状态机推进到「已体验」');
        $this->assertTrue((bool) $fresh->trial_cards[0]['attended']);
        $this->assertFalse((bool) ($fresh->trial_cards[0]['noShow'] ?? false));
        $this->assertArrayNotHasKey('attended', $fresh->trial_cards[1], '没点的那一节不能被连带标记');
        $this->assertDatabaseHas('audit_logs', [
            'module' => '前端客资', 'target_id' => (string) $lead->id, 'action' => '标记处理',
        ]);
    }

    /** 爽约（留资卡片分支）→ leads.status=爽约 + 卡片 noShow。 */
    public function test_lead_trial_no_show_writes_status_and_card(): void
    {
        Sanctum::actingAs($this->manager());
        $lead = $this->lead('留资爽约客', '13900000072', ['status' => '已约体验', 'trial_cards' => [
            ['session' => 1, 'time' => now()->format('Y-m-d 11:00'), 'topic' => '体验课', 'teacher' => '老师B'],
        ]]);

        $this->postJson('/api/today/todo/action', [
            'type' => 'trials', 'key' => 'trial:lead-'.$lead->id.'-1', 'action' => '爽约',
        ])->assertOk();

        $fresh = $lead->fresh();
        $this->assertSame('爽约', $fresh->status);
        $this->assertTrue((bool) $fresh->trial_cards[0]['noShow']);
    }

    /**
     * KY 分支（已修）：手机号带 +86 时也要能命中 11 位留资。
     *
     * 修复前：`preg_replace('/\D+/','')` 把 `+8613900000075` 归一成 13 位 `8613900000075`，
     * 与留资的 `13900000075` 等值比较恒不等 → 状态永远同步不过去。
     */
    public function test_ky_trial_matches_lead_across_country_code_forms(): void
    {
        Sanctum::actingAs($this->manager());
        $lead = $this->lead('跨形态留资客', '13900000075', ['status' => '已约体验']);
        $booking = $this->kyBooking('KY跨形态客', '+86 139-0000-0075');

        // 待办列表里的 leadId 必须与 action 会写入的那条是同一个（否则前端提示与实际行为分叉）
        $todo = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $item = collect($todo['trials'])->firstWhere('key', 'trial:ky-'.$booking->id);
        $this->assertNotNull($item);
        $this->assertSame($lead->id, $item['leadId'], '列表侧反查也必须走同一套等价形态');

        $this->postJson('/api/today/todo/action', [
            'type' => 'trials', 'key' => 'trial:ky-'.$booking->id, 'action' => '已接待',
        ])->assertOk();

        $this->assertSame('已体验', $lead->fresh()->status, '+86 形态必须命中 11 位留资');
    }

    /**
     * KY 分支 + 留资侧恰好一张今天的未取消卡 → 卡片结果也要回写。
     *
     * 修复前 KY 分支 `$session` 恒 0，`$session > 0` 的守卫生效 → trial_cards 永远不写，
     * 留资管理里那张卡一直是「未上课」。
     */
    public function test_ky_trial_writes_back_the_single_today_card(): void
    {
        Sanctum::actingAs($this->manager());
        $lead = $this->lead('KY卡片客', '13900000076', ['status' => '已约体验', 'trial_cards' => [
            ['session' => 1, 'time' => now()->format('Y-m-d 09:00'), 'topic' => '今天的体验', 'teacher' => '老师C'],
        ]]);
        $booking = $this->kyBooking('KY卡片客', '13900000076');

        $this->postJson('/api/today/todo/action', [
            'type' => 'trials', 'key' => 'trial:ky-'.$booking->id, 'action' => '已接待',
        ])->assertOk();

        $fresh = $lead->fresh();
        $this->assertSame('已体验', $fresh->status);
        $this->assertTrue((bool) $fresh->trial_cards[0]['attended'], '唯一今天的卡必须被回写');
        // 卡片同步属于待办那条审计的细节（`module=今日待办`，target_id 为待办 key）：
        // 客资那条只记录状态变更这一个事实，所以原因断言要落在待办审计上
        $this->assertStringContainsString(
            '已同步留资体验卡结果',
            $this->auditDetail(null, '标记处理', 'trial:ky-'.$booking->id)
        );
    }

    /** KY 分支：留资有多张今天的卡（无法确定是哪一节）→ 状态写、卡片不猜，并把原因写进审计。 */
    public function test_ky_trial_skips_card_writeback_when_multiple_today_cards(): void
    {
        Sanctum::actingAs($this->manager());
        $lead = $this->lead('KY两节客', '13900000077', ['status' => '已约体验', 'trial_cards' => [
            ['session' => 1, 'time' => now()->format('Y-m-d 09:00'), 'topic' => '第一节', 'teacher' => '老师C'],
            ['session' => 2, 'time' => now()->format('Y-m-d 17:00'), 'topic' => '第二节', 'teacher' => '老师C'],
        ]]);
        $booking = $this->kyBooking('KY两节客', '13900000077');

        $this->postJson('/api/today/todo/action', [
            'type' => 'trials', 'key' => 'trial:ky-'.$booking->id, 'action' => '爽约',
        ])->assertOk();

        $fresh = $lead->fresh();
        $this->assertSame('爽约', $fresh->status, '状态仍要同步（手机号唯一命中，与卡片数无关）');
        $this->assertArrayNotHasKey('noShow', $fresh->trial_cards[0], '多张今天的卡 → 不猜是哪一节');
        $this->assertArrayNotHasKey('noShow', $fresh->trial_cards[1]);
        $this->assertStringContainsString(
            '未回写卡片结果',
            $this->auditDetail(null, '标记处理', 'trial:ky-'.$booking->id)
        );
    }

    /** 多命中不联动（设计如此）：两条进行中留资都不改，但当日标记照常落库 + 原因入审计。 */
    public function test_ky_trial_with_multiple_active_leads_does_not_link(): void
    {
        Sanctum::actingAs($this->manager());
        $a = $this->lead('留资甲', '13900000078', ['status' => '已约体验']);
        $b = $this->lead('留资乙', '13900000078', ['status' => '已联系', 'source' => '美团']);
        $booking = $this->kyBooking('同号客', '13900000078');

        $this->postJson('/api/today/todo/action', [
            'type' => 'trials', 'key' => 'trial:ky-'.$booking->id, 'action' => '已接待',
        ])->assertOk()->assertJsonPath('data.done', true);

        $this->assertSame('已约体验', $a->fresh()->status);
        $this->assertSame('已联系', $b->fresh()->status);
        $this->assertDatabaseHas('todo_actions', ['todo_key' => 'trial:ky-'.$booking->id, 'action' => '已接待']);
        $this->assertStringContainsString(
            '同手机号命中 2 条进行中留资',
            $this->auditDetail(null, '标记处理', 'trial:ky-'.$booking->id)
        );
    }

    /** 完全没有留资（两套事实）→ 不报错、只写待办，并在审计里说清「没有留资记录」。 */
    public function test_ky_trial_without_any_lead_still_marks_todo(): void
    {
        Sanctum::actingAs($this->manager());
        $booking = $this->kyBooking('无留资体验客', '13900000079');

        $this->postJson('/api/today/todo/action', [
            'type' => 'trials', 'key' => 'trial:ky-'.$booking->id, 'action' => '爽约',
        ])->assertOk()->assertJsonPath('data.done', true);

        $this->assertSame(0, Lead::count(), '不允许为了「看起来同步了」凭空造一条留资（影子状态）');
        $this->assertDatabaseHas('todo_actions', ['todo_key' => 'trial:ky-'.$booking->id, 'action' => '爽约']);
        $this->assertStringContainsString(
            '该手机号没有留资记录',
            $this->auditDetail(null, '标记处理', 'trial:ky-'.$booking->id)
        );
    }

    /** 留资已是终态（已成交）→ 状态不回退，但原因写清（以前是静默）。 */
    public function test_ky_trial_does_not_roll_back_terminal_lead_status(): void
    {
        Sanctum::actingAs($this->manager());
        $lead = $this->lead('已成单客', '13900000080', ['status' => '已成交']);
        $booking = $this->kyBooking('已成单客', '13900000080');

        $this->postJson('/api/today/todo/action', [
            'type' => 'trials', 'key' => 'trial:ky-'.$booking->id, 'action' => '爽约',
        ])->assertOk();

        $this->assertSame('已成交', $lead->fresh()->status, '终态留资绝不能被待办回退');
        $this->assertStringContainsString(
            '终态',
            $this->auditDetail(null, '标记处理', 'trial:ky-'.$booking->id)
        );
    }

    // ------------------------------------------------------------- 数据准备

    private function manager(): User
    {
        return User::factory()->create([
            'username' => 'mgr-trial-sync', 'name' => '绿地店长', 'role' => 'R_MANAGER', 'venue' => '绿地店',
        ]);
    }

    private function lead(string $name, string $phone, array $overrides = []): Lead
    {
        return Lead::create(array_merge([
            'lead_date' => now()->toDateString(), 'name' => $name, 'phone' => $phone,
            'source' => '大众点评', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '新留资',
        ], $overrides));
    }

    private function kyBooking(string $memberName, string $phone): KyBooking
    {
        return KyBooking::create([
            'source_key' => uniqid('t3-'),
            'venue' => '绿地店',
            'booking_type' => '团课',
            'member_name' => $memberName,
            'phone' => $phone,
            'start_at' => now()->format('Y-m-d 10:00'),
            'course_name' => '体验团课',
            'teacher_name' => '老师A',
            'status' => 'booked',
            'is_trial' => true,
        ]);
    }

    /**
     * 取最近一条审计详情。
     *
     * `$leadId = null` 时按「今日待办」模块的 target_id（= 待办 key）定位，
     * 供「不联动」这类**不写客资日志**的路径使用（它们只有待办那条审计）。
     */
    private function auditDetail(?int $leadId, string $action, ?string $todoKey = null): string
    {
        $q = AuditLog::query()->where('action', $action);
        if ($leadId !== null) {
            $q->where('module', '前端客资')->where('target_id', (string) $leadId);
        } else {
            $q->where('module', '今日待办')->where('target_id', (string) $todoKey);
        }

        return (string) $q->orderByDesc('id')->value('detail');
    }
}
