<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'enrolled_at')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->date('enrolled_at')->nullable()->after('last_visit')->index();
            });
        }

        if (! Schema::hasColumn('customers', 'visit_at')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->date('visit_at')->nullable()->after('enrolled_at');
            });
        }
    }

    public function down(): void
    {
        // Repair migrations are intentionally irreversible.
    }
};
