/*
  Activity Log: เก็บแผนกของผู้ใช้ ณ เวลาที่ทำรายการ
  Connection: sqlsrv_menam (DB เดียวกับตาราง user_activity_logs)
  รันด้วยมือเท่านั้น และต้องรันก่อน deploy โค้ดชุดนี้
  เป็นการเพิ่มคอลัมน์ (additive) อย่างเดียว ไม่แก้และไม่ลบข้อมูลเดิม
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;

IF OBJECT_ID(N'dbo.user_activity_logs', N'U') IS NULL
    THROW 51000, 'ไม่พบตาราง dbo.user_activity_logs', 1;

IF COL_LENGTH(N'dbo.user_activity_logs', N'department_id') IS NULL
    ALTER TABLE dbo.user_activity_logs ADD department_id BIGINT NULL;

IF COL_LENGTH(N'dbo.user_activity_logs', N'department_name') IS NULL
    ALTER TABLE dbo.user_activity_logs ADD department_name NVARCHAR(150) NULL;
GO

/* index ช่วยหน้า Activity Log: กรองตามผู้ใช้ + ช่วงเวลา */
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'ual_user_at_idx' AND object_id = OBJECT_ID(N'dbo.user_activity_logs')
)
    CREATE INDEX ual_user_at_idx ON dbo.user_activity_logs (user_id, accessed_at DESC);
GO

/* ตรวจผล */
SELECT c.name, t.name AS type_name, c.max_length, c.is_nullable
FROM sys.columns c
JOIN sys.types t ON t.user_type_id = c.user_type_id
WHERE c.object_id = OBJECT_ID(N'dbo.user_activity_logs')
  AND c.name IN (N'department_id', N'department_name');
