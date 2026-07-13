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
            foreach (['pdf_description_override_page', 'pdf_comments_override_page'] as $column) {
                if ($connection->hasColumn('po_headers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        $connection->table('po_headers', function (Blueprint $table) use ($connection) {
            if (!$connection->hasColumn('po_headers', 'pdf_description_override_pages')) {
                $table->string('pdf_description_override_pages', 100)->nullable();
            }

            if (!$connection->hasColumn('po_headers', 'pdf_comments_override_pages')) {
                $table->string('pdf_comments_override_pages', 100)->nullable();
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
            foreach (['pdf_description_override_pages', 'pdf_comments_override_pages'] as $column) {
                if ($connection->hasColumn('po_headers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        $connection->table('po_headers', function (Blueprint $table) use ($connection) {
            if (!$connection->hasColumn('po_headers', 'pdf_description_override_page')) {
                $table->smallInteger('pdf_description_override_page')->nullable();
            }

            if (!$connection->hasColumn('po_headers', 'pdf_comments_override_page')) {
                $table->smallInteger('pdf_comments_override_page')->nullable();
            }
        });
    }
};
