/*
    FormDP truck assign: add Shipping contact phone column.

    Run on the workflow SQL Server database before deploying the PHP/JS change.
*/

IF COL_LENGTH(N'dbo.delivery_plan_truck_assign', N'shipping_phone') IS NULL
BEGIN
    ALTER TABLE dbo.delivery_plan_truck_assign ADD shipping_phone nvarchar(50) NULL;
END;
