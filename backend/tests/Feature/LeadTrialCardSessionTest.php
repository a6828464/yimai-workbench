<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 体验课卡片「节次」（trial_cards[].session）
 *
 * 背景：`session` 是**派生序号**（第几节 = 数组里第几个），但历史上有一批卡片
 * 落库时没写这个键（老数据只落了 date/topic/teacher/attended）。读方各自兜底、
 * 且兜底口径互相矛盾：
 *
 * | 读取位置                               | 兜底       |
 * | -------------------------------------- | ---------- |
 * | 列表展示「第 N 节」（表格 / 手机卡片）   | 无 → 渲染 `undefined` |
 * | TodayController 待办 key（trial:lead-I-S） | `?? ''` → 空串 |
 * | TodayController 回写匹配               | `?? ($i + 1)`（与上面矛盾） |
 * | api/yimai.ts 今日待办映射              | `?? idx + 1` |
 * | leads/index.vue 排序（跟进时限基准）     | `?? 0` → 全部并列 |
 *
 * 后果不只是显示 `undefined`：待办 key 拼成 `trial:lead-6-` 后，
 * todoAction 里 `if ($session > 0)` 不成立 → **卡片回写被整段跳过**，
 * 「已接待 / 已爽约」点了不落库。
 *
 * 本用例锁定两件事：
 *   1. 接口返回的卡片一定带可用的 `session`（= 数组位置），展示侧不再出现 undefined；
 *   2. 缺 `session` 的历史卡片，仍能从今日待办标记并正确回写。
 */
class LeadTrialCardSessionTest extends TestCase
{
    use RefreshDatabase;

    /** 形态与线上历史数据一致：只有 date/topic/teacher，没有 session */
    private function legacyCard(): array
    {
        return ['topic' => '体态评估', 'teacher' => '老师A', 'attended' => false];
    }

    public function test_api_returns_session_for_cards_missing_it(): void
    {
        Sanctum::actingAs($this->user('manager-green', '绿地店长', 'R_MANAGER', '绿地店'));
        $lead = Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '历史卡片客', 'phone' => '13900000031',
            'source' => '大众点评', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '已体验',
            'trial_cards' => [$this->legacyCard()],
        ]);

        $records = $this->getJson('/api/leads')->assertOk()->json('data.records');
        $row = collect($records)->firstWhere('id', $lead->id);

        $this->assertNotNull($row);
        $this->assertNotEmpty($row['trialCards'] ?? [], '卡片应随列表返回');
        // 展示侧读的就是这个值（`第{{ t.session }}节`），缺了会渲染成 undefined
        $this->assertSame(
            1,
            $row['trialCards'][0]['session'] ?? null,
            '接口必须为缺 session 的历史卡片补出节次，否则展示为「第undefined节」'
        );
    }

    public function test_session_less_card_is_markable_from_today_todo(): void
    {
        Sanctum::actingAs($this->user('manager-green', '绿地店长', 'R_MANAGER', '绿地店'));
        $lead = Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '体验小赵', 'phone' => '13900000032',
            'source' => '大众点评', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '已约体验',
            'trial_cards' => [
                $this->legacyCard() + ['time' => now()->format('Y-m-d 10:00')],
            ],
        ]);

        $todo = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $item = collect($todo['trials'])->firstWhere('leadId', $lead->id);
        $this->assertNotNull($item, '今日体验课应出现在待办');
        $this->assertSame(1, $item['session'], '待办项必须携带可用节次');

        // 标记「已接待」→ 状态流转 + 卡片 attended 回写
        $this->postJson('/api/today/todo/action', [
            'type' => 'trials', 'key' => $item['key'], 'action' => '已接待',
        ])->assertOk();

        $fresh = $lead->fresh();
        $this->assertSame('已体验', $fresh->status);
        $this->assertTrue(
            (bool) ($fresh->trial_cards[0]['attended'] ?? false),
            '缺 session 的卡片也必须被回写：old code 会因 key 拼成 trial:lead-N-（session=0）而整段跳过'
        );
        // 回写把补好的 session 一起落库 → 历史卡片渐进自愈（归一化视图落到持久层）
        $raw = json_decode((string) $fresh->getRawOriginal('trial_cards'), true);
        $this->assertSame(1, $raw[0]['session'] ?? null, '回写应把补出的 session 落库');
    }

    public function test_second_card_of_session_less_lead_is_matched_by_position(): void
    {
        Sanctum::actingAs($this->user('manager-green', '绿地店长', 'R_MANAGER', '绿地店'));
        $lead = Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '两节体验客', 'phone' => '13900000033',
            'source' => '大众点评', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '已约体验',
            'trial_cards' => [
                ['topic' => '第一节', 'teacher' => '老师A', 'time' => now()->addDay()->format('Y-m-d 10:00')],
                ['topic' => '第二节', 'teacher' => '老师A', 'time' => now()->format('Y-m-d 11:00')],
            ],
        ]);

        $todo = $this->getJson('/api/today/todo')->assertOk()->json('data');
        $item = collect($todo['trials'])->firstWhere('leadId', $lead->id);
        $this->assertNotNull($item);
        // 今天的是数组第 2 张 → 节次必须是 2（按位置），不能因为缺字段退化成 0/1
        $this->assertSame(2, $item['session']);

        $this->postJson('/api/today/todo/action', [
            'type' => 'trials', 'key' => $item['key'], 'action' => '爽约',
        ])->assertOk();

        $fresh = $lead->fresh();
        $this->assertTrue((bool) ($fresh->trial_cards[1]['noShow'] ?? false), '第 2 张应被标记爽约');
        $this->assertArrayNotHasKey('noShow', $fresh->trial_cards[0], '第 1 张不受影响');
    }

    private function user(string $username, string $name, string $role, ?string $venue): User
    {
        return User::factory()->create(compact('username', 'name', 'role', 'venue'));
    }
}
