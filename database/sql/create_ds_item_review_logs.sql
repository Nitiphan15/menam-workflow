IF OBJECT_ID(N'dbo.ds_item_review_logs', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.ds_item_review_logs (
        id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        snapshot_item_id BIGINT NOT NULL,
        review_id BIGINT NULL,
        action NVARCHAR(20) NOT NULL,
        source NVARCHAR(30) NOT NULL,
        import_row_number INT NULL,
        changed_fields NVARCHAR(MAX) NULL,
        before_values NVARCHAR(MAX) NULL,
        after_values NVARCHAR(MAX) NULL,
        changed_by BIGINT NULL,
        changed_by_name NVARCHAR(150) NULL,
        changed_at DATETIME NOT NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL
    );

    CREATE INDEX ds_review_log_item_time_idx
        ON dbo.ds_item_review_logs (snapshot_item_id, changed_at);

    CREATE INDEX ds_review_log_review_time_idx
        ON dbo.ds_item_review_logs (review_id, changed_at);

    CREATE INDEX ds_review_log_user_time_idx
        ON dbo.ds_item_review_logs (changed_by, changed_at);

    CREATE INDEX ds_review_log_source_time_idx
        ON dbo.ds_item_review_logs (source, changed_at);
END;
