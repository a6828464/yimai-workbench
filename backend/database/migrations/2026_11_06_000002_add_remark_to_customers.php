<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 会员备注（`customers.remark`）。
 *
 * 为什么现在才加：整套系统里此前**没有**这个字段 ——
 *  - `leads.remark` 是「留资备注」（录客资时填的那句需求描述）；
 *  - `renewal_evaluations.remark` 是「续费评估的评语」；
 *  - 随心瑜同步侧也没有会员备注字段（唯一叫 remark 的在预约事实里，用于体验课判定）。
 * 馆主要在小程序会员详情顶部给员工看一句「这个人的注意事项」，所以新建一个。
 *
 * 为什么是 `text` 而不是 `varchar`：备注是自由文本（过敏史 / 伤病史 / 沟通忌讳 / 家属情况），
 * 写成 varchar 的话长度上限取决于 MySQL 版本与行格式，不如 text 省心；
 * 实际可写长度由接口层限制在 **500 字**（`PATCH /customers/{id}` 的 `max:500`）。
 *
 * 为什么 nullable 且不设默认值：NULL = 「从没写过备注」，与 `''`（写过又清空）在语义上
 * 不必区分（前端 `v-if="remark"` 两者都不显示），但保留 NULL 能让「有没有维护过」可查。
 *
 * 幂等：生产上出现过「列已加、后续步骤失败」的半完成状态，重跑必须安全。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers') && ! Schema::hasColumn('customers', 'remark')) {
            Schema::table('customers', function (Blueprint $t) {
                $t->text('remark')->nullable()->after('member_state');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'remark')) {
            Schema::table('customers', function (Blueprint $t) {
                $t->dropColumn('remark');
            });
        }
    }
};
