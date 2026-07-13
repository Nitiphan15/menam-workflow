IF EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'ds_review_followup_idx'
      AND object_id = OBJECT_ID('dbo.ds_item_reviews')
)
BEGIN
    DROP INDEX ds_review_followup_idx ON dbo.ds_item_reviews;
END;

IF EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'ds_review_status_idx'
      AND object_id = OBJECT_ID('dbo.ds_item_reviews')
)
BEGIN
    DROP INDEX ds_review_status_idx ON dbo.ds_item_reviews;
END;

ALTER TABLE dbo.ds_item_reviews
    ALTER COLUMN review_status nvarchar(50) NOT NULL;

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'ds_review_status_idx'
      AND object_id = OBJECT_ID('dbo.ds_item_reviews')
)
BEGIN
    CREATE INDEX ds_review_status_idx
        ON dbo.ds_item_reviews (review_status, reviewed_at);
END;

IF COL_LENGTH('dbo.ds_item_reviews', 'next_follow_up_date') IS NOT NULL
   AND NOT EXISTS (
       SELECT 1
       FROM sys.indexes
       WHERE name = 'ds_review_followup_idx'
         AND object_id = OBJECT_ID('dbo.ds_item_reviews')
   )
BEGIN
    CREATE INDEX ds_review_followup_idx
        ON dbo.ds_item_reviews (next_follow_up_date, review_status);
END;
