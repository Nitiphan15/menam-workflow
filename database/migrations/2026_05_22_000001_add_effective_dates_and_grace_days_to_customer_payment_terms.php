<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_payment_terms', function (Blueprint $table) {
            $table->date('effective_from')->nullable()->after('erp_terms');
            $table->date('effective_to')->nullable()->after('effective_from');
            $table->unsignedSmallInteger('grace_days')->default(0)->after('credit_days');
        });
    }

    public function down(): void
    {
        Schema::table('customer_payment_terms', function (Blueprint $table) {
            $table->dropColumn(['effective_from', 'effective_to', 'grace_days']);
        });
    }
};
