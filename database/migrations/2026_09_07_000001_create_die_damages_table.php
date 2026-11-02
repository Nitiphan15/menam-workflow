<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::connection(config('database.workflow_connection'))->create('die_damages', function (Blueprint $table) {
            $table->id();
            $table->string('site', 1);
            $table->date('damaged_at');
            $table->string('die_code', 100);
            foreach (['code', 'supplier', 'reason', 'fracture_type', 'die_type', 'machine', 'customer', 'workorder', 'wire_grade', 'material_supplier', 'material_problem'] as $field) {
                $table->string($field, 255)->nullable();
            }
            foreach (['initial_size', 'current_size', 'stock_size', 'production_kg', 'price', 'tensile_min', 'tensile_max', 'tensile_drawing', 'tensile_annealing'] as $field) {
                $table->decimal($field, 18, 4)->nullable();
            }
            $table->unsignedInteger('usage_count')->nullable();
            $table->text('notes')->nullable();
            $table->text('source_snapshot')->nullable();
            $table->string('created_by', 100);
            $table->string('updated_by', 100);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['damaged_at', 'site']);
            $table->index(['site', 'die_code']);
        });
    }

    public function down()
    {
        Schema::connection(config('database.workflow_connection'))->dropIfExists('die_damages');
    }
};
