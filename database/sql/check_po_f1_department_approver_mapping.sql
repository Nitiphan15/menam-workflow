SET NOCOUNT ON;

/*
ตรวจ mapping จาก ERP f1 -> department master -> ผู้อนุมัติ FormPO ขั้นที่ 3

- ใช้ dbo.po_headers เพื่อนับเฉพาะ PO ที่ sync เข้า workflow แล้ว
- FIXED ตรวจ po_department_approvers sequence_no = 1 และไล่แผนกแม่
- POSITION จำลองกติกาเดิมของ ApproverResolver:
  1) หา role ชื่อ Assist Manager / Manager จากแผนกปัจจุบันขึ้นไปหาแผนกแม่
  2) ถ้าไม่พบ จึงใช้ role ระดับสูงสุดที่ level_no >= 3 ของแผนกที่ใกล้ที่สุด
- Planning อยู่ใน @Expected เสมอ จึงแสดงแม้จำนวน PO เป็น 0
*/

DECLARE @Today DATE = CAST(GETDATE() AS DATE);

DECLARE @Expected TABLE (
    sort_order INT NOT NULL PRIMARY KEY,
    erp_f1 NVARCHAR(100) NOT NULL,
    department_code NVARCHAR(50) NOT NULL,
    mapping_mode VARCHAR(10) NOT NULL,
    expected_username NVARCHAR(100) NOT NULL
);

INSERT INTO @Expected (
    sort_order,
    erp_f1,
    department_code,
    mapping_mode,
    expected_username
)
VALUES
    ( 1, N'Bar1',       N'SH1', N'FIXED',    N'jittinan_k'),
    ( 2, N'Bar2',       N'SH2', N'FIXED',    N'jittinan_k'),
    ( 3, N'CGM',        N'CG',  N'FIXED',    N'jittinan_k'),
    ( 4, N'DIE',        N'DD',  N'FIXED',    N'kitpon_s'),
    ( 5, N'Drawing',    N'SQR', N'FIXED',    N'jittinan_k'),
    ( 6, N'Export',     N'EP',  N'POSITION', N'preeyapan_t'),
    ( 7, N'HR',         N'HR',  N'POSITION', N'chacrit_r'),
    ( 8, N'IT',         N'IT',  N'FIXED',    N'robert_s'),
    ( 9, N'Logistic',   N'SP',  N'FIXED',    N'jittinan_k'),
    (10, N'MKT',        N'MKT', N'FIXED',    N'theerarat_k'),
    (11, N'Pack',       N'PK',  N'FIXED',    N'jittinan_k'),
    (12, N'Profile',    N'PF',  N'FIXED',    N'jittinan_k'),
    (13, N'Purchase',   N'P',   N'POSITION', N'sirithat_t'),
    (14, N'QA',         N'QA',  N'FIXED',    N'utis_j'),
    (15, N'R&D',        N'R',   N'FIXED',    N'thatree_k'),
    (16, N'Sale',       N'IP',  N'POSITION', N'thanin_p'),
    (17, N'Stock',      N'ST',  N'FIXED',    N'jittinan_k'),
    (18, N'Store',      N'S',   N'POSITION', N'sirithat_t'),
    (19, N'W&F',        N'GT',  N'FIXED',    N'chatchawal_c'),
    (20, N'ยานยนต์',    N'AM',  N'FIXED',    N'jittinan_k'),
    (21, N'วิศวกรรม',      N'ENG', N'FIXED',    N'panya_k'),
    (22, N'Planning',   N'PN',  N'POSITION', N'assadaporn_m'),
    (23, N'Account',    N'AC',  N'FIXED',    N'jiraporn_k'),
    (24, N'Finance',    N'FN',  N'FIXED',    N'jiraporn_k'),
    (25, N'Safety',     N'SE',  N'FIXED',    N'chacrit_r');

