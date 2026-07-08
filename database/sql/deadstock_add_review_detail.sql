IF COL_LENGTH('dbo.ds_item_reviews', 'review_detail') IS NULL
BEGIN
    ALTER TABLE dbo.ds_item_reviews
        ADD review_detail NVARCHAR(MAX) NULL;
END;
