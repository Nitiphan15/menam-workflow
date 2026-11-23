<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'sqlsrv_menam';

    public function up(): void
    {
        Schema::connection($this->connection)->create('delivery_plan_sales_submission_controls', function (Blueprint $table) {
            $table->id();
            $table->date('ship_posted_at');
            $table->string('action', 10);
            $table->unsignedBigInteger('mail_log_id')->nullable();
            $table->dateTime('controlled_at');
            $table->unsignedBigInteger('controlled_by')->nullable();
            $table->string('controlled_by_name', 255)->nullable();
            $table->string('remark', 500)->nullable();
            $table->timestamps();
            $table->index(['ship_posted_at', 'id'], 'ix_dp_sales_control_ship_id');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('delivery_plan_sales_submission_controls');
    }
};
