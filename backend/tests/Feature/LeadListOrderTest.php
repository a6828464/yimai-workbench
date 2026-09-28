<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 「留资管理」列表排序口径：**按留资时间（lead_date）倒序**。
 *
 * ## 缺陷（本用例要钉住的）
 *
 * 列表列头「留资日期」标着 `sortable`，但排序从来没有真正生效过：
 *
 *  1. 后端 `LeadController::index` 固定 `orderByDesc('id')` —— 按**录入先后**排，
 *     不是按留资日期。补录一条 8 月的历史客资（今天录入）会排在最前面，
 *     运营看到的第一行是「8 月的留资」，而 9 月月底留的客户被挤到后面。
 *  2. 前端 `ElTableColumn sortable`（非 `custom`）只在**当前页这 50 条**里排，
 *     分页之后各页之间不可能有序 —— 用户点一次列头，看到的是「这一页碰巧的顺序」。
 *     更糟的是点出 `ascending`：它把当前页反过来，看起来像「排好了」。
 *  3. 前端没有 `@sort-change` 监听，也没有把排序意图下推给后端，
 *     所以点列头既不改请求、也不改全局顺序。
 *
 * ## 为什么基准是 lead_date 而不是 id / created_at
 *
 * 用户口径是「按留资时间排序」。lead_date 是**业务事实**（客户什么时候留的资），
 * id 是**录入顺序**，两者在补录历史数据时会背离 —— 而补录恰恰是这个页面的常态
 * （体测报告、团购券核销记录都会事后补登）。created_at 同理。
 *
 * ## 并列怎么排（同日多条）
 *
 * 同日留资用 `id` 倒序做 tie-break：id 单调递增且唯一，能保证**全序**——
 * 否则同日多行在翻页时可能重复出现或被跳过（分页要求稳定全序，MySQL 对
 * `ORDER BY` 相同值的返回顺序不作保证）。这里选 id 倒序（后录入的靠前），
 * 与「同一天里最新补录的更值得关注」一致。
 */
class LeadListOrderTest extends TestCase
{
    use RefreshDatabase;

    private function superUser(): User
    {
        return User::factory()->create([
            'username' => 'lead-order-super',
            'name' => '排序超管',
            'role' => 'R_SUPER',
            'venue' => null,
            'venues' => ['绿地店', '东部店'],
            'status' => '启用',
        ]);
    }

    private function lead(string $name, string $leadDate, string $phone): Lead
    {
        return Lead::create([
            'lead_date' => $leadDate,
            'name' => $name,
            'phone' => $phone,
            'source' => '到店',
            'venue' => '绿地店',
            'status' => '新留资',
            'created_by' => '排序超管',
        ]);
    }

    /**
     * 核心缺口：后端按 id 倒序 ⇒ 补录的历史留资排在最前。
     * 期望：按 lead_date 倒序，最新的留资日期在最上面。
     */
    public function test_list_is_ordered_by_lead_date_desc(): void
    {
        Sanctum::actingAs($this->superUser());

        // 录入顺序（id）与留资时间**故意相反**：先录 8 月的旧客资，再录 9 月的新客资，
        // 最后补录一条 7 月的（id 最大）。按 id 排会得到 7月/9月/8月。
        $this->lead('八月旧客资', '2026-08-10', '13800000061');
        $this->lead('九月新客资', '2026-09-20', '13800000062');
        $this->lead('七月补录客资', '2026-07-05', '13800000063');

        $names = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))
            ->pluck('name')
            ->all();

        $this->assertSame(
            ['九月新客资', '八月旧客资', '七月补录客资'],
            $names,
            '留资列表必须按留资日期倒序，而不是按录入顺序（id）'
        );
    }

    /** 同日多条：靠 id 倒序做 tie-break，保证全序稳定（否则翻页会重复/漏行） */
    public function test_same_day_leads_are_ordered_by_id_desc(): void
    {
        Sanctum::actingAs($this->superUser());

        $first = $this->lead('同日第一条', '2026-09-15', '13800000071');
        $second = $this->lead('同日第二条', '2026-09-15', '13800000072');
        $this->lead('更早的一天', '2026-09-14', '13800000073');

        $ids = collect($this->getJson('/api/leads')->assertOk()->json('data.records'))
            ->pluck('id')
            ->all();

        $this->assertSame(
            [$second->id, $first->id],
            array_slice($ids, 0, 2),
            '同日留资按 id 倒序（后录入的靠前），保证分页有稳定全序'
        );
    }

    /**
     * 分页边界：顺序必须是**跨页**的全局顺序。
     * 按 id 排时第二页会拿到「更早留资」的行；按留资日期排则每页都严格递减。
     */
    public function test_order_holds_across_pages(): void
    {
        Sanctum::actingAs($this->superUser());

        // 12 条，留资日期依次递减而 id 递增 —— 两种排序的结果完全相反
        for ($i = 1; $i <= 12; $i++) {
            $day = 21 - $i; // 20, 19, ... 9
            $this->lead("客资{$i}", sprintf('2026-09-%02d', $day), sprintf('138000001%02d', $i));
        }

        $page1 = collect($this->getJson('/api/leads?current=1&size=5')->assertOk()->json('data.records'))
            ->pluck('leadDate')->all();
        $page2 = collect($this->getJson('/api/leads?current=2&size=5')->assertOk()->json('data.records'))
            ->pluck('leadDate')->all();

        $this->assertSame(['2026-09-20', '2026-09-19', '2026-09-18', '2026-09-17', '2026-09-16'], $page1);
        $this->assertSame(['2026-09-15', '2026-09-14', '2026-09-13', '2026-09-12', '2026-09-11'], $page2);

        // 跨页拼接后仍然整体递减（分页没有把顺序切碎）
        $all = array_merge($page1, $page2);
        $sorted = $all;
        rsort($sorted);
        $this->assertSame($sorted, $all, '跨页拼接后的顺序必须整体递减');
    }

    /** 列头「留资日期」点一下（ascending）应真正翻转全局顺序，而不是只翻当前页 */
    public function test_explicit_ascending_sort_is_honored(): void
    {
        Sanctum::actingAs($this->superUser());

        $this->lead('八月旧客资', '2026-08-10', '13800000081');
        $this->lead('九月新客资', '2026-09-20', '13800000082');

        $names = collect(
            $this->getJson('/api/leads?sortBy=leadDate&sortOrder=ascending')->assertOk()->json('data.records')
        )->pluck('name')->all();

        $this->assertSame(['八月旧客资', '九月新客资'], $names, '升序请求必须生效（旧的在最前）');
    }

    /** 非法排序参数不能变成注入点：未知列退回默认口径，且不报错 */
    public function test_unknown_sort_field_falls_back_to_default_order(): void
    {
        Sanctum::actingAs($this->superUser());

        $this->lead('八月旧客资', '2026-08-10', '13800000091');
        $this->lead('九月新客资', '2026-09-20', '13800000092');

        $names = collect(
            $this->getJson('/api/leads?sortBy=id;drop%20table%20leads&sortOrder=sideways')
                ->assertOk()
                ->json('data.records')
        )->pluck('name')->all();

        $this->assertSame(['九月新客资', '八月旧客资'], $names, '未知排序字段必须退回默认（留资日期倒序）');
    }
}
