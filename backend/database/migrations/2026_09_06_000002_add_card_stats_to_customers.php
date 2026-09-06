<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 会员有效卡汇总（同步时计算，阈值判定在运行时进行）：
        // countResidue=次卡剩余节数合计(含未开卡) countBound=次卡绑定总量(剩余+已用)
        // daysLeft/daysTotal=时间卡剩余天数/有效期天数合计
        Schema::table('customers', function (Blueprint $table) {
            $table->json('card_stats')->nullable()->after('card_paid_amount');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('card_stats');
        });
    }
};
