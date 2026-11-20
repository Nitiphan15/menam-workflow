/* ============================================================
   GPS planner dashboard — daily summary per truck and per delivery stop
   Database: menam_workflow (sqlsrv_menam). Run on DEV first, then PROD.
   Needs: formdp_gps_phase1_locations.sql already run.
   Safe to re-run: every step is guarded. No DROP / DELETE / TRUNCATE.
   Filled by: php artisan gps:summarize-day (nightly 23:30) — rows are upserted, never deleted.
   ============================================================ */

SET XACT_ABORT ON;
BEGIN TRANSACTION;

/* 1) One row per truck per ship date */
IF OBJECT_ID(N'dbo.gps_trip_daily', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.gps_trip_daily (
        id                 BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_gps_trip_daily PRIMARY KEY,
        ship_date          DATE          NOT NULL,
        plate_key          NVARCHAR(30)  NOT NULL,
        plate_no           NVARCHAR(50)  NOT NULL,
        truck_id           INT           NULL,
        dtc_gps_id         NVARCHAR(30)  NULL,
        driver_name        NVARCHAR(150) NULL,
        trip_nos           NVARCHAR(50)  NULL,
        has_route          BIT           NOT NULL CONSTRAINT DF_gps_trip_daily_has_route DEFAULT 0,
        point_count        INT           NULL,
        first_point_at     DATETIME2     NULL,
        last_point_at      DATETIME2     NULL,
        left_company_at    DATETIME2     NULL,
        returned_at        DATETIME2     NULL,
        late_return        BIT           NOT NULL CONSTRAINT DF_gps_trip_daily_late_return DEFAULT 0,
        km                 DECIMAL(9,1)  NULL,
        max_speed          INT           NULL,
        stops_planned      INT           NOT NULL CONSTRAINT DF_gps_trip_daily_stops_planned DEFAULT 0,
        stops_with_pin     INT           NOT NULL CONSTRAINT DF_gps_trip_daily_stops_with_pin DEFAULT 0,
        stops_visited      INT           NOT NULL CONSTRAINT DF_gps_trip_daily_stops_visited DEFAULT 0,
        stops_on_time      INT           NOT NULL CONSTRAINT DF_gps_trip_daily_stops_on_time DEFAULT 0,
        stops_late         INT           NOT NULL CONSTRAINT DF_gps_trip_daily_stops_late DEFAULT 0,
        stops_not_visited  INT           NOT NULL CONSTRAINT DF_gps_trip_daily_stops_not_visited DEFAULT 0,
        note               NVARCHAR(255) NULL,
        computed_at        DATETIME2     NOT NULL CONSTRAINT DF_gps_trip_daily_computed_at DEFAULT SYSDATETIME()
    );
END;

/* 2) One row per delivery stop (customer + DP address) per truck per ship date */
IF OBJECT_ID(N'dbo.gps_stop_visit', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.gps_stop_visit (
        id                 BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_gps_stop_visit PRIMARY KEY,
        ship_date          DATE          NOT NULL,
        plate_key          NVARCHAR(30)  NOT NULL,
        stop_key           NVARCHAR(64)  NOT NULL,          -- sha1(company|customer_id|address|customer name)
        stop_no            INT           NOT NULL,
        company            NVARCHAR(10)  NULL,
        customer_id        INT           NULL,
        customer_name      NVARCHAR(255) NULL,
        window_time        CHAR(5)       NULL,              -- HH:MM from delivery_plan_data.window_at
        [status]           NVARCHAR(20)  NOT NULL,          -- visited | at_stop | passed | not_visited | no_location | no_route
        arrived_at         DATETIME2     NULL,
        left_at            DATETIME2     NULL,
        dwell_minutes      INT           NULL,
        late_minutes       INT           NULL,              -- > 0 = arrived after window_time
        is_late            BIT           NOT NULL CONSTRAINT DF_gps_stop_visit_is_late DEFAULT 0,
        computed_at        DATETIME2     NOT NULL CONSTRAINT DF_gps_stop_visit_computed_at DEFAULT SYSDATETIME()
    );
END;

COMMIT TRANSACTION;
GO

/* 3) Unique keys: re-running a day updates the same rows */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'UX_gps_trip_daily_date_plate' AND object_id = OBJECT_ID(N'dbo.gps_trip_daily'))
    CREATE UNIQUE INDEX UX_gps_trip_daily_date_plate ON dbo.gps_trip_daily (ship_date, plate_key);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'UX_gps_stop_visit_date_plate_stop' AND object_id = OBJECT_ID(N'dbo.gps_stop_visit'))
    CREATE UNIQUE INDEX UX_gps_stop_visit_date_plate_stop ON dbo.gps_stop_visit (ship_date, plate_key, stop_key);

/* For trend queries by customer */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_gps_stop_visit_customer' AND object_id = OBJECT_ID(N'dbo.gps_stop_visit'))
    CREATE INDEX IX_gps_stop_visit_customer ON dbo.gps_stop_visit (company, customer_id, ship_date);
GO

/* 4) Check */
SELECT
    CASE WHEN OBJECT_ID(N'dbo.gps_trip_daily', N'U') IS NULL THEN 'MISSING' ELSE 'OK' END AS gps_trip_daily,
    CASE WHEN OBJECT_ID(N'dbo.gps_stop_visit', N'U') IS NULL THEN 'MISSING' ELSE 'OK' END AS gps_stop_visit;
