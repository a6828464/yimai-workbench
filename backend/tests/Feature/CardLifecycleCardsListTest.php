<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Support\CardLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 会员详情「全部卡项」补「初始余额 / 已用量 / 剩余量」（v3.19.0）。
 *
 * 病根：同步侧写 `cards_list` 时**只给次卡写 `bound`**，而且**从来没有 `remaining` 键**；
 * 小程序「全部卡项」按 `card.remaining` 渲染剩余量，于是次卡只剩一行「到期 X」，
 * 看不到还剩几节 —— 一线反馈的「次卡只显示到期时间、没有剩余次数」就是这个。
 *
 * 修复口径：全部走 `CardLifecycle::normalize()`（判定层唯一 owner），
 * 本文件第 4 个用例专门守着「`customer.cardsList`（判定层输入）一个键都不许变」。
 */
class CardLifecycleCardsListTest extends TestCase
{
    use RefreshDatabase;

    public function test_count_card_exposes_remaining_and_bound_total(): void
    {
        $customer = $this->customer([
            ['id' => 'c1', 'cardNo' => 'K001', 'title' => '私教30节', 'unit' => '节', 'type' => '1',
                'residue' => 3, 'bound' => 30, 'deadline' => now()->addDays(20)->toDateString(),
                'state' => 'current', 'unactivated' => false],
        ]);

        $cards = $this->cardsBy($customer);
        $card = $cards['c1'];

        // 「次卡没有剩余次数」的直接修复点
        $this->assertArrayHasKey('remaining', $card, 'remaining 键必须存在（小程序按它渲染剩余量）');
        $this->assertSame(3, $card['remaining']);
        $this->assertSame(30, $card['boundTotal'], '初始余额 = 同步侧写入的 bound（剩余 + 已耗次数）');
        $this->assertSame(27, $card['consumed'], '已用量 = boundTotal − residue');

        // 既有键：state / stateLabel / isMain 不动
        $this->assertSame('current', $card['state']);
        $this->assertSame('在用', $card['stateLabel']);
        $this->assertTrue($card['isMain']);
    }

    public function test_count_card_without_bound_keeps_null_bound_total(): void
    {
        $customer = $this->customer([
            ['id' => 'c2', 'cardNo' => 'K002', 'title' => '团课10次', 'unit' => '节', 'type' => '1',
                'residue' => 4, 'deadline' => now()->addDays(90)->toDateString(),
                'state' => 'current', 'unactivated' => false],
        ]);

        $card = $this->cardsBy($customer)['c2'];
        $this->assertSame(4, $card['remaining'], 'residue 有值就必须兜底成 remaining');
        $this->assertNull($card['boundTotal'], '算不出初始余额时给 null，绝不写 0（0 会被渲染成「共 0 节」）');
        $this->assertArrayHasKey('boundTotal', $card, '键必须存在，值可以是 null');
        $this->assertNull($card['consumed']);
    }

    public function test_time_and_stored_cards_use_their_own_units(): void
    {
        $deadline = now()->addDays(200)->toDateString();
        $customer = $this->customer([
            // 期限卡：remaining 由 deadline 推导（CardLifecycle 口径），初始余额 = 有效期总天数
            ['id' => 'c3', 'cardNo' => 'K003', 'title' => '年卡', 'unit' => '天', 'type' => '2',
                'residue' => 200, 'validDays' => 365, 'deadline' => $deadline,
                'state' => 'current', 'unactivated' => false],
            // 储值卡：remaining = 金额，初始总额上游没给 → null
            ['id' => 'c4', 'cardNo' => 'K004', 'title' => '储值5000', 'unit' => '元', 'type' => '3',
                'residue' => 5000, 'deadline' => null, 'state' => 'current', 'unactivated' => false],
        ]);

        $cards = $this->cardsBy($customer);

        $time = $cards['c3'];
        $this->assertSame(CardLifecycle::daysUntilDate($deadline), $time['remaining'], '期限卡剩余 = 距到期天数');
        $this->assertSame(365, $time['boundTotal'], '期限卡初始余额 = validDays（有效期总天数）');
        $this->assertNull($time['consumed'], '期限卡的「已用天数」不是权益消耗，不猜');

        $stored = $cards['c4'];
        $this->assertEquals(5000, $stored['remaining']);
        $this->assertNull($stored['boundTotal']);
    }

    public function test_history_card_gets_remaining_and_judgement_input_stays_untouched(): void
    {
        $customer = Customer::create([
            'name' => '多卡会员', 'phone' => '13900007777', 'venue' => '绿地店',
            'external_id' => 'ky:77:7777', 'layer' => 'P2', 'main_card' => '私教30节',
            'cards_list' => [
                ['id' => 'c1', 'cardNo' => 'K001', 'title' => '私教30节', 'unit' => '节', 'type' => '1',
                    'residue' => 3, 'bound' => 30, 'deadline' => now()->addDays(20)->toDateString(),
                    'state' => 'current', 'unactivated' => false],
            ],
            'card_stats' => [
                'historyCards' => [
                    ['id' => 'c0', 'cardNo' => 'K000', 'title' => '体验卡3次', 'unit' => '节', 'type' => '1',
                        'residue' => 2, 'deadline' => now()->subDays(30)->toDateString(),
                        'state' => 'history', 'expired' => true],
                ],
            ],
        ]);

        Sanctum::actingAs($this->super());
        $data = $this->getJson('/api/customers/'.$customer->id)->assertOk()->json('data');

        $cards = collect($data['cardsList'])->keyBy('id');
        $this->assertSame(2, $cards['c0']['remaining'], '历史卡同样要有 remaining（否则过期卡一行空着）');
        $this->assertSame('已过期', $cards['c0']['stateLabel']);
        $this->assertSame(['active' => 1, 'unactivated' => 0, 'expired' => 1, 'total' => 2], $data['cardGroups']);

        // 判定层输入不动：customer.cardsList 仍然是 cards_list 原样（没有 remaining/boundTotal 之类新键）
        $raw = $data['customer']['cardsList'];
        $this->assertCount(1, $raw, '过期卡仍不进判定层输入');
        $this->assertArrayNotHasKey('remaining', $raw[0], '判定层输入不许被展示层顺手改掉');
        $this->assertArrayNotHasKey('boundTotal', $raw[0]);
    }

    // ------------------------------------------------------------- 数据准备

    private function customer(array $cardsList): Customer
    {
        $customer = Customer::create([
            'name' => '卡项会员', 'phone' => '13900006666', 'venue' => '绿地店',
            'external_id' => 'ky:77:6666', 'layer' => 'P2', 'main_card' => $cardsList[0]['title'],
            'cards_list' => $cardsList,
        ]);

        Sanctum::actingAs($this->super());

        return $customer;
    }

    /** @return array<string,array<string,mixed>> */
    private function cardsBy(Customer $customer): array
    {
        $data = $this->getJson('/api/customers/'.$customer->id)->assertOk()->json('data');

        return collect($data['cardsList'])->keyBy('id')->all();
    }

    private function super(): User
    {
        return User::factory()->create(['username' => 'sup-cards', 'name' => '超管', 'role' => 'R_SUPER', 'venue' => null]);
    }
}
