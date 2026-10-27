/*
  Clear Stock Withdrawal transactional data only.

  PRESERVED:
    - dbo.stock_withdrawal_types
    - dbo.stock_withdrawal_standards
    - users / departments / dept_roles / user_dept_roles

  CLEARED:
    - dbo.stock_withdrawal_alert_log_items
    - dbo.stock_withdrawal_alert_logs
    - dbo.stock_withdrawal_item_action_logs
    - dbo.stock_withdrawal_plan_items
    - dbo.stock_withdrawal_plans

  SQL Server cannot TRUNCATE the parent tables while Foreign Keys exist.
  This script therefore uses ordered DELETE statements inside one transaction,
  then reseeds the identity values. Audit and mail history will be removed.

  STEP 1: Run with @Execute = 0 to preview counts.
  STEP 2: Back up the database.
  STEP 3: Change @Execute to 1 and run the whole script again.
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

DECLARE @Execute bit = 0; -- Change to 1 only after checking PREVIEW and backup.

IF OBJECT_ID(N'dbo.stock_withdrawal_alert_log_items', N'U') IS NULL
    THROW 51000, N'Missing table: dbo.stock_withdrawal_alert_log_items', 1;
IF OBJECT_ID(N'dbo.stock_withdrawal_alert_logs', N'U') IS NULL
    THROW 51001, N'Missing table: dbo.stock_withdrawal_alert_logs', 1;
IF OBJECT_ID(N'dbo.stock_withdrawal_item_action_logs', N'U') IS NULL
    THROW 51002, N'Missing table: dbo.stock_withdrawal_item_action_logs', 1;
IF OBJECT_ID(N'dbo.stock_withdrawal_plan_items', N'U') IS NULL
    THROW 51003, N'Missing table: dbo.stock_withdrawal_plan_items', 1;
IF OBJECT_ID(N'dbo.stock_withdrawal_plans', N'U') IS NULL
    THROW 51004, N'Missing table: dbo.stock_withdrawal_plans', 1;

SELECT N'PREVIEW' AS result, N'stock_withdrawal_alert_log_items' AS table_name, COUNT_BIG(*) AS rows_to_delete
FROM dbo.stock_withdrawal_alert_log_items
UNION ALL
SELECT N'PREVIEW', N'stock_withdrawal_alert_logs', COUNT_BIG(*)
FROM dbo.stock_withdrawal_alert_logs
UNION ALL
SELECT N'PREVIEW', N'stock_withdrawal_item_action_logs', COUNT_BIG(*)
FROM dbo.stock_withdrawal_item_action_logs
UNION ALL
SELECT N'PREVIEW', N'stock_withdrawal_plan_items', COUNT_BIG(*)
FROM dbo.stock_withdrawal_plan_items
UNION ALL
SELECT N'PREVIEW', N'stock_withdrawal_plans', COUNT_BIG(*)
FROM dbo.stock_withdrawal_plans;

SELECT N'PRESERVED' AS result, N'stock_withdrawal_types' AS table_name, COUNT_BIG(*) AS rows_preserved
FROM dbo.stock_withdrawal_types
UNION ALL
SELECT N'PRESERVED', N'stock_withdrawal_standards', COUNT_BIG(*)
FROM dbo.stock_withdrawal_standards;

IF @Execute = 0
BEGIN
    PRINT N'PREVIEW ONLY: no data was changed. Set @Execute = 1 after taking a backup.';
    RETURN;
END;

BEGIN TRY
    BEGIN TRANSACTION;

    -- Hold all five tables so no new withdrawal transaction can be inserted
    -- while the related rows are being cleared.
    DECLARE @LockCount bigint;
    SELECT @LockCount = COUNT_BIG(*) FROM dbo.stock_withdrawal_plans WITH (TABLOCKX, HOLDLOCK);
    SELECT @LockCount = COUNT_BIG(*) FROM dbo.stock_withdrawal_plan_items WITH (TABLOCKX, HOLDLOCK);
    SELECT @LockCount = COUNT_BIG(*) FROM dbo.stock_withdrawal_alert_logs WITH (TABLOCKX, HOLDLOCK);
    SELECT @LockCount = COUNT_BIG(*) FROM dbo.stock_withdrawal_alert_log_items WITH (TABLOCKX, HOLDLOCK);
    SELECT @LockCount = COUNT_BIG(*) FROM dbo.stock_withdrawal_item_action_logs WITH (TABLOCKX, HOLDLOCK);

    -- Child tables first, followed by their parents.
    DELETE FROM dbo.stock_withdrawal_alert_log_items WITH (TABLOCK);
    DELETE FROM dbo.stock_withdrawal_item_action_logs WITH (TABLOCK);
    DELETE FROM dbo.stock_withdrawal_alert_logs WITH (TABLOCK);
    DELETE FROM dbo.stock_withdrawal_plan_items WITH (TABLOCK);
    DELETE FROM dbo.stock_withdrawal_plans WITH (TABLOCK);

    IF EXISTS (SELECT 1 FROM dbo.stock_withdrawal_alert_log_items)
        THROW 51010, N'Clear failed: stock_withdrawal_alert_log_items still contains rows.', 1;
    IF EXISTS (SELECT 1 FROM dbo.stock_withdrawal_alert_logs)
        THROW 51011, N'Clear failed: stock_withdrawal_alert_logs still contains rows.', 1;
    IF EXISTS (SELECT 1 FROM dbo.stock_withdrawal_item_action_logs)
        THROW 51012, N'Clear failed: stock_withdrawal_item_action_logs still contains rows.', 1;
    IF EXISTS (SELECT 1 FROM dbo.stock_withdrawal_plan_items)
        THROW 51013, N'Clear failed: stock_withdrawal_plan_items still contains rows.', 1;
    IF EXISTS (SELECT 1 FROM dbo.stock_withdrawal_plans)
        THROW 51014, N'Clear failed: stock_withdrawal_plans still contains rows.', 1;

    DBCC CHECKIDENT (N'dbo.stock_withdrawal_alert_log_items', RESEED, 0) WITH NO_INFOMSGS;
    DBCC CHECKIDENT (N'dbo.stock_withdrawal_alert_logs', RESEED, 0) WITH NO_INFOMSGS;
    DBCC CHECKIDENT (N'dbo.stock_withdrawal_item_action_logs', RESEED, 0) WITH NO_INFOMSGS;
    DBCC CHECKIDENT (N'dbo.stock_withdrawal_plan_items', RESEED, 0) WITH NO_INFOMSGS;
    DBCC CHECKIDENT (N'dbo.stock_withdrawal_plans', RESEED, 0) WITH NO_INFOMSGS;

    COMMIT TRANSACTION;

    SELECT N'SUCCESS' AS result, N'Withdrawal transactions, mail history, and audit logs were cleared.' AS message;

    SELECT N'AFTER' AS result, N'stock_withdrawal_alert_log_items' AS table_name, COUNT_BIG(*) AS remaining_rows
    FROM dbo.stock_withdrawal_alert_log_items
    UNION ALL
    SELECT N'AFTER', N'stock_withdrawal_alert_logs', COUNT_BIG(*)
    FROM dbo.stock_withdrawal_alert_logs
    UNION ALL
    SELECT N'AFTER', N'stock_withdrawal_item_action_logs', COUNT_BIG(*)
    FROM dbo.stock_withdrawal_item_action_logs
    UNION ALL
    SELECT N'AFTER', N'stock_withdrawal_plan_items', COUNT_BIG(*)
    FROM dbo.stock_withdrawal_plan_items
    UNION ALL
    SELECT N'AFTER', N'stock_withdrawal_plans', COUNT_BIG(*)
    FROM dbo.stock_withdrawal_plans;

    SELECT N'PRESERVED' AS result, N'stock_withdrawal_types' AS table_name, COUNT_BIG(*) AS remaining_rows
    FROM dbo.stock_withdrawal_types
    UNION ALL
    SELECT N'PRESERVED', N'stock_withdrawal_standards', COUNT_BIG(*)
    FROM dbo.stock_withdrawal_standards;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;
    THROW;
END CATCH;
