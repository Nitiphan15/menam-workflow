/*
Rollback removes MG only when nobody is assigned to it.
Review the verification result before manual execution.
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;

IF EXISTS
(
    SELECT 1
    FROM dbo.user_dept_roles AS assignment
    INNER JOIN dbo.dept_roles AS role ON role.id = assignment.role_id
    WHERE UPPER(role.code) = N'MG'
)
    THROW 51000, 'MG still has user assignments; rollback was cancelled.', 1;

BEGIN TRANSACTION;

DELETE FROM dbo.dept_roles
WHERE UPPER(code) = N'MG'
  AND name = N'Invoice-MFG Gross Profit';

COMMIT TRANSACTION;

SELECT id, code, name, is_active
FROM dbo.dept_roles
WHERE UPPER(code) = N'MG';
