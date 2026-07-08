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

        if (!$schema->hasTable('grating_daily_entries')) {
            return;
        }

        $schema->table('grating_daily_entries', function (Blueprint $table) use ($schema) {
            if (!$schema->hasColumn('grating_daily_entries', 'plan_qty_pcs')) {
                $table->decimal('plan_qty_pcs', 12, 3)->nullable()->after('bad_qty_pcs');
            }
            if (!$schema->hasColumn('grating_daily_entries', 'project')) {
                $table->string('project', 500)->nullable()->after('field_details');
            }
            if (!$schema->hasColumn('grating_daily_entries', 'salesorder')) {
                $table->string('salesorder', 80)->nullable()->after('project');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(SqlServerDb::connectionName());

        if (!$schema->hasTable('grating_daily_entries')) {
            return;
        }

        $columns = ['salesorder', 'project', 'plan_qty_pcs'];

        $schema->table('grating_daily_entries', function (Blueprint $table) use ($schema, $columns) {
            foreach ($columns as $column) {
                if ($schema->hasColumn('grating_daily_entries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
