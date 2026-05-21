SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID(N'dbo.machine_load_work_centers', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.machine_load_work_centers (
        id BIGINT IDENTITY(1,1) NOT NULL,
        source_site NVARCHAR(10) NOT NULL,
        workcenter_id BIGINT NOT NULL,
        workmachine_id BIGINT NULL,
        display_name NVARCHAR(120) NULL,
        capacity_per_hour DECIMAL(18, 4) NULL,
        work_hours_per_day DECIMAL(8, 2) NULL,
        work_days_per_week TINYINT NOT NULL CONSTRAINT DF_machine_load_work_centers_work_days_per_week DEFAULT (6),
        cycle_time_minutes DECIMAL(18, 4) NULL,
        setup_time_minutes DECIMAL(18, 4) NULL,
        active BIT NOT NULL CONSTRAINT DF_machine_load_work_centers_active DEFAULT (1),
        notes NVARCHAR(500) NULL,
        created_at DATETIME2(0) NULL,
        updated_at DATETIME2(0) NULL,
        CONSTRAINT PK_machine_load_work_centers PRIMARY KEY CLUSTERED (id ASC)
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_machine_load_work_centers_site_wc'
      AND object_id = OBJECT_ID(N'dbo.machine_load_work_centers')
)
BEGIN
    CREATE INDEX IX_machine_load_work_centers_site_wc
        ON dbo.machine_load_work_centers (source_site, workcenter_id);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_machine_load_work_centers_site_wc_machine'
      AND object_id = OBJECT_ID(N'dbo.machine_load_work_centers')
)
BEGIN
    CREATE INDEX IX_machine_load_work_centers_site_wc_machine
        ON dbo.machine_load_work_centers (source_site, workcenter_id, workmachine_id);
END;
GO

IF OBJECT_ID(N'dbo.machine_load_holidays', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.machine_load_holidays (
        id BIGINT IDENTITY(1,1) NOT NULL,
        source_site NVARCHAR(10) NOT NULL,
        holiday_date DATE NOT NULL,
        description NVARCHAR(255) NULL,
        created_at DATETIME2(0) NULL,
        updated_at DATETIME2(0) NULL,
        CONSTRAINT PK_machine_load_holidays PRIMARY KEY CLUSTERED (id ASC),
        CONSTRAINT UQ_machine_load_holidays_site_date UNIQUE (source_site, holiday_date)
    );
END;
GO

DECLARE @MachineLoadHolidays TABLE (
    holiday_date DATE NOT NULL,
    description NVARCHAR(255) NULL
);

INSERT INTO @MachineLoadHolidays (holiday_date, description)
VALUES
    ('2026-01-01', N'Company Holiday'),
    ('2026-01-02', N'Company Holiday'),
    ('2026-01-03', N'Company Holiday'),
    ('2026-04-11', N'Company Holiday'),
    ('2026-04-13', N'Company Holiday'),
    ('2026-04-14', N'Company Holiday'),
    ('2026-04-15', N'Company Holiday'),
    ('2026-05-01', N'Company Holiday'),
    ('2026-07-28', N'Company Holiday'),
    ('2026-08-12', N'Company Holiday'),
    ('2026-12-05', N'Company Holiday'),
    ('2026-12-28', N'Company Holiday'),
    ('2026-12-29', N'Company Holiday'),
    ('2026-12-30', N'Company Holiday'),
    ('2026-12-31', N'Company Holiday'),
    ('2027-01-01', N'Company Holiday');

INSERT INTO dbo.machine_load_holidays (source_site, holiday_date, description, created_at, updated_at)
SELECT N'ALL', h.holiday_date, h.description, SYSDATETIME(), SYSDATETIME()
FROM @MachineLoadHolidays h
WHERE NOT EXISTS (
    SELECT 1
    FROM dbo.machine_load_holidays mh
    WHERE mh.source_site = N'ALL'
      AND mh.holiday_date = h.holiday_date
);
GO

PRINT 'Machine Load tables are ready.';
GO
