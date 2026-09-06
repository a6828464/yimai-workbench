<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CardStatsRenewalTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_with_one_used_up_card_and_one_healthy_card_is_not_flagged(): void
    {
        // 用户场景：两张卡，其中一张用完没余额，另一张还有 40 节 → 不应标记待续费
        $c = $this->customer('多卡会员', ['main_card' => '私教年卡', 'attend_m3' => 5], [
            'countResidue' => 40, 'countBound' => 90, 'daysLeft' => null, 'daysTotal' => null,
        ]);

        $this->assertNotInRenewalList($c);
    }

    public function test_big_count_card_tail_is_flagged_by_percent(): void
    {
        // 200 节大卡用剩 15 节：绝对阈值(10)没到，但占比 7.5% ≤ 20% → 标记
        $c = $this->customer('大卡尾段会员', ['attend_m3' => 5], [
            'countResidue' => 15, 'countBound' => 200, 'daysLeft' => null, 'daysTotal' => null,
        ]);

        $this->assertInRenewalList($c);
    }

    public function test_unactivated_card_inventory_keeps_member_out_of_renewal(): void
    {
        // 未开卡 50 节 = 满库存 100% → 不标记（剩余课时合计已含未开卡）
        $c = $this->customer('未开卡会员', ['attend_m3' => 5], [
            'countResidue' => 50, 'countBound' => 50, 'daysLeft' => null, 'daysTotal' => null,
        ]);

        $this->assertNotInRenewalList($c);
    }

    public function test_time_card_member_is_judged_by_expire_days_not_zero_lessons(): void
    {
        // 纯时间卡会员：无次卡（countResidue=null），不能因「0 节」被误判；到期临近才标记
        $far = $this->customer('时间卡远期', ['main_card' => '年卡', 'attend_m3' => 5], [
            'countResidue' => null, 'countBound' => 0, 'daysLeft' => 200, 'daysTotal' => 365,
        ], ['expire_date' => now()->addDays(200)->toDateString()]);
        $this->assertNotInRenewalList($far);

        $near = $this->customer('时间卡临期', ['main_card' => '年卡', 'attend_m3' => 5], [
            'countResidue' => null, 'countBound' => 0, 'daysLeft' => 20, 'daysTotal' => 365,
        ], ['expire_date' => now()->addDays(20)->toDateString()]);
        $this->assertInRenewalList($near);
    }

    public function test_time_card_percent_rule_can_be_enabled(): void
    {
        AppSetting::create(['rules' => [
            'renewalThreshold' => 10, 'renewalCountPercent' => 20,
            'renewalExpireDays' => 30, 'renewalExpirePercent' => 10,
            'vipAmountThreshold' => 30000, 'declineMode' => 'strict',
            'predropMin' => 15, 'predropMax' => 30, 'reviveDays' => 30,
        ]]);
        // 有效期用了 91.8%（30/365 剩余），到期日还远 → 占比规则命中
        $c = $this->customer('有效期占比会员', ['main_card' => '年卡', 'attend_m3' => 5], [
            'countResidue' => null, 'countBound' => 0, 'daysLeft' => 30, 'daysTotal' => 365,
        ], ['expire_date' => now()->addDays(200)->toDateString()]);

        $this->assertInRenewalList($c);
    }

    public function test_legacy_customers_without_card_stats_fall_back_to_old_fields(): void
    {
        $low = $this->customer('旧数据少课时', ['attend_m3' => 5], null, ['remain_times' => 5]);
        $this->assertInRenewalList($low);

        $far = $this->customer('旧数据远期', ['attend_m3' => 5], null, [
            'expire_date' => now()->addDays(60)->toDateString(),
        ]);
        $this->assertNotInRenewalList($far);
    }

    public function test_member_without_any_count_card_is_not_flagged_as_zero_remaining(): void
    {
        // 无次卡且无到期信息：不能因 remain 缺省被当成 0 节
        $c = $this->customer('无次卡会员', ['main_card' => '年卡', 'attend_m3' => 5], [
            'countResidue' => null, 'countBound' => 0, 'daysLeft' => null, 'daysTotal' => null,
        ]);

        $this->assertNotInRenewalList($c);
    }

    public function test_rules_endpoints_accept_and_return_renewal_thresholds(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'super', 'role' => 'R_SUPER', 'status' => '启用']));

        $this->putJson('/api/member-rules', [
            'renewalThreshold' => 8,
            'renewalCountPercent' => 15,
            'renewalExpireDays' => 45,
            'renewalExpirePercent' => 5,
            'vipAmountThreshold' => 30000,
            'declineMode' => 'strict',
            'predropMin' => 15,
            'predropMax' => 30,
            'reviveDays' => 30,
        ])->assertOk()
            ->assertJsonPath('data.renewalCountPercent', 15)
            ->assertJsonPath('data.renewalExpireDays', 45)
            ->assertJsonPath('data.renewalExpirePercent', 5);

        $this->getJson('/api/member-rules')->assertOk()
            ->assertJsonPath('data.renewalThreshold', 8)
            ->assertJsonPath('data.renewalExpireDays', 45);
    }

    private function assertInRenewalList(Customer $c): void
    {
        $ids = $this->renewalIds();
        $this->assertContains($c->id, $ids, "会员 {$c->name} 应在待续课清单");
    }

    private function assertNotInRenewalList(Customer $c): void
    {
        $ids = $this->renewalIds();
        $this->assertNotContains($c->id, $ids, "会员 {$c->name} 不应在待续课清单");
    }

    private function renewalIds(): array
    {
        Sanctum::actingAs(User::factory()->create(['username' => uniqid('s'), 'role' => 'R_SUPER', 'status' => '启用']));
        $rows = $this->getJson('/api/customers?list='.urlencode('待续课').'&size=500')->assertOk()->json('data.records');

        return array_map(fn ($r) => $r['id'], $rows);
    }

    private function customer(string $name, array $overrides, ?array $cardStats, array $extra = []): Customer
    {
        return Customer::create(array_merge([
            'name' => $name,
            'phone' => '1380000'.random_int(1000, 9999),
            'phone_tail' => '0000',
            'venue' => '绿地店',
            'source' => 'KeepYoga',
            'owner' => '店长',
            'consultant' => '店长',
            'main_card' => '私教卡',
            'layer' => 'P4',
            'status' => '在籍',
        ], $overrides, $extra, $cardStats !== null ? ['card_stats' => $cardStats] : []));
    }
}
