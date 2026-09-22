<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 业绩导入结果（一行 = 一条**归属**，不是一条交易）。
 *
 * ## 为什么是「归属行」而不是「交易行」
 *
 * 样表实测有 **3 行是一条交易同时归属两个销售员**（行 68 张芷晴 7350.5 + Nico 7350.5；
 * 行 97 娟子 10850 + Nico 10850；行 98 张芷晴 5425 + Nico 5425）。若按交易行存
 * （一行里塞两个销售员），要么丢一个归属、要么把 JSON 塞进列里查询不动。
 * 所以一拆二：**一条归属一行**，`source_row` 记录它来自样表第几行，同一行可以有多条。
 *
 * 这也是**幂等必须做「按 (门店,月份) 全量替换」而不是按行 upsert** 的原因：
 * 按行 upsert 无法表达「这条交易这次归属 2 人、下次归属 1 人」，会静默留下幽灵归属
 * （规格 §3.5）。
 *
 * ## 两套金额必须同时落库
 *
 * 生产引擎 S1:88-91 明确：`收款类型` 含「299活动卡」的行**不计个人提点业绩、
 * 也不计店长 2% / 蒙澍南 5% 的门店业绩**。样表 104/147 行是 299 活动卡，合计 31,096.00，
 * 不剔除会让个人业绩与门店销售额双双虚高。
 *
 * 但**原始口径也要留档**（用户要能核对「表上明明是 395,263.20」）：
 *
 * | 列 | 口径 | 用途 |
 * |---|---|---|
 * | `raw_amount` | 原始归属金额（含 299） | 核对与留档 |
 * | `commission_amount` | 剔除 299 后的提点金额 | 销售提成 |
 * | `store_sales_amount` | 剔除 299 后的订单金额（同一交易只计一次） | 门店提成基数 |
 *
 * ## 为什么金额是 `decimal(12,2)` 而不是 float
 *
 * 引擎逐笔 `ROUND_HALF_UP` 后累加（S1:15）。PHP 侧一律走 `App\Support\PayrollMoney`
 * 的「分」整数运算，落库用 decimal，**全链路不出现浮点累加** —— 否则 21,068.10
 * 会算成 21,068.08（规格 §3.6）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_performances', function (Blueprint $table) {
            $table->id();
            $table->string('venue', 16);
            $table->string('month', 7); // YYYY-MM

            // ---- 交易行（同一条交易的多条归属会重复这些列，便于按行自解释）----
            $table->string('transaction_key', 64)->default(''); // 指纹：日期|会员|金额|收款类型，用于「同一交易只计一次门店销售额」
            $table->date('occurred_on')->nullable();
            $table->string('member_name', 60)->default('');
            $table->string('payment_type', 60)->default('');   // 收款类型
            $table->string('payment_method', 40)->default(''); // 收款方式
            $table->decimal('transaction_amount', 12, 2)->default(0); // 该笔成交总额（**不是**归属金额）
            $table->boolean('is_activity_card')->default(false); // 收款类型含「299活动卡」

            // ---- 归属 ----
            // `personal` = 个人归属（销售员列）→ 销售提成
            // `venue`    = 会馆归属（会馆列非空）→ 门店整体业绩
            $table->string('allocation_type', 10);
            // 会馆归属行没有人员，故可空
            $table->foreignId('payroll_profile_id')->nullable()->constrained('payroll_profiles')->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            // 业绩表里写的列名（别名），如「苏米」；用于逐人核对
            $table->string('source_name', 60)->default('');
            // 解析出的真实姓名，如「罗柳柳」
            $table->string('resolved_name', 60)->default('');

            $table->decimal('raw_amount', 12, 2)->default(0);        // 原始归属（含 299）
            $table->decimal('commission_amount', 12, 2)->default(0); // 剔除 299 后（提点用）
            // 订单金额（剔 299 后）。只有该交易的第一条归属行持有，其余为 0，
            // 这样 `sum(store_sales_amount)` 天然等于门店销售额，不会因一行多归属而重复计
            $table->decimal('store_sales_amount', 12, 2)->default(0);

            // ---- 来源指纹（幂等判定与追溯）----
            $table->string('source_file_name', 160)->default('');
            $table->string('source_sha256', 64)->default('');
            $table->string('source_sheet', 60)->default('');
            $table->unsignedInteger('source_row')->default(0);

            $table->timestamps();

            $table->index(['venue', 'month'], 'payroll_performances_venue_month_index');
            $table->index(['payroll_profile_id', 'month'], 'payroll_performances_profile_month_index');
            $table->index(['venue', 'month', 'allocation_type'], 'payroll_performances_alloc_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_performances');
    }
};
