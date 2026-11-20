/* ============================================================
   GPS Phase 1 — truck <-> DTC GPS id, customer locations, DTC POI cache
   Database: menam_workflow (sqlsrv_menam). Run on DEV first, then PROD.
   Safe to re-run: every step is guarded. No DROP / DELETE / TRUNCATE.
   ============================================================ */

SET XACT_ABORT ON;
BEGIN TRANSACTION;

/* 1) Truck Master: link each truck to its DTC GPS device id (gps_id from getVehicleMaster) */
IF COL_LENGTH(N'dbo.delivery_plan_truck_master', N'dtc_gps_id') IS NULL
    ALTER TABLE dbo.delivery_plan_truck_master ADD dtc_gps_id NVARCHAR(30) NULL;

/* 2) Cache of DTC POIs (getPOI) used to suggest customer locations */
IF OBJECT_ID(N'dbo.gps_dtc_pois', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.gps_dtc_pois (
        poi_id         NVARCHAR(20)  NOT NULL CONSTRAINT PK_gps_dtc_pois PRIMARY KEY,
        poi_name       NVARCHAR(250) NOT NULL,
        location_info  NVARCHAR(500) NULL,
        poi_type       NVARCHAR(5)   NULL,
        lat            DECIMAL(9,6)  NOT NULL,
        lon            DECIMAL(9,6)  NOT NULL,
        area_m         INT           NULL,
        synced_at      DATETIME2     NOT NULL CONSTRAINT DF_gps_dtc_pois_synced_at DEFAULT SYSDATETIME()
    );
END;

/* 3) Customer / ship-to locations (our own table; dbo.customer is an ERP ETL copy and must not be altered)
      address_key = '' -> default location of the customer; otherwise hash of the normalized DP address */
IF OBJECT_ID(N'dbo.gps_customer_locations', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.gps_customer_locations (
        id            BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_gps_customer_locations PRIMARY KEY,
        company       NVARCHAR(10)  NOT NULL,
        customer_id   INT           NOT NULL,
        address_key   NVARCHAR(64)  NOT NULL CONSTRAINT DF_gps_customer_locations_address_key DEFAULT N'',
        address_text  NVARCHAR(500) NULL,
        label         NVARCHAR(255) NULL,
        lat           DECIMAL(9,6)  NOT NULL,
        lon           DECIMAL(9,6)  NOT NULL,
        radius_m      INT           NOT NULL CONSTRAINT DF_gps_customer_locations_radius DEFAULT 150,
        [source]      NVARCHAR(20)  NOT NULL,          -- dtc_poi | manual
        dtc_poi_id    NVARCHAR(20)  NULL,
        is_active     BIT           NOT NULL CONSTRAINT DF_gps_customer_locations_active DEFAULT 1,
        created_at    DATETIME2     NOT NULL CONSTRAINT DF_gps_customer_locations_created DEFAULT SYSDATETIME(),
        created_by    BIGINT        NULL,
        updated_at    DATETIME2     NULL,
        updated_by    BIGINT        NULL,
        CONSTRAINT CK_gps_customer_locations_lat CHECK (lat BETWEEN -90 AND 90),
        CONSTRAINT CK_gps_customer_locations_lon CHECK (lon BETWEEN -180 AND 180),
        CONSTRAINT CK_gps_customer_locations_radius CHECK (radius_m BETWEEN 20 AND 2000),
        CONSTRAINT CK_gps_customer_locations_source CHECK ([source] IN (N'dtc_poi', N'manual'))
    );
END;

COMMIT TRANSACTION;
GO

/* Indexes (separate batch so the new column/tables are visible) */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'UX_delivery_plan_truck_master_dtc_gps_id' AND object_id = OBJECT_ID(N'dbo.delivery_plan_truck_master'))
    CREATE UNIQUE INDEX UX_delivery_plan_truck_master_dtc_gps_id
        ON dbo.delivery_plan_truck_master (dtc_gps_id) WHERE dtc_gps_id IS NOT NULL;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'UX_gps_customer_locations_customer_address' AND object_id = OBJECT_ID(N'dbo.gps_customer_locations'))
    CREATE UNIQUE INDEX UX_gps_customer_locations_customer_address
        ON dbo.gps_customer_locations (company, customer_id, address_key) WHERE is_active = 1;
GO

/* Check */
SELECT
    CASE WHEN COL_LENGTH(N'dbo.delivery_plan_truck_master', N'dtc_gps_id') IS NULL THEN 'MISSING' ELSE 'OK' END AS truck_dtc_gps_id,
    CASE WHEN OBJECT_ID(N'dbo.gps_dtc_pois', N'U') IS NULL THEN 'MISSING' ELSE 'OK' END AS gps_dtc_pois,
    CASE WHEN OBJECT_ID(N'dbo.gps_customer_locations', N'U') IS NULL THEN 'MISSING' ELSE 'OK' END AS gps_customer_locations;
