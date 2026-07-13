-- SQL Server hotfix for FormGP save error:
-- Invalid column name 'plan_qty_pcs'.
--
-- Run on the workflow SQL Server database that owns dbo.grating_daily_entries.
-- The script is idempotent and matches Laravel migration:
-- 2026_06_30_000001_add_plan_project_salesorder_to_grating_entries.php

IF OBJECT_ID(N'dbo.grating_daily_entries', N'U') IS NULL
BEGIN
    RAISERROR(N'dbo.grating_daily_entries does not exist. Run the base FormGP migrations first.', 16, 1);
    RETURN;
END;

IF COL_LENGTH(N'dbo.grating_daily_entries', N'plan_qty_pcs') IS NULL
BEGIN
    ALTER TABLE dbo.grating_daily_entries
        ADD plan_qty_pcs DECIMAL(12, 3) NULL;
END;

IF COL_LENGTH(N'dbo.grating_daily_entries', N'project') IS NULL
BEGIN
    ALTER TABLE dbo.grating_daily_entries
        ADD project NVARCHAR(500) NULL;
END;

IF COL_LENGTH(N'dbo.grating_daily_entries', N'salesorder') IS NULL
BEGIN
    ALTER TABLE dbo.grating_daily_entries
        ADD salesorder NVARCHAR(80) NULL;
END;
