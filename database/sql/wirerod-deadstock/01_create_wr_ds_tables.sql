/*
  Wire Rod Dead Stock — additive SQL Server schema.
  Manual execution only. This script does not seed permissions or users.
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;

IF CAST(SERVERPROPERTY('EngineEdition') AS INT) IS NULL
    THROW 51000, 'Microsoft SQL Server is required.', 1;

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'dbo.wr_ds_item_lifecycles', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.wr_ds_item_lifecycles
        (
            id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_wr_ds_item_lifecycles PRIMARY KEY,
            site_code NVARCHAR(10) NOT NULL,
            serial_number NVARCHAR(160) NOT NULL,
            cycle_no INT NOT NULL,
            is_current BIT NOT NULL CONSTRAINT DF_wr_ds_lifecycle_current DEFAULT (1),
            opened_at DATETIME2(0) NOT NULL,
            last_seen_at DATETIME2(0) NULL,
            cleared_at DATETIME2(0) NULL,
            clear_reason NVARCHAR(100) NULL,
            created_at DATETIME2(0) NOT NULL CONSTRAINT DF_wr_ds_lifecycle_created DEFAULT (SYSDATETIME()),
            updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_wr_ds_lifecycle_updated DEFAULT (SYSDATETIME()),
            CONSTRAINT UQ_wr_ds_lifecycle_cycle UNIQUE (site_code, serial_number, cycle_no),
            CONSTRAINT CK_wr_ds_lifecycle_site CHECK (site_code IN (N'WIRE', N'PLUS')),
            CONSTRAINT CK_wr_ds_lifecycle_cycle CHECK (cycle_no > 0)
        );
    END;

    IF OBJECT_ID(N'dbo.wr_ds_current_items', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.wr_ds_current_items
        (
            id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_wr_ds_current_items PRIMARY KEY,
            lifecycle_id BIGINT NOT NULL,
            site_code NVARCHAR(10) NOT NULL,
            serial_number NVARCHAR(160) NOT NULL,
            erp_serializeunit_id BIGINT NULL,
            erp_part_id BIGINT NULL,
            part_number NVARCHAR(120) NOT NULL,
            part_description NVARCHAR(500) NULL,
            part_type_id INT NULL,
            material_group NVARCHAR(40) NOT NULL,
            heat_number NVARCHAR(160) NULL,
            grade NVARCHAR(120) NULL,
            diameter_size NVARCHAR(120) NULL,
            purchase_date DATE NULL,
            stock_age_days INT NULL,
            stock_status NVARCHAR(20) NOT NULL,
            became_dead_stock BIT NOT NULL CONSTRAINT DF_wr_ds_current_became_dead DEFAULT (0),
            became_dead_at DATE NULL,
            source_unit_code NVARCHAR(20) NULL,
            display_unit NVARCHAR(20) NOT NULL,
            unit_rule_source NVARCHAR(20) NOT NULL,
            unit_conflict BIT NOT NULL CONSTRAINT DF_wr_ds_current_unit_conflict DEFAULT (0),
            current_qty DECIMAL(18,4) NOT NULL,
            unitcost_thb DECIMAL(18,4) NULL,
            current_value_thb DECIMAL(18,4) NULL,
            po_number NVARCHAR(120) NULL,
            po_date DATE NULL,
            purchase_qty DECIMAL(18,4) NULL,
            used_qty DECIMAL(18,4) NULL,
            trace_status NVARCHAR(40) NOT NULL,
            mapping_source NVARCHAR(20) NOT NULL,
            erp_salesperson_id BIGINT NULL,
            erp_salesperson_name NVARCHAR(255) NULL,
            erp_division_code NVARCHAR(30) NULL,
            effective_sales_key NVARCHAR(80) NULL,
            effective_sales_name NVARCHAR(255) NULL,
            effective_division_code NVARCHAR(30) NULL,
            mfg_json NVARCHAR(MAX) NULL,
            sales_order_json NVARCHAR(MAX) NULL,
            customer_json NVARCHAR(MAX) NULL,
            sales_json NVARCHAR(MAX) NULL,
            source_retrieved_at DATETIME2(0) NOT NULL,
            last_successful_sync_at DATETIME2(0) NOT NULL,
            created_at DATETIME2(0) NOT NULL CONSTRAINT DF_wr_ds_current_created DEFAULT (SYSDATETIME()),
            updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_wr_ds_current_updated DEFAULT (SYSDATETIME()),
            CONSTRAINT UQ_wr_ds_current_site_serial UNIQUE (site_code, serial_number),
            CONSTRAINT UQ_wr_ds_current_lifecycle UNIQUE (lifecycle_id),
            CONSTRAINT FK_wr_ds_current_lifecycle FOREIGN KEY (lifecycle_id) REFERENCES dbo.wr_ds_item_lifecycles(id),
            CONSTRAINT CK_wr_ds_current_site CHECK (site_code IN (N'WIRE', N'PLUS')),
            CONSTRAINT CK_wr_ds_current_stock_status CHECK (stock_status IN (N'INCOMING', N'DEAD', N'UNKNOWN')),
            CONSTRAINT CK_wr_ds_current_display_unit CHECK (display_unit IN (N'KG', N'PCS', N'UNKNOWN')),
            CONSTRAINT CK_wr_ds_current_mapping_source CHECK (mapping_source IN (N'MANUAL', N'MFG', N'PO', N'UNMAPPED')),
            CONSTRAINT CK_wr_ds_current_qty CHECK (current_qty >= 0),
            CONSTRAINT CK_wr_ds_current_mfg_json CHECK (mfg_json IS NULL OR ISJSON(mfg_json) = 1),
            CONSTRAINT CK_wr_ds_current_so_json CHECK (sales_order_json IS NULL OR ISJSON(sales_order_json) = 1),
            CONSTRAINT CK_wr_ds_current_customer_json CHECK (customer_json IS NULL OR ISJSON(customer_json) = 1),
            CONSTRAINT CK_wr_ds_current_sales_json CHECK (sales_json IS NULL OR ISJSON(sales_json) = 1)
        );
    END;

    IF OBJECT_ID(N'dbo.wr_ds_snapshots', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.wr_ds_snapshots
        (
            id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_wr_ds_snapshots PRIMARY KEY,
            snapshot_kind NVARCHAR(20) NOT NULL,
            as_of_at DATETIME2(0) NOT NULL,
            source_retrieved_at DATETIME2(0) NULL,
            completion_status NVARCHAR(20) NOT NULL,
            included_sites NVARCHAR(50) NULL,
            failed_sites NVARCHAR(50) NULL,
            item_count INT NOT NULL CONSTRAINT DF_wr_ds_snapshot_items DEFAULT (0),
            total_qty_kg DECIMAL(18,4) NOT NULL CONSTRAINT DF_wr_ds_snapshot_kg DEFAULT (0),
            total_qty_pcs DECIMAL(18,4) NOT NULL CONSTRAINT DF_wr_ds_snapshot_pcs DEFAULT (0),
            total_value_thb DECIMAL(18,4) NOT NULL CONSTRAINT DF_wr_ds_snapshot_value DEFAULT (0),
            generated_by INT NULL,
            generated_by_name NVARCHAR(150) NULL,
            note NVARCHAR(1000) NULL,
            created_at DATETIME2(0) NOT NULL CONSTRAINT DF_wr_ds_snapshot_created DEFAULT (SYSDATETIME()),
            CONSTRAINT CK_wr_ds_snapshot_kind CHECK (snapshot_kind IN (N'DAILY', N'MONTH_END', N'MANUAL')),
            CONSTRAINT CK_wr_ds_snapshot_status CHECK (completion_status IN (N'COMPLETE', N'PARTIAL', N'FAILED'))
        );
    END;

    IF OBJECT_ID(N'dbo.wr_ds_snapshot_items', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.wr_ds_snapshot_items
        (
            id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_wr_ds_snapshot_items PRIMARY KEY,
            snapshot_id BIGINT NOT NULL,
            lifecycle_id BIGINT NOT NULL,
            site_code NVARCHAR(10) NOT NULL,
            serial_number NVARCHAR(160) NOT NULL,
            cycle_no INT NOT NULL,
            part_number NVARCHAR(120) NOT NULL,
            part_description NVARCHAR(500) NULL,
            part_type_id INT NULL,
            material_group NVARCHAR(40) NOT NULL,
            heat_number NVARCHAR(160) NULL,
            grade NVARCHAR(120) NULL,
            diameter_size NVARCHAR(120) NULL,
            purchase_date DATE NULL,
            stock_age_days INT NULL,
            stock_status NVARCHAR(20) NOT NULL,
            became_dead_stock BIT NOT NULL CONSTRAINT DF_wr_ds_snap_item_became_dead DEFAULT (0),
            source_unit_code NVARCHAR(20) NULL,
            display_unit NVARCHAR(20) NOT NULL,
            division_code NVARCHAR(30) NULL,
            snapshot_qty DECIMAL(18,4) NOT NULL,
            unitcost_thb DECIMAL(18,4) NULL,
            snapshot_value_thb DECIMAL(18,4) NULL,
            trace_status NVARCHAR(40) NOT NULL,
            mapping_source NVARCHAR(20) NOT NULL,
            effective_sales_key NVARCHAR(80) NULL,
            effective_sales_name NVARCHAR(255) NULL,
            effective_division_code NVARCHAR(30) NULL,
            trace_json NVARCHAR(MAX) NULL,
            created_at DATETIME2(0) NOT NULL CONSTRAINT DF_wr_ds_snap_item_created DEFAULT (SYSDATETIME()),
            CONSTRAINT UQ_wr_ds_snap_item UNIQUE (snapshot_id, site_code, serial_number, cycle_no),
            CONSTRAINT FK_wr_ds_snap_item_snapshot FOREIGN KEY (snapshot_id) REFERENCES dbo.wr_ds_snapshots(id),
            CONSTRAINT FK_wr_ds_snap_item_lifecycle FOREIGN KEY (lifecycle_id) REFERENCES dbo.wr_ds_item_lifecycles(id),
            CONSTRAINT CK_wr_ds_snap_item_trace_json CHECK (trace_json IS NULL OR ISJSON(trace_json) = 1)
        );
    END;

    IF OBJECT_ID(N'dbo.wr_ds_item_reviews', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.wr_ds_item_reviews
        (
            id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_wr_ds_item_reviews PRIMARY KEY,
            lifecycle_id BIGINT NOT NULL,
            review_status NVARCHAR(50) NOT NULL CONSTRAINT DF_wr_ds_review_status DEFAULT (N'open'),
            review_detail NVARCHAR(MAX) NULL,
            corrective_action NVARCHAR(MAX) NULL,
            preventive_action NVARCHAR(MAX) NULL,
            sales_remark NVARCHAR(MAX) NULL,
            next_follow_up_date DATE NULL,
            reviewed_by INT NULL,
            reviewed_by_name NVARCHAR(150) NULL,
            reviewed_at DATETIME2(0) NULL,
            created_by INT NULL,
            updated_by INT NULL,
            created_at DATETIME2(0) NOT NULL CONSTRAINT DF_wr_ds_review_created DEFAULT (SYSDATETIME()),
            updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_wr_ds_review_updated DEFAULT (SYSDATETIME()),
            CONSTRAINT UQ_wr_ds_review_lifecycle UNIQUE (lifecycle_id),
            CONSTRAINT FK_wr_ds_review_lifecycle FOREIGN KEY (lifecycle_id) REFERENCES dbo.wr_ds_item_lifecycles(id),
            CONSTRAINT CK_wr_ds_review_status CHECK (review_status IN (N'open', N'waiting_follow_up', N'in_progress'))
        );
    END;

    IF OBJECT_ID(N'dbo.wr_ds_item_review_logs', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.wr_ds_item_review_logs
        (
            id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_wr_ds_item_review_logs PRIMARY KEY,
            lifecycle_id BIGINT NOT NULL,
            review_id BIGINT NULL,
            site_code NVARCHAR(10) NOT NULL,
            serial_number NVARCHAR(160) NOT NULL,
            cycle_no INT NOT NULL,
            action NVARCHAR(30) NOT NULL,
            source NVARCHAR(30) NOT NULL,
            changed_fields NVARCHAR(MAX) NULL,
            before_values NVARCHAR(MAX) NULL,
            after_values NVARCHAR(MAX) NULL,
            changed_by INT NULL,
            changed_by_name NVARCHAR(150) NULL,
            changed_at DATETIME2(0) NOT NULL CONSTRAINT DF_wr_ds_review_log_changed DEFAULT (SYSDATETIME()),
            created_at DATETIME2(0) NOT NULL CONSTRAINT DF_wr_ds_review_log_created DEFAULT (SYSDATETIME()),
            CONSTRAINT FK_wr_ds_review_log_lifecycle FOREIGN KEY (lifecycle_id) REFERENCES dbo.wr_ds_item_lifecycles(id),
            CONSTRAINT FK_wr_ds_review_log_review FOREIGN KEY (review_id) REFERENCES dbo.wr_ds_item_reviews(id),
            CONSTRAINT CK_wr_ds_review_log_changed_json CHECK (changed_fields IS NULL OR ISJSON(changed_fields) = 1),
            CONSTRAINT CK_wr_ds_review_log_before_json CHECK (before_values IS NULL OR ISJSON(before_values) = 1),
            CONSTRAINT CK_wr_ds_review_log_after_json CHECK (after_values IS NULL OR ISJSON(after_values) = 1)
        );
    END;

    IF OBJECT_ID(N'dbo.wr_ds_compare_logs', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.wr_ds_compare_logs
        (
            id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_wr_ds_compare_logs PRIMARY KEY,
            lifecycle_id BIGINT NOT NULL,
            snapshot_item_id BIGINT NULL,
            checked_at DATETIME2(0) NOT NULL,
            compare_status NVARCHAR(20) NOT NULL,
            previous_qty DECIMAL(18,4) NULL,
            current_qty DECIMAL(18,4) NULL,
            qty_difference DECIMAL(18,4) NULL,
            previous_unitcost_thb DECIMAL(18,4) NULL,
            current_unitcost_thb DECIMAL(18,4) NULL,
            became_dead_stock BIT NOT NULL CONSTRAINT DF_wr_ds_compare_became_dead DEFAULT (0),
            changed_fields NVARCHAR(MAX) NULL,
            before_values NVARCHAR(MAX) NULL,
            after_values NVARCHAR(MAX) NULL,
            note NVARCHAR(1000) NULL,
            created_at DATETIME2(0) NOT NULL CONSTRAINT DF_wr_ds_compare_created DEFAULT (SYSDATETIME()),
            CONSTRAINT FK_wr_ds_compare_lifecycle FOREIGN KEY (lifecycle_id) REFERENCES dbo.wr_ds_item_lifecycles(id),
            CONSTRAINT FK_wr_ds_compare_snapshot_item FOREIGN KEY (snapshot_item_id) REFERENCES dbo.wr_ds_snapshot_items(id),
            CONSTRAINT CK_wr_ds_compare_status CHECK (compare_status IN (N'pending', N'active', N'changed', N'cleared')),
            CONSTRAINT CK_wr_ds_compare_changed_json CHECK (changed_fields IS NULL OR ISJSON(changed_fields) = 1),
            CONSTRAINT CK_wr_ds_compare_before_json CHECK (before_values IS NULL OR ISJSON(before_values) = 1),
            CONSTRAINT CK_wr_ds_compare_after_json CHECK (after_values IS NULL OR ISJSON(after_values) = 1)
        );
    END;

    IF OBJECT_ID(N'dbo.wr_ds_sales_overrides', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.wr_ds_sales_overrides
        (
            id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_wr_ds_sales_overrides PRIMARY KEY,
            lifecycle_id BIGINT NOT NULL,
            sales_key NVARCHAR(80) NOT NULL,
            sales_name NVARCHAR(255) NOT NULL,
            division_code NVARCHAR(30) NOT NULL,
            reason_code NVARCHAR(50) NOT NULL,
            remark NVARCHAR(1000) NOT NULL,
            is_active BIT NOT NULL CONSTRAINT DF_wr_ds_override_active DEFAULT (1),
            overridden_by INT NULL,
            overridden_by_name NVARCHAR(150) NULL,
            overridden_at DATETIME2(0) NOT NULL CONSTRAINT DF_wr_ds_override_at DEFAULT (SYSDATETIME()),
            revoked_by INT NULL,
            revoked_by_name NVARCHAR(150) NULL,
            revoked_at DATETIME2(0) NULL,
            revoke_reason NVARCHAR(1000) NULL,
            source_sales_json NVARCHAR(MAX) NULL,
            created_at DATETIME2(0) NOT NULL CONSTRAINT DF_wr_ds_override_created DEFAULT (SYSDATETIME()),
            updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_wr_ds_override_updated DEFAULT (SYSDATETIME()),
            CONSTRAINT FK_wr_ds_override_lifecycle FOREIGN KEY (lifecycle_id) REFERENCES dbo.wr_ds_item_lifecycles(id),
            CONSTRAINT CK_wr_ds_override_source_json CHECK (source_sales_json IS NULL OR ISJSON(source_sales_json) = 1),
            CONSTRAINT CK_wr_ds_override_revoke CHECK (
                (is_active = 1 AND revoked_at IS NULL)
                OR (is_active = 0 AND revoked_at IS NOT NULL AND NULLIF(LTRIM(RTRIM(revoke_reason)), N'') IS NOT NULL)
            )
        );
    END;

    IF OBJECT_ID(N'dbo.wr_ds_user_sales_access', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.wr_ds_user_sales_access
        (
            id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_wr_ds_user_sales_access PRIMARY KEY,
            user_id INT NOT NULL,
            salesperson_key NVARCHAR(50) NOT NULL,
            can_edit BIT NOT NULL CONSTRAINT DF_wr_ds_access_edit DEFAULT (1),
            is_active BIT NOT NULL CONSTRAINT DF_wr_ds_access_active DEFAULT (1),
            created_by INT NULL,
            updated_by INT NULL,
            created_at DATETIME2(0) NOT NULL CONSTRAINT DF_wr_ds_access_created DEFAULT (SYSDATETIME()),
            updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_wr_ds_access_updated DEFAULT (SYSDATETIME()),
            CONSTRAINT UQ_wr_ds_user_sales_access UNIQUE (user_id, salesperson_key)
        );
    END;

    IF OBJECT_ID(N'dbo.wr_ds_sync_runs', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.wr_ds_sync_runs
        (
            id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_wr_ds_sync_runs PRIMARY KEY,
            run_uuid UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_wr_ds_sync_uuid DEFAULT (NEWID()),
            run_type NVARCHAR(20) NOT NULL,
            site_code NVARCHAR(10) NULL,
            started_at DATETIME2(0) NOT NULL,
            finished_at DATETIME2(0) NULL,
            status NVARCHAR(20) NOT NULL,
            source_item_count INT NULL,
            applied_item_count INT NULL,
            duration_ms INT NULL,
            error_message NVARCHAR(2000) NULL,
            triggered_by INT NULL,
            triggered_by_name NVARCHAR(150) NULL,
            created_at DATETIME2(0) NOT NULL CONSTRAINT DF_wr_ds_sync_created DEFAULT (SYSDATETIME()),
            CONSTRAINT UQ_wr_ds_sync_uuid UNIQUE (run_uuid),
            CONSTRAINT CK_wr_ds_sync_type CHECK (run_type IN (N'HOURLY', N'DAILY', N'MONTH_END', N'MANUAL')),
            CONSTRAINT CK_wr_ds_sync_status CHECK (status IN (N'STARTED', N'SUCCEEDED', N'PARTIAL', N'FAILED', N'TIMEOUT'))
        );
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;

SELECT N'Wire Rod Dead Stock tables are present. Run 05_verify_wr_ds_schema.sql before application work.' AS result;
