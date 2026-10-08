<?php

namespace Tests\Feature;

use App\Models\MarketingFavorite;
use App\Models\MarketingPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 营销工具收藏服务端持久化（docs/营销工具/06-收藏服务端持久化-设计规格.md）。
 *
 * 覆盖：迁移幂等 + 结构 / 本人可见与字段形状 / 他人不可见不可删（防枚举）/ 伪造 user_id 无效 /
 * 校验与边界 / 上限拒绝且不删既有 / 生成历史裁剪不挤掉收藏。
 */
class MarketingFavoriteTest extends TestCase
{
    use RefreshDatabase;

    private const LIMIT = 300;

    // ───────────────────────── 迁移 ─────────────────────────

    public function test_migration_is_idempotent_and_creates_expected_columns_and_index(): void
    {
        $this->assertTrue(Schema::hasTable('marketing_favorites'));
        $this->assertTrue(Schema::hasColumns('marketing_favorites', [
            'id', 'user_id', 'platform', 'title', 'content', 'reply', 'cover_text', 'tags',
            'created_at', 'updated_at',
        ]));

        // 半成功状态（表已建、迁移未记账）重跑不炸：守卫必须挡在 create 之外
        $migration = require database_path('migrations/2026_10_08_000001_add_marketing_favorites_table.php');
        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasTable('marketing_favorites'));

