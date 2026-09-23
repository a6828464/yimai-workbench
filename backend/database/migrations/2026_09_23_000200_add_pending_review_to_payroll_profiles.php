<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 薪酬档案「待完善」标记。
 *
 * ## 为什么需要这一列
 *
 * 用户要求「新增建档时，把已知信息填好，未知的留空由我完善」。问题在于
 * **留空的字段会被当成默认值参与算钱**：
 *
 *  - `role` 列有 `default('全职老师')` —— 身份标签未知的人会被静默当成全职老师；
 *  - `allowsBaseReward('全职老师')` 为真 ⇒ 两店累计有效课时 ≥80 就发 200~1000 元底薪奖励，
 *    **这笔钱完全不看档案金额**，只看实际课时，所以「底薪填 0」并不能防住它；
 *  - `allowsHourlyIncentive('')` 也为真 ⇒ 业绩过档还会算私教激励；
 *  - `allowsBaseSalary('')` 为真（只要不是「兼职老师」）⇒ 底薪会参与应发。
 *
 * 也就是说：**「字段留空」在现有实现里不等于「不参与计算」**。身份标签恰恰是
 * `PayrollRoles` 里所有分支的输入，猜错就是把钱算错人、算错数。
 *
 * ## 语义与失败方向
 *
 * `pending_review = true` 表示**该档案尚未确认，禁止参与工资计算**：
 * 计算时整行跳过，并进入 `calculate()` 的 `unavailable` 清单显式告知
 * （不是静默少人、更不是按默认身份标签算出一个看似合理的数）。
 *
 * 失败方向取「不算」而不是「按默认算」：两种方向的代价不对称 ——
 * 不算会被立刻发现（清单里有人、总额对不上），按默认算则可能多发或少发工资且无人察觉。
 *
 * ## 存量数据不受影响
 *
 * `default(false)`：现有 55 行（人员主档导入，身份标签与单价都已确认）全部保持
 * `false`，计算行为**逐字节不变**。只有「新增建档 / 系统预填」造出来的行才是 `true`。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_profiles', function (Blueprint $table) {
            // 待完善：档案已建但关键字段（身份标签/单价）未经确认，不得参与计算
            $table->boolean('pending_review')->default(false)->after('status');
            $table->index(['pending_review', 'venue']);
        });
    }

    public function down(): void
    {
        Schema::table('payroll_profiles', function (Blueprint $table) {
            $table->dropIndex(['pending_review', 'venue']);
            $table->dropColumn('pending_review');
        });
    }
};
