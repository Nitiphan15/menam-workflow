<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        if (!$schema->hasTable('ds_snapshot_months')) {
            $schema->create('ds_snapshot_months', function (Blueprint $table) {
                $table->id();
                $table->date('snapshot_month');
                $table->date('recv_date')->nullable();
                $table->date('as_of_date')->nullable();
                $table->dateTime('captured_at')->nullable();
                $table->string('source_name', 80)->default('mail-daily.report:deadstock');
                $table->string('status', 20)->default('draft');
                $table->unsignedInteger('item_count')->default(0);
                $table->decimal('total_qty', 18, 4)->default(0);
                $table->decimal('total_value', 18, 4)->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique('snapshot_month', 'ds_month_unique');
                $table->index(['status', 'snapshot_month'], 'ds_month_status_idx');
            });
        }

        if (!$schema->hasTable('ds_snapshot_items')) {
            $schema->create('ds_snapshot_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('snapshot_month_id');
                $table->string('item_key', 64);
                $table->string('company', 40)->nullable();
                $table->unsignedBigInteger('part_id')->nullable();
                $table->string('partnumber', 120)->nullable();
                $table->string('part_description', 500)->nullable();
                $table->string('transaction_number', 160)->nullable();
                $table->string('serialnumber', 160)->nullable();
                $table->date('purchase_date')->nullable();
                $table->dateTime('status_time')->nullable();
                $table->decimal('snapshot_qty', 18, 4)->default(0);
                $table->string('unit', 60)->nullable();
                $table->decimal('unitcost', 18, 4)->nullable();
                $table->decimal('snapshot_value', 18, 4)->default(0);
                $table->string('part_type_description', 255)->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('customer_name', 255)->nullable();
                $table->date('due_date')->nullable();
                $table->unsignedBigInteger('salesperson_id')->nullable();
                $table->string('salesperson_name', 255)->nullable();
                $table->string('deadstock_code', 40)->nullable();
                $table->string('deadstock_desc', 500)->nullable();
                $table->integer('days_diff')->nullable();
                $table->integer('days_overdue')->nullable();
                $table->boolean('dead_stock_flag')->default(true);
                $table->string('compare_status', 20)->default('pending');
                $table->decimal('current_qty', 18, 4)->nullable();
                $table->date('current_due_date')->nullable();
                $table->dateTime('last_checked_at')->nullable();
                $table->dateTime('cleared_at')->nullable();
                $table->timestamps();

                $table->unique(['snapshot_month_id', 'item_key'], 'ds_item_month_key_unique');
                $table->index(['snapshot_month_id', 'compare_status'], 'ds_item_compare_idx');
                $table->index(['company', 'salesperson_name'], 'ds_item_sales_idx');
            });
        }

        if (!$schema->hasTable('ds_item_reviews')) {
            $schema->create('ds_item_reviews', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('snapshot_item_id');
                $table->date('revised_due_date')->nullable();
                $table->text('corrective_action')->nullable();
                $table->text('preventive_action')->nullable();
                $table->text('sales_remark')->nullable();
                $table->string('review_status', 20)->default('open');
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->dateTime('reviewed_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique('snapshot_item_id', 'ds_review_item_unique');
                $table->index(['review_status', 'reviewed_at'], 'ds_review_status_idx');
            });
        }

        if (!$schema->hasTable('ds_item_compare_logs')) {
            $schema->create('ds_item_compare_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('snapshot_item_id');
                $table->dateTime('checked_at');
                $table->string('compare_status', 20);
                $table->decimal('matched_qty', 18, 4)->nullable();
                $table->date('matched_due_date')->nullable();
                $table->string('matched_deadstock_code', 40)->nullable();
                $table->text('note')->nullable();
                $table->timestamps();

                $table->index(['snapshot_item_id', 'checked_at'], 'ds_compare_item_time_idx');
                $table->index(['compare_status', 'checked_at'], 'ds_compare_status_time_idx');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        $schema->dropIfExists('ds_item_compare_logs');
        $schema->dropIfExists('ds_item_reviews');
        $schema->dropIfExists('ds_snapshot_items');
        $schema->dropIfExists('ds_snapshot_months');
    }
};
