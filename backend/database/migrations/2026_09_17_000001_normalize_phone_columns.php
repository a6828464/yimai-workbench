<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 手机号归一：把 leads / customers 里带分隔符的历史号码改为纯数字
 *
 * 背景：全站的手机号比对（查重、身份去重、会员 360 关联、归属匹配）都按「纯数字」进行，
 * 上游同步入库的也是纯数字。但手工录入的留资可能写成 `138-0000-0001` / `138 0000 0001`，
 * 这类行在按数字查重时永远匹配不上 —— 结果是同一个人被反复录入成新客资。
 *
 * 写入侧已改为落库前归一（`LeadController::withNormalizedPhone` + 全站的 `normalizePhone()`），
 * 这里只补存量。逐条改写用 chunk 分批，避免在 2H2G 的小机器上长事务锁表；
 * 值不变则跳过，所以重复执行是安全的。
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([['leads', 'phone'], ['customers', 'phone']] as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $fixed = 0;
            DB::table($table)
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->orderBy('id')
                ->chunk(500, function ($rows) use ($table, $column, &$fixed) {
                    foreach ($rows as $row) {
                        $current = (string) $row->{$column};
                        $digits = preg_replace('/\D+/', '', $current) ?? '';
                        if ($digits === '' || $digits === $current) {
                            continue; // 已是纯数字（或全非数字，交给人工处理，不擅自清空）
                        }
                        DB::table($table)->where('id', $row->id)->update([$column => $digits]);
                        $fixed++;
                    }
                });

            if ($fixed > 0) {
                logger()->info('手机号归一完成', ['table' => $table, 'column' => $column, 'rows' => $fixed]);
            }
        }
    }

    public function down(): void
    {
        // 分隔符是录入时的噪音，归一后无需还原（还原反而会重新造成查重漏报）
    }
};
