<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'sqlsrv_menam';
    public function up(): void
    {
        Schema::connection($this->connection)->table('stock_withdrawal_plan_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('plan_section')->default(1)->after('job_type_name');
            $table->index(['plan_section', 'request_date'], 'ix_stock_withdraw_item_section_request');
        });
        DB::connection($this->connection)->table('stock_withdrawal_plan_items')
            ->where(function ($query) {
                $query->where('partnumber', 'like', 'F%')
                    ->orWhere('partnumber', 'like', 'N%')
                    ->orWhere('partnumber', 'like', 'S%');
            })->update(['plan_section' => 2]);
    }
    public function down(): void
    {
        Schema::connection($this->connection)->table('stock_withdrawal_plan_items', function (Blueprint $table) { $table->dropIndex('ix_stock_withdraw_item_section_request'); $table->dropColumn('plan_section'); });
    }
};
