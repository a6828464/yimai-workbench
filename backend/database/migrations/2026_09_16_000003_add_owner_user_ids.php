<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 归属列落地 user_id（按人隔离改以 id 为准）
 *
 * ## 为什么还要这一步
 *
 * 上一步（staff_aliases + staffNames）已经把"姓名 → 账号"变成可维护的映射，
 * 归属判断不再是裸字符串比较。但归属列的**物理形态还是姓名**，于是还剩两个软肋：
 *
 * 1. 账号改名时若忘了登记别名，历史数据就会断链 —— 靠代码纪律保证，数据库层面没有约束；
 * 2. 两个账号同名时（虽然业务上不该发生），姓名无法区分。
 *
 * 这里把 id 落到归属行上：**写入时就把 id 记下来**，id 不随改名变化，也不会撞车。
 *
 * ## 兼容策略（关键）
 *
 * 过滤一律是「id 命中 OR 姓名/别名命中」，姓名这一路**保留**：
 * - 回填漏掉的历史行（姓名对不上任何账号）仍能靠姓名命中；
 * - 外部系统写入、或本迁移之后新增的写入路径如果只写了姓名，也不会立刻掉数据。
 *
 * 也就是说这一步是**加固**，不是替换 —— 不会因为回填不全而让谁看不到自己的数据。
 *
 * 两个**不要**按姓名补 id 的例外（它们的 `created_by` 本来就是 user id，不是姓名）：
 * `body_test_reports.created_by`、`post_class_reviews.created_by`。
 * 前者写入的是 `$u->id`，后者列类型就是 bigint —— 按姓名去比会直接报类型错误。
 */
return new class extends Migration
{
    /** 补 id 列的表 → [姓名列 => user_id 列] */
    private const COLUMNS = [
        'leads' => [
            'service_teacher' => 'service_teacher_user_id',
            'trial_teacher' => 'trial_teacher_user_id',
            'created_by' => 'created_by_user_id',
        ],
        'customers' => [
            'consultant' => 'consultant_user_id',
            'owner' => 'owner_user_id',
        ],
        'ky_bookings' => [
            'teacher_name' => 'teacher_user_id',
        ],
        'tasks' => [
            'owner' => 'owner_user_id',
        ],
        'training_plans' => [
            'created_by' => 'created_by_user_id',
        ],
        // 注意：post_class_reviews 的姓名列是 teacher_name（对应已有的 teacher_user_id）；
        // 它的 created_by 本来就是 bigint（写的是 user id），不在此列
        'post_class_reviews' => [
            'teacher_name' => 'teacher_user_id',
        ],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $idColumn) {
                    if (! Schema::hasColumn($blueprint->getTable(), $idColumn)) {
                        $blueprint->unsignedBigInteger($idColumn)->nullable()->index();
                    }
                }
            });
        }

        $this->backfill();
    }

    /**
     * 按姓名（含别名）回填 id。
     *
     * 只认唯一命中：一个姓名同时对上多个账号时跳过 —— 这种情况本来就该由管理员到
     * 「人员管理 → 归属映射」里先把别名理清楚，凭猜测回填等于制造错误归属。
     */
    private function backfill(): void
    {
        $map = [];
        $ambiguous = [];

        foreach (DB::table('users')->orderBy('id')->get(['id', 'name']) as $u) {
            $name = trim((string) $u->name);
            if ($name === '') {
                continue;
            }
            if (isset($map[$name])) {
                $ambiguous[$name] = true;
            } else {
                $map[$name] = (int) $u->id;
            }
        }
        if (Schema::hasTable('staff_aliases')) {
            foreach (DB::table('staff_aliases')->orderBy('id')->get(['user_id', 'alias']) as $a) {
                $alias = trim((string) $a->alias);
                if ($alias === '') {
                    continue;
                }
                if (isset($map[$alias])) {
                    $ambiguous[$alias] = true;
                } else {
                    $map[$alias] = (int) $a->user_id;
                }
            }
        }
        foreach (array_keys($ambiguous) as $name) {
            unset($map[$name]);
        }

        $skipped = 0;
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $nameColumn => $idColumn) {
                if (! Schema::hasColumn($table, $nameColumn) || ! Schema::hasColumn($table, $idColumn)) {
                    continue;
                }

                // 按姓名分组批量更新：同一名字的行一次改掉，避免逐行 update
                $groups = DB::table($table)
                    ->select($nameColumn, DB::raw('count(*) as c'))
                    ->whereNotNull($nameColumn)
                    ->where($nameColumn, '!=', '')
                    ->groupBy($nameColumn)
                    ->get();

                foreach ($groups as $g) {
                    $name = trim((string) $g->{$nameColumn});
                    if ($name === '') {
                        continue;
                    }
                    if (! isset($map[$name])) {
                        // 对不上账号的（含歧义）留着：由「归属映射」面板提示管理员补别名，
                        // 姓名这一路过滤仍能让本人看到数据
                        $skipped += (int) $g->c;
                        continue;
                    }
                    DB::table($table)
                        ->where($nameColumn, $g->{$nameColumn})
                        ->whereNull($idColumn)
                        ->update([$idColumn => $map[$name]]);
                }
            }
        }

        if ($skipped > 0) {
            // 不阻断迁移：这些行的归属仍由「姓名 + 别名」兜住，只是暂时没有 id
            logger()->warning('归属 id 回填：有姓名对不上账号，已跳过', ['rows' => $skipped]);
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $idColumn) {
                    if (Schema::hasColumn($blueprint->getTable(), $idColumn)) {
                        $blueprint->dropColumn($idColumn);
                    }
                }
            });
        }
    }
};
