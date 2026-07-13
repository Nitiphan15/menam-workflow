-- FormGP SQL Server hotfix
-- 1) Add missing FormGP entry columns.
-- 2) Add audit columns created_by / updated_by.
-- 3) Backfill previous rows to user_id = 73 when audit columns are NULL.
-- 4) Create delete-log table for accidental delete recovery/audit.
--
-- Run on the workflow SQL Server database. Safe to run more than once.

DECLARE @backfillUserId BIGINT = 73;

IF OBJECT_ID(N'dbo.grating_daily_entries', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH(N'dbo.grating_daily_entries', N'plan_qty_pcs') IS NULL
        ALTER TABLE dbo.grating_daily_entries ADD plan_qty_pcs DECIMAL(12, 3) NULL;

    IF COL_LENGTH(N'dbo.grating_daily_entries', N'project') IS NULL
        ALTER TABLE dbo.grating_daily_entries ADD project NVARCHAR(500) NULL;

    IF COL_LENGTH(N'dbo.grating_daily_entries', N'salesorder') IS NULL
        ALTER TABLE dbo.grating_daily_entries ADD salesorder NVARCHAR(80) NULL;

    IF COL_LENGTH(N'dbo.grating_daily_entries', N'created_by') IS NULL
        ALTER TABLE dbo.grating_daily_entries ADD created_by BIGINT NULL;

    IF COL_LENGTH(N'dbo.grating_daily_entries', N'updated_by') IS NULL
        ALTER TABLE dbo.grating_daily_entries ADD updated_by BIGINT NULL;

    UPDATE dbo.grating_daily_entries
    SET
        created_by = COALESCE(created_by, @backfillUserId),
        updated_by = COALESCE(updated_by, @backfillUserId)
    WHERE created_by IS NULL OR updated_by IS NULL;
END;

IF OBJECT_ID(N'dbo.grating_entry_employees', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH(N'dbo.grating_entry_employees', N'created_by') IS NULL
        ALTER TABLE dbo.grating_entry_employees ADD created_by BIGINT NULL;

    IF COL_LENGTH(N'dbo.grating_entry_employees', N'updated_by') IS NULL
        ALTER TABLE dbo.grating_entry_employees ADD updated_by BIGINT NULL;

    UPDATE dbo.grating_entry_employees
    SET
        created_by = COALESCE(created_by, @backfillUserId),
        updated_by = COALESCE(updated_by, @backfillUserId)
    WHERE created_by IS NULL OR updated_by IS NULL;
END;

IF OBJECT_ID(N'dbo.grating_entry_steps', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH(N'dbo.grating_entry_steps', N'created_by') IS NULL
        ALTER TABLE dbo.grating_entry_steps ADD created_by BIGINT NULL;

    IF COL_LENGTH(N'dbo.grating_entry_steps', N'updated_by') IS NULL
        ALTER TABLE dbo.grating_entry_steps ADD updated_by BIGINT NULL;

    UPDATE dbo.grating_entry_steps
    SET
        created_by = COALESCE(created_by, @backfillUserId),
        updated_by = COALESCE(updated_by, @backfillUserId)
    WHERE created_by IS NULL OR updated_by IS NULL;
END;

IF OBJECT_ID(N'dbo.grating_entry_field_mfgs', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH(N'dbo.grating_entry_field_mfgs', N'created_by') IS NULL
        ALTER TABLE dbo.grating_entry_field_mfgs ADD created_by BIGINT NULL;

    IF COL_LENGTH(N'dbo.grating_entry_field_mfgs', N'updated_by') IS NULL
        ALTER TABLE dbo.grating_entry_field_mfgs ADD updated_by BIGINT NULL;

    UPDATE dbo.grating_entry_field_mfgs
    SET
        created_by = COALESCE(created_by, @backfillUserId),
        updated_by = COALESCE(updated_by, @backfillUserId)
    WHERE created_by IS NULL OR updated_by IS NULL;
END;

IF OBJECT_ID(N'dbo.grating_employees', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH(N'dbo.grating_employees', N'created_by') IS NULL
        ALTER TABLE dbo.grating_employees ADD created_by BIGINT NULL;

    IF COL_LENGTH(N'dbo.grating_employees', N'updated_by') IS NULL
        ALTER TABLE dbo.grating_employees ADD updated_by BIGINT NULL;

    UPDATE dbo.grating_employees
    SET
        created_by = COALESCE(created_by, @backfillUserId),
        updated_by = COALESCE(updated_by, @backfillUserId)
    WHERE created_by IS NULL OR updated_by IS NULL;
END;

IF OBJECT_ID(N'dbo.grating_steps', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH(N'dbo.grating_steps', N'created_by') IS NULL
        ALTER TABLE dbo.grating_steps ADD created_by BIGINT NULL;

    IF COL_LENGTH(N'dbo.grating_steps', N'updated_by') IS NULL
        ALTER TABLE dbo.grating_steps ADD updated_by BIGINT NULL;

    UPDATE dbo.grating_steps
    SET
        created_by = COALESCE(created_by, @backfillUserId),
        updated_by = COALESCE(updated_by, @backfillUserId)
    WHERE created_by IS NULL OR updated_by IS NULL;
END;

IF OBJECT_ID(N'dbo.grating_field_activities', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH(N'dbo.grating_field_activities', N'created_by') IS NULL
        ALTER TABLE dbo.grating_field_activities ADD created_by BIGINT NULL;

    IF COL_LENGTH(N'dbo.grating_field_activities', N'updated_by') IS NULL
        ALTER TABLE dbo.grating_field_activities ADD updated_by BIGINT NULL;

    UPDATE dbo.grating_field_activities
    SET
        created_by = COALESCE(created_by, @backfillUserId),
        updated_by = COALESCE(updated_by, @backfillUserId)
    WHERE created_by IS NULL OR updated_by IS NULL;
END;

IF OBJECT_ID(N'dbo.grating_projects', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH(N'dbo.grating_projects', N'created_by') IS NULL
        ALTER TABLE dbo.grating_projects ADD created_by BIGINT NULL;

    IF COL_LENGTH(N'dbo.grating_projects', N'updated_by') IS NULL
        ALTER TABLE dbo.grating_projects ADD updated_by BIGINT NULL;

    UPDATE dbo.grating_projects
    SET
        created_by = COALESCE(created_by, @backfillUserId),
        updated_by = COALESCE(updated_by, @backfillUserId)
    WHERE created_by IS NULL OR updated_by IS NULL;
END;

IF OBJECT_ID(N'dbo.grating_delete_logs', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.grating_delete_logs (
        id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        source_table NVARCHAR(80) NOT NULL,
        source_id NVARCHAR(80) NULL,
        entry_id BIGINT NULL,
        action NVARCHAR(40) NOT NULL CONSTRAINT DF_grating_delete_logs_action DEFAULT N'DELETE',
        payload_json NVARCHAR(MAX) NOT NULL,
        deleted_by BIGINT NULL,
        deleted_at DATETIME NOT NULL,
        created_at DATETIME NULL
    );

    CREATE INDEX IX_grating_delete_logs_source
        ON dbo.grating_delete_logs (source_table, source_id);

    CREATE INDEX IX_grating_delete_logs_entry
        ON dbo.grating_delete_logs (entry_id);

    CREATE INDEX IX_grating_delete_logs_deleted_by
        ON dbo.grating_delete_logs (deleted_by, deleted_at);
END;
