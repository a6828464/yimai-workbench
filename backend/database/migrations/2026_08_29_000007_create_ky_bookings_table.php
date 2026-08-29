<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ky_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('source_key')->unique();
            $table->string('venue', 10)->index();
            $table->string('booking_type', 10);
            $table->string('member_id')->default('');
            $table->string('member_name')->default('');
            $table->string('phone', 20)->default('');
            $table->dateTime('start_at')->nullable()->index();
            $table->string('course_name')->default('');
            $table->string('teacher_name')->default('');
            $table->string('status_raw', 30)->default('');
            $table->string('status', 20)->default('unknown')->index();
            $table->boolean('is_trial')->default(false)->index();
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->index(['venue', 'start_at', 'is_trial']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ky_bookings');
    }
};
