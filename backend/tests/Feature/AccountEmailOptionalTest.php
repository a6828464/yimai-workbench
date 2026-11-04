<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 人员管理：邮箱变成**可选**字段（不再伪造 `{userName}@yimai.local`）。
 *
 * 三条口径：
 *  1. 不传 email 也能开通账号，库里是 NULL（不是假邮箱、也不是空串）；
 *  2. 传空串等于不填（表单清空是常见操作，不能报「邮箱格式错误」）；
 *  3. 填了的邮箱仍然唯一 —— 「可选」不等于「可以重复」。
 *
 * 第 4 个用例顺带守住 `users.email` 的可空改造：唯一索引必须还在
 * （SQLite 重建表会丢索引，丢了的话重复邮箱在测试库里静默通过）。
 */
class AccountEmailOptionalTest extends TestCase
{
    use RefreshDatabase;

    private function superUser(): User
    {
        $u = User::factory()->create([
            'username' => 'email-super',
            'name' => '超管',
            'role' => 'R_SUPER',
            'roles' => ['R_SUPER'],
            'venue' => '绿地店',
            'venues' => ['绿地店', '东部店'],
            'status' => '启用',
        ]);
        Sanctum::actingAs($u);

        return $u;
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'userName' => 'new-teacher',
            'name' => '新老师',
            'roles' => ['R_TEACHER'],
            'venues' => ['绿地店'],
            'password' => 'Passw0rd!',
        ], $extra);
    }

    public function test_account_can_be_created_without_email(): void
    {
        $this->superUser();

        $this->postJson('/api/accounts', $this->payload())->assertOk();

        $created = User::where('username', 'new-teacher')->firstOrFail();
        // NULL 而不是 '' / 假邮箱：唯一索引允许多个 NULL，''
        // 只能有一个（第二个没邮箱的账号会撞唯一键）
        $this->assertNull($created->email);
    }

    public function test_multiple_accounts_without_email_do_not_collide(): void
    {
        $this->superUser();

        $this->postJson('/api/accounts', $this->payload(['userName' => 'no-mail-a', 'name' => '无邮箱甲']))->assertOk();
        $this->postJson('/api/accounts', $this->payload(['userName' => 'no-mail-b', 'name' => '无邮箱乙']))->assertOk();

        $this->assertSame(2, User::whereIn('username', ['no-mail-a', 'no-mail-b'])->whereNull('email')->count());
    }

    public function test_blank_email_string_is_treated_as_missing(): void
    {
        $this->superUser();

        $this->postJson('/api/accounts', $this->payload(['email' => '']))
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertNull(User::where('username', 'new-teacher')->firstOrFail()->email);
    }

    public function test_email_is_still_unique_when_provided(): void
    {
        $this->superUser();

        User::factory()->create(['username' => 'taken-mail', 'email' => 'taken@yimai.com']);

        $this->postJson('/api/accounts', $this->payload(['email' => 'taken@yimai.com']))
            ->assertStatus(422);

        $this->assertSame(0, User::where('username', 'new-teacher')->count());

        // 唯一索引本身也还在（防 SQLite 重建表时被丢掉）
        $uniqueOnEmail = collect(Schema::getIndexes('users'))
            ->contains(fn ($i) => ($i['unique'] ?? false) && in_array('email', $i['columns'] ?? [], true));
        $this->assertTrue($uniqueOnEmail, 'users.email 的唯一索引在可空改造后必须仍然存在');
    }
}
