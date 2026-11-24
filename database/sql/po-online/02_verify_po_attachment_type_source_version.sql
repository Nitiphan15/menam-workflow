SET NOCOUNT ON;

SELECT
    CASE WHEN COUNT(*) = 3 THEN 'PASS' ELSE 'FAIL' END AS schema_check,
    COUNT(*) AS installed_columns
FROM sys.columns
WHERE object_id = OBJECT_ID('dbo.po_attached')
  AND name IN ('attachment_type', 'attachment_source', 'po_version');

SELECT TOP (50)
    id,
    po_header_id,
    file_name,
    attachment_type,
    attachment_source,
    po_version,
    workflow_step_no,
    created_at,
    created_by
FROM dbo.po_attached
WHERE attachment_type = 'PO_SUBMIT'
ORDER BY id DESC;
