<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `leads.referrer` —— 转介绍来源的**介绍人姓名**（S15 / 象限②-6）。
 *
 * ## 为什么是一列，而不是塞进 remark
 *
 * 规划文档给过两个选项：「`leads.remark` 或新增一列」。选新增列的理由：
 *
 *  1. `remark` 是**自由文本**（现在是店长的跟进笔记），从里面按正则捞介绍人
 *     等于把「统计」建在一次文本解析上 —— 换个写法就统计不到，且无法索引。
 *     ②-6 的验收是「能统计转介绍来源占比」，需要的是**可查询的结构化事实**。
 *  2. 介绍人是「人」，不是备注。它天然会出现在两个字符串位置：介绍人自己的
 *     会员档案、以及被介绍人这条留资上。留在字段里才有后续做关联的可能
 *     （③-3 的 `referrals` 关系表**本次刻意不做** —— 分佣是钱，需业务先定规则；
 *     本列先只做「记录」，为那张表留下迁移的起点）。
 *
 * ## 为什么可空、且不设外键
 *
 * 介绍人是**手填姓名**而非账号引用：老会员可能早已不再到店，也可能是非会员的
 * 熟人（朋友介绍）。加外键 / 强制存在性校验会把最真实的那部分转介绍堵在门外。
 * 空值语义是「本次不是转介绍，或没问出介绍人」——与「转介绍但没记名」不可区分，
 * 这是刻意接受的损失：不填比填错更有价值。
 *
 * ## 为什么是 50
 *
 * 与 `LeadController::referrerRules()` 的 `max:50` **必须同源**。50 是中文姓名
 * （含姓/名/曾用名拼接、或「张三（李四介绍）」这类变体）的宽松上限，也是本表
 * 其它姓名字段（`service_teacher` / `trial_teacher` 均 varchar(255)，但那是自由
 * 备注型字段）的收敛取值：介绍人是短标识，不该与备注共享列宽。
 * **改这个长度必须同步改 `referrerRules()`**，否则超长写入会被 DB 静默截断
 * （MySQL 非严格模式）—— 校验通过但数据已残。
 *
 * ## 幂等与回滚
 *
 * `hasColumn` 守卫让本迁移**可重复执行**（`migrate` 在部分失败的批次里可能重跑
 * 已成功的步骤）；`down()` 同样带守卫并摘列可逆。生产是 MySQL、测试是 SQLite，
 * 两者都支持本迁移用到的操作（无索引需先摘）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leads') || Schema::hasColumn('leads', 'referrer')) {
            return;
        }

        Schema::table('leads', function (Blueprint $t) {
            // 放在 source 之后：两者语义相邻（渠道 → 介绍人），
            // 便于用 SHOW COLUMNS 人工核查时一眼对上。
            $t->string('referrer', 50)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('leads') || ! Schema::hasColumn('leads', 'referrer')) {
            return;
        }

        Schema::table('leads', function (Blueprint $t) {
            $t->dropColumn('referrer');
        });
    }
};
