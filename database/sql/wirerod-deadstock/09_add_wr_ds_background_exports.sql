/* Additive SQL Server upgrade for queued XLSX exports. Manual execution only. */
SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'dbo.wr_ds_export_requests', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.wr_ds_export_requests
        (
            id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_wr_ds_export_requests PRIMARY KEY,
            token UNIQUEIDENTIFIER NOT NULL,
            user_id INT NOT NULL,
            user_name NVARCHAR(150) NOT NULL,
            filename NVARCHAR(255) NOT NULL,
            filter_json NVARCHAR(MAX) NULL,
            status NVARCHAR(20) NOT NULL,
            file_path NVARCHAR(500) NULL,
            file_size BIGINT NULL,
            row_count BIGINT NULL,
            error_message NVARCHAR(1000) NULL,
            requested_at DATETIME2(0) NOT NULL,
            started_at DATETIME2(0) NULL,
            completed_at DATETIME2(0) NULL,
            created_at DATETIME2(0) NOT NULL,
            CONSTRAINT UQ_wr_ds_export_request_token UNIQUE (token),
            CONSTRAINT CK_wr_ds_export_request_filter CHECK (filter_json IS NULL OR ISJSON(filter_json) = 1),
            CONSTRAINT CK_wr_ds_export_request_status CHECK (status IN (N'QUEUED', N'PROCESSING', N'COMPLETED', N'FAILED'))
        );
        CREATE INDEX IX_wr_ds_export_request_user_time ON dbo.wr_ds_export_requests (user_id, requested_at DESC);
    END;

    IF OBJECT_ID(N'dbo.wr_ds_export_jobs', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.wr_ds_export_jobs
        (
            id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_wr_ds_export_jobs PRIMARY KEY,
            queue NVARCHAR(255) NOT NULL,
            payload NVARCHAR(MAX) NOT NULL,
            attempts TINYINT NOT NULL,
            reserved_at INT NULL,
            available_at INT NOT NULL,
            created_at INT NOT NULL
        );
        CREATE INDEX IX_wr_ds_export_jobs_queue ON dbo.wr_ds_export_jobs (queue);
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;

SELECT name AS table_name
FROM sys.tables
WHERE name IN (N'wr_ds_export_requests', N'wr_ds_export_jobs')
ORDER BY name;
