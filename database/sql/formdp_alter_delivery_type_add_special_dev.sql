/*
    FormDP: allow delivery_type = 'SPECIAL' (งานพิเศษ - ส่งซ่อม/ส่งคืน, ผู้รับผิดชอบ = Export)

    The existing CHECK constraint CK_dpd_delivery_type on dbo.delivery_plan_data
    only allowed ('SO','ACID','MANUAL'). This script recreates it to also allow
    'SPECIAL' while preserving every previously allowed value.

    Safe: only the CHECK constraint is rebuilt. No data is modified or deleted.
*/

USE [menam_workflow];
SET NOCOUNT ON;

IF EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE name = N'CK_dpd_delivery_type'
      AND parent_object_id = OBJECT_ID(N'dbo.delivery_plan_data')
)
BEGIN
    ALTER TABLE dbo.delivery_plan_data DROP CONSTRAINT CK_dpd_delivery_type;
END;

ALTER TABLE dbo.delivery_plan_data
    ADD CONSTRAINT CK_dpd_delivery_type
    CHECK ([delivery_type] IN ('SO', 'ACID', 'MANUAL', 'SPECIAL'));
