<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('database.workflow_connection', 'sqlsrv_menam');
        $schema = Schema::connection($connection);

        if ($schema->hasTable('machine_load_work_centers')) {
            $schema->table('machine_load_work_centers', function (Blueprint $table) use ($schema) {
                if (!$schema->hasColumn('machine_load_work_centers', 'updated_by')) {
                    $table->string('updated_by', 64)->nullable()->after('notes');
                }
                if (!$schema->hasColumn('machine_load_work_centers', 'updated_by_at')) {
                    $table->dateTime('updated_by_at')->nullable()->after('updated_by');
                }
            });

            try {
                $schema->table('machine_load_work_centers', function (Blueprint $table) {
                    $table->unique(
                        ['source_site', 'workcenter_id', 'workmachine_id'],
                        'mla_wc_machine_unique'
                    );
                });
            } catch (\Throwable $e) {
                // unique อาจตั้งไม่ได้เพราะมี null ใน workmachine_id หรือซ้ำอยู่แล้ว — ข้าม
            }
        }
    }

    public function down(): void
    {
        $connection = config('database.workflow_connection', 'sqlsrv_menam');
        $schema = Schema::connection($connection);

        if ($schema->hasTable('machine_load_work_centers')) {
            try {
                $schema->table('machine_load_work_centers', function (Blueprint $table) {
                    $table->dropUnique('mla_wc_machine_unique');
                });
            } catch (\Throwable $e) {
            }

            $schema->table('machine_load_work_centers', function (Blueprint $table) use ($schema) {
                if ($schema->hasColumn('machine_load_work_centers', 'updated_by_at')) {
                    $table->dropColumn('updated_by_at');
                }
                if ($schema->hasColumn('machine_load_work_centers', 'updated_by')) {
                    $table->dropColumn('updated_by');
                }
            });
        }
    }
};
