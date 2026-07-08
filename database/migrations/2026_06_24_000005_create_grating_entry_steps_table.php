<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

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
        Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'))
            ->dropIfExists('grating_entry_steps');
    }
};
