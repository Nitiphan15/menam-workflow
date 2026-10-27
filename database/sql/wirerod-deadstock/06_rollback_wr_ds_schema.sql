/*
  DESTRUCTIVE ROLLBACK. Disabled by default.
  It deletes all Wire Rod Dead Stock snapshots, reviews, overrides, audit logs, and access mappings.
  Set @ConfirmDestructiveRollback = 1 only after an approved backup and exact target verification.
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;

DECLARE @ConfirmDestructiveRollback BIT = 0;

IF @ConfirmDestructiveRollback <> 1
    THROW 51000, 'Rollback blocked. Review target, take backup, then explicitly set @ConfirmDestructiveRollback = 1.', 1;

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'dbo.user_dept_roles', N'U') IS NOT NULL
       AND OBJECT_ID(N'dbo.dept_roles', N'U') IS NOT NULL
    BEGIN
        DELETE assignment
        FROM dbo.user_dept_roles AS assignment
        INNER JOIN dbo.dept_roles AS role ON role.id = assignment.role_id
        WHERE role.code IN (N'WR_DS_EDIT_OWN', N'WR_DS_MANAGE_ALL', N'WR_DS_ADMIN');

        DELETE FROM dbo.dept_roles
        WHERE code IN (N'WR_DS_EDIT_OWN', N'WR_DS_MANAGE_ALL', N'WR_DS_ADMIN');
    END;

    DROP TABLE IF EXISTS dbo.wr_ds_sync_runs;
    DROP TABLE IF EXISTS dbo.wr_ds_user_sales_access;
    DROP TABLE IF EXISTS dbo.wr_ds_sales_overrides;
    DROP TABLE IF EXISTS dbo.wr_ds_compare_logs;
    DROP TABLE IF EXISTS dbo.wr_ds_item_review_logs;
    DROP TABLE IF EXISTS dbo.wr_ds_item_reviews;
    DROP TABLE IF EXISTS dbo.wr_ds_snapshot_items;
    DROP TABLE IF EXISTS dbo.wr_ds_snapshots;
    DROP TABLE IF EXISTS dbo.wr_ds_current_items;
    DROP TABLE IF EXISTS dbo.wr_ds_item_lifecycles;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;

SELECT N'Wire Rod Dead Stock schema rollback completed.' AS result;
