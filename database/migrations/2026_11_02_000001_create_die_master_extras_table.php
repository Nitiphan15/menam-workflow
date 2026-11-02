<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::connection(config('database.workflow_connection'))->create('die_master_extras', function (Blueprint $table) {
            $table->id();
            $table->string('site', 1);
            $table->string('equipnumber', 100);
            $table->date('received_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('updated_by', 100);
            $table->timestamps();
            $table->unique(['site', 'equipnumber']);
        });
    }

    public function down()
    {
        Schema::connection(config('database.workflow_connection'))->dropIfExists('die_master_extras');
    }
};
