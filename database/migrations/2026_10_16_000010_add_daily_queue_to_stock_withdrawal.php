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
            'queue_date' => fn (Blueprint $table) => $table->date('queue_date')->nullable(),
            'original_queue_date' => fn (Blueprint $table) => $table->date('original_queue_date')->nullable(),
            'queue_position' => fn (Blueprint $table) => $table->unsignedInteger('queue_position')->default(0),
            'is_special' => fn (Blueprint $table) => $table->boolean('is_special')->default(false),
            'special_note' => fn (Blueprint $table) => $table->string('special_note', 500)->nullable(),
            'special_mail_sent_at' => fn (Blueprint $table) => $table->dateTime('special_mail_sent_at')->nullable(),
        ] as $column => $definition) {
            if (!$schema->hasColumn('stock_withdrawal_plan_items', $column)) {
                $schema->table('stock_withdrawal_plan_items', $definition);
            }
        }
        if (!$schema->hasColumn('stock_withdrawal_alert_logs', 'mail_type')) {
            $schema->table('stock_withdrawal_alert_logs', fn (Blueprint $table) => $table->string('mail_type', 20)->default('AUTO'));
        }
        DB::connection($this->connection)->statement("IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ix_stock_withdraw_queue_date_pos' AND object_id = OBJECT_ID('stock_withdrawal_plan_items')) CREATE INDEX ix_stock_withdraw_queue_date_pos ON stock_withdrawal_plan_items(queue_date, queue_position)");
        DB::connection($this->connection)->statement("UPDATE stock_withdrawal_plan_items SET queue_date = CONVERT(date, COALESCE(request_date, planned_withdraw_date, created_at, GETDATE())), original_queue_date = CONVERT(date, COALESCE(request_date, planned_withdraw_date, created_at, GETDATE())), queue_position = id WHERE queue_date IS NULL");
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('stock_withdrawal_alert_logs', fn (Blueprint $table) => $table->dropColumn('mail_type'));
        Schema::connection($this->connection)->table('stock_withdrawal_plan_items', function (Blueprint $table) {
            $table->dropIndex('ix_stock_withdraw_queue_date_pos');
            $table->dropColumn(['queue_date','original_queue_date','queue_position','is_special','special_note','special_mail_sent_at']);
        });
    }
};
