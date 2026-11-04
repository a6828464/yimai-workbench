<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 新媒体客资范围：**部门共享**（2026-11 真机反馈）。
 *
 * 事故现象：线上运营两个人轮班，A 登记完客资下班，B 上班打开「留资」是空的 ——
 * 因为范围只放了 `created_by_user_id = 本人`。交接等于断档，用户要求「整个部门共享」。
 *
 * 口径：
 *  - 新媒体 → 全体 R_MEDIA 账号录入的客资（跨门店）；
 *  - 服务老师 / 授课老师 / 店长 / 超管口径**不变**（本文件同时钉住，防止改媒体时误伤）。
 */
class LeadScopeMediaShareTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $username, string $name, string $role, ?string $venue = null, array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'username' => $username,
            'name' => $name,
            'role' => $role,
            'roles' => [$role],
            'venue' => $venue,
            'venues' => $venue ? [$venue] : ['绿地店', '东部店'],
            'status' => '启用',
        ], $extra));
    }

    private function lead(string $name, string $venue, string $createdBy, ?int $createdByUserId = null): Lead
    {
        return Lead::create([
            'lead_date' => now()->toDateString(),
            'name' => $name,
            'phone' => '138'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'source' => '小红书',
            'venue' => $venue,
            'status' => '新留资',
            'created_by' => $createdBy,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    private function leadNames(): array
    {
        return collect($this->getJson('/api/leads')->assertOk()->json('data.records'))
            ->pluck('name')->sort()->values()->all();
    }

    public function test_media_sees_leads_created_by_other_media_accounts_across_venues(): void
    {
        $mediaA = $this->user('media-a', '新媒体小王', 'R_MEDIA');
        $mediaB = $this->user('media-b', '新媒体小李', 'R_MEDIA');

        // 历史行只有姓名（user_id 为空），新行两个字段都有 —— 两条路都要能命中
        $this->lead('小王登记的客资', '绿地店', '新媒体小王', $mediaA->id);
        $this->lead('小李登记的客资', '东部店', '新媒体小李', $mediaB->id);
        $this->lead('历史只有姓名的客资', '绿地店', '新媒体小李', null);

        Sanctum::actingAs($mediaB);

        // B 能看到 A 的、自己的、以及历史行；跨门店（东部店）也在范围内
        $this->assertSame(
            ['历史只有姓名的客资', '小李登记的客资', '小王登记的客资'],
            $this->leadNames()
        );
    }

    public function test_media_no_longer_sees_leads_from_other_departments(): void
    {
        $this->user('media-c', '新媒体小张', 'R_MEDIA');
        $this->lead('新媒体自己的', '绿地店', '新媒体小张');

        // 店长/服务老师登记的不属于新媒体部门
        $this->lead('店长登记的', '绿地店', '绿地店长');
        $this->lead('老师登记的', '绿地店', '服务老师甲');

        Sanctum::actingAs(User::where('username', 'media-c')->firstOrFail());

        $this->assertSame(['新媒体自己的'], $this->leadNames());
    }

    /**
     * 服务老师的范围：**只认本人名下**（2026-11 待承接池下线）。
     *
     * 池子下线前这条测试锁的是「名下 + 待承接」，而待承接池正是「未分配客资乱分配」的来源：
     * 每个服务老师都能看到全店未分配客资，谁先点开算谁的。
     * 现在未分配客资只对超管/店长/新媒体可见（分配方），店长的这几条仍然看得到。
     */
    public function test_service_teacher_scope_excludes_the_unassigned_pool(): void
    {
        $service = $this->user('svc-a', '服务老师甲', 'R_SERVICE', '绿地店');
        $this->lead('名下客资', '绿地店', '绿地店长');
        Lead::where('name', '名下客资')->update(['service_teacher' => '服务老师甲', 'service_teacher_user_id' => $service->id]);
        $this->lead('待承接客资', '绿地店', '绿地店长');
        $this->lead('东部店客资', '东部店', '东部店长');
        $this->lead('别人名下客资', '绿地店', '绿地店长');
        Lead::where('name', '别人名下客资')->update(['service_teacher' => '服务老师乙']);

        Sanctum::actingAs($service);

        $this->assertSame(['名下客资'], $this->leadNames());

        // 店长（分配方）仍看得到本店未分配客资：收口只针对老师侧
        Sanctum::actingAs($this->user('mgr-a', '绿地店长', 'R_MANAGER', '绿地店'));
        // leadNames() 按名字排序后返回（集合断言，顺序不是契约）
        $this->assertSame(['别人名下客资', '名下客资', '待承接客资'], $this->leadNames());
    }

    /**
     * 新媒体的**单条入口**与列表同口径（t5 O2）：部门共享的客资，B 也能改/删/看历史；
     * 非本部门录入的仍然 403。此前列表按部门、单条只认本人，表现为「列表看得到、点开 403」。
     */
    public function test_media_single_record_operations_follow_department_scope(): void
    {
        $mediaA = $this->user('media-x', '新媒体小王', 'R_MEDIA');
        $mediaB = $this->user('media-y', '新媒体小李', 'R_MEDIA');
        $byDept = $this->lead('小王登记的客资', '绿地店', '新媒体小王', $mediaA->id);
        $byManager = $this->lead('店长登记的客资', '绿地店', '绿地店长');

        Sanctum::actingAs($mediaB);

        // 部门共享：同事登记的单条可读可改可删
        $this->getJson("/api/leads/{$byDept->id}/history")->assertOk();
        $this->patchJson("/api/leads/{$byDept->id}", ['remark' => '小李接手'])->assertOk();
        $this->deleteJson("/api/leads/{$byDept->id}")->assertOk();

        // 非本部门登记的单条仍然 403（列表也看不到它们，口径一致）
        $this->getJson("/api/leads/{$byManager->id}/history")->assertForbidden();
        $this->patchJson("/api/leads/{$byManager->id}", ['remark' => '越权'])->assertForbidden();
        $this->deleteJson("/api/leads/{$byManager->id}")->assertForbidden();
    }

    public function test_coach_scope_is_unchanged(): void
    {
        $coach = $this->user('coach-a', '授课老师甲', 'R_TEACHER', '绿地店');
        $this->lead('我上的体验课', '绿地店', '绿地店长');
        Lead::where('name', '我上的体验课')->update(['trial_teacher' => '授课老师甲', 'trial_teacher_user_id' => $coach->id]);
        $this->lead('我名下会籍客资', '绿地店', '绿地店长');
        Lead::where('name', '我名下会籍客资')->update(['service_teacher' => '授课老师甲']);
        $this->lead('别人的客资', '绿地店', '绿地店长');
        Lead::where('name', '别人的客资')->update(['service_teacher' => '服务老师乙']);

        Sanctum::actingAs($coach);

        $this->assertSame(['我上的体验课', '我名下会籍客资'], $this->leadNames());
    }
}
