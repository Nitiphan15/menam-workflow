<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'sqlsrv_menam';
    public function up(): void
    {
        Schema::connection($this->connection)->table('stock_withdrawal_plans', function (Blueprint $table) {
            $table->unsignedTinyInteger('plan_section')->default(1)->after('fcat');
            $table->string('priority', 20)->default('NORMAL')->after('plan_section');
            $table->date('original_delivery_date')->nullable()->after('delivery_date');
            $table->index(['plan_section', 'priority'], 'ix_stock_withdraw_plan_section_priority');
        });
        Schema::connection($this->connection)->table('stock_withdrawal_plan_items', function (Blueprint $table) {
            $table->date('request_date')->nullable()->after('lead_time_days');
            $table->index('request_date', 'ix_stock_withdraw_item_request_date');
        });
    }
    public function down(): void
    {
        Schema::connection($this->connection)->table('stock_withdrawal_plan_items', function (Blueprint $table) { $table->dropIndex('ix_stock_withdraw_item_request_date'); $table->dropColumn('request_date'); });
        Schema::connection($this->connection)->table('stock_withdrawal_plans', function (Blueprint $table) { $table->dropIndex('ix_stock_withdraw_plan_section_priority'); $table->dropColumn(['plan_section','priority','original_delivery_date']); });
    }
};
