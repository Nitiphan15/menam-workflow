IF OBJECT_ID(N'dbo.vc_department_division_groups', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.vc_department_division_groups (
        id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        site NVARCHAR(10) NOT NULL,
        department_code NVARCHAR(30) NOT NULL,
        department_name NVARCHAR(200) NOT NULL,
        division_group NVARCHAR(100) NOT NULL,
        is_active BIT NOT NULL CONSTRAINT DF_vc_department_division_groups_is_active DEFAULT (1),
        remark NVARCHAR(255) NULL,
        created_at DATETIME2 NULL,
        updated_at DATETIME2 NULL,
        CONSTRAINT UX_vc_department_division_groups_site_dept UNIQUE (site, department_code)
    );
END;

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_vc_department_division_groups_group'
      AND object_id = OBJECT_ID(N'dbo.vc_department_division_groups')
)
BEGIN
    CREATE INDEX IX_vc_department_division_groups_group
        ON dbo.vc_department_division_groups (site, division_group, is_active);
END;

DECLARE @now DATETIME2 = SYSDATETIME();

WITH sites AS (
    SELECT v.site
    FROM (VALUES
        (N'WIRE'),
        (N'PLUS')
    ) v(site)
),
department_groups AS (
    SELECT *
    FROM (VALUES
        (N'PD10', N'DRAWING', N'Production'),
        (N'PD03', N'BAR 1', N'Production'),
        (N'PD08', N'CG', N'Production'),
        (N'PD11', N'PROFILE', N'Production'),
        (N'PD02', N'ANNEALING', N'Production'),
        (N'PD06', N'WATER TREATMENT', N'Production'),
        (N'PD04', N'BAR 2', N'Production'),
        (N'PD07', N'CO2', N'Production'),
        (N'PD05', N'COATING', N'Production'),
        (N'PD09', N'CLEANING', N'Production'),
        (N'PD13', N'SHOTBLAST', N'Production'),
        (N'WH02', N'PACKING', N'Production'),
        (N'WH01', N'LOGISTIC', N'Logistic'),
        (N'WH03', N'RAWMAT', N'Logistic'),
        (N'TS01', N'TRANSPORTATION', N'Logistic'),
        (N'PD14', N'Welding and Fabrication', N'Production'),
        (N'PD00', N'FACTORY GENERAL', N'Production'),
        (N'EN01', N'ENGINEERING', N'Production'),
        (N'QA01', N'QA', N'Production'),
        (N'RD01', N'RD', N'Production'),
        (N'PD01', N'DIE', N'Production'),
        (N'QM01', N'DOCUMENT CONTROL', N'Production'),
        (N'SL01', N'EXPORT', N'Sale'),
        (N'SL02', N'DOMESTIC', N'Sale'),
        (N'SL03', N'MARKETING', N'Sale'),
        (N'AC01', N'ACCOUNT', N'Admin'),
        (N'HR01', N'HR-ADMIN', N'Admin'),
        (N'HR02', N'SAFETY', N'Admin'),
        (N'PC02', N'STORE', N'Purchase'),
        (N'PC01', N'GENERAL PURCHASE', N'Purchase'),
        (N'PC03', N'MATERIAL PURCHASE', N'Purchase'),
        (N'PL01', N'PLANNING', N'Production'),
        (N'IT01', N'IT HARDWARE', N'Admin'),
        (N'IT02', N'IT SOFTWARE', N'Admin'),
        (N'AD00', N'ADMIN', N'Admin')
    ) v(department_code, department_name, division_group)
),
seed_rows AS (
    SELECT
        sites.site,
        department_groups.department_code,
        department_groups.department_name,
        department_groups.division_group
    FROM sites
    CROSS JOIN department_groups
)
MERGE dbo.vc_department_division_groups AS target
USING seed_rows AS source
    ON target.site = source.site
   AND target.department_code = source.department_code
WHEN MATCHED THEN
    UPDATE SET
        department_name = source.department_name,
        division_group = source.division_group,
        is_active = 1,
        updated_at = @now
WHEN NOT MATCHED BY TARGET THEN
    INSERT (
        site,
        department_code,
        department_name,
        division_group,
        is_active,
        created_at,
        updated_at
    )
    VALUES (
        source.site,
        source.department_code,
        source.department_name,
        source.division_group,
        1,
        @now,
        @now
    );
