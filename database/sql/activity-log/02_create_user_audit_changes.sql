/*
  Audit Trail: เก็บค่าก่อน/หลังการแก้ไขข้อมูลสำคัญ (เริ่มจากหน้า adminweb)
  Connection: sqlsrv_menam
  รันด้วยมือเท่านั้น และต้องรันก่อน deploy โค้ดชุดนี้
  สร้างตารางใหม่อย่างเดียว ถ้ามีอยู่แล้วจะข้าม
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;

IF OBJECT_ID(N'dbo.user_audit_changes', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.user_audit_changes
    (
        id           BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_user_audit_changes PRIMARY KEY,
        user_id      BIGINT NULL,                 -- ผู้ที่ทำการแก้ไข
        user_name    NVARCHAR(150) NULL,          -- snapshot ชื่อผู้แก้ไข
        entity_type  NVARCHAR(100) NOT NULL,      -- เช่น user, role, department, dept_role, user_dept_role, user_permission
        entity_id    NVARCHAR(100) NOT NULL,
        entity_label NVARCHAR(200) NULL,          -- ชื่อที่อ่านง่าย เช่น ชื่อผู้ใช้/ชื่อ role
        event        NVARCHAR(20)  NOT NULL,      -- created / updated / deleted
        changes      NVARCHAR(MAX) NOT NULL,      -- JSON {"field": {"old": ..., "new": ...}}
        route_name   NVARCHAR(150) NULL,
        ip_address   NVARCHAR(45)  NULL,
        changed_at   DATETIME2(0)  NOT NULL CONSTRAINT DF_user_audit_changes_changed_at DEFAULT (SYSDATETIME()),
        CONSTRAINT CK_user_audit_changes_event CHECK (event IN (N'created', N'updated', N'deleted'))
    );

    CREATE INDEX uac_entity_idx ON dbo.user_audit_changes (entity_type, entity_id, changed_at DESC);
    CREATE INDEX uac_changed_at_idx ON dbo.user_audit_changes (changed_at DESC);
    CREATE INDEX uac_user_idx ON dbo.user_audit_changes (user_id, changed_at DESC);
END;
GO

/* ตรวจผล */
SELECT name, create_date FROM sys.tables WHERE name = N'user_audit_changes';
