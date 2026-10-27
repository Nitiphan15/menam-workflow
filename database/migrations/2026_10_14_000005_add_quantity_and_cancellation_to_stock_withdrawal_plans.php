<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'sqlsrv_menam';

    public function up(): void
    {
        Schema::connection($this->connection)->table('stock_withdrawal_plans', function (Blueprint $table) {
            $table->dateTime('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->index(['cancelled_at', 'site', 'workorder_id'], 'ix_stock_withdraw_plan_cancelled_mfg');
        });
        Schema::connection($this->connection)->table('stock_withdrawal_plan_items', function (Blueprint $table) {
            $table->decimal('withdraw_quantity', 18, 4)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('stock_withdrawal_plan_items', fn(Blueprint $table) => $table->dropColumn('withdraw_quantity'));
        Schema::connection($this->connection)->table('stock_withdrawal_plans', function (Blueprint $table) {
            $table->dropIndex('ix_stock_withdraw_plan_cancelled_mfg');
            $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancellation_reason']);
        });
    }
};
