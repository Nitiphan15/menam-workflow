<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'sqlsrv_menam';

    public function up(): void
    {
        Schema::connection($this->connection)->create('stock_withdrawal_standards', function (Blueprint $table) {
            $table->id(); $table->string('site', 10); $table->string('partnumber', 100);
            $table->string('description', 255)->nullable(); $table->unsignedSmallInteger('standard_days');
            $table->unsignedSmallInteger('warning_days')->default(2); $table->string('day_type', 20)->default('CALENDAR');
            $table->string('responsible_name', 150)->nullable(); $table->string('responsible_email', 255)->nullable();
            $table->date('effective_from'); $table->date('effective_to')->nullable(); $table->boolean('is_active')->default(true);
            $table->string('remark', 500)->nullable(); $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable(); $table->timestamps();
            $table->unique(['site', 'partnumber', 'effective_from'], 'uq_stock_standard_site_part_effective');
            $table->index(['partnumber', 'is_active'], 'ix_stock_standard_part_active');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('stock_withdrawal_standards');
    }
};
