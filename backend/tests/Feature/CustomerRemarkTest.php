<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 会员备注（t37）：`customers.remark` 的迁移 / 详情返回 / PATCH 写入 / 列表不带。
 *
 * 这个字段此前整套系统里没有（`leads.remark` 是留资备注、`renewal_evaluations.remark` 是评估评语），
 * 馆主要在小程序会员详情顶部展示一句给员工看的注意事项，所以新建。
 *
 * 本文件钉四件事：
 *  1. 列真的建出来了（text nullable），而且**重复执行迁移不会炸**；
 *  2. 详情返回 remark（没写过时键存在、值为 null，前端 `v-if` 不会炸）；
 *  3. PATCH 能写、能清、有审计；超过 500 字 422；
 *  4. **列表不带 remark**（避免给已经很重的列表行再加负载）—— 这条是明确的产品决定，
 *     有人「顺手」把 unset 删掉就会红。
 */
class CustomerRemarkTest extends TestCase
{
    use RefreshDatabase;

    // ============================================ 1. 列与迁移

    public function test_column_exists_and_migration_is_idempotent(): void
    {
        $this->assertTrue(Schema::hasColumn('customers', 'remark'), 'customers.remark 必须存在');

        // 迁移在本测试里已经跑过一次（RefreshDatabase），再跑一次必须安全：
        // 生产上升级脚本重试是常态，半完成状态不能把站点搞挂。
        $migration = include database_path('migrations/2026_11_06_000002_add_remark_to_customers.php');
        $migration->up();
        $migration->up(); // 连跑两次

        $this->assertTrue(Schema::hasColumn('customers', 'remark'));
    }

    // ============================================ 2. 详情返回 / 列表不返回

    public function test_detail_includes_remark_key_even_when_null_but_list_does_not(): void
    {
        $customer = $this->member('备注-未填写');
        Sanctum::actingAs($this->super());

        // 详情：键必须存在且为 null（没写过备注）
        $detail = $this->getJson("/api/customers/{$customer->id}")->assertOk()->json('data.customer');
        $this->assertArrayHasKey('remark', $detail, '详情必须带 remark 键（否则前端 v-if 拿到 undefined）');
        $this->assertNull($detail['remark']);

        // 列表：**不得**带 remark
        $records = $this->getJson('/api/customers?size=50')->assertOk()->json('data.records');
        $this->assertNotEmpty($records, '列表不该为空（否则本用例空转）');
        foreach ($records as $row) {
            $this->assertArrayNotHasKey('remark', $row, '列表接口不得返回 remark（列表页不渲染，纯负载）');
        }
    }

    // ============================================ 3. PATCH 写入 / 清除

    public function test_patch_writes_remark_and_detail_reads_it_back(): void
    {
        $customer = $this->member('备注-可写');
        Sanctum::actingAs($this->super());

        $this->patchJson("/api/customers/{$customer->id}", [
            'remark' => '腰有旧伤，避免深度前屈；喜欢安静，别推高强度',
        ])->assertOk()->assertJsonPath('data.remark', '腰有旧伤，避免深度前屈；喜欢安静，别推高强度');

        $this->assertSame(
            '腰有旧伤，避免深度前屈；喜欢安静，别推高强度',
            $this->getJson("/api/customers/{$customer->id}")->assertOk()->json('data.customer.remark'),
            '写入后详情必须立刻读到'
        );

        // 审计留痕（沿用 update() 既有的 audit 调用，不新开一条口径）
        $log = AuditLog::query()
            ->where('module', '会员管理')
            ->where('target_id', (string) $customer->id)
            ->latest('id')->first();
        $this->assertNotNull($log, '改备注必须留审计痕迹');

        // 清空：空串经 ConvertEmptyStringsToNull 变 null，可空列写 null
        $this->patchJson("/api/customers/{$customer->id}", ['remark' => ''])->assertOk();
        $this->assertNull(
            $this->getJson("/api/customers/{$customer->id}")->assertOk()->json('data.customer.remark'),
            '备注必须可被清空（不是只能写一次的字段）'
        );
    }

