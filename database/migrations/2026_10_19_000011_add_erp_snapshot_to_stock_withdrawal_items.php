<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'sqlsrv_menam';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        foreach ([
            'erp_withdrawn_qty' => fn (Blueprint $table) => $table->decimal('erp_withdrawn_qty', 18, 4)->default(0),
            'erp_remaining_qty' => fn (Blueprint $table) => $table->decimal('erp_remaining_qty', 18, 4)->nullable(),
            'erp_status_code' => fn (Blueprint $table) => $table->string('erp_status_code', 20)->nullable(),
            'erp_first_work_center_number' => fn (Blueprint $table) => $table->string('erp_first_work_center_number', 50)->nullable(),
            'erp_first_work_center_name' => fn (Blueprint $table) => $table->string('erp_first_work_center_name', 255)->nullable(),
            'erp_synced_at' => fn (Blueprint $table) => $table->dateTime('erp_synced_at')->nullable(),
        ] as $column => $definition) {
            if (!$schema->hasColumn('stock_withdrawal_plan_items', $column)) {
                $schema->table('stock_withdrawal_plan_items', $definition);
            }
        }

        DB::connection($this->connection)->statement("IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ix_stock_withdraw_erp_status_queue' AND object_id = OBJECT_ID('stock_withdrawal_plan_items')) CREATE INDEX ix_stock_withdraw_erp_status_queue ON stock_withdrawal_plan_items(erp_status_code, queue_date) INCLUDE (erp_remaining_qty, erp_synced_at)");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ix_stock_withdraw_erp_status_queue' AND object_id = OBJECT_ID('stock_withdrawal_plan_items')) DROP INDEX ix_stock_withdraw_erp_status_queue ON stock_withdrawal_plan_items");
        Schema::connection($this->connection)->table('stock_withdrawal_plan_items', function (Blueprint $table) {
            $table->dropColumn(['erp_withdrawn_qty', 'erp_remaining_qty', 'erp_status_code', 'erp_first_work_center_number', 'erp_first_work_center_name', 'erp_synced_at']);
        });
    }
};
