/*
    Create FormDP special dispatch dev table.

    This table is for non-truck dispatch channels:
    CONTAINER_LOAD, CUSTOMER_PICKUP, SALES_CAR, WEIGHT_REQUEST, POSTPONED.
    It does not alter live tables.
*/

USE [menam_workflow];
SET NOCOUNT ON;

IF OBJECT_ID(N'dbo.delivery_plan_special_dispatch', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.delivery_plan_special_dispatch (
        id int IDENTITY(1,1) NOT NULL PRIMARY KEY,
        ord_id int NOT NULL,
        dispatch_type nvarchar(50) NOT NULL,
        status nvarchar(20) NOT NULL CONSTRAINT DF_delivery_plan_special_dispatch_status DEFAULT (N'OPEN'),
        remark nvarchar(500) NULL,
        action_by int NULL,
        action_at datetime NOT NULL CONSTRAINT DF_delivery_plan_special_dispatch_action_at DEFAULT (GETDATE()),
        closed_at datetime NULL,
        closed_by int NULL,
        close_remark nvarchar(500) NULL
    );
END;

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.delivery_plan_special_dispatch')
      AND name = N'IX_delivery_plan_special_dispatch_ord_open'
)
BEGIN
    CREATE INDEX IX_delivery_plan_special_dispatch_ord_open
        ON dbo.delivery_plan_special_dispatch (ord_id, status, dispatch_type);
END;
