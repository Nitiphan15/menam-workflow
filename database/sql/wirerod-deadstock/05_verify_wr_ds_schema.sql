/* Read-only verification. This script performs no persistent write or DDL. */
SET NOCOUNT ON;

WITH expected (table_name) AS
(
    SELECT table_name
    FROM (VALUES
        (N'wr_ds_item_lifecycles'),
        (N'wr_ds_current_items'),
        (N'wr_ds_snapshots'),
        (N'wr_ds_snapshot_items'),
        (N'wr_ds_item_reviews'),
        (N'wr_ds_item_review_logs'),
        (N'wr_ds_compare_logs'),
        (N'wr_ds_sales_overrides'),
        (N'wr_ds_user_sales_access'),
        (N'wr_ds_sync_runs')
    ) AS names(table_name)
)
SELECT e.table_name,
       CASE WHEN t.object_id IS NULL THEN N'MISSING' ELSE N'OK' END AS status
FROM expected AS e
LEFT JOIN sys.tables AS t
    ON t.schema_id = SCHEMA_ID(N'dbo')
   AND t.name = e.table_name
ORDER BY e.table_name;

SELECT t.name AS table_name,
       c.column_id,
       c.name AS column_name,
       TYPE_NAME(c.user_type_id) AS data_type,
       c.max_length,
       c.precision,
       c.scale,
       c.is_nullable
FROM sys.tables AS t
INNER JOIN sys.columns AS c ON c.object_id = t.object_id
WHERE t.schema_id = SCHEMA_ID(N'dbo')
  AND t.name LIKE N'wr_ds_%'
ORDER BY t.name, c.column_id;

SELECT OBJECT_NAME(i.object_id) AS table_name,
       i.name AS index_name,
       i.is_unique,
       i.has_filter,
       i.filter_definition
FROM sys.indexes AS i
WHERE OBJECT_NAME(i.object_id) LIKE N'wr_ds_%'
  AND i.name IS NOT NULL
ORDER BY table_name, index_name;

SELECT code, name, is_active
FROM dbo.dept_roles
WHERE code IN (N'WR_DS_EDIT_OWN', N'WR_DS_MANAGE_ALL', N'WR_DS_ADMIN')
ORDER BY code;

SELECT
    (SELECT COUNT_BIG(*) FROM dbo.wr_ds_item_lifecycles) AS lifecycle_rows,
    (SELECT COUNT_BIG(*) FROM dbo.wr_ds_current_items) AS current_rows,
    (SELECT COUNT_BIG(*) FROM dbo.wr_ds_snapshots) AS snapshot_rows,
    (SELECT COUNT_BIG(*) FROM dbo.wr_ds_snapshot_items) AS snapshot_item_rows,
    (SELECT COUNT_BIG(*) FROM dbo.wr_ds_item_reviews) AS review_rows,
    (SELECT COUNT_BIG(*) FROM dbo.wr_ds_item_review_logs) AS review_log_rows,
    (SELECT COUNT_BIG(*) FROM dbo.wr_ds_compare_logs) AS compare_log_rows,
    (SELECT COUNT_BIG(*) FROM dbo.wr_ds_sales_overrides) AS override_rows;

SELECT site_code, serial_number, COUNT(*) AS active_cycle_count
FROM dbo.wr_ds_item_lifecycles
WHERE is_current = 1
GROUP BY site_code, serial_number
HAVING COUNT(*) > 1;

SELECT lifecycle_id, COUNT(*) AS active_override_count
FROM dbo.wr_ds_sales_overrides
WHERE is_active = 1
GROUP BY lifecycle_id
HAVING COUNT(*) > 1;
