/*
    FormDP: รองรับชื่อลูกค้า/หน่วยงานอิสระสำหรับ delivery_type = 'SPECIAL'

    หลักการเก็บข้อมูล:
    - ลูกค้าที่มีใน master:
        customer_id   = customer.id
        customer_name = NULL
    - ลูกค้าหรือหน่วยงานที่ไม่มีใน master:
        customer_id   = NULL
        customer_name = ชื่อที่ผู้ใช้กรอก

    delivery_plan_data เป็น system-versioned temporal table
    การ ALTER TABLE ขณะ SYSTEM_VERSIONING เปิดอยู่จะปรับ schema ของ history table ให้ตรงกันด้วย
*/

USE [menam_workflow];
SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRANSACTION;

IF COL_LENGTH(N'dbo.delivery_plan_data', N'customer_name') IS NULL
BEGIN
    ALTER TABLE dbo.delivery_plan_data
        ADD customer_name NVARCHAR(255) NULL;
END;

-- บังคับให้ collation ตรงกับฐานข้อมูล เพื่อให้ COALESCE/JOIN กับ dbo.customer.name ได้
ALTER TABLE dbo.delivery_plan_data
    ALTER COLUMN customer_name NVARCHAR(255) COLLATE DATABASE_DEFAULT NULL;

IF EXISTS (
    SELECT 1
    FROM sys.columns
    WHERE object_id = OBJECT_ID(N'dbo.delivery_plan_data')
      AND name = N'customer_id'
      AND is_nullable = 0
)
BEGIN
    ALTER TABLE dbo.delivery_plan_data
        ALTER COLUMN customer_id INT NULL;
END;

COMMIT TRANSACTION;

SELECT
    c.name AS column_name,
    TYPE_NAME(c.user_type_id) AS data_type,
    c.max_length,
    c.is_nullable
FROM sys.columns c
WHERE c.object_id = OBJECT_ID(N'dbo.delivery_plan_data')
  AND c.name IN (N'customer_id', N'customer_name')
ORDER BY c.column_id;
