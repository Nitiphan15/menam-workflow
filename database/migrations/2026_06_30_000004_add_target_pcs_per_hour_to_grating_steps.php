<?php

use App\Support\SqlServerDb;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(SqlServerDb::connectionName());

        if (!$schema->hasTable('grating_steps')) {
            return;
        }

        $schema->table('grating_steps', function (Blueprint $table) use ($schema) {
            if (!$schema->hasColumn('grating_steps', 'target_pcs_per_hour')) {
                $table->decimal('target_pcs_per_hour', 12, 3)->nullable()->after('target_kg_per_hour');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(SqlServerDb::connectionName());

        if (!$schema->hasTable('grating_steps')) {
            return;
        }

        $schema->table('grating_steps', function (Blueprint $table) use ($schema) {
            if ($schema->hasColumn('grating_steps', 'target_pcs_per_hour')) {
                $table->dropColumn('target_pcs_per_hour');
            }
        });
    }
};
