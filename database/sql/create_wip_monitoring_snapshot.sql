/*
    WIP & Stock Pending Monitoring — snapshot tables
    Connection : sqlsrv_menam  (config database.workflow_connection)
    รันซ้ำได้ (idempotent) — ไม่มี DROP / DELETE / TRUNCATE

    ตาราง
      wip_sync_runs         : log การ sync แต่ละรอบ (ใช้แสดงเวลา update ล่าสุด)
      wip_wo_current        : สถานะล่าสุดของแต่ละ WO + วันที่เข้าสถานะนี้ (ใช้คำนวณ Aging) + payload สำหรับ dashboard
      wip_status_history    : ประวัติการเปลี่ยนสถานะ / Work Center ของ WO
      wip_daily_snapshots   : ภาพรวมรายวัน 1 แถว / WO / วัน (ใช้ดูแนวโน้มย้อนหลัง)
*/
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

/* ---------------------------------------------------------------- wip_sync_runs */
IF OBJECT_ID(N'dbo.wip_sync_runs', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.wip_sync_runs (
        id               BIGINT IDENTITY(1,1) NOT NULL,
        site             NVARCHAR(10)   NOT NULL,
        status           NVARCHAR(20)   NOT NULL CONSTRAINT DF_wip_sync_runs_status DEFAULT (N'RUNNING'), -- RUNNING / SUCCESS / FAILED
        triggered_by     NVARCHAR(50)   NULL,     -- schedule / manual / user id
        started_at       DATETIME2(0)   NOT NULL,
        finished_at      DATETIME2(0)   NULL,
        wo_count         INT            NULL,     -- WO ที่เปิดอยู่ที่ดึงมาได้
        wip_wo_count     INT            NULL,     -- WO ที่มี WIP ค้าง
        total_wip_kg     DECIMAL(18, 3) NULL,
        status_changes   INT            NULL,
        closed_count     INT            NULL,
        duration_ms      INT            NULL,
        error_message    NVARCHAR(1000) NULL,
        CONSTRAINT PK_wip_sync_runs PRIMARY KEY CLUSTERED (id ASC)
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_wip_sync_runs_site_status_finished' AND object_id = OBJECT_ID(N'dbo.wip_sync_runs'))
BEGIN
    CREATE INDEX IX_wip_sync_runs_site_status_finished
        ON dbo.wip_sync_runs (site, status, finished_at DESC);
END;
GO

/* ---------------------------------------------------------------- wip_wo_current */
IF OBJECT_ID(N'dbo.wip_wo_current', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.wip_wo_current (
        id                    BIGINT IDENTITY(1,1) NOT NULL,
        site                  NVARCHAR(10)   NOT NULL,
        workorder_id          BIGINT         NOT NULL,   -- WMSOne workorder.id
        wo_no                 NVARCHAR(40)   NOT NULL,
        dateopen              DATE           NULL,
        qty_kg                DECIMAL(18, 3) NOT NULL CONSTRAINT DF_wip_wo_current_qty DEFAULT (0),
        status                NVARCHAR(30)   NOT NULL,   -- WAITING_PRODUCTION / IN_PROCESS / WAITING_PACKAGING / WAITING_FG / HOLD / COMPLETED
        status_since          DATETIME2(0)   NOT NULL,   -- เข้าสถานะนี้เมื่อไร → Aging
        current_wc            NVARCHAR(30)   NULL,
        wc_since              DATETIME2(0)   NULL,
        waiting_production_kg DECIMAL(18, 3) NOT NULL CONSTRAINT DF_wip_wo_current_wp  DEFAULT (0),
        in_process_kg         DECIMAL(18, 3) NOT NULL CONSTRAINT DF_wip_wo_current_ip  DEFAULT (0),
        waiting_packaging_kg  DECIMAL(18, 3) NOT NULL CONSTRAINT DF_wip_wo_current_pk  DEFAULT (0),
        waiting_fg_kg         DECIMAL(18, 3) NOT NULL CONSTRAINT DF_wip_wo_current_fg  DEFAULT (0),
        hold_kg               DECIMAL(18, 3) NOT NULL CONSTRAINT DF_wip_wo_current_hd  DEFAULT (0),
        completed_kg          DECIMAL(18, 3) NOT NULL CONSTRAINT DF_wip_wo_current_cp  DEFAULT (0),
        wip_kg                DECIMAL(18, 3) NOT NULL CONSTRAINT DF_wip_wo_current_wip DEFAULT (0),
        last_tx               DATETIME2(0)   NULL,       -- transaction ล่าสุดใน WMSOne
        is_open               BIT            NOT NULL CONSTRAINT DF_wip_wo_current_open DEFAULT (1),
        closed_at             DATETIME2(0)   NULL,       -- หายจากรายการ WO เปิด (ปิดใน WMSOne)
        payload               NVARCHAR(MAX)  NULL,       -- JSON ของแถว (steps / buckets) สำหรับ dashboard
        first_seen_at         DATETIME2(0)   NOT NULL,
        last_synced_at        DATETIME2(0)   NOT NULL,
        sync_run_id           BIGINT         NULL,
        CONSTRAINT PK_wip_wo_current PRIMARY KEY CLUSTERED (id ASC)
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'UX_wip_wo_current_site_wo' AND object_id = OBJECT_ID(N'dbo.wip_wo_current'))
BEGIN
    CREATE UNIQUE INDEX UX_wip_wo_current_site_wo
        ON dbo.wip_wo_current (site, workorder_id);
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_wip_wo_current_site_open' AND object_id = OBJECT_ID(N'dbo.wip_wo_current'))
BEGIN
    CREATE INDEX IX_wip_wo_current_site_open
        ON dbo.wip_wo_current (site, is_open)
        INCLUDE (workorder_id, status, status_since, current_wc, wc_since);
END;
GO

/* ---------------------------------------------------------------- wip_status_history */
IF OBJECT_ID(N'dbo.wip_status_history', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.wip_status_history (
        id            BIGINT IDENTITY(1,1) NOT NULL,
        site          NVARCHAR(10)   NOT NULL,
        workorder_id  BIGINT         NOT NULL,
        wo_no         NVARCHAR(40)   NOT NULL,
        from_status   NVARCHAR(30)   NULL,      -- NULL = พบครั้งแรก
        to_status     NVARCHAR(30)   NOT NULL,  -- CLOSED = ปิด WO ใน WMSOne
        from_wc       NVARCHAR(30)   NULL,
        to_wc         NVARCHAR(30)   NULL,
        wip_kg        DECIMAL(18, 3) NULL,
        days_in_prev  INT            NULL,      -- อยู่ในสถานะเดิมกี่วัน
        changed_at    DATETIME2(0)   NOT NULL,
        sync_run_id   BIGINT         NULL,
        CONSTRAINT PK_wip_status_history PRIMARY KEY CLUSTERED (id ASC)
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_wip_status_history_site_wo' AND object_id = OBJECT_ID(N'dbo.wip_status_history'))
BEGIN
    CREATE INDEX IX_wip_status_history_site_wo
        ON dbo.wip_status_history (site, workorder_id, changed_at);
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_wip_status_history_changed' AND object_id = OBJECT_ID(N'dbo.wip_status_history'))
BEGIN
    CREATE INDEX IX_wip_status_history_changed
        ON dbo.wip_status_history (changed_at);
END;
GO

/* ---------------------------------------------------------------- wip_daily_snapshots */
IF OBJECT_ID(N'dbo.wip_daily_snapshots', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.wip_daily_snapshots (
        id                    BIGINT IDENTITY(1,1) NOT NULL,
        snapshot_date         DATE           NOT NULL,
        site                  NVARCHAR(10)   NOT NULL,
        workorder_id          BIGINT         NOT NULL,
        wo_no                 NVARCHAR(40)   NOT NULL,
        status                NVARCHAR(30)   NOT NULL,
        current_wc            NVARCHAR(30)   NULL,
        aging_days            INT            NULL,
        aging_band            NVARCHAR(20)   NULL,
        ends_with_pk          BIT            NOT NULL CONSTRAINT DF_wip_daily_pk  DEFAULT (0),
        qty_kg                DECIMAL(18, 3) NOT NULL CONSTRAINT DF_wip_daily_qty DEFAULT (0),
        waiting_production_kg DECIMAL(18, 3) NOT NULL CONSTRAINT DF_wip_daily_wp  DEFAULT (0),
        in_process_kg         DECIMAL(18, 3) NOT NULL CONSTRAINT DF_wip_daily_ip  DEFAULT (0),
        waiting_packaging_kg  DECIMAL(18, 3) NOT NULL CONSTRAINT DF_wip_daily_pkg DEFAULT (0),
        waiting_fg_kg         DECIMAL(18, 3) NOT NULL CONSTRAINT DF_wip_daily_fg  DEFAULT (0),
        hold_kg               DECIMAL(18, 3) NOT NULL CONSTRAINT DF_wip_daily_hd  DEFAULT (0),
        completed_kg          DECIMAL(18, 3) NOT NULL CONSTRAINT DF_wip_daily_cp  DEFAULT (0),
        wip_kg                DECIMAL(18, 3) NOT NULL CONSTRAINT DF_wip_daily_wip DEFAULT (0),
        updated_at            DATETIME2(0)   NOT NULL,
        CONSTRAINT PK_wip_daily_snapshots PRIMARY KEY CLUSTERED (id ASC)
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'UX_wip_daily_snapshots_date_site_wo' AND object_id = OBJECT_ID(N'dbo.wip_daily_snapshots'))
BEGIN
    CREATE UNIQUE INDEX UX_wip_daily_snapshots_date_site_wo
        ON dbo.wip_daily_snapshots (snapshot_date, site, workorder_id);
END;
GO

/* ตรวจสอบหลังรัน */
SELECT t.name AS table_name, SUM(p.rows) AS row_count
FROM sys.tables t
JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id IN (0, 1)
WHERE t.name IN (N'wip_sync_runs', N'wip_wo_current', N'wip_status_history', N'wip_daily_snapshots')
GROUP BY t.name
ORDER BY t.name;
GO
