<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        if (!$schema->hasTable('ds_item_review_logs')) {
            return;
        }

        if (!$schema->hasColumn('ds_item_review_logs', 'company')) {
            $schema->table('ds_item_review_logs', function (Blueprint $table) {
                $table->string('company', 50)->nullable()->after('review_id');
            });
        }

        if (!$schema->hasColumn('ds_item_review_logs', 'serialnumber')) {
            $schema->table('ds_item_review_logs', function (Blueprint $table) {
                $table->string('serialnumber', 120)->nullable()->after('company');
                $table->index(['serialnumber'], 'ds_review_log_serial_idx');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        if (!$schema->hasTable('ds_item_review_logs')) {
            return;
        }

        if ($schema->hasColumn('ds_item_review_logs', 'serialnumber')) {
            $schema->table('ds_item_review_logs', function (Blueprint $table) {
                $table->dropIndex('ds_review_log_serial_idx');
                $table->dropColumn('serialnumber');
            });
        }

        if ($schema->hasColumn('ds_item_review_logs', 'company')) {
            $schema->table('ds_item_review_logs', function (Blueprint $table) {
                $table->dropColumn('company');
            });
        }
    }
};
