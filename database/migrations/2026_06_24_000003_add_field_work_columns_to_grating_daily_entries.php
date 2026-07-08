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
            if (!$schema->hasColumn('grating_daily_entries', 'is_field_work')) {
                $table->boolean('is_field_work')->default(false);
            }
            if (!$schema->hasColumn('grating_daily_entries', 'field_activity')) {
                $table->string('field_activity', 120)->nullable();
            }
            if (!$schema->hasColumn('grating_daily_entries', 'field_details')) {
                $table->text('field_details')->nullable();
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        if (!$schema->hasTable('grating_daily_entries')) {
            return;
        }

        $schema->table('grating_daily_entries', function (Blueprint $table) use ($schema) {
            if ($schema->hasColumn('grating_daily_entries', 'field_details')) {
                $table->dropColumn('field_details');
            }
            if ($schema->hasColumn('grating_daily_entries', 'field_activity')) {
                $table->dropColumn('field_activity');
            }
            if ($schema->hasColumn('grating_daily_entries', 'is_field_work')) {
                $table->dropColumn('is_field_work');
            }
        });
    }
};
