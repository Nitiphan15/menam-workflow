/* Creates role definitions only. It intentionally assigns no user. */
SET NOCOUNT ON;
SET XACT_ABORT ON;

IF OBJECT_ID(N'dbo.dept_roles', N'U') IS NULL
    THROW 51000, 'Required table dbo.dept_roles was not found.', 1;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @roles TABLE
    (
        code NVARCHAR(50) NOT NULL,
        name NVARCHAR(150) NOT NULL
    );

    INSERT INTO @roles (code, name)
    VALUES
        (N'WR_DS_EDIT_OWN', N'Wire Rod Dead Stock - แก้ไขตาม Sales Mapping'),
        (N'WR_DS_MANAGE_ALL', N'Wire Rod Dead Stock - Planning/Procurement จัดการทั้งหมด'),
        (N'WR_DS_ADMIN', N'Wire Rod Dead Stock - จัดการสิทธิ์และ Override');

    INSERT INTO dbo.dept_roles (code, name, is_active)
    SELECT r.code, r.name, 1
    FROM @roles AS r
    WHERE NOT EXISTS
    (
        SELECT 1 FROM dbo.dept_roles AS existing WHERE existing.code = r.code
    );

    UPDATE target
    SET target.name = source.name,
        target.is_active = 1
    FROM dbo.dept_roles AS target
    INNER JOIN @roles AS source ON source.code = target.code;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;

SELECT id, code, name, is_active
FROM dbo.dept_roles
WHERE code IN (N'WR_DS_EDIT_OWN', N'WR_DS_MANAGE_ALL', N'WR_DS_ADMIN')
ORDER BY code;
