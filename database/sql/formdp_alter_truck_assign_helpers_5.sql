/*
    FormDP truck assign: allow up to 5 helpers per truck assignment.

    Run on the workflow SQL Server database before deploying the PHP/JS change.
*/

IF COL_LENGTH(N'dbo.delivery_plan_truck_assign', N'helper4_staff_id') IS NULL
BEGIN
    ALTER TABLE dbo.delivery_plan_truck_assign ADD helper4_staff_id int NULL;
END;

IF COL_LENGTH(N'dbo.delivery_plan_truck_assign', N'helper4_name') IS NULL
BEGIN
    ALTER TABLE dbo.delivery_plan_truck_assign ADD helper4_name nvarchar(200) NULL;
END;

IF COL_LENGTH(N'dbo.delivery_plan_truck_assign', N'helper5_staff_id') IS NULL
BEGIN
    ALTER TABLE dbo.delivery_plan_truck_assign ADD helper5_staff_id int NULL;
END;

IF COL_LENGTH(N'dbo.delivery_plan_truck_assign', N'helper5_name') IS NULL
BEGIN
    ALTER TABLE dbo.delivery_plan_truck_assign ADD helper5_name nvarchar(200) NULL;
END;
