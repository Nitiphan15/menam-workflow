SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRANSACTION;

DECLARE @RobertUserId BIGINT = (
    SELECT TOP (1) id
    FROM dbo.users
    WHERE name = N'Robert Sammayo' AND is_active = 1
    ORDER BY id
);

DECLARE @ChacritUserId BIGINT = (
    SELECT TOP (1) id
    FROM dbo.users
    WHERE (name = N'Chacrit Rakrai' OR LOWER(email) = N'chacrit@menamstainless.co.th')
      AND is_active = 1
    ORDER BY CASE WHEN LOWER(email) = N'chacrit@menamstainless.co.th' THEN 0 ELSE 1 END, id
);

IF @RobertUserId IS NULL
    THROW 51013, 'Active user Robert Sammayo was not found.', 1;

IF @ChacritUserId IS NULL
    THROW 51014, 'Active user Chacrit Rakrai was not found.', 1;

IF EXISTS (
    SELECT 1
    FROM dbo.users
    WHERE username = N'robert_s'
      AND id <> @RobertUserId
)
    THROW 51015, 'Username robert_s is already used by another user.', 1;

IF EXISTS (
    SELECT 1
    FROM dbo.users
    WHERE username = N'chacrit_r'
      AND id <> @ChacritUserId
)
    THROW 51016, 'Username chacrit_r is already used by another user.', 1;

UPDATE dbo.users
SET username = N'robert_s',
    updated_at = SYSDATETIME()
WHERE id = @RobertUserId
  AND NULLIF(LTRIM(RTRIM(username)), N'') IS NULL;

UPDATE dbo.users
SET username = N'chacrit_r',
    updated_at = SYSDATETIME()
WHERE id = @ChacritUserId
  AND NULLIF(LTRIM(RTRIM(username)), N'') IS NULL;

COMMIT TRANSACTION;

SELECT
    id,
    username,
    name,
    email,
    department_id,
    is_active
FROM dbo.users
WHERE id IN (@RobertUserId, @ChacritUserId)
ORDER BY name;
