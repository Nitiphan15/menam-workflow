<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'sqlsrv_menam';
    public function up(): void
    {
        Schema::connection($this->connection)->table('stock_withdrawal_plans', function (Blueprint $table) {
            $table->string('fcat', 100)->nullable()->after('mfg');
        });
        Schema::connection($this->connection)->table('stock_withdrawal_plan_items', function (Blueprint $table) {
            $table->string('job_type_code', 30)->nullable()->after('type_name');
            $table->string('job_type_name', 100)->nullable()->after('job_type_code');
            $table->boolean('is_split')->default(false)->after('job_type_name');
            $table->text('split_quantities')->nullable()->after('withdraw_quantity');
        });
    }
    public function down(): void
    {
        Schema::connection($this->connection)->table('stock_withdrawal_plan_items', fn(Blueprint $table) => $table->dropColumn(['job_type_code','job_type_name','is_split','split_quantities']));
        Schema::connection($this->connection)->table('stock_withdrawal_plans', fn(Blueprint $table) => $table->dropColumn('fcat'));
    }
};
