-- =============================================================
-- Variable Cost : ตารางบันทึกน้ำหนักรถบรรทุก (manual log)
-- Connection: sqlsrv_menam (SQL Server)
-- ใช้แทน migration 2026_06_30_000005_create_vc_truck_weight_logs_table
-- รันซ้ำได้ (idempotent) — มี IF NOT EXISTS ครบ
-- =============================================================

IF NOT EXISTS (
    SELECT 1 FROM sys.objects
    WHERE object_id = OBJECT_ID(N'dbo.vc_truck_weight_logs')
      AND type = N'U'
)
BEGIN
    CREATE TABLE dbo.vc_truck_weight_logs (
        id               BIGINT IDENTITY(1,1) NOT NULL,
        period_date_from DATE            NOT NULL,
        period_date_to   DATE            NOT NULL,
        site             NVARCHAR(20)    NOT NULL CONSTRAINT DF_vc_truck_weight_logs_site DEFAULT ('ALL'),
        weight_kg        DECIMAL(18,3)   NOT NULL,
        notes            NVARCHAR(MAX)   NULL,
        created_by       BIGINT          NULL,
        created_by_name  NVARCHAR(160)   NULL,
        created_by_email NVARCHAR(160)   NULL,
        created_at       DATETIME        NULL,
        updated_at       DATETIME        NULL,
        CONSTRAINT PK_vc_truck_weight_logs PRIMARY KEY CLUSTERED (id)
    );
END;
GO

-- index: period_date_from
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_vc_truck_weight_logs_period_from'
      AND object_id = OBJECT_ID(N'dbo.vc_truck_weight_logs')
)
BEGIN
    CREATE INDEX IX_vc_truck_weight_logs_period_from
        ON dbo.vc_truck_weight_logs (period_date_from);
END;
GO

-- index: period_date_to
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_vc_truck_weight_logs_period_to'
      AND object_id = OBJECT_ID(N'dbo.vc_truck_weight_logs')
)
BEGIN
    CREATE INDEX IX_vc_truck_weight_logs_period_to
        ON dbo.vc_truck_weight_logs (period_date_to);
END;
GO

-- index: site
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_vc_truck_weight_logs_site'
      AND object_id = OBJECT_ID(N'dbo.vc_truck_weight_logs')
)
BEGIN
    CREATE INDEX IX_vc_truck_weight_logs_site
        ON dbo.vc_truck_weight_logs (site);
END;
GO

-- index: created_by
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_vc_truck_weight_logs_created_by'
      AND object_id = OBJECT_ID(N'dbo.vc_truck_weight_logs')
)
BEGIN
    CREATE INDEX IX_vc_truck_weight_logs_created_by
        ON dbo.vc_truck_weight_logs (created_by);
END;
GO

-- index รวม: ใช้ค้นหา log ตามช่วงวันที่ + site (ตรงกับ query ใน VariableCostService)
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_vc_truck_weight_logs_period_site'
      AND object_id = OBJECT_ID(N'dbo.vc_truck_weight_logs')
)
BEGIN
    CREATE INDEX IX_vc_truck_weight_logs_period_site
        ON dbo.vc_truck_weight_logs (period_date_from, period_date_to, site);
END;
GO
