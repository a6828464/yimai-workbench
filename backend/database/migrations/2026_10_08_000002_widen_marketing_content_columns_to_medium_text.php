<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 正文列宽修正：`text`（65535 字节）→ `mediumText`（16MB）。
 *
 * ## 根因（RV4 R2）
 *
 * 校验上限写的是 20000 **字符**（`max:20000`，Laravel 对字符串按 mb_strlen 计），
 * 而列是 `text` = 65535 **字节**。20000 个 emoji = 80000 字节 > 65535，在 MySQL
 * STRICT 模式下 INSERT 抛 1406（Data too long）→ **本该 4xx 的输入给了 500**，
 * 并在日志里刷真实异常。汉字同样被低估：20000 个汉字 = 60000 字节勉强过线，
 * 但「汉字 + emoji 混合」的长文案很容易越界（16000 汉字 + 2000 emoji ≈ 68000 字节）。
 *
 * ## 为什么加宽列，而不是加一条「按字节」的 422
 *
 * 20000 字符是对外唯一的声明上限；再加一条**用户看不见**的字节上限，会让
 * 「粘贴 20000 字符」在不同字符集下结果不一致（纯汉字能过、emoji 被拒），
 * 等于把「承诺与实现不一致」再造一处（本仓已吃过同类亏）。加宽列后，
 * 唯一的上限就是声明的 20000 字符，且留有余量。
 *
 * ## 两张表一起改
 *
 * `marketing_posts`（生成历史）与 `marketing_favorites`（收藏）同型，必须同口径 ——
 * 只修新表会留下「新表修了、老端点还坏」的不一致。**只改 `content`**：
 * `reply` / `cover_text` 的上限是 3000 字符 = 最多 12000 字节，`text` 足够。
 *
 * ## 幂等
 *
 * 按 `information_schema` 的 `data_type` 判断，已是 `mediumtext` 时直接跳过
 * （避免每次 `migrate` 都把 TEXT 表重建一遍）。sqlite 的 TEXT 本身无字节上限
 * （且 Laravel 把 mediumText 映射成 `text`），不存在这个面，故在 sqlite 上不动表结构
 * —— 真实列宽的验证见 `MarketingFavoriteTest` 里 driver-gated 的断言与 MySQL 复跑。
 */
return new class extends Migration
{
    /** @var string[] 同型的两张表，必须一起改 */
    private const TABLES = ['marketing_posts', 'marketing_favorites'];

    public function up(): void
    {
        $this->setContentType('mediumtext');
    }

    public function down(): void
    {
        // 收窄回 text：若表里已有 >65535 字节的行，MySQL STRICT 模式会直接报错中止回滚
        // （fail-closed，宁可不回滚也不静默截断用户文案）。
        $this->setContentType('text');
    }

    private function setContentType(string $target): void
    {
        // sqlite：TEXT 无字节上限，无此面（且 mediumText 会被映射成 text，改了等于没改）
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'content')) {
                continue;
            }
            $current = DB::selectOne(
                'select data_type as data_type from information_schema.columns'
                .' where table_schema = database() and table_name = ? and column_name = ?',
                [$table, 'content']
            )?->data_type;
            if (strtolower((string) $current) === $target) {
                continue; // 幂等：目标类型已是当前类型
            }

            Schema::table($table, function (Blueprint $t) use ($target): void {
                // NOT NULL 必须一并声明：change() 只保留显式给出的修饰符
                $target === 'mediumtext'
                    ? $t->mediumText('content')->change()
                    : $t->text('content')->change();
            });
        }
    }
};
