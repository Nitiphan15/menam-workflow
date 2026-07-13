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
            $schema->create('ds_item_review_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('snapshot_item_id');
                $table->unsignedBigInteger('review_id')->nullable();
                $table->string('action', 20);
                $table->string('source', 30);
                $table->integer('import_row_number')->nullable();
                $table->text('changed_fields')->nullable();
                $table->text('before_values')->nullable();
                $table->text('after_values')->nullable();
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->string('changed_by_name', 150)->nullable();
                $table->dateTime('changed_at');
                $table->timestamps();

                $table->index(['snapshot_item_id', 'changed_at'], 'ds_review_log_item_time_idx');
                $table->index(['review_id', 'changed_at'], 'ds_review_log_review_time_idx');
                $table->index(['changed_by', 'changed_at'], 'ds_review_log_user_time_idx');
                $table->index(['source', 'changed_at'], 'ds_review_log_source_time_idx');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        if ($schema->hasTable('ds_item_review_logs')) {
            $schema->drop('ds_item_review_logs');
        }
    }
};
