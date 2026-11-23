<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 会员详情「卡项与出勤 → 到期日」兜底（t5 / v3.19.0）。
 *
 * 病根：`customers.expire_date` 是同步侧**只按主卡**写入的，主卡缺失或主卡上游没给 deadline
 * 时它就是空；而卡片自身（cards_list / historyCards 的 deadline）有值。展示层空着就显示 `—`，
 * 店员无从判断该不该催续费。
 *
 * 本文件守三件事：
 *  ① 展示值补全（库里有值优先；库里空则从在用卡 → 历史卡推）；
 *  ② 来源标注 `expireDateSource` 三态（current / inferred / history / none），推断不许冒充权威；
 *  ③ **判定零影响**：`customers.expire_date` 不被回写，五清单归属与 layer 前后一致。
 */
class CustomerExpireDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 全部用例都以超管身份读详情：本文件测的是展示层组装，与角色可见范围无关
        Sanctum::actingAs($this->super());
    }

    /** ① 库里有到期日 → 原值 + current（卡项里更早的 deadline 不得抢权威值）。 */
    public function test_stored_expire_date_wins_and_is_marked_current(): void
    {
        $stored = now()->addDays(20)->toDateString();
        $c = $this->customer([
            'expire_date' => $stored,
            'cards_list' => [
                // 故意给一张「3 天后到期」的在用卡：如果实现按卡项覆盖库值，这条就会红
                $this->card('c-early', '短期卡', now()->addDays(3)->toDateString(), 5, 10),
            ],
        ]);

        $data = $this->getJson('/api/customers/'.$c->id)->assertOk()->json('data.customer');

        $this->assertSame($stored, substr((string) $data['expireDate'], 0, 10), '库里的值优先，不被卡项覆盖');
        $this->assertSame('current', $data['expireDateSource'], '库里有值 = 权威');
    }

    /** ② 库为空 + 在用卡有 deadline → 取**最近到期**的那张推出，标 inferred。 */
    public function test_missing_expire_date_is_inferred_from_active_cards(): void
    {
        $soon = now()->addDays(7)->toDateString();
        $later = now()->addDays(45)->toDateString();
        $c = $this->customer([
            'expire_date' => null,
            'cards_list' => [
                $this->card('c-late', '团课10次', $later, 10, 10),
                $this->card('c-soon', '私教30节', $soon, 3, 30),
            ],
        ]);

        // 五清单归属：该会员本来就在「待续课」（用卡项 deadline 命中，与 expire_date 是否为空无关）
        $this->assertTrue(
            in_array($c->id, filteredIds('待续课'), true),
            '前置条件：该会员应在待续课清单里，否则「归属不变」这条断言会空转'
        );

        $data = $this->getJson('/api/customers/'.$c->id)->assertOk()->json('data.customer');

        $this->assertSame($soon, substr((string) $data['expireDate'], 0, 10), '取最近到期的那张在用卡');
        $this->assertSame('inferred', $data['expireDateSource'], '推出来的值必须标 inferred');
    }

    /** ③ 库为空且卡项也没有 deadline → null + none（不编日期）。 */
    public function test_no_deadline_anywhere_yields_null_and_none(): void
    {
        $c = $this->customer([
            'expire_date' => null,
            'cards_list' => [
                $this->card('c-nodeadline', '储值5000', null, 5000, null, '元', '3'),
            ],
        ]);

        $data = $this->getJson('/api/customers/'.$c->id)->assertOk()->json('data.customer');

        $this->assertNull($data['expireDate'], '推不出来就给 null，不能凭空造一个日期');
        $this->assertSame('none', $data['expireDateSource']);
    }

    /** ④ 库为空、在用卡没有 deadline，只有历史卡有 → 用它但标 history（依据更弱，前端同样要提示）。 */
    public function test_history_card_deadline_is_marked_as_history(): void
    {
        $historyDeadline = now()->subDays(30)->toDateString();
        $c = $this->customer([
            'expire_date' => null,
            'cards_list' => [],
            'card_stats' => [
                'historyCards' => [
                    ['id' => 'h1', 'cardNo' => 'H001', 'title' => '已过期私教卡', 'unit' => '节', 'type' => '1',
                        'residue' => 2, 'deadline' => $historyDeadline, 'state' => 'history', 'expired' => true],
                ],
            ],
        ]);

        $data = $this->getJson('/api/customers/'.$c->id)->assertOk()->json('data.customer');

        $this->assertSame($historyDeadline, substr((string) $data['expireDate'], 0, 10));
        $this->assertSame('history', $data['expireDateSource'], '历史卡推出的值要区别于 inferred（依据更弱）');
    }

    /**
     * ⑤ 判定零影响：拿到推断值之后，`customers.expire_date` 仍是空，
     *    五清单归属与 layer 派生列前后完全一致。
     */
    public function test_inference_never_touches_judgement_inputs(): void
    {
        $c = $this->customer([
            'expire_date' => null,
            'cards_list' => [
                $this->card('c-soon', '私教30节', now()->addDays(7)->toDateString(), 3, 30),
            ],
        ]);

        $before = customerDecision($c->fresh());
        $listsBefore = $this->listMembership($c->id);

        $data = $this->getJson('/api/customers/'.$c->id)->assertOk()->json('data.customer');
        $this->assertSame('inferred', $data['expireDateSource'], '前置条件：这一例必须真的走了推断分支');

        // ① 不写库：推断值只活在响应里（新增列/回写都会让这条红）
        $this->assertDatabaseHas('customers', ['id' => $c->id, 'expire_date' => null]);

        // ② 判定输入与派生结论前后一致（待续费 in / layer 都由 customerDecision 算）
        $after = customerDecision($c->fresh());
        $this->assertSame($before['renewal']['in'], $after['renewal']['in'], '待续费判定不许被展示层改动');
        $this->assertSame($before['layer'], $after['layer'], 'layer 派生列不许被展示层改动');

        // ③ 五清单归属（id 集合）不变 —— 这是「不改变任何会员清单归属」的直接证据
        $this->assertSame($listsBefore, $this->listMembership($c->id));
    }

    /**
     * ⑥ 同源（t4-F4）：同一个会员，**流失风险卡片**与**会员详情**必须给同一个到期日。
     *
     * 两处此前各自取数：详情从卡项推导（t5），流失风险卡直接读原始列 ——
     * 于是同一个人「待办说无到期日、详情说 2026-08-01（按卡项推断）」。
     * 现在两者都走 `CardLifecycle::displayExpireDate()`（唯一实现）。
     */
    public function test_churn_risk_expire_date_matches_customer_detail(): void
    {
        $deadline = now()->addDays(7)->toDateString();
        $c = $this->customer([
            'expire_date' => null,                                  // 库列为空 = t5 要兜底的那种行
            'last_visit' => now()->subDays(20)->toDateString(),      // 20 天未到店 → 预流失
            'attend_m2' => 3, 'attend_m3' => 0,
            'cards_list' => [$this->card('c1', '私教30节', $deadline, 3, 30)],
        ]);

        $risk = collect($this->getJson('/api/today/todo')->assertOk()->json('data.churnRisks'))
            ->firstWhere('id', $c->id);
        $this->assertNotNull($risk, '该会员应进流失风险组，否则本用例空转');

        $detail = $this->getJson('/api/customers/'.$c->id)->assertOk()->json('data.customer');

        $this->assertSame($deadline, $risk['expireDate'], '库列为空时流失风险卡也要从卡项推导出来');
        $this->assertSame($detail['expireDate'], $risk['expireDate'], '同一会员两处必须同源同值');
        $this->assertSame('inferred', $detail['expireDateSource'], '详情侧仍显式标注这是推断值');
    }

    // ------------------------------------------------------------- 数据准备

    private function customer(array $overrides): Customer
    {
        return Customer::create(array_merge([
            'name' => '到期日会员', 'phone' => '13900008888', 'phone_tail' => '8888',
            'venue' => '绿地店', 'source' => 'KeepYoga', 'owner' => '绿地店长', 'consultant' => '绿地店长',
            'main_card' => '私教30节', 'layer' => 'P2', 'status' => '在籍',
            'external_id' => 'ky:88:8888',
        ], $overrides));
    }

    /** 一张在用卡（默认次卡：type=1 / 单位=节）；deadline 传 null 表示上游没给到期日。 */
    private function card(
        string $id,
        string $title,
        ?string $deadline,
        int $residue,
        ?int $bound,
        string $unit = '节',
        string $type = '1'
    ): array {
        return [
            'id' => $id, 'cardNo' => strtoupper($id), 'title' => $title, 'unit' => $unit, 'type' => $type,
            'residue' => $residue, 'bound' => $bound, 'deadline' => $deadline,
            'state' => 'current', 'unactivated' => false,
        ];
    }

    /** 该会员当前命中的五清单（取 id 集合，按名排序便于比较）。 */
    private function listMembership(int $id): array
    {
        $lists = memberListIds();
        $hit = [];
        foreach ($lists as $name => $ids) {
            if (in_array($id, $ids, true)) {
                $hit[] = $name;
            }
        }
        sort($hit);

        return $hit;
    }

    private function super(): User
    {
        return User::factory()->create(['username' => 'sup-expire', 'name' => '超管', 'role' => 'R_SUPER', 'venue' => null]);
    }
}
