<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_payment_terms', function (Blueprint $table) {
            $table->id();
            $table->string('erp_source', 20)->index();
            $table->string('customer_code', 255);
            $table->string('customer_name', 255);
            $table->string('erp_terms', 100)->nullable();
            $table->unsignedSmallInteger('credit_days')->default(0);
            $table->string('credit_term_code', 20)->nullable();
            $table->string('credit_term_detail', 500)->nullable();
            $table->foreignId('billing_plan_id')->nullable()->constrained('mst_billing_plans');
            $table->foreignId('payment_schedule_id')->nullable()->constrained('mst_payment_schedules');
            $table->tinyInteger('override_payment_day')->nullable();
            $table->text('remark')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['erp_source', 'customer_code'], 'customer_payment_terms_source_customer_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payment_terms');
    }
};
