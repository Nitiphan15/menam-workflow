<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_payment_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name_th', 200);
            $table->enum('schedule_type', ['end_of_month', 'fixed_day', 'immediate', 'custom']);
            $table->tinyInteger('payment_day')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_payment_schedules');
    }
};
