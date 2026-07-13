DECLARE @sales_code NVARCHAR(20) = 'D7';
DECLARE @base_month DATE = '2026-07-01';
DECLARE @user_id INT = 2;

SELECT
    'PART_SETTING_TO_UNSELECT' AS check_name,
    COUNT(*) AS rows_count
FROM dbo.fc_rm_division_part_setting
WHERE sales_code = @sales_code
  AND forecast_base_month = @base_month
  AND is_selected = 1
  AND updated_by = @user_id;

SELECT
    'CURRENT_FORECAST_FROM_USER_BATCH' AS check_name,
    COUNT(*) AS rows_count
FROM dbo.fc_rm_division_forecast f
JOIN dbo.fc_rm_division_forecast_batch b ON b.id = f.batch_id
WHERE b.sales_code = @sales_code
  AND b.forecast_base_month = @base_month
  AND b.created_by = @user_id;

SELECT
    'HISTORY_FROM_USER_BATCH' AS check_name,
    COUNT(*) AS rows_count
FROM dbo.fc_rm_division_forecast_item_history h
JOIN dbo.fc_rm_division_forecast_batch b ON b.id = h.batch_id
WHERE b.sales_code = @sales_code
  AND b.forecast_base_month = @base_month
  AND b.created_by = @user_id;

SELECT
    'BATCH_FROM_USER' AS check_name,
    COUNT(*) AS rows_count
FROM dbo.fc_rm_division_forecast_batch
WHERE sales_code = @sales_code
  AND forecast_base_month = @base_month
  AND created_by = @user_id;

BEGIN TRAN;

UPDATE dbo.fc_rm_division_part_setting
SET is_selected = 0,
    updated_at = SYSDATETIME()
WHERE sales_code = @sales_code
  AND forecast_base_month = @base_month
  AND is_selected = 1
  AND updated_by = @user_id;

DELETE f
FROM dbo.fc_rm_division_forecast f
JOIN dbo.fc_rm_division_forecast_batch b ON b.id = f.batch_id
WHERE b.sales_code = @sales_code
  AND b.forecast_base_month = @base_month
  AND b.created_by = @user_id;

DELETE h
FROM dbo.fc_rm_division_forecast_item_history h
JOIN dbo.fc_rm_division_forecast_batch b ON b.id = h.batch_id
WHERE b.sales_code = @sales_code
  AND b.forecast_base_month = @base_month
  AND b.created_by = @user_id;

DELETE FROM dbo.fc_rm_division_forecast_batch
WHERE sales_code = @sales_code
  AND forecast_base_month = @base_month
  AND created_by = @user_id;

SELECT
    'PART_SETTING_STILL_SELECTED_BY_USER' AS check_name,
    COUNT(*) AS rows_count
FROM dbo.fc_rm_division_part_setting
WHERE sales_code = @sales_code
  AND forecast_base_month = @base_month
  AND is_selected = 1
  AND updated_by = @user_id;

SELECT
    'REMAINING_SELECTED' AS check_name,
    sales_code,
    forecast_base_month,
    customer_id,
    customer_name,
    fg_partnumber,
    fg_description,
    is_selected,
    updated_by,
    updated_at
FROM dbo.fc_rm_division_part_setting
WHERE sales_code = @sales_code
  AND forecast_base_month = @base_month
  AND is_selected = 1
ORDER BY updated_by, customer_id, fg_partnumber;

COMMIT;
