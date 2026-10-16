SET XACT_ABORT ON;
BEGIN TRANSACTION;

IF OBJECT_ID(N'dbo.delivery_plan_activity_logs', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.delivery_plan_activity_logs (
        id bigint IDENTITY(1,1) NOT NULL
            CONSTRAINT PK_delivery_plan_activity_logs PRIMARY KEY,
        ord_id int NOT NULL,
        related_ord_id int NULL,
        event_type nvarchar(50) NOT NULL,
        source_module nvarchar(50) NOT NULL,
        actor_id int NULL,
        actor_code nvarchar(100) NULL,
        actor_name nvarchar(255) NULL,
        reason nvarchar(500) NULL,
        before_values nvarchar(max) NULL,
        after_values nvarchar(max) NULL,
        metadata nvarchar(max) NULL,
        request_id uniqueidentifier NOT NULL,
        ip_address nvarchar(45) NULL,
        user_agent nvarchar(500) NULL,
        occurred_at datetime2(7) NOT NULL
            CONSTRAINT DF_delivery_plan_activity_logs_occurred_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT CK_delivery_plan_activity_logs_before_json
            CHECK (before_values IS NULL OR ISJSON(before_values) = 1),
        CONSTRAINT CK_delivery_plan_activity_logs_after_json
            CHECK (after_values IS NULL OR ISJSON(after_values) = 1),
        CONSTRAINT CK_delivery_plan_activity_logs_metadata_json
            CHECK (metadata IS NULL OR ISJSON(metadata) = 1)
    );

    CREATE INDEX IX_delivery_plan_activity_logs_ord_time
        ON dbo.delivery_plan_activity_logs (ord_id, occurred_at DESC);
    CREATE INDEX IX_delivery_plan_activity_logs_event_time
        ON dbo.delivery_plan_activity_logs (event_type, occurred_at DESC);
    CREATE INDEX IX_delivery_plan_activity_logs_request
        ON dbo.delivery_plan_activity_logs (request_id);
END;

COMMIT TRANSACTION;
