<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        if (!$schema->hasTable('machine_load_work_centers')) {
            $schema->create('machine_load_work_centers', function (Blueprint $table) {
                $table->id();
                $table->string('source_site', 10);
                $table->unsignedBigInteger('workcenter_id');
                $table->unsignedBigInteger('workmachine_id')->nullable();
                $table->string('display_name', 120)->nullable();
                $table->decimal('capacity_per_hour', 18, 4)->nullable();
                $table->decimal('work_hours_per_day', 8, 2)->nullable();
                $table->unsignedTinyInteger('work_days_per_week')->default(6);
                $table->decimal('cycle_time_minutes', 18, 4)->nullable();
                $table->decimal('setup_time_minutes', 18, 4)->nullable();
                $table->boolean('active')->default(true);
                $table->string('notes', 500)->nullable();
                $table->timestamps();

                $table->index(['source_site', 'workcenter_id']);
                $table->index(['source_site', 'workcenter_id', 'workmachine_id'], 'mla_wc_machine_idx');
            });
        }

        if (!$schema->hasTable('machine_load_holidays')) {
            $schema->create('machine_load_holidays', function (Blueprint $table) {
                $table->id();
                $table->string('source_site', 10);
                $table->date('holiday_date');
                $table->string('description', 255)->nullable();
                $table->timestamps();

                $table->unique(['source_site', 'holiday_date'], 'mla_holiday_site_date_unique');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));
        $schema->dropIfExists('machine_load_holidays');
        $schema->dropIfExists('machine_load_work_centers');
    }
};
