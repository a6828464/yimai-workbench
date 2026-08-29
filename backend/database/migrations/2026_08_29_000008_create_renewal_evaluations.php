<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('eval_level', 12)->nullable()->after('eval_score');
            $table->string('eval_by')->default('')->after('eval_at');
        });

        Schema::create('renewal_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->json('answers');
            $table->unsignedSmallInteger('score');
            $table->string('level', 12);
            $table->text('remark')->nullable();
            $table->foreignId('evaluator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('evaluator_name');
            $table->timestamp('evaluated_at');
            $table->timestamps();
            $table->index(['customer_id', 'evaluated_at']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('source_type', 32)->default('manual')->after('standard');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->string('review_role', 20)->nullable()->after('source_id');
            $table->index(['customer_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropIndex(['customer_id', 'status']);
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn(['source_type', 'source_id', 'review_role']);
        });
        Schema::dropIfExists('renewal_evaluations');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['eval_level', 'eval_by']);
        });
    }
};
