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

        if (!$schema->hasTable('grating_daily_entries')) {
            return;
        }

        $schema->table('grating_daily_entries', function (Blueprint $table) use ($schema) {
            if (!$schema->hasColumn('grating_daily_entries', 'good_qty_pcs')) {
                $table->decimal('good_qty_pcs', 14, 3)->default(0)->after('bad_qty_kg');
            }
            if (!$schema->hasColumn('grating_daily_entries', 'bad_qty_pcs')) {
                $table->decimal('bad_qty_pcs', 14, 3)->default(0)->after('good_qty_pcs');
            }
            if (!$schema->hasColumn('grating_daily_entries', 'sqm_per_piece')) {
                $table->decimal('sqm_per_piece', 14, 6)->nullable()->after('bad_qty_pcs');
            }
            if (!$schema->hasColumn('grating_daily_entries', 'good_area_sqm')) {
                $table->decimal('good_area_sqm', 14, 3)->default(0)->after('sqm_per_piece');
            }
            if (!$schema->hasColumn('grating_daily_entries', 'bad_area_sqm')) {
                $table->decimal('bad_area_sqm', 14, 3)->default(0)->after('good_area_sqm');
            }
            if (!$schema->hasColumn('grating_daily_entries', 'mfg_site')) {
                $table->string('mfg_site', 20)->nullable()->after('mfg_no');
            }
            if (!$schema->hasColumn('grating_daily_entries', 'partnumber')) {
                $table->string('partnumber', 80)->nullable()->after('mfg_site');
            }
            if (!$schema->hasColumn('grating_daily_entries', 'part_description')) {
                $table->text('part_description')->nullable()->after('partnumber');
            }
            if (!$schema->hasColumn('grating_daily_entries', 'part_unit')) {
                $table->string('part_unit', 20)->nullable()->after('part_description');
            }
            if (!$schema->hasColumn('grating_daily_entries', 'width_mm')) {
                $table->decimal('width_mm', 14, 3)->nullable()->after('part_unit');
            }
            if (!$schema->hasColumn('grating_daily_entries', 'length_mm')) {
                $table->decimal('length_mm', 14, 3)->nullable()->after('width_mm');
            }
        });
    }

    public function down(): void
    {
        $connection = config('database.workflow_connection', 'sqlsrv_menam');
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('grating_daily_entries')) {
            return;
        }

        $columns = [
            'length_mm',
            'width_mm',
            'part_unit',
            'part_description',
            'partnumber',
            'mfg_site',
            'bad_area_sqm',
            'good_area_sqm',
            'sqm_per_piece',
            'bad_qty_pcs',
            'good_qty_pcs',
        ];

        $schema->table('grating_daily_entries', function (Blueprint $table) use ($schema, $columns) {
            foreach ($columns as $column) {
                if ($schema->hasColumn('grating_daily_entries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
