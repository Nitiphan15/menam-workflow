<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'sqlsrv_menam';

    public function up(): void
    {
        Schema::connection($this->connection)->create('delivery_plan_sales_submission_unlocks', function (Blueprint $table) {
            $table->id();
            $table->date('ship_posted_at');
            $table->unsignedBigInteger('mail_log_id');
            $table->dateTime('unlocked_at');
            $table->unsignedBigInteger('unlocked_by')->nullable();
            $table->string('unlocked_by_name', 255)->nullable();
            $table->string('remark', 500)->nullable();
            $table->timestamps();
            $table->index(['ship_posted_at', 'unlocked_at'], 'ix_dp_sales_unlock_ship_time');
            $table->unique('mail_log_id', 'ux_dp_sales_unlock_mail_log');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('delivery_plan_sales_submission_unlocks');
    }
};
