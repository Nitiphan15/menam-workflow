SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRANSACTION;

IF OBJECT_ID(N'dbo.ds_user_sales_access', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.ds_user_sales_access
    (
        id BIGINT IDENTITY(1, 1) NOT NULL PRIMARY KEY,
        user_id BIGINT NOT NULL,
        salesperson_key NVARCHAR(50) NOT NULL,
        can_import BIT NOT NULL CONSTRAINT DF_ds_sales_access_import DEFAULT (1),
        can_edit BIT NOT NULL CONSTRAINT DF_ds_sales_access_edit DEFAULT (1),
        is_active BIT NOT NULL CONSTRAINT DF_ds_sales_access_active DEFAULT (1),
        created_by BIGINT NULL,
        updated_by BIGINT NULL,
        created_at DATETIME2 NULL,
        updated_at DATETIME2 NULL,
        CONSTRAINT UQ_ds_user_sales_access UNIQUE (user_id, salesperson_key)
    );

    CREATE INDEX IX_ds_sales_access_scope
        ON dbo.ds_user_sales_access (salesperson_key, is_active);
END;

DECLARE @roles TABLE
(
    code NVARCHAR(50) NOT NULL,
    name NVARCHAR(100) NOT NULL
);

INSERT INTO @roles (code, name)
VALUES
    (N'DS_IMPORT', N'Deadstock - Import ตาม Sales Mapping'),
    (N'DS_IMPORT_ALL', N'Deadstock - Import ทุก Sales'),
    (N'DS_MANAGE_ALL', N'Deadstock - จัดการและ Import ทุก Sales');

INSERT INTO dbo.dept_roles (code, name, is_active)
SELECT r.code, r.name, 1
FROM @roles AS r
WHERE NOT EXISTS
(
    SELECT 1
    FROM dbo.dept_roles AS existing
    WHERE existing.code = r.code
);

DECLARE @sales_mappings TABLE
(
    username NVARCHAR(100) NOT NULL,
    salesperson_key NVARCHAR(50) NOT NULL
);

INSERT INTO @sales_mappings (username, salesperson_key)
VALUES
    (N'dilok_s', N'D1'),
    (N'kwanruan_i', N'D1'),
    (N'preeyapan_t', N'D2'),
    (N'nittaya_t', N'D2'),
    (N'pakawadee_r', N'D3'),
    (N'thanutcha_t', N'D3'),
    (N'tutliya_p', N'D5'),
    (N'surasak_l', N'D6'),
    (N'kanyika_k', N'D6'),
    (N'sirinapa_s', N'D7'),
    (N'monnaphat_w', N'D7'),
    (N'sathit_m', N'D8'),
    (N'suthasinee_k', N'D8'),
    (N'woradecha_w', N'D9'),
    (N'laddawan_p', N'D9');

MERGE dbo.ds_user_sales_access AS target
USING
(
    SELECT u.id AS user_id, m.salesperson_key
    FROM @sales_mappings AS m
    INNER JOIN dbo.users AS u
        ON LOWER(LTRIM(RTRIM(u.username))) = m.username
) AS source
ON target.user_id = source.user_id
AND target.salesperson_key = source.salesperson_key
WHEN MATCHED THEN
    UPDATE SET
        can_import = 1,
        can_edit = 1,
        is_active = 1,
        updated_at = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (user_id, salesperson_key, can_import, can_edit, is_active, created_at, updated_at)
    VALUES (source.user_id, source.salesperson_key, 1, 1, 1, SYSDATETIME(), SYSDATETIME());

INSERT INTO dbo.user_dept_roles (user_id, department_id, role_id)
SELECT u.id, u.department_id, r.id
FROM @sales_mappings AS m
INNER JOIN dbo.users AS u
    ON LOWER(LTRIM(RTRIM(u.username))) = m.username
INNER JOIN dbo.dept_roles AS r
    ON r.code = N'DS_IMPORT'
WHERE NOT EXISTS
(
    SELECT 1
    FROM dbo.user_dept_roles AS existing
    WHERE existing.user_id = u.id
      AND existing.role_id = r.id
      AND (
          existing.department_id = u.department_id
          OR (existing.department_id IS NULL AND u.department_id IS NULL)
      )
);

DECLARE @full_access_users TABLE (username NVARCHAR(100) NOT NULL);

INSERT INTO @full_access_users (username)
VALUES (N'assadaporn_m'), (N'thanin_p');

INSERT INTO dbo.user_dept_roles (user_id, department_id, role_id)
SELECT u.id, u.department_id, r.id
FROM @full_access_users AS f
INNER JOIN dbo.users AS u
    ON LOWER(LTRIM(RTRIM(u.username))) = f.username
INNER JOIN dbo.dept_roles AS r
    ON r.code = N'DS_MANAGE_ALL'
WHERE NOT EXISTS
(
    SELECT 1
    FROM dbo.user_dept_roles AS existing
    WHERE existing.user_id = u.id
      AND existing.role_id = r.id
      AND (
          existing.department_id = u.department_id
          OR (existing.department_id IS NULL AND u.department_id IS NULL)
      )
);

COMMIT TRANSACTION;

SELECT
    u.username,
    access.salesperson_key,
    access.can_import,
    access.can_edit,
    access.is_active
FROM dbo.ds_user_sales_access AS access
INNER JOIN dbo.users AS u ON u.id = access.user_id
ORDER BY access.salesperson_key, u.username;
