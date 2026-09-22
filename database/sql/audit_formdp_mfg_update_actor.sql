/*
Read-only audit: who changed a FormDP MFG and who changed its Tracking confirmation.
Run in SSMS against the workflow SQL Server database.
*/

SET NOCOUNT ON;

DECLARE @mfg_no nvarchar(80) = N'SOD260800379';

;WITH plan_history AS (
    SELECT
        d.ord_id,
        d.mfg_no,
        d.so_number,
        d.ship_posted_at,
        d.status,
        d.revision_number,
        d.edit_remark,
        d.revise_by,
        d.created_by,
        d.SysStartTime,
        d.SysEndTime,
        LAG(d.ship_posted_at) OVER (
            PARTITION BY d.ord_id ORDER BY d.SysStartTime
        ) AS previous_ship_posted_at,
        LAG(d.status) OVER (
            PARTITION BY d.ord_id ORDER BY d.SysStartTime
        ) AS previous_status
    FROM dbo.delivery_plan_data FOR SYSTEM_TIME ALL AS d
    WHERE UPPER(LTRIM(RTRIM(d.mfg_no))) = UPPER(LTRIM(RTRIM(@mfg_no)))
)
SELECT
    h.ord_id,
    h.mfg_no,
    h.so_number,
    h.previous_ship_posted_at,
    h.ship_posted_at,
    h.previous_status,
    h.status,
    h.revision_number,
    h.edit_remark,
    h.SysStartTime AS changed_at,
    h.SysEndTime,
    h.revise_by,
    u_rev.user_code AS revise_user_code,
    u_rev.name AS revise_user_name,
    h.created_by,
    u_create.user_code AS created_user_code,
    u_create.name AS created_user_name,
    CASE
        WHEN h.previous_ship_posted_at IS NULL THEN N'CREATED/FIRST VERSION'
        WHEN CONVERT(date, h.previous_ship_posted_at) <> CONVERT(date, h.ship_posted_at) THEN N'SHIP DATE CHANGED'
        WHEN ISNULL(h.previous_status, N'') <> ISNULL(h.status, N'') THEN N'STATUS CHANGED'
        ELSE N'OTHER UPDATE'
    END AS change_type
FROM plan_history AS h
LEFT JOIN dbo.users AS u_rev ON u_rev.id = h.revise_by
LEFT JOIN dbo.users AS u_create
    ON CONVERT(nvarchar(80), u_create.id) = LTRIM(RTRIM(CONVERT(nvarchar(80), h.created_by)))
    OR u_create.user_code = LTRIM(RTRIM(CONVERT(nvarchar(80), h.created_by)))
    OR u_create.name = LTRIM(RTRIM(CONVERT(nvarchar(150), h.created_by)))
ORDER BY h.ord_id, h.SysStartTime;

-- Tracking confirmation history: CONFIRM / POSTPONE / RECONFIRM / CANCELLED.
SELECT
    c.id,
    c.mfg_no,
    c.site,
    c.so_number,
    c.confirmation_status,
    c.original_ship_date,
    c.new_delivery_date,
    c.remark,
    c.confirmed_at,
    c.confirmed_by_id,
    c.confirmed_by_login,
    c.confirmed_by_name
FROM dbo.dp_delivery_confirmation AS c
WHERE UPPER(LTRIM(RTRIM(c.mfg_no))) = UPPER(LTRIM(RTRIM(@mfg_no)))
ORDER BY c.id;

-- Logistics special-dispatch actions, including POSTPONED.
SELECT
    sd.id,
    sd.ord_id,
    d.mfg_no,
    d.so_number,
    sd.dispatch_type,
    sd.status,
    sd.remark,
    sd.action_at,
    sd.action_by,
    u_action.user_code AS action_user_code,
    u_action.name AS action_user_name,
    sd.closed_at,
    sd.closed_by,
    u_close.name AS closed_by_name,
    sd.close_remark
FROM dbo.delivery_plan_special_dispatch AS sd
INNER JOIN dbo.delivery_plan_data AS d ON d.ord_id = sd.ord_id
LEFT JOIN dbo.users AS u_action ON u_action.id = sd.action_by
LEFT JOIN dbo.users AS u_close ON u_close.id = sd.closed_by
WHERE UPPER(LTRIM(RTRIM(d.mfg_no))) = UPPER(LTRIM(RTRIM(@mfg_no)))
ORDER BY sd.id;
