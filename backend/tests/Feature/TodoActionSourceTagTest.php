<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 待办标记写入会员留痕时带**来源栏目**标签（t12 / v3.20.0）。
 *
 * 馆主原话：「我是从待续费页面标记了他，然后备注了内容，你得把这个显示出来」。
 * 以前 detail 只有 `已沟通：<内容>`，四个栏目写出来的留痕长得一模一样，
 * 换人接手时看不出这次沟通是从哪个池子里发起的。
 *
 * 本文件同时守住「补齐 customerId」这一半：renewals / churnRisks / birthdays 三组
 * 此前只有 `id`（会员 id）没有 `customerId`，前端按 `item.customerId` 取 → 传空 →
 * `todoAction` 里 `! empty($d['customerId'])` 不成立 → **留痕根本没写**。
 * 标签再对也没用，所以两组断言必须一起在。
 */
class TodoActionSourceTagTest extends TestCase
{
    use RefreshDatabase;

    /** 待续费 → `[待续费] 已沟通：…`，并且会员详情能直接读到。 */
    public function test_renewal_mark_writes_source_tagged_audit(): void
    {
        Sanctum::actingAs($this->manager());

        $c = Customer::create([
            'name' => '待续会员', 'phone' => '13800008001', 'phone_tail' => '8001',
            'venue' => '绿地店', 'source' => 'KeepYoga', 'owner' => '绿地店长', 'consultant' => '绿地店长',
            'main_card' => '私教小班', 'remain_times' => 3, 'attend_m3' => 4,
            'expire_date' => now()->addDays(5)->toDateString(), 'layer' => 'P4', 'status' => '在籍',
        ]);
        $this->booking($c->name, $c->phone, '09:00');

        $item = collect($this->getJson('/api/today/todo')->assertOk()->json('data.renewals'))
            ->firstWhere('id', $c->id);
        $this->assertNotNull($item, '会员必须进待续费组，否则本用例空转');
        $this->assertSame($c->id, $item['customerId'], 'renewals 必须带 customerId（否则留痕写不进去）');

        $this->postJson('/api/today/todo/action', [
            'type' => 'renewals', 'key' => $item['key'], 'action' => '已沟通',
            'customerId' => $item['customerId'], 'touch' => true,
            'remark' => '她说膝盖恢复中，10 月再来',
        ])->assertOk();

        $detail = $this->memberLogDetail($c->id);
        $this->assertSame('[待续费] 已沟通：她说膝盖恢复中，10 月再来', $detail);

        // 会员 360 的 logs 直接就能看到（detail 里带前缀，前端不用再解析）
        $logs = $this->getJson('/api/customers/'.$c->id)->assertOk()->json('data.logs');
        $this->assertTrue(
            collect($logs)->contains(fn ($l) => ($l['detail'] ?? '') === '[待续费] 已沟通：她说膝盖恢复中，10 月再来'),
            '/customers/{id} 的 logs 必须能直接读到带栏目前缀的那条'
        );
    }

    /** 流失风险 → `[流失风险] 已沟通：…` */
    public function test_churn_mark_writes_source_tagged_audit(): void
    {
        Sanctum::actingAs($this->manager());

        $c = Customer::create([
            'name' => '流失会员', 'phone' => '13800008002', 'phone_tail' => '8002',
            'venue' => '绿地店', 'source' => 'KeepYoga', 'owner' => '绿地店长', 'consultant' => '绿地店长',
            'main_card' => '团课卡', 'remain_times' => 3,
            'last_visit' => now()->subDays(30)->toDateString(),
            'attend_m2' => 3, 'attend_m3' => 0, 'layer' => 'P4', 'status' => '在籍',
        ]);

        $item = collect($this->getJson('/api/today/todo')->assertOk()->json('data.churnRisks'))
            ->firstWhere('id', $c->id);
        $this->assertNotNull($item, '会员必须进流失风险组');
        $this->assertSame($c->id, $item['customerId'], 'churnRisks 必须带 customerId');

        $this->postJson('/api/today/todo/action', [
            'type' => 'churnRisks', 'key' => $item['key'], 'action' => '已沟通',
            'customerId' => $item['customerId'], 'remark' => '电话未接，微信留了言',
        ])->assertOk();

        $this->assertSame('[流失风险] 已沟通：电话未接，微信留了言', $this->memberLogDetail($c->id));
    }

