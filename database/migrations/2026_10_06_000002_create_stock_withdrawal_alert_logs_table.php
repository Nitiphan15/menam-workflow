<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'sqlsrv_menam';

    public function up(): void
    {
        Schema::connection($this->connection)->create('stock_withdrawal_alert_logs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('run_at');
            $table->string('recipient', 255);
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('red_count')->default(0);
            $table->unsignedInteger('orange_count')->default(0);
            $table->unsignedInteger('yellow_count')->default(0);
            $table->unsignedInteger('gray_count')->default(0);
            $table->string('status', 30);
            $table->string('attachment_name', 255)->nullable();
            $table->text('error_message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['run_at', 'status'], 'ix_stock_alert_log_run_status');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('stock_withdrawal_alert_logs');
    }
};