        // 复合索引服务 ?platform= 过滤 + latest('id') 排序
        $names = array_column(Schema::getIndexes('marketing_favorites'), 'name');
        $this->assertContains('mf_user_platform_idx', $names);
    }

    // ───────────────────── 本人可见 / 字段形状 ─────────────────────

    public function test_owner_sees_favorites_with_expected_shape_and_order(): void
    {
        $a = $this->user('fav-owner', '张老师');
        Sanctum::actingAs($a);

        $id1 = $this->postJson('/api/marketing/favorites', [
            'platform' => '朋友圈',
            'title' => '体验课案例 · 真实日常版',
            'content' => "正文一\n\n——首条回复——\n首评一",
            'reply' => '首评一',
            'tags' => ['#瑜伽', '#宁波', '#瑜伽'],
        ])->assertOk()->json('data.id');

        $this->postJson('/api/marketing/favorites', [
            'platform' => '小红书',
            'title' => '笔记',
            'content' => '正文二',
            'coverText' => '封面文案：3 个月变化',
            'tags' => [],
        ])->assertOk();

        $list = $this->getJson('/api/marketing/favorites')->assertOk()->json('data');
        $this->assertCount(2, $list);
        // latest('id') 倒序
        $this->assertSame('小红书', $list[0]['platform']);
        $this->assertSame($id1, $list[1]['id']);

        $row = $list[1];
        $this->assertSame('朋友圈', $row['platform']);
        $this->assertSame('体验课案例 · 真实日常版', $row['title']);
        $this->assertSame('首评一', $row['reply']);
        $this->assertNull($row['coverText']); // nullable 往返
        $this->assertSame(['#瑜伽', '#宁波'], $row['tags']); // 去重且仍是 JSON 数组
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $row['createdAt']);

        $row2 = $list[0];
        $this->assertSame('封面文案：3 个月变化', $row2['coverText']); // nullable 往返（另一个方向）
        $this->assertNull($row2['reply']);
        $this->assertSame([], $row2['tags']); // 空数组往返，绝不返回 null
    }

    public function test_platform_filter_and_unknown_platform_returns_empty(): void
    {
        $u = $this->user('fav-filter', '张老师');
        Sanctum::actingAs($u);
        $this->postJson('/api/marketing/favorites', ['platform' => '朋友圈', 'content' => '朋友圈正文'])->assertOk();
        $this->postJson('/api/marketing/favorites', ['platform' => '小红书', 'content' => '小红书正文'])->assertOk();

        $moments = $this->getJson('/api/marketing/favorites?platform='.urlencode('朋友圈'))->assertOk()->json('data');
        $this->assertCount(1, $moments);
        $this->assertSame('朋友圈', $moments[0]['platform']);

        // 读路径不做 in: 校验（与 /marketing/history 一致）：非法值自然空数组、无副作用
        $this->assertSame([], $this->getJson('/api/marketing/favorites?platform='.urlencode('抖音'))->assertOk()->json('data'));
    }

    public function test_null_tags_column_is_normalized_to_empty_array(): void
    {
        $u = $this->user('fav-nulltags', '张老师');
        Sanctum::actingAs($u);
        DB::table('marketing_favorites')->insert([
            'user_id' => $u->id, 'platform' => '朋友圈', 'title' => '老行', 'content' => '正文',
            'reply' => null, 'cover_text' => null, 'tags' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = $this->getJson('/api/marketing/favorites')->assertOk()->json('data.0');
        $this->assertSame([], $row['tags']); // 列 null → 响应永远数组
        $this->assertNull($row['coverText']);
    }

    // ───────────────────── 隔离：他人不可见 / 不可删 ─────────────────────

    public function test_other_user_cannot_see_or_delete_and_delete_responses_are_indistinguishable(): void
    {
        $a = $this->user('fav-a', '张老师');
        $b = $this->user('fav-b', '李老师');
        Sanctum::actingAs($a);
        $secret = '会员原话：肩颈松多了（合成文案）';
        $idA = $this->postJson('/api/marketing/favorites', ['platform' => '朋友圈', 'content' => $secret])
            ->assertOk()->json('data.id');

        Sanctum::actingAs($b);
        $listB = $this->getJson('/api/marketing/favorites')->assertOk()->json('data');
        $this->assertSame([], $listB); // 他人不可见

        // 「他人 id」与「不存在 id」的响应形态必须完全一致（防枚举：状态码差异会泄露存在性）
        $nonexistent = $this->deleteJson('/api/marketing/favorites/99999998')->assertOk()->json();
        $foreign = $this->deleteJson("/api/marketing/favorites/{$idA}")->assertOk()->json();
        $this->assertSame($nonexistent, $foreign);
        $this->assertSame(['code' => 0, 'data' => ['ok' => true]], $foreign);

        Sanctum::actingAs($a);
        $listA = $this->getJson('/api/marketing/favorites')->assertOk()->json('data');
        $this->assertCount(1, $listA); // 删不掉：A 的记录仍在
        $this->assertSame($idA, $listA[0]['id']);
        $this->assertSame($secret, $listA[0]['content']);
        $this->assertSame('朋友圈', $listA[0]['platform']);
    }

    public function test_forged_user_id_in_body_is_ignored_and_does_not_write_to_the_other_account(): void
    {
        $a = $this->user('fav-victim', '张老师');
        $b = $this->user('fav-attacker', '李老师');
        Sanctum::actingAs($b);

        $id = $this->postJson('/api/marketing/favorites', [
            'platform' => '小红书', 'content' => 'B 的文案', 'user_id' => $a->id,
        ])->assertOk()->json('data.id');

        $this->assertSame($b->id, (int) MarketingFavorite::find($id)->user_id); // 写到自己名下

        Sanctum::actingAs($a);
        $this->assertSame([], $this->getJson('/api/marketing/favorites')->assertOk()->json('data')); // A 一条没多
        Sanctum::actingAs($b);
        $this->assertCount(1, $this->getJson('/api/marketing/favorites')->assertOk()->json('data'));
    }

    public function test_superadmin_sees_only_own_favorites(): void
    {
        $a = $this->user('fav-member', '张老师');
        Sanctum::actingAs($a);
        $aId = $this->postJson('/api/marketing/favorites', ['platform' => '朋友圈', 'content' => 'A 的收藏'])
            ->assertOk()->json('data.id');

        // 收藏是个人劳动成果、不是门店资产：不给超管旁路（规格 §6.2 红线）
        $super = $this->user('fav-super', '超管', 'R_SUPER');
        Sanctum::actingAs($super);
        $this->assertSame([], $this->getJson('/api/marketing/favorites')->assertOk()->json('data'));
        $this->deleteJson("/api/marketing/favorites/{$aId}")->assertOk();

        Sanctum::actingAs($a);
        $this->assertCount(1, $this->getJson('/api/marketing/favorites')->assertOk()->json('data')); // A 的记录仍在
    }

    public function test_all_three_endpoints_require_login(): void
    {
        $this->getJson('/api/marketing/favorites')->assertStatus(401);
        $this->postJson('/api/marketing/favorites', ['platform' => '朋友圈', 'content' => 'x'])->assertStatus(401);
        $this->deleteJson('/api/marketing/favorites/1')->assertStatus(401);
    }

    // ───────────────────────── 校验与边界 ─────────────────────────

    public function test_validation_failures_return_422_with_allowed_enum_values(): void
    {
        Sanctum::actingAs($this->user('fav-validate', '张老师'));

        $allowed = 'platform 取值不在允许范围内，允许值为：朋友圈、小红书';
        $this->postJson('/api/marketing/favorites', ['platform' => '抖音', 'content' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('message', $allowed)
            ->assertJsonPath('errors.platform.0', $allowed);

        $this->postJson('/api/marketing/favorites', ['content' => 'x'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['platform']]);
        $this->postJson('/api/marketing/favorites', ['platform' => '朋友圈'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['content']]);

        // tags 类型错 / 单条超长 / 条数超限
        $this->postJson('/api/marketing/favorites', ['platform' => '朋友圈', 'content' => 'x', 'tags' => '#瑜伽'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['tags']]);
        $this->postJson('/api/marketing/favorites', ['platform' => '朋友圈', 'content' => 'x', 'tags' => [str_repeat('长', 61)]])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['tags.0']]);
        $this->postJson('/api/marketing/favorites', [
            'platform' => '朋友圈', 'content' => 'x', 'tags' => array_map(fn ($i) => "标签{$i}", range(1, 11)),
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['tags']]);

        // 文本超长
        $this->postJson('/api/marketing/favorites', ['platform' => '朋友圈', 'content' => 'x', 'title' => str_repeat('标', 121)])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['title']]);
        $this->postJson('/api/marketing/favorites', ['platform' => '朋友圈', 'content' => str_repeat('正', 20001)])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['content']]);
        $this->postJson('/api/marketing/favorites', ['platform' => '朋友圈', 'content' => 'x', 'reply' => str_repeat('评', 3001)])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['reply']]);
        $this->postJson('/api/marketing/favorites', ['platform' => '朋友圈', 'content' => 'x', 'coverText' => str_repeat('封', 3001)])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['coverText']]);
    }

    public function test_length_and_count_edges_are_enforced_at_the_boundary(): void
    {
        $u = $this->user('fav-edge', '张老师');
        Sanctum::actingAs($u);

        // title 恰好 120：通过且原样落库
        $id = $this->postJson('/api/marketing/favorites', [
            'platform' => '朋友圈', 'content' => '正文', 'title' => str_repeat('标', 120),
        ])->assertOk()->json('data.id');
        $this->assertSame(120, mb_strlen(MarketingFavorite::find($id)->title));

        // content 恰好 20000：通过
        $this->postJson('/api/marketing/favorites', [
            'platform' => '小红书', 'content' => str_repeat('正', 20000),
        ])->assertOk();

        // tags：10 条 × 每条恰好 60 字（互不相同，避免被去重），同批给到上限，通过
        $tags = [];
        for ($i = 1; $i <= 10; $i++) {
            $tags[] = str_repeat('标', 60 - mb_strlen((string) $i)).$i;
        }
        $id = $this->postJson('/api/marketing/favorites', [
            'platform' => '小红书', 'content' => '正文', 'tags' => $tags,
        ])->assertOk()->json('data.id');
        $stored = MarketingFavorite::find($id)->tags;
        $this->assertCount(10, $stored);
        $this->assertSame(60, mb_strlen($stored[0]));

        // reply / coverText 恰好 3000：通过
        $this->postJson('/api/marketing/favorites', [
            'platform' => '朋友圈', 'content' => '正文',
            'reply' => str_repeat('评', 3000), 'coverText' => str_repeat('封', 3000),
        ])->assertOk();
    }

    public function test_empty_and_null_values_round_trip_and_client_supplied_fields_are_ignored(): void
    {
        $u = $this->user('fav-empty', '张老师');
        Sanctum::actingAs($u);

        $id = $this->postJson('/api/marketing/favorites', [
            'platform' => '朋友圈',
            'title' => '',
            'content' => '正文',
            'reply' => null,
            'coverText' => null,
            'tags' => [],
            'id' => 999,                       // 服务端发号：传了忽略
            'createdAt' => '1999-01-01 00:00',  // 服务端时间：传了忽略
            'user_id' => $u->id,                // 服务端从登录态取：传了忽略
        ])->assertOk()->json('data.id');
        $this->assertNotSame(999, $id);

        $row = $this->getJson('/api/marketing/favorites')->assertOk()->json('data.0');
        $this->assertSame('', $row['title']); // default('')：是空串而不是 null
        $this->assertSame([], $row['tags']);
        $this->assertNull($row['reply']);
        $this->assertNull($row['coverText']);
        $this->assertStringNotContainsString('1999-01-01', $row['createdAt']);
    }

    // ───────────────────── 上限：拒绝新增，不删既有 ─────────────────────

    public function test_over_limit_rejects_new_favorite_without_deleting_existing_ones(): void
    {
        $u = $this->user('fav-limit', '张老师');
        Sanctum::actingAs($u);
        $this->seedFavorites($u, self::LIMIT);
        $oldest = MarketingFavorite::where('user_id', $u->id)->orderBy('id')->first();

        $this->postJson('/api/marketing/favorites', ['platform' => '朋友圈', 'content' => '第 301 条'])
            ->assertStatus(422)
            ->assertJsonPath('message', '收藏已达上限 '.self::LIMIT.' 条，请先删除部分收藏再添加');

        // 静默删旧的 bug 形态：一条都不能少（尤其最早那条）
        $this->assertSame(self::LIMIT, MarketingFavorite::where('user_id', $u->id)->count());
        $this->assertTrue(MarketingFavorite::where('id', $oldest->id)->exists());
        // GET 读上限与写上限同值：存得进就一定看得到
        $this->assertCount(self::LIMIT, $this->getJson('/api/marketing/favorites')->assertOk()->json('data'));
    }

    public function test_limit_counts_across_platforms_and_frees_up_after_delete(): void
    {
        $u = $this->user('fav-limit2', '张老师');
        Sanctum::actingAs($u);
        $this->seedFavorites($u, self::LIMIT - 1, '朋友圈');
        $this->seedFavorites($u, 1, '小红书');

        // 上限按「该 user 全平台合计」算
        $this->postJson('/api/marketing/favorites', ['platform' => '小红书', 'content' => '第 301 条'])
            ->assertStatus(422);

        // 上限不是永久封禁：删一条即可再收藏
        $first = MarketingFavorite::where('user_id', $u->id)->orderBy('id')->first();
        $this->deleteJson("/api/marketing/favorites/{$first->id}")->assertOk();
        $this->postJson('/api/marketing/favorites', ['platform' => '小红书', 'content' => '删一条后就能收'])
            ->assertOk();
    }

    // ─────────────── 核心动机：收藏不被生成记录挤掉 ───────────────

    public function test_history_trim_does_not_squeeze_favorites(): void
    {
        $u = $this->user('fav-trim', '张老师');
        Sanctum::actingAs($u);

        $favId = $this->postJson('/api/marketing/favorites', [
            'platform' => '朋友圈', 'content' => '这条收藏不能被生成记录挤掉',
        ])->assertOk()->json('data.id');

        // 攒满 305 条生成历史，再来一条触发 saveHistory 的裁剪 skip(300)->take(50)->delete()
        $this->seedHistory($u, 305);
        $this->postJson('/api/marketing/history', ['platform' => '朋友圈', 'content' => '再来一条'])->assertOk();

        // 裁剪确实发生了（否则本测试没有咬合力）：历史回落到 300 条上限
        $this->assertSame(self::LIMIT, MarketingPost::where('user_id', $u->id)->where('platform', '朋友圈')->count());
        // 收藏一条不少，且仍在 API 可见
        $this->assertSame(1, MarketingFavorite::where('user_id', $u->id)->count());
        $this->assertSame([$favId], array_column($this->getJson('/api/marketing/favorites')->assertOk()->json('data'), 'id'));
    }

    // ───────────── 输入健壮性：畸形 id 与正文字节溢出（RV4 R1/R2 收口） ─────────────

    /**
     * R1：畸形 id 必须是 404，而不是 `int $id` 形参强转失败抛出的 500。
     *
     * 口径（全仓一致）：畸形 id 不匹配任何 `{id}` 路由 → 404。
     * 与防枚举红线不冲突 —— 畸形 id 不可能对应任何人的记录，404 不泄露任何存在性；
     * 「他人的 id / 不存在的 id」依旧是 200 `{ok:true}`（见上一个测试）。
     */
    public function test_malformed_id_never_returns_500(): void
    {
        $u = $this->user('fav-badid', '张老师');
        Sanctum::actingAs($u);

        // 非数字 / 数字+字母 / 超出 int64 的纯数字（19 位以上都会 TypeError，故 pattern 必须限位数）
        foreach (['abc', '12abc', '999999999999999999999999'] as $bad) {
            $this->deleteJson("/api/marketing/favorites/{$bad}")->assertStatus(404);
        }

        // 合法数字 id 的语义不变
        $this->deleteJson('/api/marketing/favorites/99999998')->assertOk()->assertJsonPath('data.ok', true);

        // 全局 pattern 的证据：同根因的既有端点一并覆盖（不再「新表修了、老端点还坏」）
        $this->deleteJson('/api/training-plans/999999999999999999999999')->assertStatus(404);
    }

    /**
     * R2：正文在「声明的字符上限」下必须存得下 —— 20000 个 emoji = 80000 字节 > text 的 65535。
     *
     * ⚠️ 本用例在 sqlite（默认测试连接）上不可能变红：sqlite 的 TEXT 无字节上限。
     * 它钉的是**接口契约**；列宽本身由下面的迁移测试（MySQL 分支）与「在真实 MySQL 库上复跑本类」
     * 共同证明 —— 未加宽列时本用例在 MySQL 上会直接 500（1406）。
     */
    public function test_content_at_char_limit_with_emoji_is_accepted_and_round_trips(): void
    {
        $u = $this->user('fav-bytes', '张老师');
        Sanctum::actingAs($u);

        $content = str_repeat('😀', 20000);
        $this->assertSame(20000, mb_strlen($content));
        $this->assertSame(80000, strlen($content)); // 字符上限对应的最坏字节量

        $id = $this->postJson('/api/marketing/favorites', ['platform' => '朋友圈', 'content' => $content])
            ->assertOk()->json('data.id');
        $this->assertSame(80000, strlen(MarketingFavorite::find($id)->content));

        $row = $this->getJson('/api/marketing/favorites')->assertOk()->json('data.0');
        $this->assertSame(20000, mb_strlen($row['content']));
    }

    /** `2026_10_08_000002`（content → mediumText）：重复执行不报错、不动已有数据、两张表同口径 */
    public function test_widen_content_migration_is_idempotent_and_keeps_existing_rows(): void
    {
        $u = $this->user('fav-migrate', '张老师');
        Sanctum::actingAs($u);
        $id = $this->postJson('/api/marketing/favorites', ['platform' => '朋友圈', 'content' => '迁移前就存在的行'])
            ->assertOk()->json('data.id');

        $migration = require database_path('migrations/2026_10_08_000002_widen_marketing_content_columns_to_medium_text.php');
        $migration->up();
        $migration->up(); // 幂等：目标类型已就位时跳过，不报错

        $this->assertTrue(Schema::hasColumn('marketing_posts', 'content'));
        $this->assertTrue(Schema::hasColumn('marketing_favorites', 'content'));
        $this->assertSame('迁移前就存在的行', MarketingFavorite::find($id)->content); // 加宽不动数据

        // 列宽只有 MySQL 会强制（sqlite 把 mediumText 映射成 text 且 TEXT 无字节上限），
        // 故断言只在 MySQL 连接下生效；本类已在本机 MySQL 库上复跑过（见任务汇报）。
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }
        foreach (['marketing_posts', 'marketing_favorites'] as $table) {
            $type = DB::selectOne(
                'select data_type as data_type from information_schema.columns'
                .' where table_schema = database() and table_name = ? and column_name = ?',
                [$table, 'content']
            )?->data_type;
            $this->assertSame('mediumtext', strtolower((string) $type), "{$table}.content 应为 mediumtext");
        }
    }

    // ───────────────────────── 夹具 ─────────────────────────

    private function user(string $username, string $name, string $role = 'R_TEACHER'): User
    {
        return User::factory()->create([
            'username' => $username, 'name' => $name, 'role' => $role, 'venue' => '绿地店',
        ]);
    }

    private function seedFavorites(User $u, int $count, string $platform = '朋友圈'): void
    {
        $now = now()->format('Y-m-d H:i:s');
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'user_id' => $u->id, 'platform' => $platform,
                'title' => "收藏 {$i}", 'content' => "正文 {$i}",
                'reply' => null, 'cover_text' => null, 'tags' => json_encode([]),
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('marketing_favorites')->insert($chunk);
        }
    }

    private function seedHistory(User $u, int $count, string $platform = '朋友圈'): void
    {
        $now = now()->format('Y-m-d H:i:s');
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'user_id' => $u->id, 'platform' => $platform,
                'title' => "历史 {$i}", 'content' => "历史正文 {$i}",
                'reply' => null, 'source' => 'llm',
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('marketing_posts')->insert($chunk);
        }
    }
}
