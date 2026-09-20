<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 对外分享快照补上「停用」开关。
 *
 * published_shares 原先只有 (type, token, payload)，物理上无法表达「已停用」：
 * 销售分享页把开关拨到停用时，后端记录原样保留，公开接口
 * GET /public/sales/{token} 依然把 payload（含未授权学员案例原文）下发出去。
 *
 * 形态选择（update.sh 升级失败时只回滚代码、不回退迁移）：
 *  - 附加列，不改动既有列：旧代码（不认识 enabled 列）继续按原样读写 published_shares，
 *    不会因为多了一列而失败，回滚代码后站点仍可正常提供服务；
 *  - 带默认值 true：迁移到线上时，既有记录（迁移前发布、且当时默认就是对外可见）
 *    语义保持不变，不会把线上正在使用的分享链接一次性全部变成 404。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('published_shares', 'enabled')) {
            return;
        }

        Schema::table('published_shares', function (Blueprint $table) {
            $table->boolean('enabled')->default(true)->after('token');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('published_shares', 'enabled')) {
            return;
        }

        Schema::table('published_shares', function (Blueprint $table) {
            $table->dropColumn('enabled');
        });
    }
};
