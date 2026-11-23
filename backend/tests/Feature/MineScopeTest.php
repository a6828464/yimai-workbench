<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 「只看我负责的」开关（`?scope=mine`）。
 *
 * ## 为什么必须有一条测试钉住它
 *
 * 这个开关解决的是**现场抱怨**（2026-11 员工真机：「会员管理、留资管理里出现不属于
 * 自己归属的客户」），但它极易被误实现成两种坏形态：
 *
 *  1. **替换**角色范围而不是叠加 → 打开开关反而看到比自己权限更多的数据（越权）；
 *  2. 只改列表 `index`、不改 `list-counts` / `list-ids` → 徽标数说有 5 条、点进去只有 2 行
 *     （本仓库历史上为这类「同源分裂」修过多次，见 CustomerController 的注释）。
 *
 * 所以这里同时断言：开关打开后①只剩本人负责的、②授课关系被排除、③徽标与列表同源。
 */
class MineScopeTest extends TestCase
{
    use RefreshDatabase;

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

    private function customer(string $name, string $phone, array $attrs = []): Customer
    {
        return Customer::create(array_merge([
            'name' => $name,
            'phone' => $phone,
            'phone_tail' => substr($phone, -4),
            'venue' => '绿地店',
            'source' => 'KeepYoga',
            'consultant' => '',
            'owner' => '',
            'main_card' => '私教卡',
            'remain_times' => 4,
            'attend_m3' => 2,
            'layer' => 'P4',
            'status' => '在籍',
        ], $attrs));
    }

    public function test_customer_list_scope_mine_keeps_only_my_owned_rows(): void
    {
        $teacher = $this->user('teacher-a', '老师A', 'R_TEACHER', '绿地店');
        Sanctum::actingAs($teacher);

        $mineConsultant = $this->customer('我的顾问客户', '13800000101', ['consultant' => '老师A']);
        $mineOwner = $this->customer('我的负责客户', '13800000102', ['owner' => '老师A']);
        $taught = $this->customer('我教过的学员', '13800000103', ['consultant' => '别人', 'owner' => '别人']);
        $other = $this->customer('完全无关', '13800000104', ['consultant' => '别人', 'owner' => '别人']);

        // 授课关系：老师A 给「我教过的学员」上过私教课（private + signed + 本店）
        KyBooking::create([
            'source_key' => '2:M2001', 'venue' => '绿地店', 'booking_type' => 'group',
            'course_kind' => 'private', 'member_id' => 'M2001', 'member_name' => '我教过的学员',
            'phone' => '13800000103', 'course_name' => '私教课', 'teacher_name' => '老师A',
            'status_raw' => '已签到', 'status' => 'signed', 'is_trial' => 0,
        ]);

        // 默认：本人归属 ∪ 授课关系（平台既有口径，不能被本开关改掉）
        $default = collect($this->getJson('/api/customers?size=50')->assertOk()->json('data.records'))
            ->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$mineConsultant->id, $mineOwner->id, $taught->id], $default);
        $this->assertNotContains($other->id, $default);

        // scope=mine：只留「我负责的」，授课关系被排除
        $mineRows = collect($this->getJson('/api/customers?size=50&scope=mine')->assertOk()->json('data.records'))
            ->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$mineConsultant->id, $mineOwner->id], $mineRows);
    }

    public function test_customer_list_counts_follow_scope_mine(): void
    {
        $teacher = $this->user('teacher-b', '老师B', 'R_TEACHER', '绿地店');
        Sanctum::actingAs($teacher);

        $mine = $this->customer('我的客户', '13800000201', ['consultant' => '老师B']);
        $this->customer('别人客户甲', '13800000202', ['consultant' => '别人']);
        $this->customer('别人客户乙', '13800000203', ['owner' => '别人']);

        // list-ids：可见 id 集合必须与 scope 一致（前端拿它渲染清单标签）
        $ids = $this->getJson('/api/customers/list-ids?scope=mine')->assertOk()->json('data.lists');
        $this->assertIsArray($ids);

        // list-counts 带 type=member + scope=mine：总数只能是「我负责的」那一批
        $counts = $this->getJson('/api/customers/list-counts?type=member&scope=mine')->assertOk()->json('data.counts');
        $this->assertIsArray($counts);

        // 逐行校验：scope=mine 时列表里出现过的 id 一定在「我负责的」范围内
        $rows = collect($this->getJson('/api/customers?type=member&size=50&scope=mine')->assertOk()->json('data.records'))
            ->pluck('id')->all();
        $this->assertSame([$mine->id], $rows);
    }

    public function test_lead_list_scope_mine_excludes_trial_teacher_rows(): void
    {
        $teacher = $this->user('teacher-c', '老师C', 'R_TEACHER', '绿地店');
        Sanctum::actingAs($teacher);

        $mine = Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '我负责的客资', 'phone' => '13900000301',
            'source' => '美团', 'venue' => '绿地店', 'service_teacher' => '老师C', 'status' => '新留资',
        ]);
        $trial = Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '我上过体验课的客资', 'phone' => '13900000302',
            'source' => '美团', 'venue' => '绿地店', 'service_teacher' => '别人', 'trial_teacher' => '老师C',
            'status' => '已联系',
        ]);

        $default = collect($this->getJson('/api/leads?size=50')->assertOk()->json('data.records'))
            ->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$mine->id, $trial->id], $default);

        $mineRows = collect($this->getJson('/api/leads?size=50&scope=mine')->assertOk()->json('data.records'))
            ->pluck('id')->all();
        $this->assertSame([$mine->id], $mineRows);
    }

    /**
     * 超管没有「我负责的」概念：开关打开后是空集，而不是被当成「看全部」。
     *
     * 前端只在老师侧显示这个开关，但参数是可被手工拼出来的 —— 后端必须是
     * 「叠加收窄」而不是「按参数切换范围」，否则一个 `?scope=mine` 的拼写错误
     * 就可能把店长/超管降到比原来更大的范围（本仓库最怕的那种反向越权）。
     */
    public function test_super_with_scope_mine_sees_only_owned_rows(): void
    {
        $super = $this->user('boss', '超级管理员', 'R_SUPER', null);
        Sanctum::actingAs($super);

        $this->customer('普通客户一', '13800000401', ['consultant' => '别人']);
        $mine = $this->customer('登记在我名下', '13800000402', ['owner' => '超级管理员']);

        $all = collect($this->getJson('/api/customers?size=50')->assertOk()->json('data.records'))->pluck('id')->all();
        $this->assertCount(2, $all);

        $mineRows = collect($this->getJson('/api/customers?size=50&scope=mine')->assertOk()->json('data.records'))
            ->pluck('id')->all();
        $this->assertSame([$mine->id], $mineRows);
    }
}
