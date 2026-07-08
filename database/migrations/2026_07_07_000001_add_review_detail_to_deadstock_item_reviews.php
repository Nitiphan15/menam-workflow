<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        if ($schema->hasTable('ds_item_reviews') && !$schema->hasColumn('ds_item_reviews', 'review_detail')) {
            $schema->table('ds_item_reviews', function (Blueprint $table) {
                $table->text('review_detail')->nullable();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        if ($schema->hasTable('ds_item_reviews') && $schema->hasColumn('ds_item_reviews', 'review_detail')) {
            $schema->table('ds_item_reviews', function (Blueprint $table) {
                $table->dropColumn('review_detail');
            });
        }
    }
};
