IF OBJECT_ID('dbo.fc_rm_division_forecast_approval', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.fc_rm_division_forecast_approval (
        id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        sales_code NVARCHAR(20) NOT NULL,
        forecast_base_month DATE NOT NULL,
        customer_id INT NOT NULL,
        customer_name NVARCHAR(255) NULL,
        fg_partnumber NVARCHAR(100) NOT NULL,
        fg_description NVARCHAR(500) NULL,
        rm_partnumber NVARCHAR(100) NULL,
        division_forecast_1m DECIMAL(18,2) NULL,
        division_forecast_6m DECIMAL(18,2) NULL,
        approval_k_factor DECIMAL(18,1) NULL,
        approval_forecast_1m DECIMAL(18,2) NOT NULL,
        approval_forecast_6m DECIMAL(18,2) NOT NULL,
        approval_remark NVARCHAR(500) NULL,
        created_at DATETIME2 NULL,
        created_by INT NULL,
        updated_at DATETIME2 NULL,
        updated_by INT NULL
    );

    CREATE UNIQUE INDEX UX_fc_rm_division_forecast_approval_row
        ON dbo.fc_rm_division_forecast_approval
        (sales_code, forecast_base_month, customer_id, fg_partnumber);

    CREATE INDEX IX_fc_rm_division_forecast_approval_rm_month
        ON dbo.fc_rm_division_forecast_approval
        (forecast_base_month, rm_partnumber, sales_code);
END;

IF COL_LENGTH('dbo.fc_rm_division_forecast_approval', 'approval_k_factor') IS NULL
BEGIN
    ALTER TABLE dbo.fc_rm_division_forecast_approval
        ADD approval_k_factor DECIMAL(18,1) NULL;
END;
