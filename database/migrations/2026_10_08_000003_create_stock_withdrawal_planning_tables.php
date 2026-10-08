<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'sqlsrv_menam';

    public function up(): void
    {
        Schema::connection($this->connection)->create('stock_withdrawal_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->unsignedSmallInteger('lead_time_days');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('remark', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['code', 'effective_from'], 'uq_stock_withdraw_type_effective');
        });

        Schema::connection($this->connection)->create('stock_withdrawal_plans', function (Blueprint $table) {
            $table->id();
            $table->string('site', 10);
            $table->unsignedBigInteger('workorder_id');
            $table->string('mfg', 100);
            $table->string('partnumber', 100)->nullable();
            $table->string('part_description', 255)->nullable();
            $table->string('size', 150)->nullable();
            $table->decimal('quantity', 18, 4)->nullable();
            $table->string('unit', 30)->nullable();
            $table->string('customer_name', 255)->nullable();
            $table->date('delivery_date')->nullable();
            $table->date('production_date');
            $table->text('mfg_note')->nullable();
            $table->text('plan_note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['site', 'mfg'], 'ix_stock_withdraw_plan_site_mfg');
            $table->index('production_date', 'ix_stock_withdraw_plan_production');
        });

        Schema::connection($this->connection)->create('stock_withdrawal_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_withdrawal_plan_id')->constrained('stock_withdrawal_plans')->cascadeOnDelete();
            $table->unsignedBigInteger('stock_withdrawal_type_id');
            $table->string('type_code', 30);
            $table->string('type_name', 100);
            $table->unsignedSmallInteger('lead_time_days');
            $table->date('recommended_withdraw_date');
            $table->date('planned_withdraw_date');
            $table->string('remark', 500)->nullable();
            $table->timestamps();
            $table->index(['planned_withdraw_date', 'type_code'], 'ix_stock_withdraw_item_date_type');
        });

        $now = now('Asia/Bangkok');
        DB::connection($this->connection)->table('stock_withdrawal_types')->insert([
            ['code' => 'RAW_MATERIAL', 'name' => 'เบิกวัตถุดิบ', 'lead_time_days' => 2, 'effective_from' => $now->toDateString(), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'FG', 'name' => 'เบิก FG', 'lead_time_days' => 3, 'effective_from' => $now->toDateString(), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'NCR', 'name' => 'เบิก NCR', 'lead_time_days' => 5, 'effective_from' => $now->toDateString(), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('stock_withdrawal_plan_items');
        Schema::connection($this->connection)->dropIfExists('stock_withdrawal_plans');
        Schema::connection($this->connection)->dropIfExists('stock_withdrawal_types');
    }
};
