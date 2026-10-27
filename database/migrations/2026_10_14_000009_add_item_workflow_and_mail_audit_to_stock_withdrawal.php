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
            $table->dateTime('planner_closed_at')->nullable()->after('remark');
            $table->unsignedBigInteger('planner_closed_by')->nullable()->after('planner_closed_at');
            $table->string('planner_close_reason', 500)->nullable()->after('planner_closed_by');
            $table->index('planner_closed_at', 'ix_stock_withdraw_item_planner_closed');
        });

        Schema::connection($this->connection)->table('stock_withdrawal_alert_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('sent_by')->nullable()->after('recipient');
        });

        Schema::connection($this->connection)->create('stock_withdrawal_alert_log_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_withdrawal_alert_log_id')->constrained('stock_withdrawal_alert_logs')->cascadeOnDelete();
            $table->foreignId('stock_withdrawal_plan_item_id')->constrained('stock_withdrawal_plan_items');
            $table->json('snapshot')->nullable();
            $table->timestamps();
            $table->unique(['stock_withdrawal_alert_log_id', 'stock_withdrawal_plan_item_id'], 'uq_stock_alert_log_item');
            $table->index('stock_withdrawal_plan_item_id', 'ix_stock_alert_item_plan_item');
        });

        Schema::connection($this->connection)->create('stock_withdrawal_item_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_withdrawal_plan_item_id')->constrained('stock_withdrawal_plan_items');
            $table->string('action', 30);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->dateTime('acted_at');
            $table->timestamps();
            $table->index(['stock_withdrawal_plan_item_id', 'acted_at'], 'ix_stock_item_action_time');
        });

        DB::connection($this->connection)->table('stock_withdrawal_plan_items')
            ->whereNull('job_type_code')->where('type_code', 'RM')
            ->update(['job_type_code' => 'RAW_MATERIAL', 'job_type_name' => 'วัตถุดิบ']);
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('stock_withdrawal_item_action_logs');
        Schema::connection($this->connection)->dropIfExists('stock_withdrawal_alert_log_items');
        Schema::connection($this->connection)->table('stock_withdrawal_alert_logs', fn(Blueprint $table) => $table->dropColumn('sent_by'));
        Schema::connection($this->connection)->table('stock_withdrawal_plan_items', function (Blueprint $table) {
            $table->dropIndex('ix_stock_withdraw_item_planner_closed');
            $table->dropColumn(['planner_closed_at', 'planner_closed_by', 'planner_close_reason']);
        });
    }
};
