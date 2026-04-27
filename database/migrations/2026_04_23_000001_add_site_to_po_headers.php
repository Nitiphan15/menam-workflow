<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::connection('sqlsrv_menam');

        if (!$connection->hasTable('po_headers')) {
            return;
        }

        if (!$connection->hasColumn('po_headers', 'site')) {
            $connection->table('po_headers', function (Blueprint $table) {
                $table->string('site', 20)->nullable();
            });
        }

        DB::connection('sqlsrv_menam')
            ->table('po_headers')
            ->whereNull('site')
            ->update(['site' => 'wire']);
    }

    public function down(): void
    {
        $connection = Schema::connection('sqlsrv_menam');

        if ($connection->hasTable('po_headers') && $connection->hasColumn('po_headers', 'site')) {
            $connection->table('po_headers', function (Blueprint $table) {
                $table->dropColumn('site');
            });
        }
    }
};