    /** 长度上限 500：501 字 422，500 字通过（边界两侧都测）。 */
    public function test_remark_length_limit(): void
    {
        $customer = $this->member('备注-超长');
        Sanctum::actingAs($this->super());

        $this->patchJson("/api/customers/{$customer->id}", ['remark' => str_repeat('备', 501)])
            ->assertStatus(422);

        $this->patchJson("/api/customers/{$customer->id}", ['remark' => str_repeat('备', 500)])
            ->assertOk();

        $this->assertSame(500, mb_strlen((string) $customer->fresh()->remark));
    }

    // ============================================ 4. 权限（沿用既有规则）

    /**
     * 无权限照样 403 —— 备注不是「谁都能改」的旁路。
     *
     * 用一个同店但非归属人的服务老师：该会员已挂在别人名下，她既不是顾问也不是负责人，
     * 私教关系也没有 → `canAccessCustomer()` 返回 false → 403。
     */
    public function test_remark_write_requires_access_to_the_customer(): void
    {
        $customer = $this->member('备注-别人家的', ['consultant' => '别的顾问', 'owner' => '别的顾问']);

        $outsider = $this->user('remark-outsider', '隔壁老师', 'R_SERVICE', '绿地店');
        Sanctum::actingAs(User::findOrFail($outsider->id));

        $this->patchJson("/api/customers/{$customer->id}", ['remark' => '不该写进去'])->assertStatus(403);
        $this->assertNull($customer->fresh()->remark, '403 时不得落库');

        // 未登录同样进不来
        $this->app['auth']->forgetGuards();
        $this->patchJson("/api/customers/{$customer->id}", ['remark' => 'x'])->assertStatus(401);
    }

    /** 本店店长可以改（权限沿用既有规则，不因备注而额外收紧）。 */
    public function test_manager_of_same_venue_can_write_remark(): void
    {
        $customer = $this->member('备注-店长可改', ['consultant' => '别的顾问', 'owner' => '别的顾问']);
        Sanctum::actingAs($this->user('remark-manager', '本店店长', 'R_MANAGER', '绿地店'));

        $this->patchJson("/api/customers/{$customer->id}", ['remark' => '店长补充：周三下午固定来'])
            ->assertOk();

        $this->assertSame('店长补充：周三下午固定来', $customer->fresh()->remark);
    }

    /**
     * `/today/followups` 也不带 remark —— 与 `GET /customers` 同一条口径。
     *
     * 这条是「列表不带备注」的**一致性守卫**：两处列表若口径分裂，以后就没法用一句话讲清楚
     * 「备注只在详情返回」。今日待跟进还会被店长截图/转发，少带隐私字段更稳妥。
     */
    public function test_followups_list_does_not_include_remark(): void
    {
        // followups 只看 P0/P1（或前端客资）；这里造一位 P0 且写了备注的会员
        $customer = $this->member('备注-待跟进', [
            'layer' => 'P0',
            'remark' => '对艾草过敏，团课别安排艾灸',
        ]);
        Sanctum::actingAs($this->super());

        $rows = $this->getJson('/api/today/followups')->assertOk()->json('data');

        $this->assertNotEmpty($rows, '待跟进列表不该为空（否则本用例空转）');
        $this->assertSame($customer->id, $rows[0]['id'], '夹具会员必须出现在待跟进里');
        $this->assertArrayNotHasKey('remark', $rows[0], '/today/followups 不得返回会员备注');
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('remark', $row);
        }

