/*
  Grant STOCK_ACTION to every active user in the active Planning department.
  Verified production selector on 2026-10-21: departments.code = 'PN', name = N'วางแผน'.

  This script is intentionally not executed by deployment. Review the PREVIEW
  result first; the transaction rolls back automatically if a precondition fails.
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;

DECLARE @PlannerDepartmentCode nvarchar(50) = N'PN';
DECLARE @StockActionCode nvarchar(50) = N'STOCK_ACTION';
DECLARE @PlannerDepartmentId bigint;
DECLARE @StockActionRoleId bigint;

SELECT @PlannerDepartmentId = id
FROM dbo.departments
WHERE UPPER(code) = UPPER(@PlannerDepartmentCode)
  AND is_active = 1;

IF @PlannerDepartmentId IS NULL
    THROW 51000, N'ไม่พบแผนกวางแผนที่ Active (code = PN)', 1;

IF (SELECT COUNT(*) FROM dbo.departments WHERE UPPER(code) = UPPER(@PlannerDepartmentCode) AND is_active = 1) <> 1
    THROW 51001, N'พบแผนกวางแผน Active มากกว่า 1 รายการ กรุณาหยุดตรวจสอบก่อน', 1;

SELECT @StockActionRoleId = id
FROM dbo.dept_roles
WHERE UPPER(code) = UPPER(@StockActionCode)
  AND is_active = 1;

IF @StockActionRoleId IS NULL
    THROW 51002, N'ไม่พบสิทธิ์ STOCK_ACTION ที่ Active กรุณาสร้าง Role ก่อน', 1;

-- PREVIEW: รายชื่อที่จะเพิ่มสิทธิ์ (ควรตรวจผลชุดนี้ก่อนรันส่วน BEGIN TRANSACTION)
SELECT
    u.id AS user_id,
    u.username,
    u.name,
    d.code AS department_code,
    @StockActionCode AS permission_to_add
FROM dbo.users u
INNER JOIN dbo.departments d ON d.id = u.department_id
WHERE u.department_id = @PlannerDepartmentId
  AND u.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM dbo.user_dept_roles udr
      WHERE udr.user_id = u.id
        AND udr.department_id = @PlannerDepartmentId
        AND udr.role_id = @StockActionRoleId
  )
ORDER BY u.username;

BEGIN TRANSACTION;

DECLARE @Inserted TABLE (
    user_id bigint,
    department_id bigint,
    role_id bigint
);

INSERT INTO dbo.user_dept_roles (user_id, department_id, role_id)
OUTPUT inserted.user_id, inserted.department_id, inserted.role_id
INTO @Inserted (user_id, department_id, role_id)
SELECT u.id, @PlannerDepartmentId, @StockActionRoleId
FROM dbo.users u WITH (UPDLOCK, HOLDLOCK)
WHERE u.department_id = @PlannerDepartmentId
  AND u.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM dbo.user_dept_roles udr WITH (UPDLOCK, HOLDLOCK)
      WHERE udr.user_id = u.id
        AND udr.department_id = @PlannerDepartmentId
        AND udr.role_id = @StockActionRoleId
  );

SELECT
    u.id AS user_id,
    u.username,
    u.name,
    CASE WHEN i.user_id IS NULL THEN N'มีสิทธิ์อยู่แล้ว' ELSE N'เพิ่มสิทธิ์แล้ว' END AS result
FROM dbo.users u
LEFT JOIN @Inserted i ON i.user_id = u.id
WHERE u.department_id = @PlannerDepartmentId
  AND u.is_active = 1
ORDER BY u.username;

IF EXISTS (
    SELECT 1
    FROM dbo.users u
    WHERE u.department_id = @PlannerDepartmentId
      AND u.is_active = 1
      AND NOT EXISTS (
          SELECT 1
          FROM dbo.user_dept_roles udr
          WHERE udr.user_id = u.id
            AND udr.department_id = @PlannerDepartmentId
            AND udr.role_id = @StockActionRoleId
      )
)
BEGIN
    ROLLBACK TRANSACTION;
    THROW 51003, N'ตรวจพบ Planner ที่ยังไม่มี STOCK_ACTION จึง Rollback', 1;
END;

COMMIT TRANSACTION;

SELECT COUNT(*) AS active_planners_with_stock_action
FROM dbo.users u
WHERE u.department_id = @PlannerDepartmentId
  AND u.is_active = 1
  AND EXISTS (
      SELECT 1
      FROM dbo.user_dept_roles udr
      WHERE udr.user_id = u.id
        AND udr.department_id = @PlannerDepartmentId
        AND udr.role_id = @StockActionRoleId
  );
