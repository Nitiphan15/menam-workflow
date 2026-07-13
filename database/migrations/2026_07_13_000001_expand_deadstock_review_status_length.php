<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        if (!$schema->hasTable('ds_item_reviews') || !$schema->hasColumn('ds_item_reviews', 'review_status')) {
            return;
        }

        $connection = DB::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        $connection->unprepared("
            IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ds_review_followup_idx' AND object_id = OBJECT_ID('dbo.ds_item_reviews'))
                DROP INDEX ds_review_followup_idx ON dbo.ds_item_reviews;

            IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ds_review_status_idx' AND object_id = OBJECT_ID('dbo.ds_item_reviews'))
                DROP INDEX ds_review_status_idx ON dbo.ds_item_reviews;

            ALTER TABLE dbo.ds_item_reviews
                ALTER COLUMN review_status nvarchar(50) NOT NULL;

            IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ds_review_status_idx' AND object_id = OBJECT_ID('dbo.ds_item_reviews'))
                CREATE INDEX ds_review_status_idx ON dbo.ds_item_reviews (review_status, reviewed_at);

            IF COL_LENGTH('dbo.ds_item_reviews', 'next_follow_up_date') IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ds_review_followup_idx' AND object_id = OBJECT_ID('dbo.ds_item_reviews'))
                CREATE INDEX ds_review_followup_idx ON dbo.ds_item_reviews (next_follow_up_date, review_status);
        ");
    }

    public function down(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        if (!$schema->hasTable('ds_item_reviews') || !$schema->hasColumn('ds_item_reviews', 'review_status')) {
            return;
        }

        $connection = DB::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        $connection->unprepared("
            IF NOT EXISTS (
                SELECT 1
                FROM dbo.ds_item_reviews
                WHERE LEN(review_status) > 20
            )
            BEGIN
                IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ds_review_followup_idx' AND object_id = OBJECT_ID('dbo.ds_item_reviews'))
                    DROP INDEX ds_review_followup_idx ON dbo.ds_item_reviews;

                IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ds_review_status_idx' AND object_id = OBJECT_ID('dbo.ds_item_reviews'))
                    DROP INDEX ds_review_status_idx ON dbo.ds_item_reviews;

                ALTER TABLE dbo.ds_item_reviews
                    ALTER COLUMN review_status nvarchar(20) NOT NULL;

                IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ds_review_status_idx' AND object_id = OBJECT_ID('dbo.ds_item_reviews'))
                    CREATE INDEX ds_review_status_idx ON dbo.ds_item_reviews (review_status, reviewed_at);

                IF COL_LENGTH('dbo.ds_item_reviews', 'next_follow_up_date') IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ds_review_followup_idx' AND object_id = OBJECT_ID('dbo.ds_item_reviews'))
                    CREATE INDEX ds_review_followup_idx ON dbo.ds_item_reviews (next_follow_up_date, review_status);
            END
        ");
    }
};
