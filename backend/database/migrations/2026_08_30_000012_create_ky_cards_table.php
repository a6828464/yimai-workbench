<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ky_cards', function (Blueprint $table) {
            $table->id();
            $table->string('source_key')->unique();
            $table->string('venue', 10)->index();
            $table->string('external_id', 64)->index();
            $table->string('card_title')->default('');
            $table->string('member_id', 64)->default('');
            $table->string('member_name')->default('');
            $table->string('phone', 20)->default('');
            $table->string('consultant_name')->default('');
            $table->decimal('deal_price', 12, 2)->default(0);
            $table->decimal('price', 12, 2)->default(0);
            $table->string('status', 20)->default('');
            $table->string('status_format', 20)->default('');
            $table->boolean('is_taste')->default(false);
            $table->date('sold_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ky_cards');
    }
};
