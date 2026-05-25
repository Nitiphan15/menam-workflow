IF OBJECT_ID(N'dbo.fc_division_account_groups', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.fc_division_account_groups (
        id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        division NVARCHAR(10) NOT NULL,
        account_code NVARCHAR(20) NOT NULL,
        group_name NVARCHAR(100) NULL,
        is_active BIT NOT NULL CONSTRAINT DF_fc_division_account_groups_is_active DEFAULT (1),
        remark NVARCHAR(255) NULL,
        created_at DATETIME2 NULL,
        created_by BIGINT NULL,
        updated_at DATETIME2 NULL,
        updated_by BIGINT NULL,
        CONSTRAINT UX_fc_division_account_groups_division_account UNIQUE (division, account_code)
    );
END;

IF OBJECT_ID(N'dbo.fc_division_group_masters', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.fc_division_group_masters (
        id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        group_code NVARCHAR(50) NOT NULL,
        group_name NVARCHAR(100) NOT NULL,
        sort_order INT NOT NULL CONSTRAINT DF_fc_division_group_masters_sort_order DEFAULT (0),
        is_active BIT NOT NULL CONSTRAINT DF_fc_division_group_masters_is_active DEFAULT (1),
        remark NVARCHAR(255) NULL,
        created_at DATETIME2 NULL,
        created_by BIGINT NULL,
        updated_at DATETIME2 NULL,
        updated_by BIGINT NULL,
        CONSTRAINT UX_fc_division_group_masters_code UNIQUE (group_code)
    );
END;

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_fc_division_account_groups_group'
      AND object_id = OBJECT_ID(N'dbo.fc_division_account_groups')
)
BEGIN
    CREATE INDEX IX_fc_division_account_groups_group
        ON dbo.fc_division_account_groups (division, group_name, is_active);
END;

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_fc_division_group_masters_active_sort'
      AND object_id = OBJECT_ID(N'dbo.fc_division_group_masters')
)
BEGIN
    CREATE INDEX IX_fc_division_group_masters_active_sort
        ON dbo.fc_division_group_masters (is_active, sort_order, group_code);
END;

DECLARE @now DATETIME2 = SYSDATETIME();

WITH divisions AS (
    SELECT v.division
    FROM (VALUES
        (N'D1'),
        (N'D2'),
        (N'D3'),
        (N'D4'),
        (N'D5'),
        (N'D6'),
        (N'D7'),
        (N'D8'),
        (N'D9')
    ) v(division)
),
accounts AS (
    SELECT v.account_code
    FROM (VALUES
        (N'5210100'),
        (N'5210310'),
        (N'5210330'),
        (N'5210340'),
        (N'5210350'),
        (N'5210370'),
        (N'5210401'),
        (N'5210602'),
        (N'5210700'),
        (N'5211000'),
        (N'5211100'),
        (N'5211200'),
        (N'5211700'),
        (N'5211800'),
        (N'5220200'),
        (N'5220600'),
        (N'6030001'),
        (N'6040000'),
        (N'6050000'),
        (N'6060100'),
        (N'6120201'),
        (N'6120401'),
        (N'7050000'),
        (N'7060200'),
        (N'7070000'),
        (N'7080000')
    ) v(account_code)
),
seed_rows AS (
    SELECT
        divisions.division,
        accounts.account_code
    FROM divisions
    CROSS JOIN accounts
)
MERGE dbo.fc_division_account_groups AS target
USING seed_rows AS source
    ON target.division = source.division
   AND target.account_code = source.account_code
WHEN NOT MATCHED BY TARGET THEN
    INSERT (
        division,
        account_code,
        group_name,
        is_active,
        remark,
        created_at,
        updated_at
    )
    VALUES (
        source.division,
        source.account_code,
        NULL,
        1,
        NULL,
        @now,
        @now
    );
