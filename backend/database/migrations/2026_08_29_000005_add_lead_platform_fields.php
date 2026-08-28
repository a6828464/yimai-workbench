<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('order_platform')->default('')->after('source');
            $table->string('wechat')->default('')->after('phone');
            $table->string('coupon_name')->default('')->after('voucher_code');
            $table->unsignedSmallInteger('coupon_total')->nullable()->after('coupon_name');
            $table->unsignedSmallInteger('coupon_remaining')->nullable()->after('coupon_total');
            $table->json('trial_cards')->nullable()->after('coupon_remaining');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['order_platform', 'wechat', 'coupon_name', 'coupon_total', 'coupon_remaining', 'trial_cards']);
        });
    }
};
