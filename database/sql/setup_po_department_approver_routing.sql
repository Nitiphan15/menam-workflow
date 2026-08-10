SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRANSACTION;

IF OBJECT_ID(N'dbo.po_department_approvers', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.po_department_approvers (
        id BIGINT IDENTITY(1,1) NOT NULL
            CONSTRAINT PK_po_department_approvers PRIMARY KEY,
        department_id BIGINT NOT NULL,
        approver_user_id BIGINT NOT NULL,
        is_active BIT NOT NULL
            CONSTRAINT DF_po_department_approvers_is_active DEFAULT (1),
        created_at DATETIME2 NOT NULL
            CONSTRAINT DF_po_department_approvers_created_at DEFAULT (SYSDATETIME()),
        updated_at DATETIME2 NOT NULL
            CONSTRAINT DF_po_department_approvers_updated_at DEFAULT (SYSDATETIME()),
        CONSTRAINT UX_po_department_approvers_department UNIQUE (department_id)
    );

    CREATE INDEX IX_po_department_approvers_user_active
        ON dbo.po_department_approvers (approver_user_id, is_active);
END;

DECLARE @ProductionDepartmentId INT = (
    SELECT TOP (1) id
    FROM dbo.departments
    WHERE code = N'PD' AND is_active = 1
    ORDER BY id
);

IF @ProductionDepartmentId IS NULL
    THROW 51001, 'Active PD - Production department was not found.', 1;

DECLARE @DepartmentSeed TABLE (
    code NVARCHAR(50) NOT NULL PRIMARY KEY,
    name NVARCHAR(255) NOT NULL
);

INSERT INTO @DepartmentSeed (code, name)
VALUES
    (N'SB',  N'Shotblast'),
    (N'PF',  N'Profile'),
    (N'WW',  N'บ่อบำบัด'),
    (N'SH2', N'BAR 2'),
    (N'CO2', N'CO2'),
    (N'CT',  N'Coating');

IF EXISTS (
    SELECT 1
    FROM @DepartmentSeed AS seed
    JOIN dbo.departments AS department ON department.code = seed.code
    WHERE department.name <> seed.name
)
    THROW 51002, 'A requested department code already belongs to another department.', 1;

IF EXISTS (
    SELECT 1
    FROM @DepartmentSeed AS seed
    JOIN dbo.departments AS department ON department.name = seed.name
    WHERE department.code <> seed.code
)
    THROW 51003, 'A requested department name already belongs to another code.', 1;

MERGE dbo.departments AS target
USING @DepartmentSeed AS source
   ON target.code = source.code
WHEN MATCHED THEN
    UPDATE SET
        target.name = source.name,
        target.parent_id = @ProductionDepartmentId,
        target.is_active = 1,
        target.updated_at = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (code, name, parent_id, is_active, created_at, updated_at)
    VALUES (source.code, source.name, @ProductionDepartmentId, 1, SYSDATETIME(), SYSDATETIME());

DECLARE @RoleSeed TABLE (
    code NVARCHAR(50) NOT NULL PRIMARY KEY,
    name NVARCHAR(255) NOT NULL,
    level_no NVARCHAR(20) NOT NULL
);

INSERT INTO @RoleSeed (code, name, level_no)
VALUES
    (N'EMP',              N'พนักงาน',                N'1'),
    (N'EMP_SK2',          N'พนักงาน Skill2',         N'1'),
    (N'OFFICER_SK3',      N'เจ้าหน้าที่ Skill3',     N'1'),
    (N'SHIFT_HEAD',       N'หัวหน้ากะ',              N'2'),
    (N'ASST_DEPT_HEAD',   N'ผู้ช่วยหัวหน้าแผนก',     N'2'),
    (N'DEPT_HEAD',        N'หัวหน้าแผนก',            N'3');

MERGE dbo.department_roles AS target
USING (
    SELECT department.id AS department_id,
           role_seed.code,
           role_seed.name,
           role_seed.level_no
    FROM @DepartmentSeed AS department_seed
    JOIN dbo.departments AS department ON department.code = department_seed.code
    CROSS JOIN @RoleSeed AS role_seed
) AS source
   ON target.department_id = source.department_id
  AND target.code = source.code
WHEN MATCHED THEN
    UPDATE SET
        target.name = source.name,
        target.level_no = source.level_no,
        target.is_active = 1,
        target.updated_at = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (department_id, code, name, level_no, is_active, created_at, updated_at)
    VALUES (source.department_id, source.code, source.name, source.level_no, 1, SYSDATETIME(), SYSDATETIME());

DECLARE @ApproverSeed TABLE (
    department_code NVARCHAR(50) NOT NULL PRIMARY KEY,
    approver_key NVARCHAR(255) NOT NULL
);

-- The 15 requested Production labels resolve to 14 unique department masters
-- because Logistic and Transport both use SP - จัดส่ง.
INSERT INTO @ApproverSeed (department_code, approver_key)
VALUES
    (N'SH1', N'jittinan_k'),
    (N'CG',  N'jittinan_k'),
    (N'SB',  N'jittinan_k'),
    (N'DD',  N'jittinan_k'),
    (N'ANL', N'jittinan_k'),
    (N'PF',  N'jittinan_k'),
    (N'WW',  N'jittinan_k'),
    (N'SH2', N'jittinan_k'),
    (N'CO2', N'jittinan_k'),
    (N'CT',  N'jittinan_k'),
    (N'PK',  N'jittinan_k'),
    (N'SP',  N'jittinan_k'),
    (N'ST',  N'jittinan_k'),
    (N'AM',  N'jittinan_k'),
    (N'PN',  N'assadaporn_m'),
    (N'AC',  N'jiraporn_k'),
    (N'HR',  N'chacrit@menamstainless.co.th');

IF EXISTS (
    SELECT 1
    FROM @ApproverSeed AS seed
    LEFT JOIN dbo.departments AS department
      ON department.code = seed.department_code
     AND department.is_active = 1
    WHERE department.id IS NULL
)
    THROW 51004, 'One or more approver departments are missing or inactive.', 1;

IF EXISTS (
    SELECT 1
    FROM @ApproverSeed AS seed
    LEFT JOIN dbo.users AS approver
      ON (approver.username = seed.approver_key OR LOWER(approver.email) = LOWER(seed.approver_key))
     AND approver.is_active = 1
    WHERE approver.id IS NULL
)
    THROW 51005, 'One or more approver users are missing or inactive.', 1;

MERGE dbo.po_department_approvers AS target
USING (
    SELECT department.id AS department_id,
           approver.id AS approver_user_id
    FROM @ApproverSeed AS seed
    JOIN dbo.departments AS department ON department.code = seed.department_code
    JOIN dbo.users AS approver
      ON (approver.username = seed.approver_key OR LOWER(approver.email) = LOWER(seed.approver_key))
     AND approver.is_active = 1
) AS source
   ON target.department_id = source.department_id
WHEN MATCHED THEN
    UPDATE SET
        target.approver_user_id = source.approver_user_id,
        target.is_active = 1,
        target.updated_at = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (department_id, approver_user_id, is_active, created_at, updated_at)
    VALUES (source.department_id, source.approver_user_id, 1, SYSDATETIME(), SYSDATETIME());

DECLARE @PoRoleId INT = (
    SELECT TOP (1) id
    FROM dbo.dept_roles
    WHERE code = N'PO' AND is_active = 1
    ORDER BY id
);

IF @PoRoleId IS NULL
    THROW 51006, 'Active PO web permission was not found.', 1;

DECLARE @PoUsers TABLE (approver_key NVARCHAR(255) NOT NULL PRIMARY KEY);

INSERT INTO @PoUsers (approver_key)
VALUES
    (N'panya_k'),
    (N'thatree_k'),
    (N'kitpon_s'),
    (N'utis_j'),
    (N'chatchawal_c'),
    (N'jittinan_k'),
    (N'theerarat_k'),
    (N'preeyapan_t'),
    (N'thanin_p'),
    (N'assadaporn_m'),
    (N'jiraporn_k'),
    (N'chacrit@menamstainless.co.th');

IF EXISTS (
    SELECT 1
    FROM @PoUsers AS target_user
    LEFT JOIN dbo.users AS user_row
      ON (user_row.username = target_user.approver_key OR LOWER(user_row.email) = LOWER(target_user.approver_key))
     AND user_row.is_active = 1
     AND user_row.department_id IS NOT NULL
    WHERE user_row.id IS NULL
)
    THROW 51007, 'One or more PO users are missing, inactive, or have no department.', 1;

INSERT INTO dbo.user_dept_roles (user_id, department_id, role_id)
SELECT user_row.id, user_row.department_id, @PoRoleId
FROM @PoUsers AS target_user
JOIN dbo.users AS user_row
  ON (user_row.username = target_user.approver_key OR LOWER(user_row.email) = LOWER(target_user.approver_key))
 AND user_row.is_active = 1
WHERE NOT EXISTS (
    SELECT 1
    FROM dbo.user_dept_roles AS existing
    WHERE existing.user_id = user_row.id
      AND existing.department_id = user_row.department_id
      AND existing.role_id = @PoRoleId
);

COMMIT TRANSACTION;

SELECT department.code AS department_code,
       department.name AS department_name,
       approver.username AS approver_username,
       approver.email AS approver_email,
       mapping.is_active
FROM dbo.po_department_approvers AS mapping
JOIN dbo.departments AS department ON department.id = mapping.department_id
JOIN dbo.users AS approver ON approver.id = mapping.approver_user_id
WHERE department.code IN (N'SH1',N'CG',N'SB',N'DD',N'ANL',N'PF',N'WW',N'SH2',N'CO2',N'CT',N'PK',N'SP',N'ST',N'AM',N'PN',N'AC',N'HR')
ORDER BY department.code;
