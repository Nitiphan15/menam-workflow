<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_payment_term_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_payment_term_id')
                ->constrained('customer_payment_terms')
                ->cascadeOnDelete();
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->string('action', 20);
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('changed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payment_term_history');
    }
};
