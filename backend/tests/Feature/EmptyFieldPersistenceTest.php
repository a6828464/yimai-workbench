<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 「清空字段」必须真正落库。
 *
 * 前端清空输入框提交的是空串，Laravel 的 ConvertEmptyStringsToNull 会先把它变成 null。
 * 这类 null 有两种错误处理方式，症状刚好相反：
 *   - 当成「没传」丢掉：用户看到「保存成功、刷新又回来」（留资备注清不掉就是这条）；
 *   - 当成「显式清空」但写错了值：非空列（如 leads.wechat）撞 NOT NULL 直接 500。
 * 所以判定必须区分「字段没传」和「字段传空」—— 没传的字段一律不许动。
 */
class EmptyFieldPersistenceTest extends TestCase
{
    use RefreshDatabase;

    /** 清空留资备注后必须落库，刷新列表不能再看到旧备注 */
    public function test_clearing_a_lead_remark_is_persisted(): void
    {
        Sanctum::actingAs($this->user('clear-manager', '绿地店长', 'R_MANAGER', '绿地店'));
        $lead = $this->lead('备注客户', '13800000041', '绿地店');
        $lead->update(['remark' => '初次沟通，关注肩颈', 'demand' => '肩颈不适']);

        $this->patchJson("/api/leads/{$lead->id}", ['remark' => ''])->assertOk();

        // remark 是可空列：清空写 null；非空列（微信等）写 ''，见下一个用例
        $this->assertNull($lead->refresh()->remark, '备注应被清空');
        $this->assertSame('肩颈不适', $lead->demand, '没传的字段必须保持原值');

        $row = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))
            ->firstWhere('id', $lead->id);
        $this->assertEmpty($row['remark'] ?? null, '刷新列表后不应再出现旧备注');
    }

    /** 清空非空列（微信）写入空串，而不是 500 */
    public function test_clearing_a_not_null_lead_field_writes_empty_string(): void
    {
        Sanctum::actingAs($this->user('clear-manager2', '绿地店长', 'R_MANAGER', '绿地店'));
        $lead = $this->lead('非空列客户', '13800000042', '绿地店');
        $lead->update(['wechat' => 'amy_wechat']);

        $this->patchJson("/api/leads/{$lead->id}", ['wechat' => ''])->assertOk();

        $this->assertSame('', (string) $lead->refresh()->wechat);
    }

    /**
     * 店长/超管的编辑弹窗提交的是**整个表单**（dialog.form 由整行数据展开而来），
     * 所以真实请求里除了被清空的字段，还夹着 phoneTail / id / dealAt 等非库表字段。
     * 这条用例按真实载荷走一遍：既有「清空备注」的诉求，也要确认没传的字段不被误伤。
     */
    public function test_manager_full_form_payload_clears_remark_without_touching_other_fields(): void
    {
        Sanctum::actingAs($this->user('clear-manager3', '绿地店长', 'R_MANAGER', '绿地店'));
        $lead = $this->lead('整表客户', '13800000044', '绿地店');
        $lead->update([
            'remark' => '旧备注内容',
            'demand' => '体态调整',
            'wechat' => 'old_wechat',
            'grade' => '内观流',
            'deal_amount' => 3200,
            'deal_at' => now(),
        ]);

        $this->patchJson("/api/leads/{$lead->id}", [
            'id' => $lead->id,
            'leadDate' => (string) $lead->lead_date,
            'name' => '整表客户',
            'phone' => '13800000044',
            'phoneTail' => '0044',
            'wechat' => 'old_wechat',
            'demand' => '体态调整',
            'source' => '测试',
            'orderPlatform' => '大众点评',
            'venue' => '绿地店',
            'serviceTeacher' => '',
            'status' => '已成交',
            'grade' => '内观流',
            'dealCard' => '私教年卡',
            'dealAmount' => 3200,
            'redeemAmount' => null,
            'trialCards' => [],
            'remark' => '', // 清空备注
            'dealAt' => '2026-09-01T10:00:00.000000Z', // 非库表字段，应被忽略
            'redeemedAt' => null,
            'createdBy' => '别人',
            'createdAt' => '2026-08-26T02:02:44.000000Z',
            'updatedAt' => '2026-09-01T10:00:00.000000Z',
        ])->assertOk();

        $lead->refresh();
        $this->assertNull($lead->remark, '清空的备注必须落库');
        $this->assertSame('体态调整', $lead->demand);
        $this->assertSame('old_wechat', $lead->wechat, '表单里带回来的原值不该被清掉');
        $this->assertSame('内观流', $lead->grade);
        $this->assertSame(3200.0, (float) $lead->deal_amount, '成交金额不该被清掉');
        $this->assertSame('私教年卡', $lead->deal_card);
    }

    /** 老师端只提交部分字段（不带金额），不能把留资的成交金额抹掉 */
    public function test_teacher_partial_patch_does_not_wipe_untouched_amounts(): void
    {
        $coach = $this->user('clear-coach', '绿地教练', 'R_TEACHER', '绿地店');
        $lead = $this->lead('教练客资', '13800000043', '绿地店', '绿地教练');
        $lead->update(['deal_amount' => 1280.50, 'deal_card' => '私教年卡', 'deal_at' => now()]);
        Sanctum::actingAs($coach);

        // 老师端编辑只发这几个字段
        $this->patchJson("/api/leads/{$lead->id}", [
            'demand' => '改善体态',
            'status' => '已联系',
            'remark' => '',
            'serviceTeacher' => '绿地教练',
            'trialCards' => [],
        ])->assertOk();

        $lead->refresh();
        $this->assertSame(1280.50, (float) $lead->deal_amount, '没传的成交金额不许被清空');
        $this->assertSame('私教年卡', $lead->deal_card);
        $this->assertSame('改善体态', $lead->demand);
    }

    /** 保存续课预报（只发 renewalPlan）不能顺手抹掉最近沟通与生日 */
    public function test_saving_renewal_plan_does_not_wipe_other_member_fields(): void
    {
        $manager = $this->user('clear-mgr-member', '绿地店长', 'R_MANAGER', '绿地店');
        $customer = $this->customer('工作流会员', '绿地店', '绿地店长');
        $customer->update(['last_touch' => '2026-09-01', 'birthday' => '1990-05-20', 'expected_return' => '2026-10-01']);
        Sanctum::actingAs($manager);

        $this->patchJson("/api/customers/{$customer->id}", [
            'renewalPlan' => ['goal' => '体态改善'],
            '_action' => '续课预报',
        ])->assertOk();

        $customer->refresh();
        $this->assertSame('2026-09-01', (string) $customer->last_touch, '最近沟通日期不许被抹掉');
        $this->assertSame('1990-05-20', (string) $customer->birthday, '生日不许被抹掉');
        $this->assertSame('2026-10-01', (string) $customer->expected_return, '预期复活时间不许被抹掉');
    }

    /** 会员侧的「预期复活 / 停练原因」清空后写入空串（非空列），刷新不再回来 */
    public function test_clearing_member_workflow_text_fields_is_persisted(): void
    {
        Sanctum::actingAs($this->user('clear-mgr-member2', '绿地店长', 'R_MANAGER', '绿地店'));
        $customer = $this->customer('清空会员', '绿地店', '绿地店长');
        $customer->update(['expected_return' => '2026-10-01', 'stop_reason' => '出差']);

        $this->patchJson("/api/customers/{$customer->id}", [
            'expectedReturn' => '',
            'stopReason' => '',
            '_action' => '复活跟进',
        ])->assertOk();

        $customer->refresh();
        $this->assertSame('', (string) $customer->expected_return);
        $this->assertSame('', (string) $customer->stop_reason);
    }

    /** 清空任务的截止时间 / 验收标准写入空串，而不是 500 */
    public function test_clearing_task_fields_is_persisted(): void
    {
        Sanctum::actingAs($this->user('clear-mgr-task', '绿地店长', 'R_MANAGER', '绿地店'));
        $task = Task::create([
            'title' => '清空验证', 'customer_name' => '会员', 'venue' => '绿地店',
            'owner' => '绿地店长', 'priority' => '中', 'deadline' => '2026-09-30 18:00',
            'status' => '待接收', 'standard' => '完成沟通',
        ]);

        $this->patchJson("/api/tasks/{$task->id}", ['deadline' => '', 'standard' => ''])->assertOk();

        $task->refresh();
        $this->assertSame('', (string) $task->deadline);
        $this->assertSame('', (string) $task->standard);
    }

    private function user(string $username, string $name, string $role, ?string $venue): User
    {
        return User::factory()->create([
            'username' => $username,
            'name' => $name,
            'role' => $role,
            'venue' => $venue,
            'venues' => $venue ? [$venue] : ['绿地店', '东部店'],
            'status' => '启用',
        ]);
    }

    private function lead(string $name, string $phone, string $venue, string $teacher = ''): Lead
    {
        return Lead::create([
            'lead_date' => now()->toDateString(),
            'name' => $name,
            'phone' => $phone,
            'source' => '测试',
            'venue' => $venue,
            'service_teacher' => $teacher,
            'status' => '新留资',
        ]);
    }

    private function customer(string $name, string $venue, string $consultant): Customer
    {
        return Customer::create([
            'name' => $name,
            'phone' => '13800000001',
            'phone_tail' => '0001',
            'venue' => $venue,
            'source' => 'KeepYoga',
            'consultant' => $consultant,
            'owner' => $consultant,
            'main_card' => '私教卡',
            'remain_times' => 30,
            'layer' => 'P4',
            'status' => '在籍',
        ]);
    }
}
