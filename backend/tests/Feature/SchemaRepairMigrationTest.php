<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaRepairMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_migration_restores_missing_customer_dates(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['enrolled_at']);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['enrolled_at', 'visit_at']);
        });

        $migration = require database_path('migrations/2026_09_09_000001_repair_customer_sync_columns.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumns('customers', ['enrolled_at', 'visit_at']));
    }
}
