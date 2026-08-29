<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dateTime('deal_at')->nullable()->after('deal_amount')->index();
            $table->dateTime('redeemed_at')->nullable()->after('redeem_amount')->index();
        });

        Schema::create('notification_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('notification_key');
            $table->timestamp('read_at');
            $table->unique(['user_id', 'notification_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_reads');
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['deal_at', 'redeemed_at']);
        });
    }
};
