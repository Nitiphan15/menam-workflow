<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        if (!$schema->hasTable('grating_daily_entries')) {
            return;
        }

        $schema->table('grating_daily_entries', function (Blueprint $table) use ($schema) {
            if ($schema->hasColumn('grating_daily_entries', 'created_by')) {
                $table->dropColumn('created_by');
            }
            if ($schema->hasColumn('grating_daily_entries', 'updated_by')) {
                $table->dropColumn('updated_by');
            }
        });
    }

    public function down(): void
    {
        // Intentionally not restoring user audit columns; this workflow is entered by department heads.
    }
};
