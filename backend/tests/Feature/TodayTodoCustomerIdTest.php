<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `/today/todo` 的 `reviews` / `trials` 两组补 `customerId`（t15 追加项，馆主已批）。
 *
 * 为什么要有这两条断言：分组「点行进人」做完 8/8 组后，只有这两组定位不到具体的人 ——
 * reviews 只给 bookingId，trials 的 KY 条目只给手机号，员工只能落到列表页自己找。
 *
 * 收敛口径（馆主 2026-11 拍板）：**只认 memberId 与归一的手机号两条精确路径，姓名兜底返回 null**。
 *   - 落列表（null）：多点一次，2 秒；
 *   - 跳错人（姓名误命中）：员工看到另一个同名客户的卡额/出勤/续费评估并据此决策，不报错也发现不了。
 *
 * 所以本文件的第 3 个用例是**反向**的：姓名+门店完全对得上、但 memberId/手机号都对不上时，
 * 必须返回 null —— 这条红了就说明有人把 name 兜底接回来了。
 */
class TodayTodoCustomerIdTest extends TestCase
{
    use RefreshDatabase;

    /** ① memberId 命中：签到的体验课/私教课，booking.member_id 对上 external_id 的尾段。 */
    public function test_reviews_and_trials_resolve_customer_by_member_id(): void
    {
        $member = $this->member('B2待办-按ID', '13900000021', 'ky:77001:880123');
        $this->booking(['source_key' => 'b2-bk-id', 'member_id' => '880123', 'phone' => '13600000000']);

        Sanctum::actingAs($this->super());
        $data = $this->getJson('/api/today/todo')->assertOk()->json('data');

        $this->assertNotEmpty($data['reviews'], '签到私教课必须进 reviews 组（否则本用例空转）');
        $this->assertNotEmpty($data['trials'], '体验课必须进 trials 组');
        $this->assertSame($member->id, $data['reviews'][0]['customerId'], 'reviews 组必须按 memberId 命中会员');
        $this->assertSame($member->id, $data['trials'][0]['customerId'], 'trials 组必须按 memberId 命中会员');
    }

    /** ② 手机号命中（带分隔符/+86 的原始串也要归一后才比）。 */
    public function test_trials_resolve_customer_by_normalized_phone(): void
    {
        $member = $this->member('B2待办-按手机', '13900000022', 'ky:77001:880124');
        // member_id 故意给一个不存在的值，只留手机号这一条路
        $this->booking(['source_key' => 'b2-bk-phone', 'member_id' => '000000', 'phone' => '+86 139-0000-0022']);

        Sanctum::actingAs($this->super());
        $data = $this->getJson('/api/today/todo')->assertOk()->json('data');

        $this->assertSame($member->id, $data['trials'][0]['customerId'], '带分隔符的手机号必须归一后才能命中');
    }

    /** ③ 两条精确路径都不命中 ⇒ null（姓名对得上也不行）。 */
    public function test_customer_id_is_null_when_only_name_matches(): void
    {
        // 会员姓名与门店和预约行完全一致，但 member_id / 手机号都对不上
        $this->member('B2待办-同名', '13900000023', 'ky:77001:880125');
        $this->booking([
            'source_key' => 'b2-bk-name',
            'member_id' => '999999',
            'phone' => '13700000000',
            'member_name' => 'B2待办-同名',
        ]);

        Sanctum::actingAs($this->super());
        $data = $this->getJson('/api/today/todo')->assertOk()->json('data');

        $this->assertNull($data['reviews'][0]['customerId'], '姓名兜底必须被砍掉：认不出就是 null');
        $this->assertNull($data['trials'][0]['customerId'], '同上');

        // 键必须存在（前端按「无 customerId → 落列表」判断，键缺失与 null 都要能处理，
        // 但契约上我们给的是显式 null，不是省略键）
        $this->assertArrayHasKey('customerId', $data['reviews'][0]);
        $this->assertArrayHasKey('customerId', $data['trials'][0]);
    }

    // ------------------------------------------------------------- 数据准备

    private function member(string $name, string $phone, string $externalId): Customer
    {
        return Customer::create([
            'name' => $name,
            'phone' => $phone,
            'phone_tail' => substr($phone, -4),
            'venue' => '绿地店',
            'source' => 'KeepYoga',
            'consultant' => 'B2顾问',
            'owner' => 'B2顾问',
            'status' => '在籍',
            'layer' => 'P4',
            'external_id' => $externalId,
        ]);
    }

    /** 一条「今天、已签到、体验课兼私教」的预约：同时进 reviews 与 trials 两组。 */
    private function booking(array $overrides = []): KyBooking
    {
        return KyBooking::create(array_merge([
            'source_key' => 'b2-bk-default',
            'venue' => '绿地店',
            'booking_type' => 'private',
            'member_id' => '880123',
            'member_name' => 'B2待办会员',
            'phone' => '13900000021',
            'start_at' => now()->startOfHour(),
            'course_name' => '私教体验课',
            'teacher_name' => 'B2老师',
            'status_raw' => '已签到',
            'status' => 'signed',
            'is_trial' => true,
            'course_kind' => 'private',
            'raw' => [],
        ], $overrides));
    }

    private function super(): User
    {
        return User::factory()->create([
            'username' => uniqid('b2-todo-super-'),
            'name' => 'B2超管',
            'role' => 'R_SUPER',
            'roles' => ['R_SUPER'],
            'status' => '启用',
            'venue' => null,
            'venues' => ['绿地店', '东部店'],
        ]);
    }
}
