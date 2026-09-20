<?php

namespace Tests\Feature;

use App\Models\PublishedShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 归属/来源迁移的**升级路径**（不只是「能跑」）。
 *
 * 单测 migrate 只证明「迁移不报错」，证明不了它对存量数据做了什么。这里按
 * SchemaRepairMigrationTest 的做法直接驱动迁移类，覆盖两件事：
 *  1. 按姓名（含别名）唯一命中回填 created_by_user_id —— 升级后本人的历史分享
 *     立刻按 id 归属，不再依赖姓名串比较；
 *  2. 存量行一律标为 legacy、且 16hex 形态的销售行会被识别为「来源不可信」——
 *     这是「形似不等于可信」在数据层的前提。
 */
class PublishedShareMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        $migration = require database_path(
            'migrations/2026_09_21_000002_add_owner_and_source_to_published_shares.php'
        );
        $migration->up();
    }

    private function dropOwnershipColumns(): void
    {
        // SQLite 不允许直接 drop 带索引的列（报 "error in index ... after drop column"），
        // 所以先摘索引再摘列。生产是 MySQL 不需要这一步；这样做只是为了让「升级路径」
        // 能在 sqlite 测试库里被真实演练，而不是只覆盖空表建列。
        if (Schema::hasColumn('published_shares', 'created_by_user_id')) {
            Schema::table('published_shares', function ($t) {
                $t->dropIndex(['created_by_user_id']);
            });
        }
        foreach (['token_source', 'created_by_user_id'] as $col) {
            if (Schema::hasColumn('published_shares', $col)) {
                Schema::table('published_shares', fn ($t) => $t->dropColumn($col));
            }
        }
    }

    public function test_migration_adds_ownership_and_source_columns(): void
    {
        $this->dropOwnershipColumns();
        $this->assertFalse(Schema::hasColumn('published_shares', 'created_by_user_id'));
        $this->assertFalse(Schema::hasColumn('published_shares', 'token_source'));

        $this->runMigration();

        $this->assertTrue(Schema::hasColumn('published_shares', 'created_by_user_id'));
        $this->assertTrue(Schema::hasColumn('published_shares', 'token_source'));
    }

    public function test_backfills_user_id_by_name_and_marks_existing_rows_legacy(): void
    {
        $this->dropOwnershipColumns();

        $user = User::factory()->create(['name' => '王教练', 'role' => 'R_MANAGER']);

        // 升级前的存量行：只有姓名、没有 id、没有来源标记
        DB::table('published_shares')->insert([
            'type' => 'sales', 'token' => 'a1b2c3d4e5f60718',
            'payload' => json_encode(['share' => ['code' => 'a1b2c3d4e5f60718']]),
            'created_by' => '王教练', 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // 孤儿行：姓名对不上任何账号
        DB::table('published_shares')->insert([
            'type' => 'sales', 'token' => 'legacy-code',
            'payload' => json_encode([]),
            'created_by' => '查无此人', 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigration();

        // 唯一命中的姓名 → 回填 id
        $matched = DB::table('published_shares')->where('token', 'a1b2c3d4e5f60718')->first();
        $this->assertSame($user->id, (int) $matched->created_by_user_id);
        // 存量行一律 legacy（本服务端此前没有签发标记能力）
        $this->assertSame('legacy', $matched->token_source);

        // 对不上账号的行：不猜（id 保持 null），但同样标 legacy，且能被孤儿清单挑出来
        $orphan = DB::table('published_shares')->where('token', 'legacy-code')->first();
        $this->assertNull($orphan->created_by_user_id);
        $this->assertSame('legacy', $orphan->token_source);

        $orphans = \App\Http\Controllers\ShareController::orphanShares();
        $this->assertNotEmpty($orphans);
        $this->assertSame('查无此人', $orphans[0]['created_by']);
    }

    /**
     * 别名同样参与回填（与 staffNames 口径一致）：改过名/有别名的老师，
     * 其历史行的归属列写的是旧名，必须能对上。
     */
    public function test_backfill_resolves_aliases(): void
    {
        $this->dropOwnershipColumns();

        $user = User::factory()->create(['name' => '新名字', 'role' => 'R_MANAGER']);
        DB::table('staff_aliases')->insert([
            'user_id' => $user->id, 'alias' => '旧名字',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('published_shares')->insert([
            'type' => 'sales', 'token' => 'b1b2c3d4e5f60718',
            'payload' => json_encode([]),
            'created_by' => '旧名字', 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigration();

        $row = DB::table('published_shares')->where('token', 'b1b2c3d4e5f60718')->first();
        $this->assertSame($user->id, (int) $row->created_by_user_id, '别名必须参与回填');
    }

    /** 迁移可重入：重复执行不报错、不改变既有结论 */
    public function test_migration_is_idempotent(): void
    {
        $this->runMigration();
        $this->runMigration();

        $this->assertTrue(Schema::hasColumn('published_shares', 'created_by_user_id'));
        $this->assertTrue(Schema::hasColumn('published_shares', 'token_source'));
    }

    /** 存量 16hex 销售行会被识别为「来源不可信」，不因形似而放行 */
    public function test_legacy_hex_shaped_token_is_not_trusted(): void
    {
        $lookalike = 'deadbeefdeadbeef';
        PublishedShare::create([
            'type' => 'sales', 'token' => $lookalike, 'created_by' => '王教练',
            'payload' => [], 'enabled' => true, 'token_source' => 'legacy',
        ]);

        $this->getJson("/api/public/sales/{$lookalike}")->assertStatus(404);
    }

    /**
     * 上线前探测：迁移会把「已存在 16hex 形态的 sales 行」记为 warning。
     *
     * 这类行在本次迁移之前不可能由服务端签发（那时没有签发标记代码），
     * 因此要么是巧合、要么是有人猜中后长期有效的库存链接 —— 两种都需要人工确认。
     * 这里锁定「探测确实会报警」，否则升级时这段风险提示会静默失效。
     */
    public function test_migration_warns_when_hex_shaped_sales_rows_exist(): void
    {
        $this->dropOwnershipColumns();

        DB::table('published_shares')->insert([
            'type' => 'sales', 'token' => 'aabbccddeeff0011',
            'payload' => '{}', 'created_by' => '老王', 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // 非 16hex 的历史码（可猜常量形态）不应触发这条 warning
        DB::table('published_shares')->insert([
            'type' => 'sales', 'token' => 'yimai-lvdi',
            'payload' => '{}', 'created_by' => '老王', 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $warnings = [];
        Log::listen(function ($event) use (&$warnings) {
            $warnings[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });

        $this->runMigration();

        $hit = array_values(array_filter(
            $warnings,
            fn ($w) => str_contains((string) $w['message'], '16hex')
        ));
        $this->assertCount(1, $hit, '存量存在 16hex 销售行时必须告警');
        $this->assertSame('warning', $hit[0]['level']);
        $this->assertSame(1, (int) ($hit[0]['context']['rows'] ?? 0), '只应统计 16hex 形态的行');
        $this->assertSame('high', $hit[0]['context']['risk'] ?? null);

        // 探测不阻断迁移：列仍会建出来
        $this->assertTrue(Schema::hasColumn('published_shares', 'token_source'));
    }
}
