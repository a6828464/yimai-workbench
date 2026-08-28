<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('ip', 45)->default('')->after('detail');
            $table->string('user_agent', 300)->default('')->after('ip');
        });

        Schema::table('sync_jobs', function (Blueprint $table) {
            $table->text('detail')->nullable()->after('fail_count');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['ip', 'user_agent']);
        });

        Schema::table('sync_jobs', function (Blueprint $table) {
            $table->dropColumn('detail');
        });
    }
};
