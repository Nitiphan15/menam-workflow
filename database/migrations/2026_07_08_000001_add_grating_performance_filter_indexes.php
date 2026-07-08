<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function connection()
    {
        return DB::connection(config('database.workflow_connection', 'sqlsrv_menam'));
    }

    public function up(): void
    {
        $db = $this->connection();

        $db->statement("
            IF OBJECT_ID('dbo.grating_daily_entries', 'U') IS NOT NULL
               AND COL_LENGTH('dbo.grating_daily_entries', 'salesorder') IS NOT NULL
               AND NOT EXISTS (
                    SELECT 1 FROM sys.indexes
                    WHERE name = 'IX_grating_daily_entries_work_date_salesorder'
                      AND object_id = OBJECT_ID('dbo.grating_daily_entries')
               )
            BEGIN
                CREATE INDEX IX_grating_daily_entries_work_date_salesorder
                    ON dbo.grating_daily_entries (work_date, salesorder);
            END
        ");

        $db->statement("
            IF OBJECT_ID('dbo.grating_daily_entries', 'U') IS NOT NULL
               AND COL_LENGTH('dbo.grating_daily_entries', 'project') IS NOT NULL
               AND NOT EXISTS (
                    SELECT 1 FROM sys.indexes
                    WHERE name = 'IX_grating_daily_entries_work_date_project_include'
                      AND object_id = OBJECT_ID('dbo.grating_daily_entries')
               )
            BEGIN
                CREATE INDEX IX_grating_daily_entries_work_date_project_include
                    ON dbo.grating_daily_entries (work_date)
                    INCLUDE (project);
            END
        ");
    }

    public function down(): void
    {
        $db = $this->connection();

        $db->statement("
            IF OBJECT_ID('dbo.grating_daily_entries', 'U') IS NOT NULL
               AND EXISTS (
                    SELECT 1 FROM sys.indexes
                    WHERE name = 'IX_grating_daily_entries_work_date_project_include'
                      AND object_id = OBJECT_ID('dbo.grating_daily_entries')
               )
            BEGIN
                DROP INDEX IX_grating_daily_entries_work_date_project_include
                    ON dbo.grating_daily_entries;
            END
        ");

        $db->statement("
            IF OBJECT_ID('dbo.grating_daily_entries', 'U') IS NOT NULL
               AND EXISTS (
                    SELECT 1 FROM sys.indexes
                    WHERE name = 'IX_grating_daily_entries_work_date_salesorder'
                      AND object_id = OBJECT_ID('dbo.grating_daily_entries')
               )
            BEGIN
                DROP INDEX IX_grating_daily_entries_work_date_salesorder
                    ON dbo.grating_daily_entries;
            END
        ");
    }
};
