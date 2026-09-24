<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 「留资管理 · 成交时间」录入端与读取端。
 *
 * 需求：客户编辑里能设置成交时间；列表成交金额列下方显示「成交 2026-08-28 · 距留资 1 天」。
 *
 * 背景（本用例要钉住的缺口）：`leads.deal_at` 列早已存在（迁移 2026_08_30_000009，已建索引，
 * 模型已 cast datetime），后端也早已会在状态变「已成交」时自动写 now()，`camel()` 也把它
 * 序列化成 `dealAt`。**唯一缺的是它不在 `LeadController::$leadFields` 白名单里** ——
 * 写入走 `array_intersect_key(camelToSnake($r->all()), array_flip($this->leadFields))`，
 * 于是用户手动填的成交时间被**静默丢弃**，补录历史成交日期永远存不进去。
 *
 * 最易写错的一处是「自动补 now()」与「用户值」的优先级：前端提交的是整个表单，
 * `dealAt` 这个键**恒存在**（未填时为 null），所以判定必须看「有无非空值」而不是「键在不在」——
 * 后者会把既有的「状态转已成交自动写 now()」整个关掉。下面每个分支都有独立用例。
 */
class LeadDealTimeTest extends TestCase
{
    use RefreshDatabase;

    private function superUser(): User
    {
        return User::factory()->create([
            'username' => 'deal-time-super',
            'name' => '成交时间超管',
            'role' => 'R_SUPER',
            'venue' => null,
            'venues' => ['绿地店', '东部店'],
            'status' => '启用',
        ]);
    }

    private function lead(array $overrides = []): Lead
    {
        return Lead::create(array_merge([
            'lead_date' => '2026-08-27',
            'name' => '基线客资',
            'phone' => '13800000009',
            'source' => '到店',
            'venue' => '绿地店',
            'status' => '新留资',
            'created_by' => '成交时间超管',
        ], $overrides));
    }

    // ---------- 1. 成交时间可写（核心缺口） ----------

    public function test_store_accepts_explicit_deal_at_and_persists_it(): void
    {
        Sanctum::actingAs($this->superUser());

        $id = $this->postJson('/api/leads', [
            'leadDate' => '2026-09-01',
            'name' => '补录成交',
            'source' => '到店',
            'venue' => '绿地店',
            'status' => '已成交',
            'dealAmount' => 6800,
            'dealAt' => '2026-09-20',
        ])->assertOk()->json('data.id');

        // 落库的是用户给的那天，既不是被静默丢弃（NULL），也不是被 now() 覆盖成今天
        $this->assertDatabaseHas('leads', ['id' => $id, 'deal_amount' => 6800]);
        $lead = Lead::findOrFail($id);
        $this->assertNotNull($lead->deal_at, 'deal_at 被静默丢弃了 —— $leadFields 白名单里没有 deal_at');
        $this->assertSame('2026-09-20', $lead->deal_at->toDateString());
    }

    public function test_update_accepts_explicit_deal_at_and_persists_it(): void
    {
        Sanctum::actingAs($this->superUser());
        $lead = $this->lead();

        $this->patchJson("/api/leads/{$lead->id}", ['dealAt' => '2026-09-20'])->assertOk();

        $this->assertSame('2026-09-20', $lead->fresh()->deal_at?->toDateString());
    }

    /** 补录一个早于「今天」的成交日期：不能被 now() 顶掉（用户的核心诉求就是补历史） */
    public function test_explicit_deal_at_is_not_overwritten_by_now(): void
    {
        Carbon::setTestNow('2026-09-24 10:00:00');
        try {
            Sanctum::actingAs($this->superUser());

            $id = $this->postJson('/api/leads', [
                'name' => '历史成交',
                'source' => '转介绍',
                'venue' => '绿地店',
                'status' => '已成交',
                'dealAt' => '2026-08-28',
            ])->assertOk()->json('data.id');

            $this->assertSame('2026-08-28', Lead::findOrFail($id)->deal_at->toDateString());
        } finally {
            Carbon::setTestNow();
        }
    }

