IF COL_LENGTH('dbo.po_headers', 'site') IS NULL
BEGIN
    ALTER TABLE dbo.po_headers
    ADD site NVARCHAR(20) NULL;
END;
GO

UPDATE dbo.po_headers
SET site = 'wire'
WHERE site IS NULL;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'IX_po_headers_ordnumber_site'
      AND object_id = OBJECT_ID('dbo.po_headers')
)
BEGIN
    CREATE INDEX IX_po_headers_ordnumber_site
    ON dbo.po_headers (ordnumber, site);
END;
GO
