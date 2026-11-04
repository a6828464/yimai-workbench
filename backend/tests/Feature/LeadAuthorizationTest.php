<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_is_limited_to_own_venue_for_check_update_and_history(): void
    {
        $manager = $this->user('manager-green', '绿地店长', 'R_MANAGER', '绿地店');
        $own = $this->lead('绿地客资', '13800000001', '绿地店');
        $other = $this->lead('东部客资', '13800000001', '东部店');
        $this->auditFor($other);
        Sanctum::actingAs($manager);

        $this->getJson('/api/leads/check?phone=13800000001')
            ->assertOk()
            ->assertJsonPath('data.matches.0.name', '绿地客资')
            ->assertJsonCount(1, 'data.matches');
        $this->patchJson("/api/leads/{$own->id}", ['remark' => '已跟进'])->assertOk();
        $this->patchJson("/api/leads/{$other->id}", ['remark' => '越权'])->assertForbidden();
        $this->getJson("/api/leads/{$other->id}/history")->assertForbidden();
    }

    public function test_manager_create_and_update_force_own_venue(): void
    {
        $manager = $this->user('manager-green-force', '绿地店长', 'R_MANAGER', '绿地店');
        Sanctum::actingAs($manager);

        $id = $this->postJson('/api/leads', [
            'name' => '强制门店',
            'source' => '转介绍',
            'venue' => '东部店',
        ])->assertOk()->json('data.id');
        $this->assertDatabaseHas('leads', ['id' => $id, 'venue' => '绿地店']);

        $this->patchJson("/api/leads/{$id}", ['venue' => '东部店', 'remark' => '更新'])->assertOk();
        $this->assertDatabaseHas('leads', ['id' => $id, 'venue' => '绿地店', 'remark' => '更新']);
    }

    public function test_service_teacher_sees_only_own_leads_and_not_the_unassigned_pool(): void
    {
        // 2026-11 口径：未分配客资只对超管/店长/新媒体可见，服务老师只看**明确分到自己名下**的。
        // 待承接池下线前，这条测试锁的是「本人名下 + 全部未分配」，正是用户反馈的
        // 「没有分配的客资乱分配」的来源。
        $service = $this->user('service-green', '绿地顾问', 'R_SERVICE', '绿地店');
        $own = $this->lead('本人客资', '13800000002', '绿地店', '绿地顾问');
        $unassigned = $this->lead('待认领客资', '13800000002', '绿地店');
        $colleague = $this->lead('同事客资', '13800000002', '绿地店', '其他顾问');
        $otherVenue = $this->lead('跨店客资', '13800000002', '东部店');
        Sanctum::actingAs($service);

        // 列表：只剩本人名下（未分配与同事的都不在）
        $names = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))->pluck('name')->all();
        $this->assertSame(['本人客资'], $names);

        // 手机号查重：只回本人那一条（未分配客资不再命中）
        $this->getJson('/api/leads/check?phone=13800000002')
            ->assertOk()
            ->assertJsonCount(1, 'data.matches');

        $this->patchJson("/api/leads/{$own->id}", ['remark' => '本人跟进'])->assertOk();
        // 未分配：列表看不到，直达 URL 也不能改（否则就是「列表看不到、URL 能改」的 IDOR）
        $this->patchJson("/api/leads/{$unassigned->id}", ['serviceTeacher' => '绿地顾问'])->assertForbidden();
        $this->deleteJson("/api/leads/{$unassigned->id}")->assertForbidden();
        $this->getJson("/api/leads/{$unassigned->id}/history")->assertForbidden();
        $this->patchJson("/api/leads/{$colleague->id}", ['remark' => '越权'])->assertForbidden();
        $this->getJson("/api/leads/{$otherVenue->id}/history")->assertForbidden();
    }

    /**
     * 超管 / 店长 / 新媒体仍然看得到未分配客资 —— 收口的是老师侧，不是管理层。
     *
     * 这三类角色是客资的**分配方**：如果他们也看不到未分配客资，就没人能把它分配给谁。
     */
    public function test_unassigned_leads_stay_visible_to_manager_super_and_media(): void
    {
        $unassigned = $this->lead('待分配客资', '13800000021', '绿地店');
        $otherVenueUnassigned = $this->lead('东部待分配客资', '13800000022', '东部店');

        // 店长：本店全部（含未分配），可改
        Sanctum::actingAs($this->user('manager-pool', '绿地店长', 'R_MANAGER', '绿地店'));
        $names = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))->pluck('name')->all();
        $this->assertContains('待分配客资', $names);
        $this->assertNotContains('东部待分配客资', $names, '店长仍只限本店');
        $this->patchJson("/api/leads/{$unassigned->id}", ['serviceTeacher' => '绿地顾问'])->assertOk();
        $this->assertDatabaseHas('leads', ['id' => $unassigned->id, 'service_teacher' => '绿地顾问']);

        // 超管：双店全部，可改
        Sanctum::actingAs($this->user('super-pool', '老板', 'R_SUPER', null));
        $names = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))->pluck('name')->all();
        $this->assertContains('待分配客资', $names);
        $this->assertContains('东部待分配客资', $names);
        $this->patchJson("/api/leads/{$otherVenueUnassigned->id}", ['remark' => '老板分配'])->assertOk();

        // 新媒体：只看得到本部门录入的（未分配也不例外）
        $media = $this->user('media-pool', '新媒体小李', 'R_MEDIA', null);
        $mine = $this->lead('新媒体录的待分配', '13800000023', '东部店', '', $media->name);
        Sanctum::actingAs($media);
        $names = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))->pluck('name')->all();
        $this->assertSame(['新媒体录的待分配'], $names);
        $this->patchJson("/api/leads/{$mine->id}", ['remark' => '新媒体跟进'])->assertOk();
        $this->patchJson("/api/leads/{$unassigned->id}", ['remark' => '越权'])->assertForbidden();
    }

    /**
     * 老师侧录入的客资**默认落本人名下**：不会掉进未分配池，也不会「录完就消失」。
     *
     * 待承接池下线时必须一起补这条，否则老师录一条不填顾问的客资，自己的列表里立刻看不到
     * —— 数据没丢（管理层看得见），但录的人会以为丢了。归属列存本人后，列表与单条准入
     * （`scopeLeadsForUser` / `userCanAccessLead`）双双认她，不存在「列表看不到、URL 能改」。
     */
    public function test_teacher_created_lead_is_owned_by_the_creator_not_dropped_into_the_pool(): void
    {
        $service = $this->user('service-own-create', '绿地顾问', 'R_SERVICE', '绿地店');
        Sanctum::actingAs($service);

        $id = $this->postJson('/api/leads', [
            'name' => '自己录的客资', 'source' => '到店', 'venue' => '绿地店',
        ])->assertOk()->json('data.id');

        $this->assertDatabaseHas('leads', [
            'id' => $id,
            'service_teacher' => '绿地顾问',
            'service_teacher_user_id' => $service->id,
        ]);

        $names = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))->pluck('name')->all();
        $this->assertSame(['自己录的客资'], $names);
        $this->patchJson("/api/leads/{$id}", ['remark' => '自己跟进'])->assertOk();

        // 授课老师同理（她也可能是录客资的人）
        $coach = $this->user('coach-own-create', '绿地教练', 'R_TEACHER', '绿地店');
        Sanctum::actingAs($coach);
        $coachId = $this->postJson('/api/leads', [
            'name' => '教练录的客资', 'source' => '到店', 'venue' => '绿地店',
        ])->assertOk()->json('data.id');
        $this->assertDatabaseHas('leads', ['id' => $coachId, 'service_teacher' => '绿地教练']);
        $this->assertContains(
            '教练录的客资',
            collect($this->getJson('/api/leads')->assertOk()->json('data.records'))->pluck('name')->all()
        );

        // 管理层录入仍然保持「未分配」（由他们决定分给谁）：老师看不到，也不能改
        Sanctum::actingAs($this->user('manager-own-create', '绿地店长', 'R_MANAGER', '绿地店'));
        $poolId = $this->postJson('/api/leads', [
            'name' => '店长录入待分配', 'source' => '到店', 'venue' => '绿地店',
        ])->assertOk()->json('data.id');
        $this->assertDatabaseHas('leads', ['id' => $poolId, 'service_teacher' => '']);

        Sanctum::actingAs($service);
        $this->getJson("/api/leads/{$poolId}/history")->assertForbidden();
        $this->deleteJson("/api/leads/{$poolId}")->assertForbidden();
    }

    /**
     * 授课老师的「私教课学员」通道**只在客资已有真人服务老师时生效**（t5 O3）。
     *
     * 馆主原话：「所有没有标记会籍顾问、没有分配服务老师的客户，都不应该出现在超管店长
     * 新媒体以外任何人的会员管理或留资管理中」。会员侧已由 customerHasOwner 挡住，
     * 客资侧此前还能靠「手机号 = 本人私教学员」把无主客资带出来 —— 这条把它钉住。
     */
    public function test_coach_private_student_channel_requires_an_owned_lead(): void
    {
        $coach = $this->user('coach-pool', '绿地教练', 'R_TEACHER', '绿地店');
        // 私教学员关系：手机号 13800000061 是这位教练的私教签到学员
        KyBooking::create([
            'source_key' => 'V1:M61', 'venue' => '绿地店', 'booking_type' => '私教',
            'member_id' => 'M61', 'member_name' => '私教学员', 'phone' => '13800000061',
            'start_at' => now()->subDay(), 'course_name' => '私教', 'status' => 'signed',
            'course_kind' => 'private', 'teacher_name' => '绿地教练', 'teacher_user_id' => $coach->id,
        ]);
        // 同手机号两条客资：一条无主、一条已分配给别人
        $unowned = $this->lead('私教学员无主客资', '13800000061', '绿地店');
        $owned = $this->lead('私教学员有主客资', '13800000061', '绿地店', '其他顾问');

        $coach = User::findOrFail($coach->id);   // privateStudentKeys 请求内缓存，换实例=下一次请求
        Sanctum::actingAs($coach);

        $names = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))->pluck('name')->all();
        $this->assertSame(['私教学员有主客资'], $names, '无主客资不能靠私教手机号命中');
        // 查重同样只回有主那条
        $this->getJson('/api/leads/check?phone=13800000061')->assertOk()->assertJsonCount(1, 'data.matches');

        // 单条入口同口径：无主客资 403，有主（手机号命中）200
        $this->patchJson("/api/leads/{$unowned->id}", ['remark' => '越权'])->assertForbidden();
        $this->getJson("/api/leads/{$unowned->id}/history")->assertForbidden();
        $this->patchJson("/api/leads/{$owned->id}", ['remark' => '本人私教学员'])->assertOk();

        // 店长（分配方）两条都看得到：证明数据本身存在，老师的空集是收口而不是没数据
        Sanctum::actingAs($this->user('coach-pool-manager', '绿地店长', 'R_MANAGER', '绿地店'));
        $managerNames = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))->pluck('name')->all();
        $this->assertContains('私教学员无主客资', $managerNames);
        $this->assertContains('私教学员有主客资', $managerNames);
    }

    /**
     * 「管理层 + 老师」的多角色账号录入客资时**不自动挂本人**（t5 O4）。
     *
     * 自动补本人是为了「纯老师录完不至于消失」；管理层是分配方，它们录的客资按既有口径
     * 保持未分配，留给认领/分配。两条口径同时成立才叫一致。
     */
    public function test_manager_side_multi_role_does_not_auto_assign_new_lead(): void
    {
        // 店长 + 服务老师
        $managerService = $this->user('multi-ms', '绿地店长', 'R_MANAGER', '绿地店');
        $managerService->forceFill(['roles' => ['R_MANAGER', 'R_SERVICE']])->save();
        Sanctum::actingAs($managerService);
        $id = $this->postJson('/api/leads', [
            'name' => '店长兼服务录入', 'source' => '到店', 'venue' => '绿地店',
        ])->assertOk()->json('data.id');
        $this->assertDatabaseHas('leads', ['id' => $id, 'service_teacher' => '']);

        // 超管 + 授课老师：双店账号（venue=null），按管理层口径不自动挂人；
        // venue 也不再被账号的 null 覆盖（t10 修的是同一处中间件分支）
        $superTeacher = $this->user('multi-st', '老板', 'R_SUPER', null);
        $superTeacher->forceFill(['roles' => ['R_SUPER', 'R_TEACHER']])->save();
        Sanctum::actingAs($superTeacher);
        $id2 = $this->postJson('/api/leads', [
            'name' => '超管兼老师录入', 'source' => '到店', 'venue' => '绿地店',
        ])->assertOk()->json('data.id');
        $this->assertDatabaseHas('leads', ['id' => $id2, 'service_teacher' => '']);

        // 新媒体 + 授课老师
        $mediaTeacher = $this->user('multi-mt', '新媒体小李', 'R_MEDIA', '绿地店');
        $mediaTeacher->forceFill(['roles' => ['R_MEDIA', 'R_TEACHER']])->save();
        Sanctum::actingAs($mediaTeacher);
        $id3 = $this->postJson('/api/leads', [
            'name' => '新媒体兼老师录入', 'source' => '到店', 'venue' => '绿地店',
        ])->assertOk()->json('data.id');
        $this->assertDatabaseHas('leads', ['id' => $id3, 'service_teacher' => '']);

        // 纯老师（对照）：仍然自动挂本人
        Sanctum::actingAs($this->user('multi-pure', '纯顾问', 'R_SERVICE', '绿地店'));
        $id4 = $this->postJson('/api/leads', [
            'name' => '纯顾问录入', 'source' => '到店', 'venue' => '绿地店',
        ])->assertOk()->json('data.id');
        $this->assertDatabaseHas('leads', ['id' => $id4, 'service_teacher' => '纯顾问']);
    }

    /**
     * 管理层叠加老师角色（R_SUPER + R_TEACHER）录客资按**管理层**口径（t10）。
     *
     * 修复前：中间件按 `elseif` 逐个角色判断，超管兼老师会掉进「授课老师」分支，
     * 把 venue 覆盖成账号自身的 null → `venue required` 422，连录客资都做不了；
     * 字段也被锁成老师可见集（改不了成交金额）。现在按管理层处理：venue 取请求值、
     * 字段不锁，但仍保持「未分配」（分配方的既有口径）。
     */
    public function test_super_with_teacher_role_can_create_lead_with_request_venue(): void
    {
        $superTeacher = $this->user('super-teacher-create', '老板', 'R_SUPER', null);
        $superTeacher->forceFill(['roles' => ['R_SUPER', 'R_TEACHER']])->save();
        Sanctum::actingAs($superTeacher);

        $id = $this->postJson('/api/leads', [
            'name' => '超管兼老师录入', 'source' => '到店', 'venue' => '东部店',
        ])->assertOk()->json('data.id');
        $this->assertDatabaseHas('leads', ['id' => $id, 'venue' => '东部店', 'service_teacher' => '']);

        // 字段不被老师白名单锁住（纯老师改 dealAmount 会被 403）
        $this->patchJson("/api/leads/{$id}", ['dealAmount' => 1200])->assertOk();
        $this->assertDatabaseHas('leads', ['id' => $id, 'deal_amount' => 1200]);
    }

    /** 店长删除客资仍锁本店（t5 O5）：跨店 403、本店 200。 */
    public function test_manager_delete_lead_is_venue_locked(): void
    {
        $manager = $this->user('manager-delete', '绿地店长', 'R_MANAGER', '绿地店');
        $own = $this->lead('本店待删客资', '13800000071', '绿地店');
        $other = $this->lead('东部待删客资', '13800000072', '东部店');
        Sanctum::actingAs($manager);

        $this->deleteJson("/api/leads/{$other->id}")->assertForbidden();
        $this->assertDatabaseHas('leads', ['id' => $other->id]);
        $this->deleteJson("/api/leads/{$own->id}")->assertOk();
        $this->assertDatabaseMissing('leads', ['id' => $own->id]);
    }

    public function test_coach_sees_own_and_taught_leads_but_not_the_unassigned_pool(): void
    {
        // 授课老师（私教主教练）不承接公海：只看本人作为会籍顾问的、本人上过体验课的、本人私教学员的客资
        $coach = $this->user('coach-green', '绿地教练', 'R_TEACHER', '绿地店');
        $own = $this->lead('本人客资', '13800000004', '绿地店', '绿地教练');
        $taught = $this->lead('本人体验课客资', '13800000004', '绿地店', '其他顾问');
        $taught->update(['trial_teacher' => '绿地教练']);
        $unassigned = $this->lead('待认领客资', '13800000004', '绿地店');
        $otherVenue = $this->lead('跨店客资', '13800000004', '东部店', '绿地教练');
        Sanctum::actingAs($coach);

        // 待认领客资不在授课老师的可见范围内
        $this->getJson('/api/leads/check?phone=13800000004')
            ->assertOk()
            ->assertJsonCount(2, 'data.matches');
        $this->patchJson("/api/leads/{$own->id}", ['remark' => '本人跟进'])->assertOk();
        $this->patchJson("/api/leads/{$taught->id}", ['trialTopic' => '肩颈体验'])->assertOk();
        $this->patchJson("/api/leads/{$unassigned->id}", ['serviceTeacher' => '绿地教练'])->assertForbidden();
        $this->getJson("/api/leads/{$otherVenue->id}/history")->assertForbidden();
    }

    public function test_teacher_create_and_patch_reject_privileged_lead_fields(): void
    {
        $teacher = $this->user('teacher-restricted', '限制老师', 'R_TEACHER', '绿地店');
        Sanctum::actingAs($teacher);

        $this->postJson('/api/leads', [
            'name' => '非法成交',
            'source' => '到店',
            'venue' => '东部店',
            'status' => '已成交',
            'dealAmount' => 5000,
        ])->assertForbidden();

        $id = $this->postJson('/api/leads', [
            'name' => '老师录入',
            'source' => '到店',
            'venue' => '东部店',
        ])->assertOk()->json('data.id');
        $this->assertDatabaseHas('leads', ['id' => $id, 'venue' => '绿地店', 'status' => '新留资']);

        $this->patchJson("/api/leads/{$id}", ['dealAmount' => 5000])->assertForbidden();
        $this->patchJson("/api/leads/{$id}", ['serviceTeacher' => '其他老师'])->assertForbidden();
        $this->patchJson("/api/leads/{$id}", [
            'serviceTeacher' => '限制老师',
            'status' => '已联系',
            'demand' => '改善肩颈',
            'trialTopic' => '基础体验',
        ])->assertOk();
    }

    public function test_super_and_media_can_manage_leads_across_both_venues(): void
    {
        $lead = $this->lead('双店客资', '13800000003', '东部店', '东部老师');
        $this->auditFor($lead);

        // 超管：双店全权（口径未变）
        Sanctum::actingAs($this->user('owner', '老板', 'R_SUPER', null));
        $this->getJson('/api/leads/check?phone=13800000003')->assertJsonCount(1, 'data.matches');
        $this->patchJson("/api/leads/{$lead->id}", ['remark' => '老板'])->assertOk();
        $this->getJson("/api/leads/{$lead->id}/history")->assertOk();

        // 新媒体：**仅本人录入的**（v3.1.64 收口了列表口径，但单条路径当时被遗漏；
        // 本用例原先断言新媒体可跨店改单条，锁的是收口前的旧行为 —— 见下方新用例）
        Sanctum::actingAs($this->user('media', '新媒体', 'R_MEDIA', null));
        $this->patchJson("/api/leads/{$lead->id}", ['remark' => '新媒体'])->assertForbidden();
        $this->deleteJson("/api/leads/{$lead->id}")->assertForbidden();
    }

    /**
     * 新媒体的单条读写口径必须与列表口径一致（F2 越权修复）。
     *
     * 列表走 `staffOwnerFilter($user,'created_by_user_id','created_by')`（helpers.php:1096-1098），
     * 而单条在 `EnsureUserIsEnabled::canAccessLead()` 里对 R_MEDIA 直接 `return true` ⇒
     * 新媒体能改/删**任意门店**留资的 venue/deal_amount/status。典型 IDOR：两套口径。
     * 两个用例的冲突本身即证据：列表用例（v3.1.64）说「只看自己录入的」，本条所说的旧行为
     * 说「可跨店管理」—— 同一次请求的列表与详情不可能都对。
     */
    public function test_media_can_only_touch_leads_it_created(): void
    {
        $mine = $this->lead('我录的', '13800000041', '绿地店', '', '新媒体小张');
        $others = $this->lead('别人录的', '13800000042', '东部店', '', '东部店长');

        Sanctum::actingAs($this->user('media-scope', '新媒体小张', 'R_MEDIA', null));

        // 本人录入：可写可读可删
        $this->patchJson("/api/leads/{$mine->id}", ['remark' => '我的'])->assertOk();
        $this->getJson("/api/leads/{$mine->id}/history")->assertOk();

        // 他人录入：一律 403（改属性 / 删除 / 读变更历史）
        $this->patchJson("/api/leads/{$others->id}", ['remark' => '越权'])->assertForbidden();
        $this->patchJson("/api/leads/{$others->id}", ['dealAmount' => 99999])->assertForbidden();
        $this->patchJson("/api/leads/{$others->id}", ['status' => '已成交'])->assertForbidden();
        $this->deleteJson("/api/leads/{$others->id}")->assertForbidden();
        $this->getJson("/api/leads/{$others->id}/history")->assertForbidden();

        // 库内未被改动
        $others->refresh();
        $this->assertSame(0, (int) $others->deal_amount, '越权写入不得生效');
        $this->assertSame('新留资', $others->status);
    }

    public function test_lead_amounts_accept_two_decimal_places(): void
    {
        $manager = $this->user('manager-decimal', '小数店长', 'R_MANAGER', '绿地店');
        Sanctum::actingAs($manager);

        $id = $this->postJson('/api/leads', [
            'name' => '体验课核销',
            'source' => '到店',
            'venue' => '绿地店',
            'redeemAmount' => 99.9,
            'dealAmount' => 1280.50,
        ])->assertOk()->json('data.id');
        $this->assertDatabaseHas('leads', ['id' => $id, 'redeem_amount' => 99.9, 'deal_amount' => 1280.50]);

        $this->patchJson("/api/leads/{$id}", ['redeemAmount' => 88.05])->assertOk();
        $this->assertDatabaseHas('leads', ['id' => $id, 'redeem_amount' => 88.05]);

        // 超过两位小数应被拒绝
        $this->patchJson("/api/leads/{$id}", ['redeemAmount' => 88.005])->assertStatus(422);
    }

    public function test_disabled_user_existing_token_is_rejected(): void
    {
        $user = $this->user('disabled-token', '停用用户', 'R_TEACHER', '绿地店');
        $token = $user->createToken('test')->plainTextToken;
        $user->update(['status' => '停用']);

        $this->withToken($token)->getJson('/api/me')->assertForbidden();
    }

    public function test_disabling_or_resetting_an_account_revokes_all_tokens(): void
    {
        $super = $this->user('account-owner', '账号管理员', 'R_SUPER', null);
        $disabled = $this->user('disable-me', '待停用', 'R_TEACHER', '绿地店');
        $reset = $this->user('reset-me', '待重置', 'R_TEACHER', '绿地店');
        $disabled->createToken('one');
        $reset->createToken('one');
        Sanctum::actingAs($super);

        $this->patchJson('/api/accounts/disable-me', ['action' => 'disable'])->assertOk();
        $this->patchJson('/api/accounts/reset-me', [
            'action' => 'resetPassword',
            'password' => 'new-password',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_create_account_with_name_and_delete_it(): void
    {
        Sanctum::actingAs($this->user('account-admin', '账号管理员', 'R_SUPER', null));

        $this->postJson('/api/accounts', [
            'userName' => 'teacher-new',
            'name' => '新老师',
            'roleCode' => 'R_TEACHER',
            'venues' => ['绿地店'],
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('data.key', 'teacher-new');

        $this->assertDatabaseHas('users', [
            'username' => 'teacher-new',
            'name' => '新老师',
        ]);

        $this->patchJson('/api/accounts/teacher-new', ['action' => 'delete'])->assertOk();
        $this->assertDatabaseMissing('users', ['username' => 'teacher-new']);
    }

    public function test_today_followups_and_alerts_follow_role_scopes(): void
    {
        $this->customer('老师会员', '绿地店', '绿地老师', 'P1');
        $this->customer('同事会员', '绿地店', '其他老师', 'P1');
        $this->customer('跨店会员', '东部店', '东部老师', 'P1');
        $this->lead('老师客资', '13800000010', '绿地店', '绿地老师');
        $this->lead('待分配客资', '13800000011', '绿地店');
        $this->lead('同事客资', '13800000012', '绿地店', '其他老师');
        $this->lead('跨店客资', '13800000013', '东部店');
        Task::create([
            'title' => '老师任务', 'customer_name' => '老师会员', 'venue' => '绿地店',
            'owner' => '绿地老师', 'status' => '已逾期',
        ]);
        Task::create([
            'title' => '跨店任务', 'customer_name' => '跨店会员', 'venue' => '东部店',
            'owner' => '未分配', 'status' => '已逾期',
        ]);

        Sanctum::actingAs($this->user('today-service', '绿地老师', 'R_SERVICE', '绿地店'));
        $followups = $this->getJson('/api/today/followups')->assertOk()->json('data');
        $this->assertSame(['老师会员'], collect($followups)->pluck('name')->all());
        $serviceAlerts = collect($this->getJson('/api/today/alerts')->assertOk()->json('data'))->pluck('text')->join(' ');
        $this->assertStringContainsString('老师客资', $serviceAlerts);
        // 未分配客资只对超管/店长/新媒体可见：服务老师不再从提醒里看到待分配池
        $this->assertStringNotContainsString('待分配客资', $serviceAlerts);
        $this->assertStringNotContainsString('同事客资', $serviceAlerts);
        $this->assertStringNotContainsString('跨店', $serviceAlerts);

        // 授课老师看本人作为会籍顾问的会员与客资，同样不看未分配
        Sanctum::actingAs($this->user('today-coach', '绿地老师', 'R_TEACHER', '绿地店'));
        $coachFollowups = $this->getJson('/api/today/followups')->assertOk()->json('data');
        $this->assertSame(['老师会员'], collect($coachFollowups)->pluck('name')->all());
        $coachAlerts = collect($this->getJson('/api/today/alerts')->assertOk()->json('data'))->pluck('text')->join(' ');
        $this->assertStringContainsString('老师客资', $coachAlerts);
        $this->assertStringNotContainsString('待分配客资', $coachAlerts);
        $this->assertStringNotContainsString('同事客资', $coachAlerts);
        $this->assertStringNotContainsString('跨店', $coachAlerts);

        Sanctum::actingAs($this->user('today-manager', '绿地店长', 'R_MANAGER', '绿地店'));
        $managerAlerts = collect($this->getJson('/api/today/alerts')->assertOk()->json('data'))->pluck('text')->join(' ');
        $this->assertStringNotContainsString('跨店', $managerAlerts);
        // 店长是分配方：未分配客资必须出现在他的提醒里（收口只针对老师侧）
        $this->assertStringContainsString('待分配客资', $managerAlerts);

        // 新媒体：只看自己录入的客资。别人的客资（含跨店）都不应出现在提醒里。
        $media = $this->user('today-media', '新媒体', 'R_MEDIA', null);
        Sanctum::actingAs($media);
        $this->lead('媒体自己客资', '13800000014', '东部店', '', $media->name);
        $this->getJson('/api/today/followups')->assertOk()->assertJsonCount(0, 'data');
        $mediaAlerts = collect($this->getJson('/api/today/alerts')->assertOk()->json('data'))->pluck('text')->join(' ');
        $this->assertStringContainsString('媒体自己客资', $mediaAlerts);
        $this->assertStringNotContainsString('老师客资', $mediaAlerts);
        $this->assertStringNotContainsString('待分配客资', $mediaAlerts);
        $this->assertStringNotContainsString('跨店客资', $mediaAlerts);
        $this->assertStringNotContainsString('任务', $mediaAlerts);
        $this->assertStringNotContainsString('卡项临近到期', $mediaAlerts);
    }

    /**
     * 新媒体账号的客资列表 = **整个部门**录入的（此前的实现返回双店全量；
     * 2026-11 真机反馈后又从「只看本人」放开到「部门共享」，见 LeadScopeMediaShareTest）。
     */
    public function test_media_lead_list_is_department_scoped(): void
    {
        $this->lead('自己录入', '13800000021', '绿地店', '', '新媒体小张');
        $this->lead('绿地同事录入', '13800000022', '绿地店', '', '绿地店长');
        $this->lead('东部同事录入', '13800000023', '东部店', '', '东部店长');

        Sanctum::actingAs($this->user('list-media', '新媒体小张', 'R_MEDIA', null));
        $mediaNames = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))->pluck('name')->all();
        $this->assertSame(['自己录入'], $mediaNames);

        // 店长口径不变：本店全部，但不含跨店
        Sanctum::actingAs($this->user('list-manager', '绿地店长', 'R_MANAGER', '绿地店'));
        $managerNames = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))->pluck('name')->all();
        $this->assertContains('自己录入', $managerNames);
        $this->assertContains('绿地同事录入', $managerNames);
        $this->assertNotContains('东部同事录入', $managerNames);
    }

    /** 手机号带分隔符也要能查到重复，且落库归一为纯数字（否则查重静默漏报 → 重复客资） */
    public function test_lead_check_and_store_normalize_phone_separators(): void
    {
        $this->lead('已有客资', '13800000031', '绿地店', '', '绿地店长');
        Sanctum::actingAs($this->user('check-manager', '绿地店长', 'R_MANAGER', '绿地店'));

        $res = $this->getJson('/api/leads/check?phone=138-0000-0031')->assertOk();
        $this->assertTrue($res->json('data.exists'));
        $this->assertSame('已有客资', $res->json('data.matches.0.name'));

        $id = $this->postJson('/api/leads', [
            'name' => '带分隔符录入', 'source' => '到店', 'venue' => '绿地店', 'phone' => '138 0000 0032',
        ])->assertOk()->json('data.id');
        $this->assertDatabaseHas('leads', ['id' => $id, 'phone' => '13800000032']);

        // 归一后再查这条新数据，同样能命中
        $this->assertTrue($this->getJson('/api/leads/check?phone=13800000032')->assertOk()->json('data.exists'));
    }

    public function test_media_tasks_are_personal_and_analytics_follow_allowed_venues(): void
    {
        Task::create(['title' => '东部任务', 'customer_name' => '甲', 'venue' => '东部店', 'owner' => '东部老师', 'status' => '待接收']);
        Task::create(['title' => '新媒体任务', 'customer_name' => '乙', 'venue' => '东部店', 'owner' => '新媒体', 'status' => '待接收']);
        // created_by 必须写成新媒体账号的人名：新媒体客资范围是「整个部门」（t9 调整），
        // 没有录入人的行不属于任何部门，谁都不该看到。
        Lead::create(['lead_date' => now()->toDateString(), 'name' => '东部客资', 'source' => '测试', 'venue' => '东部店', 'status' => '已成交', 'deal_amount' => 100, 'created_by' => '新媒体']);
        Lead::create(['lead_date' => now()->toDateString(), 'name' => '绿地客资', 'source' => '小红书', 'venue' => '绿地店', 'status' => '新留资', 'created_by' => '新媒体']);

        $media = $this->user('media-scope', '新媒体', 'R_MEDIA', null);
        Sanctum::actingAs($media);

        // 任务：只能看到指派给自己的
        $tasks = $this->getJson('/api/tasks')->assertOk()->json('data.records');
        $this->assertSame(['新媒体任务'], collect($tasks)->pluck('title')->all());

        $this->getJson('/api/analytics/channels')->assertOk()->assertJsonPath('data.total', 2);
        $this->getJson('/api/analytics/channels?venue='.urlencode('东部店'))->assertOk()->assertJsonPath('data.total', 1);
        // 走势按「部门」口径：两位新媒体登记的客资都算（t9 之前是门店口径，会把全店留资算进来）
        $this->getJson('/api/analytics/trends')->assertOk()->assertJsonPath('data.summary.leadCount', 2);
        $this->getJson('/api/analytics/platforms')->assertOk()->assertJsonPath('data.totalDeal', 100);

        $greenOnly = $this->user('media-green', '绿地新媒体', 'R_MEDIA', null);
        $greenOnly->update(['venues' => ['绿地店']]);
        Sanctum::actingAs($greenOnly);
        $this->getJson('/api/analytics/channels?venue='.urlencode('东部店'))->assertForbidden();
        $this->getJson('/api/analytics/trends?venue='.urlencode('东部店'))->assertForbidden();
        $this->getJson('/api/analytics/platforms?venue='.urlencode('东部店'))->assertForbidden();
        $this->getJson('/api/analytics/channels')->assertOk()->assertJsonPath('data.total', 1);
        // 授权门店只剩绿地店，但客资是**部门**口径（跨门店共享）：另一个新媒体登记的东部客资也算。
        // 这条与上面 channels 的 1 不矛盾：channels 是门店业绩（applyVenueScope），
        // trends 的留资数是「我能跟进的客资」，新媒体要能接手同事跨店登记的客资。
        $this->getJson('/api/analytics/trends')->assertOk()->assertJsonPath('data.summary.leadCount', 2);
        $this->getJson('/api/analytics/platforms')->assertOk()->assertJsonPath('data.totalDeal', 0);
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

    private function lead(string $name, string $phone, string $venue, string $teacher = '', string $createdBy = ''): Lead
    {
        return Lead::create([
            'lead_date' => now()->toDateString(),
            'name' => $name,
            'phone' => $phone,
            'source' => '测试',
            'venue' => $venue,
            'service_teacher' => $teacher,
            'created_by' => $createdBy,
            'status' => '新留资',
        ]);
    }

    private function customer(string $name, string $venue, string $consultant, string $layer): Customer
    {
        return Customer::create([
            'name' => $name,
            'venue' => $venue,
            'consultant' => $consultant,
            'owner' => $consultant,
            'layer' => $layer,
        ]);
    }

    private function auditFor(Lead $lead): void
    {
        AuditLog::create([
            'operator_name' => '测试',
            'operator_role' => '超管',
            'action' => '修改',
            'module' => '前端客资',
            'target_id' => (string) $lead->id,
            'target_label' => $lead->name,
            'venue' => $lead->venue,
        ]);
    }
}
