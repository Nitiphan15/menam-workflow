<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::connection('sqlsrv_menam');

        if (!$connection->hasTable('po_headers')) {
            return;
        }

        $connection->table('po_headers', function (Blueprint $table) use ($connection) {
            if (!$connection->hasColumn('po_headers', 'pdf_description_overrides')) {
                $table->text('pdf_description_overrides')->nullable();
            }

            if (!$connection->hasColumn('po_headers', 'pdf_comments_override')) {
                $table->text('pdf_comments_override')->nullable();
            }
        });
    }

    public function down(): void
    {
        $connection = Schema::connection('sqlsrv_menam');

        if (!$connection->hasTable('po_headers')) {
            return;
        }

        $connection->table('po_headers', function (Blueprint $table) use ($connection) {
            foreach (['pdf_description_overrides', 'pdf_comments_override'] as $column) {
                if ($connection->hasColumn('po_headers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
