SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRANSACTION;

IF COL_LENGTH('dbo.po_attached', 'attachment_type') IS NULL
    ALTER TABLE dbo.po_attached ADD attachment_type NVARCHAR(30) NULL;

IF COL_LENGTH('dbo.po_attached', 'attachment_source') IS NULL
    ALTER TABLE dbo.po_attached ADD attachment_source NVARCHAR(20) NULL;

IF COL_LENGTH('dbo.po_attached', 'po_version') IS NULL
    ALTER TABLE dbo.po_attached ADD po_version INT NULL;

COMMIT TRANSACTION;

SELECT
    c.name AS column_name,
    t.name AS data_type,
    c.max_length,
    c.is_nullable
FROM sys.columns c
JOIN sys.types t ON t.user_type_id = c.user_type_id
WHERE c.object_id = OBJECT_ID('dbo.po_attached')
  AND c.name IN ('attachment_type', 'attachment_source', 'po_version')
ORDER BY c.column_id;