    // ---------- 2. 成交时间可读 ----------

    public function test_index_returns_deal_at_for_each_row(): void
    {
        Sanctum::actingAs($this->superUser());
        $this->lead(['name' => '有成交', 'status' => '已成交', 'deal_at' => '2026-08-28 02:46:34']);
        $this->lead(['name' => '无成交', 'phone' => '13800000010']);

        $records = $this->getJson('/api/leads?size=100')->assertOk()->json('data.records');

        $byName = collect($records)->keyBy('name');
        // 每行都带 dealAt 键（无值的为 null），前端映射链无需再兜字段
        foreach ($records as $row) {
            $this->assertArrayHasKey('dealAt', $row, 'GET /leads 的行缺少 dealAt 键');
        }
        $this->assertNotNull($byName['有成交']['dealAt']);
        $this->assertNull($byName['无成交']['dealAt'], '没成交时间的行必须是 null，不能编造日期');
    }

    // ---------- 3. 可清空 ----------

    public function test_null_deal_at_clears_it_without_touching_other_fields(): void
    {
        Sanctum::actingAs($this->superUser());
        $lead = $this->lead([
            'status' => '已成交',
            'deal_amount' => 5000,
            'deal_card' => '年卡',
            'remark' => '原有备注',
            'deal_at' => '2026-08-28 02:46:34',
        ]);

        $this->patchJson("/api/leads/{$lead->id}", ['dealAt' => null])->assertOk();

        $lead->refresh();
        $this->assertNull($lead->deal_at, '显式清空成交时间未生效');
        // 清空一个字段不得波及别的字段
        $this->assertSame('已成交', $lead->status);
        $this->assertEquals(5000, $lead->deal_amount);
        $this->assertSame('年卡', $lead->deal_card);
        $this->assertSame('原有备注', $lead->remark);
    }

    /** 空串（表单清空输入框经 ConvertEmptyStringsToNull 变 null）同样能清掉 */
    public function test_empty_string_deal_at_clears_it(): void
    {
        Sanctum::actingAs($this->superUser());
        $lead = $this->lead(['status' => '已成交', 'deal_at' => '2026-08-28 02:46:34']);

        $this->patchJson("/api/leads/{$lead->id}", ['dealAt' => ''])->assertOk();

        $this->assertNull($lead->fresh()->deal_at);
    }

    // ---------- 4. 不覆盖自动值（最易写错处，两个分支都要） ----------

    /**
     * 分支 A：用户**没传** dealAt 而状态转「已成交」⇒ 仍自动写 now()（既有行为，不得回归）。
     * 注意前端提交整表单时是「带键但 null」，所以两个形态都验。
     */
    public function test_auto_fills_now_when_status_becomes_dealt_and_deal_at_absent(): void
    {
        Carbon::setTestNow('2026-09-24 10:00:00');
        try {
            Sanctum::actingAs($this->superUser());

            // A1：update，请求里完全没有 dealAt 键
            $lead = $this->lead();
            $this->patchJson("/api/leads/{$lead->id}", ['status' => '已成交'])->assertOk();
            $this->assertNotNull($lead->fresh()->deal_at, '状态转已成交时的自动写 now() 被回归掉了');
            $this->assertSame('2026-09-24', $lead->fresh()->deal_at->toDateString());

            // A2：update，带键但值为 null（前端提交整个表单的真实形态）
            $lead2 = $this->lead(['name' => '带键空值', 'phone' => '13800000011']);
            $this->patchJson("/api/leads/{$lead2->id}", ['status' => '已成交', 'dealAt' => null])->assertOk();
            $this->assertNotNull($lead2->fresh()->deal_at, '带键空值（表单未填）时自动兜底被关掉了');
            $this->assertSame('2026-09-24', $lead2->fresh()->deal_at->toDateString());

            // A3：store，全新建档直接以「已成交」入档
            $id = $this->postJson('/api/leads', [
                'name' => '新建已成交', 'source' => '到店', 'venue' => '绿地店',
                'status' => '已成交',
            ])->assertOk()->json('data.id');
            $this->assertSame('2026-09-24', Lead::findOrFail($id)->deal_at?->toDateString());
        } finally {
            Carbon::setTestNow();
        }
    }

