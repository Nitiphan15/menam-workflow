/*
    Refresh current rows from dbo.delivery_plan_data into dbo.delivery_plan_data_dev.

    Notes:
    - Both tables may be system-versioned temporal tables.
    - This script copies current rows only, not history rows.
    - Period/generated columns, computed columns, and rowversion/timestamp columns are not inserted.
    - Identity values are preserved when delivery_plan_data_dev has an identity column.
    - Live table schema/data is not changed.

    Run this whole file in one SSMS tab.
*/

USE [menam_workflow];
SET NOCOUNT ON;
SET XACT_ABORT ON;

IF OBJECT_ID(N'dbo.delivery_plan_data', N'U') IS NULL
BEGIN
    RAISERROR('Missing source table dbo.delivery_plan_data', 16, 1);
    RETURN;
END;

IF OBJECT_ID(N'dbo.delivery_plan_data_dev', N'U') IS NULL
BEGIN
    RAISERROR('Missing target table dbo.delivery_plan_data_dev', 16, 1);
    RETURN;
END;

BEGIN TRANSACTION;

DELETE FROM dbo.delivery_plan_data_dev;

DECLARE @live_object_id int = OBJECT_ID(N'dbo.delivery_plan_data');
DECLARE @dev_object_id int = OBJECT_ID(N'dbo.delivery_plan_data_dev');
DECLARE @cols nvarchar(max);
DECLARE @identity_cols nvarchar(max);
DECLARE @has_identity bit;
DECLARE @sql nvarchar(max);

SELECT @cols =
    STUFF((
        SELECT N',' + QUOTENAME(lc.name)
        FROM sys.columns lc
        INNER JOIN sys.columns dc
            ON dc.name = lc.name
        WHERE lc.object_id = @live_object_id
          AND dc.object_id = @dev_object_id
          AND lc.is_computed = 0
          AND dc.is_computed = 0
          AND lc.generated_always_type = 0
          AND dc.generated_always_type = 0
          AND lc.system_type_id <> 189
          AND dc.system_type_id <> 189
        ORDER BY lc.column_id
        FOR XML PATH(''), TYPE
    ).value('.', 'nvarchar(max)'), 1, 1, N'');

SELECT @identity_cols =
    STUFF((
        SELECT N',' + QUOTENAME(dc.name)
        FROM sys.columns dc
        INNER JOIN sys.columns lc
            ON lc.name = dc.name
        WHERE dc.object_id = @dev_object_id
          AND lc.object_id = @live_object_id
          AND dc.is_identity = 1
          AND lc.is_computed = 0
          AND dc.is_computed = 0
          AND lc.generated_always_type = 0
          AND dc.generated_always_type = 0
        ORDER BY dc.column_id
        FOR XML PATH(''), TYPE
    ).value('.', 'nvarchar(max)'), 1, 1, N'');

SET @has_identity = CASE WHEN @identity_cols IS NULL OR @identity_cols = N'' THEN 0 ELSE 1 END;

IF @cols IS NULL OR @cols = N''
BEGIN
    ROLLBACK TRANSACTION;
    RAISERROR('No matching insertable columns for delivery_plan_data -> delivery_plan_data_dev', 16, 1);
    RETURN;
END;

SET @sql = N'';

IF @has_identity = 1
    SET @sql = @sql + N'SET IDENTITY_INSERT dbo.delivery_plan_data_dev ON;';

SET @sql = @sql
    + N'INSERT INTO dbo.delivery_plan_data_dev (' + @cols + N') '
    + N'SELECT ' + @cols + N' FROM dbo.delivery_plan_data;';

IF @has_identity = 1
    SET @sql = @sql + N'SET IDENTITY_INSERT dbo.delivery_plan_data_dev OFF;';

EXEC sys.sp_executesql @sql;

COMMIT TRANSACTION;

SELECT COUNT_BIG(*) AS delivery_plan_data_dev_rows
FROM dbo.delivery_plan_data_dev;
