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
            $table->unsignedBigInteger('source_part_id')->nullable()->after('stock_withdrawal_type_id');
            $table->string('partnumber', 100)->nullable()->after('source_part_id');
            $table->string('part_description', 255)->nullable()->after('partnumber');
            $table->string('size', 150)->nullable()->after('part_description');
            $table->decimal('quantity', 18, 4)->nullable()->after('size');
            $table->string('unit', 30)->nullable()->after('quantity');
            $table->index(['partnumber', 'type_code'], 'ix_stock_withdraw_item_part_type');
        });

        $db = DB::connection($this->connection);
        $now = now('Asia/Bangkok');
        $db->table('stock_withdrawal_types')->whereIn('code', ['RAW_MATERIAL', 'NCR'])
            ->update(['is_active' => 0, 'updated_at' => $now]);
        $db->table('stock_withdrawal_types')->where('code', 'FG')
            ->update(['name' => 'FG (Part F*)', 'updated_at' => $now]);
        $db->table('stock_withdrawal_types')->updateOrInsert(
            ['code' => 'RM', 'effective_from' => '2026-10-08'],
            ['name' => 'RM (Part R*)', 'lead_time_days' => 2, 'is_active' => 1, 'updated_at' => $now, 'created_at' => $now]
        );
    }

    public function down(): void
    {
        DB::connection($this->connection)->table('stock_withdrawal_types')
            ->where('code', 'RM')->where('effective_from', '2026-10-08')->delete();

        Schema::connection($this->connection)->table('stock_withdrawal_plan_items', function (Blueprint $table) {
            $table->dropIndex('ix_stock_withdraw_item_part_type');
            $table->dropColumn(['source_part_id', 'partnumber', 'part_description', 'size', 'quantity', 'unit']);
        });
    }
};