    /** 分支 B：用户**显式传了** dealAt ⇒ 以用户值为准，不得被自动 now() 覆盖 */
    public function test_auto_fill_does_not_override_user_supplied_deal_at(): void
    {
        Carbon::setTestNow('2026-09-24 10:00:00');
        try {
            Sanctum::actingAs($this->superUser());
            $lead = $this->lead();

            $this->patchJson("/api/leads/{$lead->id}", [
                'status' => '已成交',
                'dealAt' => '2026-09-20',
            ])->assertOk();

            $this->assertSame('2026-09-20', $lead->fresh()->deal_at?->toDateString(), '用户填的成交时间被 now() 覆盖了');
        } finally {
            Carbon::setTestNow();
        }
    }

    /** 已经有成交时间、本次没动它（改别的字段）⇒ 原值必须保住，不得被重写成 now() */
    public function test_existing_deal_at_survives_unrelated_update(): void
    {
        Carbon::setTestNow('2026-09-24 10:00:00');
        try {
            Sanctum::actingAs($this->superUser());
            $lead = $this->lead(['status' => '已成交', 'deal_at' => '2026-08-28 02:46:34']);

            $this->patchJson("/api/leads/{$lead->id}", ['remark' => '只改备注'])->assertOk();

            $this->assertSame('2026-08-28', $lead->fresh()->deal_at?->toDateString());
        } finally {
            Carbon::setTestNow();
        }
    }

    /** 原为 null 且状态本来就是「已成交」时，再次提交已成交也应补上 now()（既有判定 `! $lead->deal_at`） */
    public function test_auto_fill_when_status_already_dealt_but_deal_at_missing(): void
    {
        Carbon::setTestNow('2026-09-24 10:00:00');
        try {
            Sanctum::actingAs($this->superUser());
            $lead = $this->lead(['status' => '已成交', 'deal_at' => null]);

            $this->patchJson("/api/leads/{$lead->id}", ['status' => '已成交'])->assertOk();

            $this->assertNotNull($lead->fresh()->deal_at);
            $this->assertSame('2026-09-24', $lead->fresh()->deal_at->toDateString());
        } finally {
            Carbon::setTestNow();
        }
    }

    // ---------- 5. 边界与不破坏存量 ----------

    /** 非法日期必须被校验拦下，不能悄悄落库 */
    public function test_invalid_deal_at_is_rejected(): void
    {
        Sanctum::actingAs($this->superUser());

        $this->postJson('/api/leads', [
            'name' => '非法日期', 'source' => '到店', 'venue' => '绿地店',
            'dealAt' => '不是日期',
        ])->assertStatus(422);
    }

    /**
     * 成交金额与成交时间可各自独立填写：只填金额不填时间不得报错、不得被填充，
     * 只填时间不填金额同样成立（不阻塞录入）。
     */
    public function test_deal_amount_and_deal_time_are_independent(): void
    {
        Sanctum::actingAs($this->superUser());

        $id = $this->postJson('/api/leads', [
            'name' => '只填金额', 'source' => '到店', 'venue' => '绿地店',
            'dealAmount' => 1280.5,
        ])->assertOk()->json('data.id');
        $onlyAmount = Lead::findOrFail($id);
        $this->assertEquals(1280.5, $onlyAmount->deal_amount);
        $this->assertNull($onlyAmount->deal_at, '只填成交金额时不应凭空生出成交时间');

        $lead = $this->lead(['name' => '只填时间', 'phone' => '13800000012']);
        $this->patchJson("/api/leads/{$lead->id}", ['dealAt' => '2026-09-20'])->assertOk();
        $lead->refresh();
        $this->assertSame('2026-09-20', $lead->deal_at?->toDateString());
        $this->assertNull($lead->deal_amount, '只填成交时间时不应影响成交金额');
    }

