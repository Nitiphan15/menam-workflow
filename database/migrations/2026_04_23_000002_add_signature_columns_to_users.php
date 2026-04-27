<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::connection('sqlsrv_menam');

        if (!$connection->hasTable('users')) {
            return;
        }

        $connection->table('users', function (Blueprint $table) use ($connection) {
            if (!$connection->hasColumn('users', 'signature_path')) {
                $table->string('signature_path')->nullable();
            }

            if (!$connection->hasColumn('users', 'signature_uploaded_at')) {
                $table->dateTime('signature_uploaded_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        $connection = Schema::connection('sqlsrv_menam');

        if (!$connection->hasTable('users')) {
            return;
        }

        $connection->table('users', function (Blueprint $table) use ($connection) {
            if ($connection->hasColumn('users', 'signature_uploaded_at')) {
                $table->dropColumn('signature_uploaded_at');
            }

            if ($connection->hasColumn('users', 'signature_path')) {
                $table->dropColumn('signature_path');
            }
        });
    }
};