    /** 生日关怀 → `[生日关怀] 已祝福：…` */
    public function test_birthday_mark_writes_source_tagged_audit(): void
    {
        Sanctum::actingAs($this->manager());

        $c = Customer::create([
            'name' => '生日会员', 'phone' => '13800008003', 'phone_tail' => '8003',
            'venue' => '绿地店', 'source' => 'KeepYoga', 'owner' => '绿地店长', 'consultant' => '绿地店长',
            'main_card' => '私教卡', 'birthday' => date('Y').'-'.date('m-d'),
            'layer' => 'P4', 'status' => '在籍',
        ]);

        $item = collect($this->getJson('/api/today/todo')->assertOk()->json('data.birthdays'))
            ->firstWhere('id', $c->id);
        $this->assertNotNull($item, '会员必须进生日关怀组');
        $this->assertSame($c->id, $item['customerId'], 'birthdays 必须带 customerId');

        $this->postJson('/api/today/todo/action', [
            'type' => 'birthdays', 'key' => $item['key'], 'action' => '已祝福',
            'customerId' => $item['customerId'], 'remark' => '发了生日券，她说谢谢',
        ])->assertOk();

        $this->assertSame('[生日关怀] 已祝福：发了生日券，她说谢谢', $this->memberLogDetail($c->id));
    }

    /** 体验课 → `[体验课] 已接待：…`（trials 组的 customerId 由手机号严格命中会员档案） */
    public function test_trial_mark_writes_source_tagged_audit(): void
    {
        Sanctum::actingAs($this->manager());

        $c = Customer::create([
            'name' => '体验会员', 'phone' => '13800008004', 'phone_tail' => '8004',
            'venue' => '绿地店', 'source' => 'KeepYoga', 'owner' => '绿地店长', 'consultant' => '绿地店长',
            'main_card' => '—', 'layer' => 'P4', 'status' => '在籍',
        ]);
        $lead = Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '体验会员', 'phone' => '13800008004',
            'source' => '大众点评', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '已约体验',
            'trial_cards' => [[
                'session' => 1, 'time' => now()->format('Y-m-d 15:00'), 'topic' => '体态评估',
                'teacher' => '老师B', 'couponName' => '', 'voucherCode' => '', 'platform' => '',
            ]],
        ]);

        $item = collect($this->getJson('/api/today/todo')->assertOk()->json('data.trials'))
            ->firstWhere('key', 'trial:lead-'.$lead->id.'-1');
        $this->assertNotNull($item, '留资体验卡必须进体验课组');
        $this->assertSame($c->id, $item['customerId'], 'trials 的 customerId 必须按手机号命中会员档案');

        $this->postJson('/api/today/todo/action', [
            'type' => 'trials', 'key' => $item['key'], 'action' => '已接待',
            'customerId' => $item['customerId'], 'remark' => '到店了，状态不错',
        ])->assertOk();

        $this->assertSame('[体验课] 已接待：到店了，状态不错', $this->memberLogDetail($c->id));
        // 既有流转不变：留资状态机仍推进到「已体验」
        $this->assertSame('已体验', $lead->fresh()->status);
    }

    // ------------------------------------------------------------- 数据准备

    /** 该会员最近一条「沟通记录」留痕的 detail（会员 360 读的就是这一份） */
    private function memberLogDetail(int $customerId): string
    {
        return (string) AuditLog::where('module', '会员管理')
            ->where('target_id', (string) $customerId)
            ->where('action', '沟通记录')
            ->orderByDesc('id')
            ->value('detail');
    }

    private function manager(): User
    {
        return User::factory()->create([
            'username' => 'mgr-src-tag', 'name' => '绿地店长', 'role' => 'R_MANAGER', 'venue' => '绿地店',
        ]);
    }

    private function booking(string $memberName, string $phone, string $time): void
    {
        KyBooking::create([
            'source_key' => uniqid('src-tag-'),
            'venue' => '绿地店',
            'booking_type' => '私教',
            'course_kind' => 'private',
            'member_id' => 'm-'.md5($memberName),
            'member_name' => $memberName,
            'phone' => $phone,
            'start_at' => date('Y-m-d').' '.$time.':00',
            'course_name' => '普拉提私教',
            'teacher_name' => '小周',
            'status_raw' => '',
            'status' => 'booked',
            'is_trial' => false,
            'raw' => ['course_type' => '2'],
        ]);
    }
}
