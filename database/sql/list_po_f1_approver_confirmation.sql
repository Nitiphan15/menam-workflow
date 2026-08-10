SET NOCOUNT ON;

/* รายการ business mapping สำหรับยืนยัน f1 -> ผู้อนุมัติ FormPO ขั้นที่ 3 */
DECLARE @Map TABLE (
    sort_order INT NOT NULL,
    f1 NVARCHAR(100) NOT NULL,
    approver_key NVARCHAR(100) NOT NULL,
    routing_type NVARCHAR(20) NOT NULL
);

INSERT INTO @Map (sort_order, f1, approver_key, routing_type)
VALUES
    ( 1, N'Bar1',                 N'jittinan_k',    N'FIXED'),
    ( 2, N'Bar2',                 N'jittinan_k',    N'FIXED'),
    ( 3, N'CGM',                  N'jittinan_k',    N'FIXED'),
    ( 4, N'Shotblast',            N'jittinan_k',    N'FIXED'),
    ( 5, N'CO2',                  N'jittinan_k',    N'FIXED'),
    ( 6, N'Coating',              N'jittinan_k',    N'FIXED'),
    ( 7, N'Profile',              N'jittinan_k',    N'FIXED'),
    ( 8, N'Anneal',               N'jittinan_k',    N'FIXED'),
    ( 9, N'บ่อบำบัด',             N'jittinan_k',    N'FIXED'),
    (10, N'Drawing',              N'jittinan_k',    N'FIXED'),
    (11, N'DIE',                  N'kitpon_s',       N'FIXED'),
    (12, N'Tooling',              N'kitpon_s',       N'FIXED_ALIAS'),
    (13, N'Pack',                 N'jittinan_k',    N'FIXED'),
    (14, N'Logistic',             N'jittinan_k',    N'FIXED'),
    (15, N'Transport',            N'jittinan_k',    N'FIXED'),
    (16, N'Stock',                N'jittinan_k',    N'FIXED'),
    (17, N'Store',                N'sirithat_t',    N'POSITION'),
    (18, N'Purchase',             N'sirithat_t',    N'POSITION'),
    (19, N'ยานยนต์',              N'jittinan_k',    N'FIXED'),
    (20, N'W&F',                  N'chatchawal_c',  N'FIXED'),
    (21, N'MKT',                  N'theerarat_k',   N'FIXED'),
    (22, N'Export',               N'preeyapan_t',   N'POSITION'),
    (23, N'Sale',                 N'thanin_p',       N'POSITION'),
    (23, N'Sale',                 N'surasak_l',      N'POSITION'),
    (24, N'Planning',             N'assadaporn_m',  N'POSITION'),
    (25, N'Account',              N'jiraporn_k',     N'FIXED'),
    (26, N'Finance',              N'jiraporn_k',     N'FIXED'),
    (27, N'HR',                   N'chacrit_r',      N'POSITION'),
    (28, N'Safety',               N'chacrit_r',      N'FIXED'),
    (29, N'QA',                   N'utis_j',         N'FIXED'),
    (30, N'QC',                   N'utis_j',         N'FIXED'),
    (31, N'R&D',                  N'thatree_k',      N'FIXED'),
    (32, N'วิศวกรรม',            N'panya_k',       N'FIXED'),
    (33, N'วิศวกรรมเครื่องกล', N'panya_k',       N'FIXED'),
    (34, N'วิศวกรรมไฟฟ้า',      N'panya_k',       N'FIXED'),
    (35, N'IT',                   N'robert_s',      N'FIXED');

SELECT mapping.sort_order,
       mapping.f1,
       mapping.approver_key AS expected_username,
       user_row.username AS actual_username,
       user_row.name AS approver_name,
       mapping.routing_type,
       user_row.is_active AS user_active,
       CASE
           WHEN user_row.id IS NULL THEN N'USER_NOT_FOUND'
           WHEN NULLIF(LTRIM(RTRIM(user_row.username)), N'') IS NULL THEN N'USERNAME_MISSING'
           WHEN user_row.is_active <> 1 THEN N'USER_INACTIVE'
           ELSE N'OK'
       END AS user_status
FROM @Map AS mapping
OUTER APPLY (
    SELECT TOP (1)
           app_user.id,
           app_user.username,
           app_user.name,
           app_user.is_active
    FROM dbo.users AS app_user
    WHERE app_user.username = mapping.approver_key
       OR (
           mapping.approver_key = N'robert_s'
           AND app_user.name = N'Robert Sammayo'
       )
    ORDER BY CASE WHEN app_user.username = mapping.approver_key THEN 0 ELSE 1 END,
             app_user.id
) AS user_row
ORDER BY mapping.sort_order, mapping.approver_key;
