<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 人员列表的手动顺序（拖动排序）。
 *
 * 守住的四条口径：
 *  1. **没拖过顺序时顺序不变**：`sort_order` 默认 0，复合排序退化成 id（开通先后）——
 *     迁移不需要回填，升级前后列表一模一样；
 *  2. 保存后 GET 按新顺序返回（10/20/30 编号，留空位）；
 *  3. **入参里没有的账号接在末尾，而不是被置 0 跳到最前面**——
 *     「保存顺序」不能误伤没被拖动的行；
 *  4. 新开通的账号接在末尾（max+10），不会插到店长前面。
 */
class AccountOrderTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuper(): User
    {
        $u = User::factory()->create([
            'username' => 'order-super',
            'name' => '超管',
            'role' => 'R_SUPER',
            'roles' => ['R_SUPER'],
            'venues' => ['绿地店', '东部店'],
            'status' => '启用',
        ]);
        Sanctum::actingAs($u);

        return $u;
    }

    private function staff(string $key, string $name): User
    {
        return User::factory()->create([
            'username' => $key,
            'name' => $name,
            'role' => 'R_TEACHER',
            'roles' => ['R_TEACHER'],
            'venue' => '绿地店',
            'venues' => ['绿地店'],
            'status' => '启用',
        ]);
    }

    /** @return string[] */
    private function keys(): array
    {
        return array_column($this->getJson('/api/accounts')->assertOk()->json('data'), 'key');
    }

    public function test_list_falls_back_to_creation_order_when_never_reordered(): void
    {
        $this->actingAsSuper();
        $this->staff('t1', '老师一');
        $this->staff('t2', '老师二');
        $this->staff('t3', '老师三');

        // 全员 sort_order = 0 → 退化成 id 升序 = 开通先后
        $this->assertSame(['order-super', 't1', 't2', 't3'], $this->keys());
    }

    public function test_saved_order_is_returned_by_index(): void
    {
        $this->actingAsSuper();
        $this->staff('t1', '老师一');
        $this->staff('t2', '老师二');
        $this->staff('t3', '老师三');

        $this->putJson('/api/accounts/order', [
            'order' => ['t3', 't1', 'order-super', 't2'],
        ])->assertOk()->assertJsonPath('data.updated', 4);

        $this->assertSame(['t3', 't1', 'order-super', 't2'], $this->keys());
        // 10/20/30 编号：留 9 个空位，将来插队不用重排全表
        $this->assertSame(10, (int) User::where('username', 't3')->value('sort_order'));
        $this->assertSame(40, (int) User::where('username', 't2')->value('sort_order'));
    }

    public function test_accounts_missing_from_payload_are_appended_not_jumped_to_top(): void
    {
        $this->actingAsSuper();
        $this->staff('t1', '老师一');
        $this->staff('t2', '老师二');
        $this->staff('t3', '老师三');

        // 只提交两个账号：其余账号必须接在末尾，且保持它们彼此原有的先后
        $this->putJson('/api/accounts/order', ['order' => ['t2', 't1']])->assertOk();

        $this->assertSame(['t2', 't1', 'order-super', 't3'], $this->keys());
    }

    public function test_unknown_and_duplicated_keys_are_ignored(): void
    {
        $this->actingAsSuper();
        $this->staff('t1', '老师一');
        $this->staff('t2', '老师二');

        $this->putJson('/api/accounts/order', [
            'order' => ['t2', 'nobody', 't2', 't1'],
        ])->assertOk()->assertJsonPath('data.updated', 3);

        $this->assertSame(['t2', 't1', 'order-super'], $this->keys());
    }

    public function test_new_account_is_appended_after_manual_order(): void
    {
        $this->actingAsSuper();
        $this->staff('t1', '老师一');
        $this->staff('t2', '老师二');

        $this->putJson('/api/accounts/order', ['order' => ['t2', 't1']])->assertOk();

        $this->postJson('/api/accounts', [
            'userName' => 't9',
            'name' => '新来的',
            'roles' => ['R_TEACHER'],
            'venues' => ['绿地店'],
            'password' => 'Passw0rd!',
        ])->assertOk();

        $this->assertSame(['t2', 't1', 'order-super', 't9'], $this->keys());
    }

    public function test_only_super_can_reorder(): void
    {
        $manager = User::factory()->create([
            'username' => 'm1',
            'name' => '店长',
            'role' => 'R_MANAGER',
            'roles' => ['R_MANAGER'],
            'venue' => '绿地店',
            'venues' => ['绿地店'],
            'status' => '启用',
        ]);
        Sanctum::actingAs($manager);

        $this->putJson('/api/accounts/order', ['order' => ['m1']])->assertForbidden();
    }
}
