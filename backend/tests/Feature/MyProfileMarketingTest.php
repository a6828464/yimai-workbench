<?php

namespace Tests\Feature;

use App\Models\MarketingPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MyProfileMarketingTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_and_persona_round_trip(): void
    {
        $u = $this->user('teacher-a', '张老师', 'R_TEACHER', '绿地店');
        Sanctum::actingAs($u);

        $this->getJson('/api/my/profile')->assertOk()
            ->assertJsonPath('data.name', '张老师');

        $this->putJson('/api/my/profile', [
            'phone' => '13900001234',
            'profile' => [
                'gender' => '女', 'age' => '28', 'years' => '5年',
                'specialties' => ['普拉提', '康复理疗', '空中瑜伽（自定义）'],
                'persona' => ['role' => '会籍顾问', 'audiences' => ['25-45岁久坐上班族', '肩颈腰背慢性酸痛人群']],
                'xhs' => ['ipType' => '个人IP', 'style' => '真实接地气', 'conversion' => '私信咨询', 'localFocus' => true, 'audiences' => [], 'accountName' => '', 'role' => '会籍顾问'],
            ],
        ])->assertOk();

        $d = $this->getJson('/api/my/profile')->assertOk()->json('data');
        $this->assertSame('13900001234', $d['phone']);
        $this->assertSame('会籍顾问', $d['profile']['persona']['role']);
        $this->assertContains('空中瑜伽（自定义）', $d['profile']['specialties']);

        // /me 也带出手机号供系统联动展示
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.phone', '13900001234');
    }

    public function test_persona_saves_are_isolated_per_user(): void
    {
        $a = $this->user('teacher-a', '张老师', 'R_TEACHER', '绿地店');
        $b = $this->user('teacher-b', '李老师', 'R_TEACHER', '绿地店');
        Sanctum::actingAs($a);
        $this->putJson('/api/my/profile', ['profile' => ['years' => '9年']])->assertOk();
        Sanctum::actingAs($b);
        $this->getJson('/api/my/profile')->assertOk()->assertJsonMissingPath('data.profile.years');
    }

    public function test_password_change_requires_correct_old_password(): void
    {
        $u = $this->user('teacher-a', '张老师', 'R_TEACHER', '绿地店');
        $u->update(['password' => 'oldpass123']);
        Sanctum::actingAs($u);

        $this->putJson('/api/my/password', ['oldPassword' => 'wrong', 'newPassword' => 'newpass123'])
            ->assertStatus(422);

        $this->putJson('/api/my/password', ['oldPassword' => 'oldpass123', 'newPassword' => 'newpass123'])
            ->assertOk();

        $this->postJson('/api/auth/login', ['userName' => 'teacher-a', 'password' => 'newpass123'])->assertOk();
    }

    public function test_marketing_history_crud_scoped_by_user_and_platform(): void
    {
        $a = $this->user('teacher-a', '张老师', 'R_TEACHER', '绿地店');
        $b = $this->user('teacher-b', '李老师', 'R_TEACHER', '绿地店');
        Sanctum::actingAs($a);

        $id = $this->postJson('/api/marketing/history', [
            'platform' => '朋友圈', 'title' => '续课邀约', 'content' => '正文一', 'reply' => '首评一', 'source' => 'llm',
        ])->assertOk()->json('data.id');
        $this->postJson('/api/marketing/history', [
            'platform' => '小红书', 'title' => '笔记', 'content' => '正文二', 'source' => 'fallback',
        ])->assertOk();

        $list = $this->getJson('/api/marketing/history?platform='.urlencode('朋友圈'))->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('首评一', $list[0]['reply']);

        // 其他账号看不到、删不掉
        Sanctum::actingAs($b);
        $this->getJson('/api/marketing/history')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/marketing/history/{$id}")->assertOk();
        Sanctum::actingAs($a);
        $this->assertCount(2, $this->getJson('/api/marketing/history')->assertOk()->json('data'));

        // 本账号删除生效
        $this->deleteJson("/api/marketing/history/{$id}")->assertOk();
        $this->assertCount(1, $this->getJson('/api/marketing/history')->assertOk()->json('data'));
    }

    public function test_history_rejects_unknown_platform(): void
    {
        Sanctum::actingAs($this->user('teacher-a', '张老师', 'R_TEACHER', '绿地店'));
        $this->postJson('/api/marketing/history', ['platform' => '抖音', 'content' => 'x'])->assertStatus(422);
    }

    // ───────── 输入健壮性：畸形 id 与正文字节溢出（RV4 R1/R2 收口；与收藏侧同口径） ─────────

    /** R1：`DELETE /marketing/history/{id}` 收到畸形 id 必须是 404，而不是 TypeError 的 500 */
    public function test_history_delete_with_malformed_id_returns_404_not_500(): void
    {
        Sanctum::actingAs($this->user('teacher-a', '张老师', 'R_TEACHER', '绿地店'));

        foreach (['abc', '12abc', '999999999999999999999999'] as $bad) {
            $this->deleteJson("/api/marketing/history/{$bad}")->assertStatus(404);
        }

        // 合法数字 id 的既有语义不变：不存在 → 200 幂等 ok
        $this->deleteJson('/api/marketing/history/99999998')->assertOk()->assertJsonPath('data.ok', true);
    }

    /**
     * R2：`POST /marketing/history` 的正文按声明上限 20000 字符（20000 个 emoji = 80000 字节）
     * 必须存得下 —— 与收藏侧同口径（两张表的 content 一起加宽到 mediumText）。
     * sqlite 上此用例恒过（TEXT 无字节上限），真实列宽由 MySQL 复跑证明，见 MarketingFavoriteTest。
     */
    public function test_history_content_at_char_limit_with_emoji_is_accepted(): void
    {
        Sanctum::actingAs($this->user('teacher-a', '张老师', 'R_TEACHER', '绿地店'));

        $content = str_repeat('😀', 20000);
        $this->assertSame(80000, strlen($content));

        $id = $this->postJson('/api/marketing/history', ['platform' => '小红书', 'content' => $content])
            ->assertOk()->json('data.id');
        $this->assertSame(80000, strlen(MarketingPost::find($id)->content));
    }

    private function user(string $username, string $name, string $role, ?string $venue): User
    {
        return User::factory()->create(compact('username', 'name', 'role', 'venue'));
    }
}