    /** 老师的可写字段白名单不应因为本改动而放开成交时间（授权面不得扩大） */
    public function test_teacher_still_cannot_write_deal_at(): void
    {
        $teacher = User::factory()->create([
            'username' => 'deal-time-teacher',
            'name' => '成交时间老师',
            'role' => 'R_TEACHER',
            'venue' => '绿地店',
            'venues' => ['绿地店'],
            'status' => '启用',
        ]);
        Sanctum::actingAs($teacher);

        // store：老师提交成交时间 → 整个请求被拒（与既有 dealAmount 同一条授权路径）
        $this->postJson('/api/leads', [
            'name' => '老师填成交时间', 'source' => '到店', 'venue' => '绿地店',
            'dealAt' => '2026-09-20',
        ])->assertForbidden();

        // update：同上
        $lead = $this->lead(['service_teacher' => '成交时间老师']);
        $this->patchJson("/api/leads/{$lead->id}", ['dealAt' => '2026-09-20'])->assertForbidden();
        $this->assertNull($lead->fresh()->deal_at);
    }

    /**
     * 存量回归：库里有成交时间的行不受影响；14 条里 12 条无成交时间，
     * 这些行必须仍然可读、可编辑别的字段而**不被填上**成交时间。
     */
    public function test_legacy_rows_without_deal_at_are_untouched(): void
    {
        Sanctum::actingAs($this->superUser());
        $withDeal = $this->lead(['name' => '存量有成交', 'status' => '已成交', 'deal_at' => '2026-08-28 02:46:34']);
        $without = $this->lead(['name' => '存量无成交', 'phone' => '13800000013']);
        $without2 = $this->lead(['name' => '存量无成交2', 'phone' => '13800000014']);

        // 无成交时间的行改别的字段，不应被塞进成交时间
        $this->patchJson("/api/leads/{$without->id}", ['status' => '已联系'])->assertOk();
        $this->patchJson("/api/leads/{$without2->id}", ['demand' => '体态调整'])->assertOk();

        $this->assertNull($without->fresh()->deal_at);
        $this->assertNull($without2->fresh()->deal_at);
        $this->assertSame('2026-08-28', $withDeal->fresh()->deal_at?->toDateString());
    }

    /** 成交周期口径：留资→成交的自然日差（同日 0 天、跨月、跨年），纯读侧由日期算出，这里钉住基准字段 */
    public function test_deal_cycle_basis_is_lead_date_and_deal_at(): void
    {
        Sanctum::actingAs($this->superUser());

        // 用户的真实样例：唐诗雨 08-27 → 08-28 = 1 天；林一诺 09-11 → 09-12 = 1 天
        $same = $this->lead(['name' => '同日成交', 'lead_date' => '2026-09-11', 'status' => '已成交', 'deal_at' => '2026-09-11 02:46:34']);
        $crossMonth = $this->lead(['name' => '跨月成交', 'phone' => '13800000015', 'lead_date' => '2026-08-30', 'status' => '已成交', 'deal_at' => '2026-09-02 02:46:34']);
        $crossYear = $this->lead(['name' => '跨年成交', 'phone' => '13800000016', 'lead_date' => '2026-12-30', 'status' => '已成交', 'deal_at' => '2027-01-02 02:46:34']);

        $this->assertSame('2026-09-11', $same->deal_at->toDateString());
        $this->assertSame('2026-09-02', $crossMonth->deal_at->toDateString());
        $this->assertSame('2027-01-02', $crossYear->deal_at->toDateString());

        // deal_at 有 datetime cast，lead_date 没有（是字符串列）—— 两端都按自然日归零后取差，
        // 与前端 civilDayDiff 的口径一致：同日 = 0、跨月 = 3、跨年 = 3
        $days = fn (Lead $l): int => Carbon::parse((string) $l->lead_date)
            ->startOfDay()
            ->diffInDays($l->deal_at->copy()->startOfDay());
        $this->assertSame(0, $days($same));
        $this->assertSame(3, $days($crossMonth));
        $this->assertSame(3, $days($crossYear));
    }
}
