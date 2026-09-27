<?php

namespace Tests\Feature;

use App\Models\BodyTestReport;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
