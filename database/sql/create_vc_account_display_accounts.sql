IF OBJECT_ID(N'dbo.vc_account_display_accounts', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.vc_account_display_accounts (
        id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        account_code NVARCHAR(20) NOT NULL,
        account_name NVARCHAR(255) NULL,
        is_active BIT NOT NULL CONSTRAINT DF_vc_account_display_accounts_active DEFAULT (0),
        created_at DATETIME2 NULL,
        updated_at DATETIME2 NULL,
        CONSTRAINT UX_vc_account_display_accounts_code UNIQUE (account_code)
    );
END;

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_vc_account_display_accounts_active'
      AND object_id = OBJECT_ID(N'dbo.vc_account_display_accounts')
)
BEGIN
    CREATE INDEX IX_vc_account_display_accounts_active
        ON dbo.vc_account_display_accounts (is_active, account_code);
END;

WITH defaults(account_code, account_name) AS (
    SELECT N'5210100', N'ค่าซ่อมเครื่องจักร เครื่องมือ' UNION ALL
    SELECT N'5210310', N'ค่าวัสดุสิ้นเปลือง-สารเคมี' UNION ALL
    SELECT N'5210330', N'ค่าวัสดุสิ้นเปลือง-บรรจุภัณฑ์' UNION ALL
    SELECT N'5210340', N'ค่าวัสดุสิ้นเปลือง-อะไหล่' UNION ALL
    SELECT N'5210350', N'ค่าวัสดุสิ้นเปลือง-ทั่วไป' UNION ALL
    SELECT N'5210370', N'ค่าวัสดุสิ้นเปลือง-ไดร์' UNION ALL
    SELECT N'5210401', N'ค่าบริการทดสอบงาน' UNION ALL
    SELECT N'5210602', N'ค่าจ้างกัดกรดเหล็ก' UNION ALL
    SELECT N'5210700', N'ค่าภาชนะ' UNION ALL
    SELECT N'5211000', N'ค่าไฟฟ้า-โรงงาน' UNION ALL
    SELECT N'5211100', N'ค่าน้ำประปา-โรงงาน' UNION ALL
    SELECT N'5211200', N'ค่าเครื่องเขียนแบบพิมพ์-โรงงาน' UNION ALL
    SELECT N'5211700', N'ค่าซ่อมแซมอุปกรณ์สนง.-โรงงาน' UNION ALL
    SELECT N'5211800', N'ค่าใช้จ่ายเดินทางและที่พัก-โรงงาน' UNION ALL
    SELECT N'5220200', N'เงินเดือน/ค่าแรงพนักงาน-โรงงาน' UNION ALL
    SELECT N'5220600', N'ค่าล่วงเวลา-โรงงาน' UNION ALL
    SELECT N'6030001', N'ค่าพาหนะน้ำมัน-จัดส่ง' UNION ALL
    SELECT N'6040000', N'ค่าจ้างรถส่งสินค้า' UNION ALL
    SELECT N'6050000', N'ค่าวัสดุสิ้นเปลือง-ขาย-จัดส่ง' UNION ALL
    SELECT N'6060100', N'ค่าซ่อมโฟล์คลิฟ-จัดส่ง' UNION ALL
    SELECT N'6120201', N'เงินเดือนพนักงาน-จัดส่ง' UNION ALL
    SELECT N'6120401', N'ค่าล่วงเวลา-จัดส่ง' UNION ALL
    SELECT N'7050000', N'ค่าเครื่องเขียนแบบพิมพ์-สำนักงาน' UNION ALL
    SELECT N'7060200', N'ค่าซ่อมอุปกรณ์-สำนักงาน' UNION ALL
    SELECT N'7070000', N'ค่าใช้จ่ายเกี่ยวกับรถยนต์' UNION ALL
    SELECT N'7080000', N'ค่าพาหนะ น้ำมัน-สำนักงาน'
)
MERGE dbo.vc_account_display_accounts AS target
USING defaults AS source
   ON target.account_code = source.account_code
WHEN MATCHED THEN
    UPDATE SET
        target.account_name = source.account_name,
        target.is_active = 1,
        target.updated_at = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (account_code, account_name, is_active, created_at, updated_at)
    VALUES (source.account_code, source.account_name, 1, SYSDATETIME(), SYSDATETIME());
