/* Read-only verification for the MG permission and its current assignments. */
SELECT id, code, name, is_active
FROM dbo.dept_roles
WHERE UPPER(code) = N'MG';

SELECT
    assignment.user_id,
    assignment.department_id,
    role.id AS role_id,
    role.code,
    role.name
FROM dbo.user_dept_roles AS assignment
INNER JOIN dbo.dept_roles AS role ON role.id = assignment.role_id
WHERE UPPER(role.code) = N'MG'
ORDER BY assignment.department_id, assignment.user_id;
