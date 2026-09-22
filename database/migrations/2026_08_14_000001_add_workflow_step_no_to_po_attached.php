<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::connection('sqlsrv_menam');

        if (!$connection->hasTable('po_attached') || $connection->hasColumn('po_attached', 'workflow_step_no')) {
            return;
        }

        $connection->table('po_attached', function (Blueprint $table) {
            $table->unsignedInteger('workflow_step_no')->nullable();
        });
    }

    public function down(): void
    {
        $connection = Schema::connection('sqlsrv_menam');

        if ($connection->hasTable('po_attached') && $connection->hasColumn('po_attached', 'workflow_step_no')) {
            $connection->table('po_attached', function (Blueprint $table) {
                $table->dropColumn('workflow_step_no');
            });
        }
    }
};
