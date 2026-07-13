-- เพิ่มคอลัมน์ company + serialnumber ให้ ds_item_review_logs
-- เพื่อให้ log ระบุตัว item ได้ด้วยตัวเอง แม้ snapshot item จะถูกสร้างใหม่ (id เปลี่ยน)
-- รันซ้ำได้ (มี IF NOT EXISTS ครบ)

IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('ds_item_review_logs') AND name = 'company'
)
BEGIN
    ALTER TABLE ds_item_review_logs ADD company NVARCHAR(50) NULL;
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('ds_item_review_logs') AND name = 'serialnumber'
)
BEGIN
    ALTER TABLE ds_item_review_logs ADD serialnumber NVARCHAR(120) NULL;
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('ds_item_review_logs') AND name = 'ds_review_log_serial_idx'
)
BEGIN
    CREATE INDEX ds_review_log_serial_idx ON ds_item_review_logs (serialnumber);
END
GO

-- Backfill จาก item ที่ยังผูกกันอยู่ (log ที่ item ถูกลบไปแล้วจะยังเป็น NULL)
UPDATE l
SET l.company = i.company,
    l.serialnumber = i.serialnumber
FROM ds_item_review_logs l
INNER JOIN ds_snapshot_items i ON i.id = l.snapshot_item_id
WHERE l.serialnumber IS NULL;
GO
