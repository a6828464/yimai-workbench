<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('nickname', 30)->nullable()->after('name');
        });
        Schema::table('app_settings', function (Blueprint $table) {
            $table->json('retention')->nullable()->after('sync_meta');
        });
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('operator_id')->nullable()->after('time')->index();
            $table->index(['time', 'id']);
            $table->index(['module', 'action', 'time']);
        });
        Schema::create('model_generation_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operator_name', 30);
            $table->string('operator_role', 20);
            $table->string('feature_type', 40)->default('chat');
            $table->string('source', 16)->default('llm');
            $table->string('provider', 80)->default('');
            $table->string('model', 120)->default('');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->string('input_summary', 300)->default('');
            $table->text('output_preview')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['created_at', 'id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['feature_type', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_generation_records');
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['module', 'action', 'time']);
            $table->dropIndex(['time', 'id']);
            $table->dropIndex(['operator_id']);
            $table->dropColumn('operator_id');
        });
        Schema::table('app_settings', fn (Blueprint $table) => $table->dropColumn('retention'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('nickname'));
    }
};
