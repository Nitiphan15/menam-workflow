<?php

use App\Support\SqlServerDb;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'grating_daily_entries',
        'grating_entry_employees',
        'grating_entry_steps',
        'grating_entry_field_mfgs',
        'grating_employees',
        'grating_steps',
        'grating_field_activities',
        'grating_projects',
    ];

    public function up(): void
    {
        $schema = Schema::connection(SqlServerDb::connectionName());
        $backfillUserId = 73;

        foreach ($this->tables as $tableName) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }

            $schema->table($tableName, function (Blueprint $table) use ($schema, $tableName) {
                if (!$schema->hasColumn($tableName, 'created_by')) {
                    $table->unsignedBigInteger('created_by')->nullable();
                }

                if (!$schema->hasColumn($tableName, 'updated_by')) {
                    $table->unsignedBigInteger('updated_by')->nullable();
                }
            });

            SqlServerDb::table($tableName)
                ->whereNull('created_by')
                ->update(['created_by' => $backfillUserId]);

            SqlServerDb::table($tableName)
                ->whereNull('updated_by')
                ->update(['updated_by' => $backfillUserId]);
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(SqlServerDb::connectionName());

        foreach (array_reverse($this->tables) as $tableName) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }

            $schema->table($tableName, function (Blueprint $table) use ($schema, $tableName) {
                if ($schema->hasColumn($tableName, 'updated_by')) {
                    $table->dropColumn('updated_by');
                }

                if ($schema->hasColumn($tableName, 'created_by')) {
                    $table->dropColumn('created_by');
                }
            });
        }
    }
};
