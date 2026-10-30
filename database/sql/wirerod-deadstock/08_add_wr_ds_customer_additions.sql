/* Additive SQL Server upgrade. Manual execution only; no DELETE, DROP, or ERP write. */
SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'dbo.wr_ds_customer_additions', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.wr_ds_customer_additions
        (
            id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_wr_ds_customer_additions PRIMARY KEY,
            lifecycle_id BIGINT NOT NULL,
            revision_no INT NOT NULL,
            customer_name NVARCHAR(255) NOT NULL,
            reason NVARCHAR(255) NOT NULL,
            explanation NVARCHAR(2000) NOT NULL,
            supersedes_id BIGINT NULL,
            is_active BIT NOT NULL CONSTRAINT DF_wr_ds_customer_active DEFAULT (1),
            superseded_at DATETIME2(0) NULL,
            revoked_by INT NULL,
            revoked_by_name NVARCHAR(150) NULL,
            revoked_at DATETIME2(0) NULL,
            revoke_reason NVARCHAR(1000) NULL,
            created_by INT NOT NULL,
            created_by_name NVARCHAR(150) NOT NULL,
            created_at DATETIME2(0) NOT NULL,
            updated_at DATETIME2(0) NOT NULL,
            CONSTRAINT UQ_wr_ds_customer_revision UNIQUE (lifecycle_id, revision_no),
            CONSTRAINT FK_wr_ds_customer_lifecycle FOREIGN KEY (lifecycle_id) REFERENCES dbo.wr_ds_item_lifecycles(id),
            CONSTRAINT FK_wr_ds_customer_supersedes FOREIGN KEY (supersedes_id) REFERENCES dbo.wr_ds_customer_additions(id)
        );

        CREATE INDEX IX_wr_ds_customer_active
            ON dbo.wr_ds_customer_additions (lifecycle_id, is_active)
            INCLUDE (customer_name, reason, revision_no, created_at);
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;

SELECT name AS table_name FROM sys.tables WHERE object_id = OBJECT_ID(N'dbo.wr_ds_customer_additions');
