<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 老师新建客资不再被整单 403（t19 / v3.21.0）。
 *
 * ## 事故现场
 *
 * 老师真机反馈「没有新建客资的权限」。根因不是角色权限，而是
 * `EnsureUserIsEnabled::assertTeacherFields()` 里那句
 * `abort_if(array_diff(array_keys($request->all()), $allowed) !== [], 403, ...)`：
 * **请求体多一个键就整单拒绝**。而小程序 `buildCreatePayload()` 会带
 * `dealCard/dealAmount/couponName/voucherCode/redeemAmount` 这 5 个管理字段
 * （旧版本的构建里还有 `grade`/`trialCards`），于是老师 100% 建不了客资，
 * 且报错文案「老师无权修改该客资字段」把人误导成「我没权限」。
 *
 * ## 本文件守什么
 *
 * ① 老师拿**完整的**小程序 payload（含 5 个管理字段 + trialCards + grade）必须 200：
 *    管理字段被**剥离**（不进库），trialCards / grade 正常落库；
 * ② 剥离要留痕（audit：module=留资管理，detail 列出被剥离的字段名）；
 * ③ 真正的越权防线不动：指派给别人 403、标记成交 403。
 *
 * 之所以必须造「完整 payload」而不是干净请求：这类事故正是因为测试只发最小字段
 * （`name + source + venue`）才漏掉的 —— 服务端契约与前端真实请求脱节。
 */
class TeacherLeadCreateFieldStripTest extends TestCase
{
    use RefreshDatabase;

    /** ① 老师提交完整小程序 payload → 200，管理字段被剥离、trialCards/grade 落库。 */
    public function test_teacher_can_create_lead_with_full_miniapp_payload(): void
    {
        foreach (['R_SERVICE', 'R_TEACHER'] as $role) {
            $teacher = $this->user('绿地顾问', 'staff-'.strtolower($role), $role);
            Sanctum::actingAs($teacher);

            $res = $this->postJson('/api/leads', $this->fullMiniappPayload())
                ->assertOk();

            $id = $res->json('data.id');
            $lead = Lead::findOrFail($id);

            // ① 老师确实需要的两个字段：进白名单、按 leadFields 落库
            $this->assertSame('A', $lead->grade, "{$role}：grade 必须落库");
            $this->assertIsArray($lead->trial_cards);
            $this->assertCount(1, $lead->trial_cards, "{$role}：体验课卡片必须落库");
            $this->assertSame('普拉提体验课', $lead->trial_cards[0]['topic']);
            $this->assertSame(now()->addDay()->format('Y-m-d 10:00'), $lead->trial_cards[0]['time']);
            // 镜像列：第一节的老师
            $this->assertSame('老师A', $lead->trial_teacher);

            // ② 管理字段被剥离：一个都不许落库
            $this->assertNull($lead->deal_amount, "{$role}：金额字段必须被剥离");
            $this->assertNull($lead->redeem_amount, "{$role}：核销金额必须被剥离");
            $this->assertSame('', (string) $lead->deal_card, "{$role}：成交卡项必须被剥离");
            $this->assertSame('', (string) $lead->coupon_name);
            $this->assertSame('', (string) $lead->voucher_code);

            // ③ 剥离留痕：字段名进审计（便于日后查「填了没保存」）
            $log = AuditLog::where('module', '留资管理')
                ->where('action', '字段剥离')
                ->where('operator_name', $teacher->name)
                ->orderByDesc('id')
                ->first();
            $this->assertNotNull($log, "{$role}：剥离动作必须留痕，不能静默");
            foreach (['dealCard', 'dealAmount', 'couponName', 'voucherCode', 'redeemAmount'] as $field) {
                $this->assertStringContainsString($field, (string) $log->detail, "剥离清单里应列出 {$field}");
            }
            // 白名单内的两个字段不算「被剥离」
            $this->assertStringNotContainsString('trialCards', (string) $log->detail);
            $this->assertStringNotContainsString('grade', (string) $log->detail);

            // ④ 既有行为不变：老师新建的客资归本人、初始状态强制「新留资」
            $this->assertSame($teacher->name, $lead->service_teacher);
            $this->assertSame('新留资', $lead->status);
            $this->assertSame('绿地店', $lead->venue);
        }
    }

