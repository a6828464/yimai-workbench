<?php

namespace Tests\Feature;

use App\Models\BodyTestReport;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 体测报告的写入校验与列表口径（T1 §7 F3/F4 修复）。
 *
 * F3：`store()` 里 venue/customerId/leadId 直接取自请求输入，全文件无 canAccessCustomer
 *     调用 ⇒ 可往任意门店/任意会员挂报告。
 * F4：`index()` 对 R_SERVICE 只卡门店，详情 `isVisible()` 却要求 ownsByRelation
 *     ⇒ 列表看得见、点进去 403，列表本身就是全店健康数据的泄露面。
 */
class BodyTestReportAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_write_rejects_foreign_venue_and_foreign_customer(): void
    {
        $teacher = $this->user('bt-teacher', '授课老师', 'R_TEACHER', '绿地店');
        $foreignCustomer = $this->customer('他店会员', '东部店', '别人');
        $ownCustomer = $this->customer('本店会员', '绿地店', '授课老师');

        Sanctum::actingAs($teacher);

        // 指定他店 venue ⇒ 必须被否定（venue 一律取账号自身门店，超管除外）
        $this->postJson('/api/body-test-reports', [
            'url' => $this->url(),
            'venue' => '东部店',
            'customerId' => $ownCustomer->id,
        ])->assertForbidden();

        // 指定他人名下的会员 ⇒ 403
        $this->postJson('/api/body-test-reports', [
            'url' => $this->url(),
            'customerId' => $foreignCustomer->id,
        ])->assertForbidden();
    }

    /**
     * 体测写入的客资准入必须与留资侧**同一判据**（t10）。
     *
     * 修复前 `leadVisible()` 自己写了一套，多出一条 `created_by` 通道：老师能凭「本人录入」
     * 把体测报告写到**无主客资**（service_teacher 为空/占位）上并回填 lead_id ——
     * 而列表/PATCH/DELETE 早就把无主客资挡在老师侧之外，这条是侧门。
     * 现在直接复用 userCanAccessLead()：无主 → 403；已分到本人名下 → 越过权限闸门
     * （Http::fake 让拉取失败，观测点变成 422 而不是 403，证明挡的是权限而不是别的）。
     */
    public function test_write_rejects_unassigned_lead_even_if_teacher_created_it(): void
    {
        $teacher = $this->user('bt-teacher-lead', '授课老师', 'R_TEACHER', '绿地店');

        $unassigned = Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '老师自己录入的待分配客资',
            'phone' => '13800009001', 'source' => '到店', 'venue' => '绿地店',
            'service_teacher' => '', 'service_teacher_user_id' => null,
            'created_by' => '授课老师', 'created_by_user_id' => $teacher->id, 'status' => '新留资',
        ]);
        $assigned = Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '已分到本人名下的客资',
            'phone' => '13800009002', 'source' => '到店', 'venue' => '绿地店',
            'service_teacher' => '授课老师', 'service_teacher_user_id' => $teacher->id,
            'created_by' => '店长', 'status' => '新留资',
        ]);

        // 越过权限后的拉取失败（避免真实出网），观测点 = 不是 403
        Http::fake(['*' => Http::response([], 500)]);

        Sanctum::actingAs($teacher);

        $this->postJson('/api/body-test-reports', [
            'url' => $this->url(),
            'leadId' => $unassigned->id,
        ])->assertForbidden();

        $this->postJson('/api/body-test-reports', [
            'url' => $this->url(),
            'leadId' => $assigned->id,
        ])->assertStatus(422);
    }

    /** 合法体测链接（parseUrl 要求 report-new/{数字}/{hex}/{数字}） */
    private function url(): string
    {
        return 'https://bodytest.ruleye.com/#/report-new/12345/deadbeef0123/1700000000';
    }

    /** 列表可见集必须与详情可见性一致（列表能看到的，点进去不能 403） */
    public function test_list_and_detail_share_the_same_visibility(): void
    {
        $service = $this->user('bt-service', '服务老师', 'R_SERVICE', '绿地店');

        // 两条报告都落在本店：一条挂本人名下会员，一条挂他人名下会员
        $mine = $this->customer('我的会员', '绿地店', '服务老师');
        $others = $this->customer('他人会员', '绿地店', '别人');
        $visible = $this->report('绿地店', $mine->id, null);
        $hidden = $this->report('绿地店', $others->id, null);

        Sanctum::actingAs($service);

        $ids = collect($this->getJson('/api/body-test-reports')->assertOk()->json('data.records'))
            ->pluck('id')->all();

        // 列表里出现过的每一条，详情都必须可读
        foreach ($ids as $id) {
            $this->getJson("/api/body-test-reports/{$id}")->assertOk();
        }

        // 挂他人名下会员的报告不得出现在列表中（否则列表即泄露面）
        $this->assertNotContains($hidden->id, $ids, '他人名下会员的体测报告不得对 R_SERVICE 列表可见');

        // 本人名下的必须可见，且详情可读
        $this->assertContains($visible->id, $ids, '本人名下会员的体测报告必须可见');
        $this->getJson("/api/body-test-reports/{$visible->id}")->assertOk();
    }

    private function user(string $username, string $name, string $role, ?string $venue): User
    {
        return User::factory()->create([
            'username' => $username, 'name' => $name, 'role' => $role,
            'venue' => $venue, 'venues' => $venue ? [$venue] : ['绿地店', '东部店'],
            'status' => '启用',
        ]);
    }

    private function customer(string $name, string $venue, string $consultant): Customer
    {
        return Customer::create([
            'name' => $name, 'venue' => $venue,
            'consultant' => $consultant, 'owner' => $consultant,
            'layer' => 'P4', 'status' => '跟进中',
        ]);
    }

    private function report(string $venue, ?int $customerId, ?int $leadId): BodyTestReport
    {
        return BodyTestReport::create([
            'body_test_id' => 'bt-'.uniqid(),
            'venue' => $venue,
            'customer_id' => $customerId,
            'lead_id' => $leadId,
            'member_name' => '体测对象',
            'tested_at' => now(),
            'score' => 80,
            'created_by' => 999,
        ]);
    }
}
