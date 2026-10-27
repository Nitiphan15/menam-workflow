/*
  Copies only active Sales scope mappings from FG Deadstock when both tables exist.
  It does not grant Planning/Procurement/Admin roles because exact user accounts require approval.
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;

IF OBJECT_ID(N'dbo.wr_ds_user_sales_access', N'U') IS NULL
    THROW 51000, 'Run 01_create_wr_ds_tables.sql first.', 1;

IF OBJECT_ID(N'dbo.ds_user_sales_access', N'U') IS NULL
    THROW 51000, 'dbo.ds_user_sales_access was not found; prepare an approved explicit mapping list instead.', 1;

BEGIN TRY
    BEGIN TRANSACTION;

    MERGE dbo.wr_ds_user_sales_access WITH (HOLDLOCK) AS target
    USING
    (
        SELECT user_id, salesperson_key, can_edit, is_active
        FROM dbo.ds_user_sales_access
        WHERE is_active = 1
    ) AS source
    ON target.user_id = source.user_id
       AND target.salesperson_key = source.salesperson_key
    WHEN MATCHED THEN
        UPDATE SET
            can_edit = source.can_edit,
            is_active = source.is_active,
            updated_at = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (user_id, salesperson_key, can_edit, is_active, created_at, updated_at)
        VALUES (source.user_id, source.salesperson_key, source.can_edit, source.is_active, SYSDATETIME(), SYSDATETIME());

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;

SELECT u.username, a.salesperson_key, a.can_edit, a.is_active
FROM dbo.wr_ds_user_sales_access AS a
LEFT JOIN dbo.users AS u ON u.id = a.user_id
ORDER BY a.salesperson_key, u.username;

-- Planning/Procurement/Admin role assignment is intentionally absent.
-- Add users only after confirming exact usernames and intended role codes.
