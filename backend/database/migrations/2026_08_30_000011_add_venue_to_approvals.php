<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->string('venue', 10)->default('双店')->after('applicant');
        });

        $venues = DB::table('users')->whereNotNull('venue')->where('venue', '!=', '')->pluck('venue', 'name');
        foreach ($venues as $name => $venue) {
            DB::table('approvals')->where('applicant', $name)->update(['venue' => $venue]);
        }
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropColumn('venue');
        });
    }
};
