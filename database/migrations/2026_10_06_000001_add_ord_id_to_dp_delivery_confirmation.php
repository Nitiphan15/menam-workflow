<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('sqlsrv_menam');

        if ($schema->hasTable('dp_delivery_confirmation') && !$schema->hasColumn('dp_delivery_confirmation', 'ord_id')) {
            $schema->table('dp_delivery_confirmation', function (Blueprint $table) {
                $table->unsignedBigInteger('ord_id')->nullable();
                $table->index(
                    ['ord_id', 'site', 'mfg_no', 'id'],
                    'dp_delivery_confirmation_plan_item_index'
                );
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('sqlsrv_menam');

        if ($schema->hasTable('dp_delivery_confirmation') && $schema->hasColumn('dp_delivery_confirmation', 'ord_id')) {
            $schema->table('dp_delivery_confirmation', function (Blueprint $table) {
                $table->dropIndex('dp_delivery_confirmation_plan_item_index');
                $table->dropColumn('ord_id');
            });
        }
    }
};