;WITH PoCounts AS (
    SELECT expected.sort_order,
           COUNT(po.id) AS synced_po_count
    FROM @Expected AS expected
    LEFT JOIN dbo.po_headers AS po
      ON UPPER(LTRIM(RTRIM(po.f1))) LIKE UPPER(expected.erp_f1) + N'%'
    GROUP BY expected.sort_order
),
DepartmentSeed AS (
    SELECT expected.sort_order,
           department.id AS department_id,
           department.parent_id,
           0 AS depth
    FROM @Expected AS expected
    LEFT JOIN dbo.departments AS department
      ON department.code = expected.department_code
     AND department.is_active = 1
),
DepartmentLineage AS (
    SELECT seed.sort_order,
           seed.department_id,
           seed.parent_id,
           seed.depth
    FROM DepartmentSeed AS seed
    WHERE seed.department_id IS NOT NULL

    UNION ALL

    SELECT lineage.sort_order,
           parent.id,
           parent.parent_id,
           lineage.depth + 1
    FROM DepartmentLineage AS lineage
    JOIN dbo.departments AS parent
      ON parent.id = lineage.parent_id
     AND parent.is_active = 1
),
FixedDepth AS (
    SELECT lineage.sort_order,
           MIN(lineage.depth) AS selected_depth
    FROM DepartmentLineage AS lineage
    JOIN dbo.po_department_approvers AS mapping
      ON mapping.department_id = lineage.department_id
     AND mapping.sequence_no = 1
     AND mapping.is_active = 1
    JOIN dbo.users AS approver
      ON approver.id = mapping.approver_user_id
     AND approver.is_active = 1
    GROUP BY lineage.sort_order
),
FixedUsers AS (
    SELECT DISTINCT lineage.sort_order,
           approver.id AS user_id,
           approver.username,
           approver.name,
           lineage.department_id AS source_department_id
    FROM DepartmentLineage AS lineage
    JOIN FixedDepth AS selected
      ON selected.sort_order = lineage.sort_order
     AND selected.selected_depth = lineage.depth
    JOIN dbo.po_department_approvers AS mapping
      ON mapping.department_id = lineage.department_id
     AND mapping.sequence_no = 1
     AND mapping.is_active = 1
    JOIN dbo.users AS approver
      ON approver.id = mapping.approver_user_id
     AND approver.is_active = 1
),
ActiveRoleUsers AS (
    SELECT lineage.sort_order,
           lineage.depth,
           lineage.department_id,
           role_row.name AS role_name,
           role_row.level_no,
           assignment.is_primary,
           assignment.start_date,
           approver.id AS user_id,
           approver.username,
           approver.name
    FROM DepartmentLineage AS lineage
    JOIN dbo.department_roles AS role_row
      ON role_row.department_id = lineage.department_id
     AND role_row.is_active = 1
    JOIN dbo.department_role_users AS assignment
      ON assignment.department_role_id = role_row.id
     AND assignment.start_date <= @Today
     AND (assignment.end_date IS NULL OR assignment.end_date >= @Today)
    JOIN dbo.users AS approver
      ON approver.id = assignment.user_id
     AND approver.is_active = 1
),
NamedRoleDepth AS (
    SELECT role_user.sort_order,
           MIN(role_user.depth) AS selected_depth
    FROM ActiveRoleUsers AS role_user
    WHERE role_user.role_name IN (N'Assist Manager', N'Manager')
    GROUP BY role_user.sort_order
),
NamedPositionUsers AS (
    SELECT DISTINCT role_user.sort_order,
           role_user.user_id,
           role_user.username,
           role_user.name,
           role_user.department_id AS source_department_id
    FROM ActiveRoleUsers AS role_user
    JOIN NamedRoleDepth AS selected
      ON selected.sort_order = role_user.sort_order
     AND selected.selected_depth = role_user.depth
    WHERE role_user.role_name IN (N'Assist Manager', N'Manager')
),
FallbackDepth AS (
    SELECT role_user.sort_order,
           MIN(role_user.depth) AS selected_depth
    FROM ActiveRoleUsers AS role_user
    LEFT JOIN NamedRoleDepth AS named
      ON named.sort_order = role_user.sort_order
    WHERE named.sort_order IS NULL
      AND role_user.level_no >= 3
    GROUP BY role_user.sort_order
),
FallbackLevel AS (
    SELECT role_user.sort_order,
           selected.selected_depth,
           MAX(role_user.level_no) AS selected_level
    FROM ActiveRoleUsers AS role_user
    JOIN FallbackDepth AS selected
      ON selected.sort_order = role_user.sort_order
     AND selected.selected_depth = role_user.depth
    GROUP BY role_user.sort_order, selected.selected_depth
),
FallbackPositionUsers AS (
    SELECT DISTINCT role_user.sort_order,
           role_user.user_id,
           role_user.username,
           role_user.name,
           role_user.department_id AS source_department_id
    FROM ActiveRoleUsers AS role_user
    JOIN FallbackLevel AS selected
      ON selected.sort_order = role_user.sort_order
     AND selected.selected_depth = role_user.depth
     AND selected.selected_level = role_user.level_no
),
PositionUsers AS (
    SELECT * FROM NamedPositionUsers
    UNION ALL
    SELECT * FROM FallbackPositionUsers
),
ResolvedUsers AS (
    SELECT expected.sort_order,
           fixed.user_id,
           fixed.username,
           fixed.name,
           fixed.source_department_id
    FROM @Expected AS expected
    JOIN FixedUsers AS fixed
      ON fixed.sort_order = expected.sort_order
    WHERE expected.mapping_mode = N'FIXED'

    UNION ALL

    SELECT expected.sort_order,
           position_user.user_id,
           position_user.username,
           position_user.name,
           position_user.source_department_id
    FROM @Expected AS expected
    JOIN PositionUsers AS position_user
      ON position_user.sort_order = expected.sort_order
    WHERE expected.mapping_mode = N'POSITION'
),
DistinctResolvedUsers AS (
    SELECT DISTINCT sort_order,
           user_id,
           username,
           name,
           source_department_id
    FROM ResolvedUsers
),
ResolvedSummary AS (
    SELECT resolved.sort_order,
           COUNT(*) AS approver_count,
           MAX(CASE
               WHEN resolved.username = expected.expected_username THEN 1
               ELSE 0
           END) AS expected_user_found,
           STRING_AGG(
               COALESCE(NULLIF(resolved.username, N''), resolved.name),
               N', '
           ) AS resolved_approvers
    FROM DistinctResolvedUsers AS resolved
    JOIN @Expected AS expected
      ON expected.sort_order = resolved.sort_order
    GROUP BY resolved.sort_order
)
SELECT expected.sort_order AS no,
       expected.erp_f1,
       po_count.synced_po_count,
       expected.department_code AS expected_department_code,
       department.id AS department_id,
       department.code AS actual_department_code,
       department.name AS department_name,
       expected.mapping_mode,
       expected.expected_username,
       summary.resolved_approvers,
       summary.approver_count,
       CASE
           WHEN department.id IS NULL
               THEN N'DEPARTMENT_NOT_FOUND'
           WHEN expected.mapping_mode = N'FIXED'
                AND ISNULL(summary.approver_count, 0) = 0
               THEN N'FIXED_MAPPING_NOT_FOUND'
           WHEN expected.mapping_mode = N'POSITION'
                AND ISNULL(summary.approver_count, 0) = 0
               THEN N'POSITION_APPROVER_NOT_FOUND'
           WHEN ISNULL(summary.expected_user_found, 0) = 0
               THEN N'EXPECTED_USER_NOT_FOUND'
           WHEN summary.approver_count > 1
               THEN N'OK_WITH_ADDITIONAL_APPROVERS'
           ELSE N'OK'
       END AS mapping_status
FROM @Expected AS expected
LEFT JOIN PoCounts AS po_count
  ON po_count.sort_order = expected.sort_order
LEFT JOIN dbo.departments AS department
  ON department.code = expected.department_code
 AND department.is_active = 1
LEFT JOIN ResolvedSummary AS summary
  ON summary.sort_order = expected.sort_order
ORDER BY expected.sort_order
OPTION (MAXRECURSION 20);
