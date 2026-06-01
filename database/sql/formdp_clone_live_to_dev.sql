/*
    Refresh FormDP truck workflow tables into *_dev tables.

    Scope:
    - Does not touch delivery_plan_data or delivery_plan_data_dev.
    - Does not clone SO, WO, customer, or other reference tables.
    - Creates missing *_dev truck workflow tables from the live table structure.
    - Reloads *_dev data on every run.
    - Adds trip close columns to delivery_plan_truck_assign_dev when missing.

    Run this whole file in one SSMS tab.
*/

USE [menam_workflow];
SET NOCOUNT ON;
SET XACT_ABORT ON;

IF OBJECT_ID(N'tempdb..#formdp_clone_tables', N'U') IS NOT NULL
    DROP TABLE #formdp_clone_tables;

CREATE TABLE #formdp_clone_tables (
    sort_order int NOT NULL,
    live_table sysname NOT NULL,
    dev_table sysname NOT NULL
);

INSERT INTO #formdp_clone_tables (sort_order, live_table, dev_table)
VALUES
    (10, N'delivery_plan_mail_logs', N'delivery_plan_mail_logs_dev'),
    (20, N'delivery_plan_truck_master', N'delivery_plan_truck_master_dev'),
    (30, N'delivery_plan_truck_staff_master', N'delivery_plan_truck_staff_master_dev'),
    (40, N'delivery_plan_truck_staff_map', N'delivery_plan_truck_staff_map_dev'),
    (50, N'delivery_plan_truck_assign', N'delivery_plan_truck_assign_dev');

IF OBJECT_ID(N'dbo.delivery_plan_mail_logs_dev', N'U') IS NULL
    SELECT TOP (0) * INTO dbo.delivery_plan_mail_logs_dev FROM dbo.delivery_plan_mail_logs;

IF OBJECT_ID(N'dbo.delivery_plan_truck_master_dev', N'U') IS NULL
    SELECT TOP (0) * INTO dbo.delivery_plan_truck_master_dev FROM dbo.delivery_plan_truck_master;

IF OBJECT_ID(N'dbo.delivery_plan_truck_staff_master_dev', N'U') IS NULL
    SELECT TOP (0) * INTO dbo.delivery_plan_truck_staff_master_dev FROM dbo.delivery_plan_truck_staff_master;

IF OBJECT_ID(N'dbo.delivery_plan_truck_staff_map_dev', N'U') IS NULL
    SELECT TOP (0) * INTO dbo.delivery_plan_truck_staff_map_dev FROM dbo.delivery_plan_truck_staff_map;

IF OBJECT_ID(N'dbo.delivery_plan_truck_assign_dev', N'U') IS NULL
    SELECT TOP (0) * INTO dbo.delivery_plan_truck_assign_dev FROM dbo.delivery_plan_truck_assign;

IF COL_LENGTH(N'dbo.delivery_plan_truck_assign_dev', N'trip_no') IS NULL
    ALTER TABLE dbo.delivery_plan_truck_assign_dev
        ADD trip_no int NOT NULL
            CONSTRAINT DF_delivery_plan_truck_assign_dev_trip_no DEFAULT (1);

IF COL_LENGTH(N'dbo.delivery_plan_truck_assign_dev', N'closed_at') IS NULL
    ALTER TABLE dbo.delivery_plan_truck_assign_dev ADD closed_at datetime NULL;

IF COL_LENGTH(N'dbo.delivery_plan_truck_assign_dev', N'closed_by') IS NULL
    ALTER TABLE dbo.delivery_plan_truck_assign_dev ADD closed_by int NULL;

IF COL_LENGTH(N'dbo.delivery_plan_truck_assign_dev', N'closed_remark') IS NULL
    ALTER TABLE dbo.delivery_plan_truck_assign_dev ADD closed_remark nvarchar(500) NULL;

BEGIN TRANSACTION;

DELETE FROM dbo.delivery_plan_truck_assign_dev;
DELETE FROM dbo.delivery_plan_truck_staff_map_dev;
DELETE FROM dbo.delivery_plan_truck_staff_master_dev;
DELETE FROM dbo.delivery_plan_truck_master_dev;
DELETE FROM dbo.delivery_plan_mail_logs_dev;