    /** ② 指派给别人仍是硬拒绝（真正的越权防线）。 */
    public function test_teacher_cannot_assign_lead_to_someone_else(): void
    {
        $teacher = $this->user('绿地顾问', 'staff-assign', 'R_SERVICE');
        $other = $this->user('另一个顾问', 'staff-other', 'R_SERVICE');
        Sanctum::actingAs($teacher);

        $this->postJson('/api/leads', array_merge($this->fullMiniappPayload(), [
            'serviceTeacher' => $other->name,
        ]))->assertForbidden();

        $this->assertSame(0, Lead::count(), '被拒的请求不能留下半条数据');
    }

    /** ③ 老师不能直接标记成交（仍是硬拒绝）。 */
    public function test_teacher_cannot_mark_lead_as_dealt(): void
    {
        $teacher = $this->user('绿地顾问', 'staff-deal', 'R_SERVICE');
        Sanctum::actingAs($teacher);

        $this->postJson('/api/leads', array_merge($this->fullMiniappPayload(), [
            'status' => '已成交',
        ]))->assertForbidden();

        $this->assertSame(0, Lead::count());
    }

    /** ④ 管理员不受影响：完整 payload 原样落库（含金额），且不产生「字段剥离」审计。 */
    public function test_manager_payload_is_untouched(): void
    {
        $manager = $this->user('绿地店长', 'mgr-full-payload', 'R_MANAGER');
        Sanctum::actingAs($manager);

        $id = $this->postJson('/api/leads', $this->fullMiniappPayload())
            ->assertOk()->json('data.id');
        $lead = Lead::findOrFail($id);

        $this->assertSame('私教30节', (string) $lead->deal_card);
        $this->assertSame(9900, (int) $lead->deal_amount, '管理层金额照常落库');
        $this->assertSame('A', $lead->grade);
        $this->assertSame(0, AuditLog::where('action', '字段剥离')->count(), '管理层不该有剥离审计');
    }

    // ------------------------------------------------------------- 数据准备

    /**
     * 小程序 `leads/form.vue::buildCreatePayload()` 的真实形状（旧构建，未做前端裁剪）：
     * 11 个业务字段 + 5 个管理字段 + trialCards + grade。
     */
    private function fullMiniappPayload(): array
    {
        return [
            'leadDate' => now()->toDateString(),
            'name' => '王女士',
            'phone' => '13900003333',
            'wechat' => 'wx_wang',
            'demand' => '想改善体态',
            'source' => '大众点评',
            'orderPlatform' => '美团',
            'venue' => '绿地店',
            'status' => '新留资',
            'remark' => '电话里说下周三来',
            // ── 老师需要、本次补进 CREATE 白名单的两项 ──
            'grade' => 'A',
            'trialCards' => [[
                'session' => 1,
                'time' => now()->addDay()->format('Y-m-d 10:00'),
                'topic' => '普拉提体验课',
                'teacher' => '老师A',
                'couponName' => '',
                'voucherCode' => '',
                'platform' => '美团',
            ]],
            // ── 白名单外的管理字段：必须被剥离，不许落库 ──
            'dealCard' => '私教30节',
            'dealAmount' => 9900,
            'couponName' => '体验券',
            'voucherCode' => 'VOUCHER-001',
            'redeemAmount' => 0,
        ];
    }

    private function user(string $name, string $username, string $role, string $venue = '绿地店'): User
    {
        return User::factory()->create([
            'name' => $name, 'username' => $username, 'role' => $role, 'roles' => [$role],
            'venue' => $venue, 'venues' => [$venue], 'status' => '启用',
        ]);
    }
}
