<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        if (!$schema->hasTable('grating_employees')) {
            $schema->create('grating_employees', function (Blueprint $table) {
                $table->id();
                $table->string('employee_code', 40)->nullable()->index();
                $table->string('name', 160);
                $table->string('nickname', 80)->nullable();
                $table->string('responsible_work', 255)->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (!$schema->hasTable('grating_steps')) {
            $schema->create('grating_steps', function (Blueprint $table) {
                $table->id();
                $table->string('step_code', 40)->unique();
                $table->string('step_name', 160);
                $table->boolean('is_field_work')->default(false);
                $table->decimal('target_kg_per_hour', 12, 3)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(100);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (!$schema->hasTable('grating_daily_entries')) {
            $schema->create('grating_daily_entries', function (Blueprint $table) {
                $table->id();
                $table->date('work_date')->index();
                $table->string('mfg_no', 80)->index();
                $table->foreignId('step_id')->constrained('grating_steps')->cascadeOnDelete();
                $table->dateTime('started_at');
                $table->dateTime('finished_at')->nullable();
                $table->unsignedInteger('duration_minutes')->nullable();
                $table->decimal('good_qty_kg', 14, 3)->default(0);
                $table->decimal('bad_qty_kg', 14, 3)->default(0);
                $table->boolean('is_finished')->default(false);
                $table->boolean('is_field_work')->default(false);
                $table->string('field_activity', 120)->nullable();
                $table->text('field_details')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['work_date', 'step_id']);
                $table->index(['mfg_no', 'step_id']);
            });
        }

        if (!$schema->hasTable('grating_entry_employees')) {
            $schema->create('grating_entry_employees', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entry_id')->constrained('grating_daily_entries')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('grating_employees')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['entry_id', 'employee_id']);
                $table->index(['employee_id', 'entry_id']);
            });
        }

        if (!$schema->hasTable('grating_entry_steps')) {
            $schema->create('grating_entry_steps', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entry_id')->constrained('grating_daily_entries')->cascadeOnDelete();
                $table->foreignId('step_id')->constrained('grating_steps')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['entry_id', 'step_id']);
                $table->index(['step_id', 'entry_id']);
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        $schema->dropIfExists('grating_entry_employees');
        $schema->dropIfExists('grating_entry_steps');
        $schema->dropIfExists('grating_daily_entries');
        $schema->dropIfExists('grating_steps');
        $schema->dropIfExists('grating_employees');
    }
};
