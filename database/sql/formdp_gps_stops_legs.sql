/* ============================================================
   GPS planner dashboard — stops (incl. unplanned) and travel legs per truck per day
   Database: menam_workflow (sqlsrv_menam). Run on DEV first, then PROD.
   Needs: formdp_gps_phase1_locations.sql and formdp_gps_dashboard.sql already run.
   Safe to re-run: every step is guarded. No DROP / DELETE / TRUNCATE.
   Filled by: php artisan gps:summarize-day (nightly 00:30) — rows are upserted, never deleted.
   ============================================================ */

SET XACT_ABORT ON;
BEGIN TRANSACTION;

/* 1) Every stay of 10+ minutes on a truck's route that day */
IF OBJECT_ID(N'dbo.gps_trip_stop', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.gps_trip_stop (
        id              BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_gps_trip_stop PRIMARY KEY,
        ship_date       DATE          NOT NULL,
        plate_key       NVARCHAR(30)  NOT NULL,
        seq             INT           NOT NULL,          -- order of the stop in the day (1, 2, ...)
        kind            NVARCHAR(20)  NOT NULL,          -- company | customer | unplanned | removed
        stop_key        NVARCHAR(64)  NULL,              -- gps_stop_visit.stop_key when kind = customer
        customer_name   NVARCHAR(255) NULL,
        arrived_at      DATETIME2     NOT NULL,
        left_at         DATETIME2     NOT NULL,
        minutes         INT           NOT NULL,
        lat             DECIMAL(9,6)  NOT NULL,
        lon             DECIMAL(9,6)  NOT NULL,
        place_kind      NVARCHAR(20)  NULL,              -- customer | station | poi | near | road | area (from GpsPlaceResolver)
        place_label     NVARCHAR(255) NULL,
        place_detail    NVARCHAR(255) NULL,
        computed_at     DATETIME2     NOT NULL CONSTRAINT DF_gps_trip_stop_computed_at DEFAULT SYSDATETIME()
    );
END;

/* 2) Travel between known places: company -> customer, customer -> customer, customer -> company */
IF OBJECT_ID(N'dbo.gps_trip_leg', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.gps_trip_leg (
        id                 BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_gps_trip_leg PRIMARY KEY,
        ship_date          DATE          NOT NULL,
        plate_key          NVARCHAR(30)  NOT NULL,
        seq                INT           NOT NULL,
        from_kind          NVARCHAR(20)  NOT NULL,       -- company | customer
        from_key           NVARCHAR(64)  NULL,           -- stop_key of the customer (NULL = company)
        from_name          NVARCHAR(255) NULL,
        to_kind            NVARCHAR(20)  NOT NULL,       -- customer | company | removed
        to_key             NVARCHAR(64)  NULL,
        to_name            NVARCHAR(255) NULL,
        company            NVARCHAR(10)  NULL,           -- customer of the destination (for per-customer stats)
        customer_id        INT           NULL,
        depart_at          DATETIME2     NOT NULL,
        arrive_at          DATETIME2     NOT NULL,
        minutes            INT           NOT NULL,
        unplanned_minutes  INT           NOT NULL CONSTRAINT DF_gps_trip_leg_unplanned DEFAULT 0,
        drive_minutes      INT           NOT NULL,       -- minutes - unplanned_minutes
        km                 DECIMAL(9,1)  NULL,
        computed_at        DATETIME2     NOT NULL CONSTRAINT DF_gps_trip_leg_computed_at DEFAULT SYSDATETIME()
    );
END;

COMMIT TRANSACTION;
GO

/* 3) Unique keys: re-running a day updates the same rows */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'UX_gps_trip_stop_date_plate_seq' AND object_id = OBJECT_ID(N'dbo.gps_trip_stop'))
    CREATE UNIQUE INDEX UX_gps_trip_stop_date_plate_seq ON dbo.gps_trip_stop (ship_date, plate_key, seq);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'UX_gps_trip_leg_date_plate_seq' AND object_id = OBJECT_ID(N'dbo.gps_trip_leg'))
    CREATE UNIQUE INDEX UX_gps_trip_leg_date_plate_seq ON dbo.gps_trip_leg (ship_date, plate_key, seq);

/* For per-customer travel-time statistics */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_gps_trip_leg_customer' AND object_id = OBJECT_ID(N'dbo.gps_trip_leg'))
    CREATE INDEX IX_gps_trip_leg_customer ON dbo.gps_trip_leg (company, customer_id, ship_date);
GO

/* 4) Check */
SELECT
    CASE WHEN OBJECT_ID(N'dbo.gps_trip_stop', N'U') IS NULL THEN 'MISSING' ELSE 'OK' END AS gps_trip_stop,
    CASE WHEN OBJECT_ID(N'dbo.gps_trip_leg', N'U') IS NULL THEN 'MISSING' ELSE 'OK' END AS gps_trip_leg;
