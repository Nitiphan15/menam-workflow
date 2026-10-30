/* Additive SQL Server upgrade. Manual execution only; no DELETE, DROP, or user assignment. */
SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF NOT EXISTS (SELECT 1 FROM dbo.dept_roles WHERE code = N'WR_DS_EXPORT')
        INSERT INTO dbo.dept_roles (code, name, is_active)
        VALUES (N'WR_DS_EXPORT', N'Wire Rod Dead Stock - Export ข้อมูล', 1);
    ELSE
        UPDATE dbo.dept_roles
        SET name = N'Wire Rod Dead Stock - Export ข้อมูล', is_active = 1
        WHERE code = N'WR_DS_EXPORT';

    IF OBJECT_ID(N'dbo.wr_ds_export_logs', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.wr_ds_export_logs
        (
            id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_wr_ds_export_logs PRIMARY KEY,
            user_id INT NULL,
            user_name NVARCHAR(150) NULL,
            filename NVARCHAR(255) NOT NULL,
            filter_json NVARCHAR(MAX) NULL,
            row_count BIGINT NULL,
            ip_address NVARCHAR(45) NULL,
            user_agent NVARCHAR(500) NULL,
            status NVARCHAR(20) NOT NULL,
            error_message NVARCHAR(1000) NULL,
            started_at DATETIME2(0) NOT NULL,
            completed_at DATETIME2(0) NULL,
            created_at DATETIME2(0) NOT NULL CONSTRAINT DF_wr_ds_export_log_created DEFAULT (SYSDATETIME()),
            CONSTRAINT CK_wr_ds_export_log_filter_json CHECK (filter_json IS NULL OR ISJSON(filter_json) = 1),
            CONSTRAINT CK_wr_ds_export_log_status CHECK (status IN (N'STARTED', N'COMPLETED', N'FAILED'))
        );

        CREATE INDEX IX_wr_ds_export_log_user_time
            ON dbo.wr_ds_export_logs (user_id, started_at DESC);
    END;

    IF COL_LENGTH(N'dbo.wr_ds_export_logs', N'error_message') IS NULL
        ALTER TABLE dbo.wr_ds_export_logs ADD error_message NVARCHAR(1000) NULL;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;

SELECT code, name, is_active FROM dbo.dept_roles WHERE code = N'WR_DS_EXPORT';
SELECT name AS table_name FROM sys.tables WHERE object_id = OBJECT_ID(N'dbo.wr_ds_export_logs');
