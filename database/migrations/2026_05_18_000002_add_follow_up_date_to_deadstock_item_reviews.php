<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        if ($schema->hasTable('ds_item_reviews') && !$schema->hasColumn('ds_item_reviews', 'next_follow_up_date')) {
            $schema->table('ds_item_reviews', function (Blueprint $table) {
                $table->date('next_follow_up_date')->nullable();
                $table->index(['next_follow_up_date', 'review_status'], 'ds_review_followup_idx');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        if ($schema->hasTable('ds_item_reviews') && $schema->hasColumn('ds_item_reviews', 'next_follow_up_date')) {
            $schema->table('ds_item_reviews', function (Blueprint $table) {
                $table->dropIndex('ds_review_followup_idx');
                $table->dropColumn('next_follow_up_date');
            });
        }
    }
};
