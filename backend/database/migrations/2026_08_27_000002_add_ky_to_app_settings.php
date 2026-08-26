<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $t) {
            $t->json('ky')->nullable()->after('snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $t) {
            $t->dropColumn('ky');
        });
    }
};