<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 留资成交/核销金额支持小数点：
     * unsignedBigInteger -> decimal(12,2)，配合 /leads 校验由 integer 改为 numeric。
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->decimal('deal_amount', 12, 2)->nullable()->change();
            $t->decimal('redeem_amount', 12, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->unsignedBigInteger('deal_amount')->nullable()->change();
            $t->unsignedBigInteger('redeem_amount')->nullable()->change();
        });
    }
};
