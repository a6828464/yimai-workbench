<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_settings') && ! Schema::hasColumn('app_settings', 'backup')) {
            Schema::table('app_settings', function (Blueprint $t) {
                $t->json('backup')->nullable()->after('retention');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('app_settings') && Schema::hasColumn('app_settings', 'backup')) {
            Schema::table('app_settings', function (Blueprint $t) {
                $t->dropColumn('backup');
            });
        }
    }
};
