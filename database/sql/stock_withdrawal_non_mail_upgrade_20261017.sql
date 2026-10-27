SET XACT_ABORT ON;
BEGIN TRANSACTION;

IF NOT EXISTS (SELECT 1 FROM dbo.dept_roles WHERE UPPER(code) = 'STOCK_ACTION')
BEGIN
    INSERT INTO dbo.dept_roles (code, name, is_active)
    VALUES ('STOCK_ACTION', 'Stock Withdrawal Planner', 1);
END;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'uq_stock_withdraw_active_mfg'
      AND object_id = OBJECT_ID('dbo.stock_withdrawal_plans')
)
BEGIN
    IF EXISTS (
        SELECT site, workorder_id
        FROM dbo.stock_withdrawal_plans
        WHERE cancelled_at IS NULL
        GROUP BY site, workorder_id
        HAVING COUNT(*) > 1
    )
        PRINT N'ข้าม Unique Index: พบ MFG ที่มี Active Plan ซ้ำ กรุณาตรวจสอบข้อมูลก่อน';
    ELSE
        CREATE UNIQUE INDEX uq_stock_withdraw_active_mfg
            ON dbo.stock_withdrawal_plans(site, workorder_id)
            WHERE cancelled_at IS NULL;
END;

COMMIT TRANSACTION;

SELECT code, name, is_active
FROM dbo.dept_roles
WHERE UPPER(code) = 'STOCK_ACTION';

SELECT name AS index_name
FROM sys.indexes
WHERE name = 'uq_stock_withdraw_active_mfg'
  AND object_id = OBJECT_ID('dbo.stock_withdrawal_plans');