DECLARE @live_table sysname;
DECLARE @dev_table sysname;
DECLARE @live_object_id int;
DECLARE @dev_object_id int;
DECLARE @cols nvarchar(max);
DECLARE @identity_cols nvarchar(max);
DECLARE @has_identity bit;
DECLARE @sql nvarchar(max);

DECLARE clone_cur CURSOR LOCAL FAST_FORWARD FOR
    SELECT live_table, dev_table
    FROM #formdp_clone_tables
    ORDER BY sort_order;

OPEN clone_cur;
FETCH NEXT FROM clone_cur INTO @live_table, @dev_table;

WHILE @@FETCH_STATUS = 0
BEGIN
    SET @live_object_id = OBJECT_ID(QUOTENAME(N'dbo') + N'.' + QUOTENAME(@live_table));
    SET @dev_object_id = OBJECT_ID(QUOTENAME(N'dbo') + N'.' + QUOTENAME(@dev_table));

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
            ORDER BY dc.column_id
            FOR XML PATH(''), TYPE
        ).value('.', 'nvarchar(max)'), 1, 1, N'');

    SET @has_identity = CASE WHEN @identity_cols IS NULL OR @identity_cols = N'' THEN 0 ELSE 1 END;

    IF @cols IS NULL OR @cols = N''
    BEGIN
        CLOSE clone_cur;
        DEALLOCATE clone_cur;
        ROLLBACK TRANSACTION;
        RAISERROR('No matching columns for %s -> %s', 16, 1, @live_table, @dev_table);
        RETURN;
    END;

    SET @sql = N'';

    IF @has_identity = 1
        SET @sql = @sql + N'SET IDENTITY_INSERT dbo.' + QUOTENAME(@dev_table) + N' ON;';

    SET @sql = @sql
        + N'INSERT INTO dbo.' + QUOTENAME(@dev_table) + N' (' + @cols + N') '
        + N'SELECT ' + @cols + N' FROM dbo.' + QUOTENAME(@live_table) + N';';

    IF @has_identity = 1
        SET @sql = @sql + N'SET IDENTITY_INSERT dbo.' + QUOTENAME(@dev_table) + N' OFF;';

    EXEC sys.sp_executesql @sql;

    FETCH NEXT FROM clone_cur INTO @live_table, @dev_table;
END;

CLOSE clone_cur;
DEALLOCATE clone_cur;

UPDATE dbo.delivery_plan_truck_assign_dev
SET trip_no = 1
WHERE trip_no IS NULL OR trip_no < 1;

COMMIT TRANSACTION;

DROP TABLE #formdp_clone_tables;

IF OBJECT_ID(N'dbo.delivery_plan_special_dispatch_dev', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.delivery_plan_special_dispatch_dev (
        id int IDENTITY(1,1) NOT NULL PRIMARY KEY,
        ord_id int NOT NULL,
        dispatch_type nvarchar(50) NOT NULL,
        status nvarchar(20) NOT NULL CONSTRAINT DF_delivery_plan_special_dispatch_dev_status DEFAULT (N'OPEN'),
        remark nvarchar(500) NULL,
        action_by int NULL,
        action_at datetime NOT NULL CONSTRAINT DF_delivery_plan_special_dispatch_dev_action_at DEFAULT (GETDATE()),
        closed_at datetime NULL,
        closed_by int NULL,
        close_remark nvarchar(500) NULL
    );
END;

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.delivery_plan_special_dispatch_dev')
      AND name = N'IX_delivery_plan_special_dispatch_dev_ord_open'
)
BEGIN
    CREATE INDEX IX_delivery_plan_special_dispatch_dev_ord_open
        ON dbo.delivery_plan_special_dispatch_dev (ord_id, status, dispatch_type);
END;

SELECT
    t.name AS dev_table,
    p.rows AS row_count
FROM sys.tables t
INNER JOIN sys.schemas s
    ON s.schema_id = t.schema_id
INNER JOIN sys.partitions p
    ON p.object_id = t.object_id
   AND p.index_id IN (0, 1)
WHERE s.name = N'dbo'
  AND t.name IN (
        N'delivery_plan_mail_logs_dev',
        N'delivery_plan_truck_master_dev',
        N'delivery_plan_truck_staff_master_dev',
        N'delivery_plan_truck_staff_map_dev',
        N'delivery_plan_truck_assign_dev',
        N'delivery_plan_special_dispatch_dev'
  )
ORDER BY t.name;
