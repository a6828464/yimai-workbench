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

    public function test_service_teacher_scope_is_unchanged(): void
    {
        $service = $this->user('svc-a', '服务老师甲', 'R_SERVICE', '绿地店');
        $this->lead('名下客资', '绿地店', '绿地店长');
        Lead::where('name', '名下客资')->update(['service_teacher' => '服务老师甲', 'service_teacher_user_id' => $service->id]);
        $this->lead('待承接客资', '绿地店', '绿地店长');
        $this->lead('东部店客资', '东部店', '东部店长');
        $this->lead('别人名下客资', '绿地店', '绿地店长');
        Lead::where('name', '别人名下客资')->update(['service_teacher' => '服务老师乙']);

        Sanctum::actingAs($service);

        $this->assertSame(['名下客资', '待承接客资'], $this->leadNames());
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
