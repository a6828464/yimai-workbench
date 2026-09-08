<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_jobs', function (Blueprint $table) {
            $table->string('display_name', 160)->nullable()->after('batch_no');
            $table->string('run_key', 80)->nullable()->unique()->after('display_name');
            $table->json('date_range')->nullable()->after('venue');
            $table->timestamp('started_at')->nullable()->after('status');
            $table->text('error_message')->nullable()->after('detail');
            $table->json('metadata')->nullable()->after('error_message');
        });

        Schema::create('sync_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_job_id')->nullable()->constrained('sync_jobs')->nullOnDelete();
            $table->string('artifact_type', 40);
            $table->string('display_name', 255);
            $table->string('disk', 30)->default('local');
            $table->string('path', 500)->unique();
            $table->string('mime', 100)->default('text/csv');
            $table->unsignedBigInteger('row_count')->default(0);
            $table->unsignedBigInteger('size')->default(0);
            $table->string('sha256', 64);
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->boolean('is_full')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['sync_job_id', 'artifact_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_artifacts');

        Schema::table('sync_jobs', function (Blueprint $table) {
            $table->dropUnique(['run_key']);
            $table->dropColumn([
                'display_name', 'run_key', 'date_range', 'started_at', 'error_message', 'metadata',
            ]);
        });
    }
};
