/* Additive install only. Creates/reactivates the MG permission; assigns no users. */
SET NOCOUNT ON;
SET XACT_ABORT ON;

IF OBJECT_ID(N'dbo.dept_roles', N'U') IS NULL
    THROW 51000, 'Required table dbo.dept_roles was not found.', 1;

BEGIN TRY
    BEGIN TRANSACTION;

    IF NOT EXISTS (SELECT 1 FROM dbo.dept_roles WHERE UPPER(code) = N'MG')
    BEGIN
        INSERT INTO dbo.dept_roles (code, name, is_active)
        VALUES (N'MG', N'Invoice-MFG Gross Profit', 1);
    END;
    ELSE
    BEGIN
        UPDATE dbo.dept_roles
        SET name = N'Invoice-MFG Gross Profit',
            is_active = 1
        WHERE UPPER(code) = N'MG';
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;

SELECT id, code, name, is_active
FROM dbo.dept_roles
WHERE UPPER(code) = N'MG';
