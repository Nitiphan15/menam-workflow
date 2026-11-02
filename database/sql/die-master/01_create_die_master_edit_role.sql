/* Creates the role definition only. It intentionally assigns no user. */
SET NOCOUNT ON;
SET XACT_ABORT ON;

IF OBJECT_ID(N'dbo.dept_roles', N'U') IS NULL
    THROW 51000, 'Required table dbo.dept_roles was not found.', 1;

IF NOT EXISTS (SELECT 1 FROM dbo.dept_roles WHERE code = N'DIE_MASTER_EDIT')
    INSERT INTO dbo.dept_roles (code, name, is_active)
    VALUES (N'DIE_MASTER_EDIT', N'DIE Tracking - กรอกวันที่รับเข้าไดร์', 1);

SELECT id, code, name, is_active
FROM dbo.dept_roles
WHERE code = N'DIE_MASTER_EDIT';