        // 反向确认：备注本身没丢，详情仍然读得到
        $this->assertSame(
            '对艾草过敏，团课别安排艾灸',
            $this->getJson("/api/customers/{$customer->id}")->assertOk()->json('data.customer.remark')
        );
    }

    // ============================================ 5. 审计说明（馆主 2026-11 选 C：记备注内容）

    /** 只改备注 → detail 列出「备注」并带上新内容。 */
    public function test_audit_detail_names_remark_and_its_new_value(): void
    {
        $customer = $this->member('审计-只改备注');
        Sanctum::actingAs($this->super());

        $this->patchJson("/api/customers/{$customer->id}", [
            'remark' => '腰有旧伤，避免深度前屈',
        ])->assertOk();

        $this->assertSame(
            '修改：备注；备注→「腰有旧伤，避免深度前屈」',
            $this->lastDetail($customer->id)
        );
    }

    /** 把备注清空也是一次变更，detail 必须能看出「清空」而不是一片空白。 */
    public function test_audit_detail_marks_cleared_remark(): void
    {
        $customer = $this->member('审计-清空备注', ['remark' => '原来写过一句']);
        Sanctum::actingAs($this->super());

        $this->patchJson("/api/customers/{$customer->id}", ['remark' => ''])->assertOk();

        $this->assertSame('修改：备注；备注→（已清空）', $this->lastDetail($customer->id));
    }

    /** 只改归属（不改备注）→ 只记字段名，**不得**把已有的备注内容带进审计。 */
    public function test_audit_detail_omits_remark_content_when_other_fields_change(): void
    {
        $customer = $this->member('审计-只改归属', [
            'remark' => '这句备注这次没动，不该出现在审计里',
            'consultant' => '原顾问',
        ]);
        Sanctum::actingAs($this->super());

        $this->patchJson("/api/customers/{$customer->id}", ['consultant' => '新顾问'])->assertOk();

        $detail = $this->lastDetail($customer->id);
        $this->assertStringContainsString('会籍顾问', $detail);
        $this->assertStringNotContainsString('备注', $detail, '本次没改备注，审计不该提到它');
        $this->assertStringNotContainsString('这句备注这次没动', $detail, '未变更的备注内容不得进入审计');
    }

    /** 备注写满 500 字 → detail 必须完整保留（不被截断），或显式标注「已截断」。 */
    public function test_audit_detail_keeps_full_500_char_remark(): void
    {
        $customer = $this->member('审计-满字数');
        Sanctum::actingAs($this->super());
        $remark = str_repeat('备', 500);

        $this->patchJson("/api/customers/{$customer->id}", ['remark' => $remark])->assertOk();

        $detail = $this->lastDetail($customer->id);
        $this->assertStringContainsString($remark, $detail, '500 字备注不得被截断');
        $this->assertStringNotContainsString('已截断', $detail);
        $this->assertGreaterThan(500, mb_strlen($detail));
    }

    /** 取该会员最近一条「会员管理」审计的 detail。 */
    private function lastDetail(int $customerId): string
    {
        $log = AuditLog::query()
            ->where('module', '会员管理')
            ->where('target_id', (string) $customerId)
            ->latest('id')->first();

        $this->assertNotNull($log, "会员 {$customerId} 没有审计记录");

        return (string) $log->detail;
    }

    // ------------------------------------------------------------- 工具

    /** @param  array<string, mixed>  $extra */
    private function member(string $name, array $extra = []): Customer
    {
        return Customer::create(array_merge([
            'name' => $name,
            'phone' => '1390000'.random_int(1000, 9999),
            'phone_tail' => '0000',
            'venue' => '绿地店',
            'source' => 'KeepYoga',
            'consultant' => '本店顾问',
            'owner' => '本店顾问',
            'status' => '在籍',
            'layer' => 'P4',
            'main_card' => '私教50次',
            'external_id' => 'ky:77001:'.random_int(100000, 999999),
        ], $extra));
    }

    private function super(): User
    {
        return User::factory()->create([
            'username' => uniqid('remark-super-'),
            'name' => '馆长',
            'role' => 'R_SUPER',
            'roles' => ['R_SUPER'],
            'status' => '启用',
            'venue' => null,
            'venues' => ['绿地店', '东部店'],
        ]);
    }

    private function user(string $username, string $name, string $role, ?string $venue): User
    {
        return User::factory()->create([
            'username' => $username,
            'name' => $name,
            'role' => $role,
            'roles' => [$role],
            'venue' => $venue,
            'venues' => $venue ? [$venue] : ['绿地店', '东部店'],
            'status' => '启用',
        ]);
    }
}
