<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 薪酬月度输入（考勤 / 社保 / 个税 / 调整项 / 活动月规则），一人一店一月一行。
 *
 * ## `social_security_mode` 三态 —— 本表存在的核心理由
 *
 * 用户原话：「社保也是手动输入，没有输入的，都按照 0 处理。社保可以设置成固定的。
 * 比如这个月设置了社保扣减，下个月如果没有操作，就默认按照这个执行。」
 *
 * 这是**状态继承**，不是**默认 0**，两者必须区分：
 *
 * | mode | 含义 | 计算取值 |
 * |---|---|---|
 * | `set` | 本月**显式**设定了金额（含显式 0） | 取本行 `social_security` |
 * | `off` | 本月**显式停缴** | 恒 0，且**打断**继承链 |
 * | `inherit` | 本月**未操作** | 按 (venue, user_id) 往更早月份找最近一条 mode∈{set,off} |
 *
 * 只有 `set` 与 `inherit` 两种状态的话，「这个月明确不缴」与「这个月还没录」会落成同一行，
 * 于是停缴之后下个月又会把历史非零值沿回来（规格 §4.2 点名的坑）。所以三态是必需的，
 * 不是冗余字段。`social_security` 也因此必须**可空**：`null`（未操作）与 `0`（显式不缴）
 * 是不同的业务事实。
 *
 * ## 为什么 `social_security_inherited_from` 不落库
 *
 * 「沿用的是哪个月」是**回退链的推导结果**，不是独立事实。落库会立刻产生两个真相源：
 * 历史行改了、已存下来的 `from` 不会跟着变。所以只在响应里现算（见 PayrollService::monthlyInputRows）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_monthly_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_profile_id')->constrained('payroll_profiles')->cascadeOnDelete();
            // 冗余存 user_id / venue：社保回退链要按 (venue, user_id) 倒序查，
            // 而这两个键正是业务语义里的键，冗余一份让查询不必回表 join
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('venue', 16)->default('');
            $table->string('month', 7); // YYYY-MM

            $table->decimal('attendance_days', 5, 2)->nullable();
            $table->decimal('personal_leave_hours', 6, 2)->nullable();
            $table->decimal('sick_leave_hours', 6, 2)->nullable();

            // ★ null（未操作）与 0（显式不缴）语义不同，故不加 default(0)
            $table->decimal('social_security', 10, 2)->nullable();
            $table->string('social_security_mode', 10)->default('inherit');
            $table->decimal('tax', 10, 2)->nullable();

            // 调整项（S1:126-150 的专项输入）。引擎按「调整项目」分派，
            // 本期无独立 UI，但公式需要，故同表承载
            $table->decimal('subsidy', 10, 2)->nullable();            // 新媒体/补贴调整
            $table->decimal('previous_adjustment', 10, 2)->nullable(); // 上月工资有误
            $table->decimal('other_deduction', 10, 2)->nullable();     // 其他扣款（含课程处罚）
            $table->decimal('fixed_salary_override', 10, 2)->nullable(); // 固定薪资折算
            $table->boolean('base_salary_zeroed')->default(false);     // 底薪归零
            $table->decimal('store_commission_addon', 10, 2)->nullable(); // 门店提成加项

            // 活动月激励规则（S1:100-110 要求来自当月输入，**禁止按月份硬编码**）
            $table->string('activity_type', 16)->nullable();      // 普通月|品牌月|周年庆
            $table->decimal('threshold_multiplier', 6, 2)->nullable();
            $table->string('activity_confirm_status', 16)->nullable(); // 已确认|未确认

            // 门店销售额人工确认覆盖（S1:111-115）
            $table->decimal('confirmed_store_sales', 12, 2)->nullable();
            $table->string('store_sales_confirm_status', 16)->nullable();

            $table->string('note', 200)->default('');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['venue', 'payroll_profile_id', 'month'], 'payroll_monthly_inputs_unique');
            // 社保回退链：按 (venue, user_id) + month 倒序取最近一条
            $table->index(['venue', 'user_id', 'month'], 'payroll_monthly_inputs_inherit_index');
            $table->index(['month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_monthly_inputs');
    }
};
