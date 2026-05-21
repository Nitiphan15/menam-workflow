IF COL_LENGTH('dbo.ds_item_reviews', 'next_follow_up_date') IS NULL
BEGIN
    ALTER TABLE dbo.ds_item_reviews
        ADD next_follow_up_date date NULL;
END;

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'ds_review_followup_idx'
      AND object_id = OBJECT_ID('dbo.ds_item_reviews')
)
BEGIN
    CREATE INDEX ds_review_followup_idx
        ON dbo.ds_item_reviews (next_follow_up_date, review_status);
END;
