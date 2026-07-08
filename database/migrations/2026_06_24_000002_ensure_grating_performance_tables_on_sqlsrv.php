<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $migration = include database_path('migrations/2026_06_24_000001_create_grating_performance_tables.php');
        $migration->up();
    }

    public function down(): void
    {
        // The primary migration owns the table drop order.
    }
};
