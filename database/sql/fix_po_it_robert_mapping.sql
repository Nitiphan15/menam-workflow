SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRANSACTION;

DECLARE @RobertUserId BIGINT = (
    SELECT TOP (1) id
    FROM dbo.users
    WHERE is_active = 1
      AND (
          username = N'robert_s'
          OR email IN (N'robert@menam.co.th', N'robert@menamstainless.co.th')
          OR name = N'Robert Sammayo'
      )
    ORDER BY CASE WHEN username = N'robert_s' THEN 0 ELSE 1 END, id
);

DECLARE @ItDepartmentId BIGINT = (
    SELECT TOP (1) id
    FROM dbo.departments
    WHERE is_active = 1
      AND (code = N'IT' OR name = N'IT')
    ORDER BY CASE WHEN code = N'IT' THEN 0 ELSE 1 END, id
);

IF @RobertUserId IS NULL
    THROW 51021, 'Active user Robert Sammayo was not found.', 1;

IF @ItDepartmentId IS NULL
    THROW 51022, 'Active IT department was not found.', 1;

IF EXISTS (
    SELECT 1
    FROM dbo.users
    WHERE username = N'robert_s'
      AND id <> @RobertUserId
)
    THROW 51023, 'Username robert_s is already used by another user.', 1;

UPDATE dbo.users
SET username = N'robert_s',
    updated_at = SYSDATETIME()
WHERE id = @RobertUserId
  AND NULLIF(LTRIM(RTRIM(username)), N'') IS NULL;

MERGE dbo.po_department_approvers AS target
USING (SELECT @ItDepartmentId AS department_id, @RobertUserId AS approver_user_id) AS source
   ON target.department_id = source.department_id
  AND target.sequence_no = 1
WHEN MATCHED THEN
    UPDATE SET
        target.approver_user_id = source.approver_user_id,
        target.is_active = 1,
        target.updated_at = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (department_id, approver_user_id, sequence_no, is_active, created_at, updated_at)
    VALUES (source.department_id, source.approver_user_id, 1, 1, SYSDATETIME(), SYSDATETIME());

DECLARE @PoRoleId BIGINT = (
    SELECT TOP (1) id
    FROM dbo.dept_roles
    WHERE code = N'PO' AND is_active = 1
    ORDER BY id
);

IF @PoRoleId IS NULL
    THROW 51024, 'Active PO permission role was not found.', 1;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.user_dept_roles
    WHERE user_id = @RobertUserId
      AND department_id = @ItDepartmentId
      AND role_id = @PoRoleId
)
BEGIN
    INSERT INTO dbo.user_dept_roles (user_id, department_id, role_id)
    VALUES (@RobertUserId, @ItDepartmentId, @PoRoleId);
END;

COMMIT TRANSACTION;

SELECT d.code AS department_code,
       d.name AS department_name,
       pda.sequence_no,
       pda.is_active,
       u.username,
       u.name AS approver_name
FROM dbo.po_department_approvers AS pda
JOIN dbo.departments AS d ON d.id = pda.department_id
JOIN dbo.users AS u ON u.id = pda.approver_user_id
WHERE d.id = @ItDepartmentId
  AND pda.sequence_no = 1;
