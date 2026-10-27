/* Additive performance and invariant indexes. Safe to rerun. */
SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.wr_ds_item_lifecycles') AND name = N'UX_wr_ds_lifecycle_current')
        CREATE UNIQUE INDEX UX_wr_ds_lifecycle_current
            ON dbo.wr_ds_item_lifecycles (site_code, serial_number)
            WHERE is_current = 1;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.wr_ds_current_items') AND name = N'IX_wr_ds_current_filter')
        CREATE INDEX IX_wr_ds_current_filter
            ON dbo.wr_ds_current_items (site_code, stock_status, material_group, display_unit)
            INCLUDE (part_number, purchase_date, current_qty, current_value_thb, trace_status, effective_division_code);

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.wr_ds_current_items') AND name = N'IX_wr_ds_current_sales')
        CREATE INDEX IX_wr_ds_current_sales
            ON dbo.wr_ds_current_items (effective_sales_key, trace_status, site_code)
            INCLUDE (serial_number, part_number, current_qty, display_unit);

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.wr_ds_snapshots') AND name = N'IX_wr_ds_snapshot_kind_date')
        CREATE INDEX IX_wr_ds_snapshot_kind_date
            ON dbo.wr_ds_snapshots (snapshot_kind, as_of_at DESC, completion_status);

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.wr_ds_snapshot_items') AND name = N'IX_wr_ds_snapshot_item_filter')
        CREATE INDEX IX_wr_ds_snapshot_item_filter
            ON dbo.wr_ds_snapshot_items (snapshot_id, site_code, stock_status, material_group)
            INCLUDE (serial_number, part_number, snapshot_qty, display_unit, snapshot_value_thb, effective_sales_key);

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.wr_ds_item_reviews') AND name = N'IX_wr_ds_review_status_followup')
        CREATE INDEX IX_wr_ds_review_status_followup
            ON dbo.wr_ds_item_reviews (review_status, next_follow_up_date)
            INCLUDE (lifecycle_id, reviewed_at, updated_at);

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.wr_ds_item_review_logs') AND name = N'IX_wr_ds_review_log_lifecycle_time')
        CREATE INDEX IX_wr_ds_review_log_lifecycle_time
            ON dbo.wr_ds_item_review_logs (lifecycle_id, changed_at DESC);

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.wr_ds_compare_logs') AND name = N'IX_wr_ds_compare_lifecycle_time')
        CREATE INDEX IX_wr_ds_compare_lifecycle_time
            ON dbo.wr_ds_compare_logs (lifecycle_id, checked_at DESC)
            INCLUDE (compare_status, became_dead_stock, qty_difference);

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.wr_ds_compare_logs') AND name = N'IX_wr_ds_compare_status_time')
        CREATE INDEX IX_wr_ds_compare_status_time
            ON dbo.wr_ds_compare_logs (compare_status, checked_at DESC)
            INCLUDE (lifecycle_id, became_dead_stock);

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.wr_ds_sales_overrides') AND name = N'UX_wr_ds_override_active')
        CREATE UNIQUE INDEX UX_wr_ds_override_active
            ON dbo.wr_ds_sales_overrides (lifecycle_id)
            WHERE is_active = 1;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.wr_ds_user_sales_access') AND name = N'IX_wr_ds_access_scope')
        CREATE INDEX IX_wr_ds_access_scope
            ON dbo.wr_ds_user_sales_access (salesperson_key, is_active)
            INCLUDE (user_id, can_edit);

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.wr_ds_sync_runs') AND name = N'IX_wr_ds_sync_status_time')
        CREATE INDEX IX_wr_ds_sync_status_time
            ON dbo.wr_ds_sync_runs (site_code, status, started_at DESC)
            INCLUDE (run_type, finished_at, source_item_count, applied_item_count);

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;

SELECT OBJECT_NAME(object_id) AS table_name, name AS index_name, is_unique, has_filter, filter_definition
FROM sys.indexes
WHERE OBJECT_NAME(object_id) LIKE N'wr_ds_%'
  AND name IS NOT NULL
ORDER BY table_name, index_name;
