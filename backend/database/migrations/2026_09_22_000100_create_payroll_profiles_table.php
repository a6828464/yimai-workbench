<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 薪酬档案（一人一行）＋ 档案别名。
 *
 * ## 为什么必须新建表，不能复用 users
 *
 * `users` 只有 `name / email / password / roles / venue`，**没有任何薪酬字段**；
 * 而且 `users.roles` 是**系统权限角色**（`R_SUPER` 等），与工资岗位（全职老师/专职老师/…）
 * 语义完全无关，不能复用。55 名薪酬人员里还有相当一部分（兼职老师、保洁）**没有登录账号**，
 * 所以 `user_id` 必须可空 —— 否则这些人根本无法维护档案，也无法算他们的工资。
 *
 * ## 两个容易踩的点
 *
 * 1. **存储层枚举 vs 展示层标签**：`role` 存的是**存储层枚举**（`馆主/店长/全职老师/…`）。
 *    引擎的 `岗位显示()` 会把 `馆主` 改写成「管理层」、把跨店的 `全职老师` 改写成
 *    「{所属门店}全职老师」—— 那是**展示**结果，回写进来会让 `role === '馆主'` 的判定全部失效。
 * 2. **`venue` 是「工资所属门店」不是「账号绑定门店」**：`users.venue` 的语义是账号绑哪家店，
 *    与发工资的归属在例外人员上并不一致（主档「特殊规则」实测：彭一桐的底薪/社保归东部店，
 *    而她实际是绿地店的兼职老师）。所以档案必须自带 `venue`，并允许与 `users.venue` 不同。
 *
 * ## 姓名别名为什么是档案上的一个 JSON 列
 *
 * `staff_aliases` 的 `user_id` 是**非空外键**，只覆盖有账号的人；而业绩表列名里
 * `苏米/娟子/芷晴/阿玉/婷婷/Nico/CC/Lily/小鹏` 这些别名指向的人未必都有账号，
 * 所以档案侧必须自己存一份。业绩导入的姓名解析因此走四个来源：
 * 「`payroll_profiles.name` ∪ `payroll_profiles.aliases` ∪ `users.name` ∪ `staff_aliases.alias`」，
 * 四者同一语义、只解析一次（见 PayrollService::nameMap()）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_profiles', function (Blueprint $table) {
            $table->id();
            // 可空：兼职老师/保洁等没有登录账号，但照样要发工资
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // 主档人员编号（如 YM-26CA807519），用于导入对账与幂等识别；主档外新增的人可为空
            $table->string('external_id', 32)->nullable();
            // 真实姓名。有账号时与 users.name 同步；无账号时这里是唯一姓名来源
            $table->string('name', 60);

            // 工资所属门店（不是账号绑定门店）
            $table->string('venue', 16)->default('');
            // 身份标签（存储层枚举，见 App\Support\PayrollRoles::ROLES）
            $table->string('role', 16)->default('全职老师');

            $table->decimal('base_salary', 10, 2)->default(0);
            $table->decimal('performance', 10, 2)->default(0);
            $table->decimal('fee_private60', 10, 2)->default(0);
            // 允许为 0：为 0 时引擎按 fee_private60 × 0.75 自动折算（S1:175）
            $table->decimal('fee_private45', 10, 2)->default(0);
            $table->decimal('fee_small', 10, 2)->default(0);
            $table->decimal('fee_group', 10, 2)->default(0);
            // 企业课：`ky_bookings` 无法区分企业课/总监私教/短期集训类，本期恒为 0，
            // 但保留列以便未来同步侧补分类后直接启用（规格 §6）
            $table->decimal('fee_enterprise', 10, 2)->default(0);

            // 双底薪例外（两店各发一份底薪）。仅 3 人可置真，白名单见 PayrollRoles::DUAL_BASE_SALARY_WHITELIST
            $table->boolean('dual_base_salary')->default(false);
            // 门店提成率覆盖：引擎里 `蒙澍南` 是按姓名硬编码的 5%，档案化后改为档案覆盖值，
            // 不再复制姓名硬编码（规格 §2.3）。为空时按身份标签取（店长 2%，其他 0）
            $table->decimal('store_commission_rate', 6, 4)->nullable();
            // 固定销售提成率覆盖：引擎里 `邓淼月` 是按姓名硬编码的固定 7%，
            // 档案化后与「专职老师固定 7%」同一条实现，只是数据不同
            $table->decimal('commission_fixed_rate', 6, 4)->nullable();

            // 姓名别名：存 JSON 数组（如 ["苏米","阿玉"]）。
            //
            // 为什么不单开一张别名表：`alias` 的全局唯一约束确实更适合用表来表达，
            // 但一张只有 48 行、且**只被一个人读**（薪酬姓名解析）的表，换来的代价是
            // 多一个模型、多一次 join、多一个写入路径。这里把唯一性校验放在写入侧
            // （PayrollController::syncAliases 明确报 AMBIGUOUS_NAME，不静默覆盖），
            // 读侧一次性加载进内存映射 —— 48 条的规模不值得为它建表。
            $table->json('aliases')->nullable();
            $table->string('status', 16)->default('有效');
            // 主档「重点提醒」原文（如「两店分别固定底薪5000，社保扣在绿地」）
            $table->string('alert', 200)->default('');
            // 主档「账户确认状态」原文（如「历史发薪/已知资料回填，付款前复核」）
            $table->string('account_status', 60)->default('');
            $table->string('note', 200)->default('');
            $table->timestamps();

            $table->unique('user_id');
            $table->unique('external_id');
            $table->index(['venue', 'role']);
            $table->index(['name']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_profiles');
    }
};
