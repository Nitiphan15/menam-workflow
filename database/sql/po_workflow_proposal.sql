-- Proposed workflow for PO Online
-- Flow:
-- 1) Purchase submit
-- 2) Purchase approval (Supervisor Purchase + Assist Manager/Manager Purchase)
--    Both approvals are required before moving on
-- 3) Department head approval (Assist Manager / Manager of requesting department)
-- 4) Close at step 999
--
-- Signature mapping for printed PO:
-- - Ordered by     = step 2 approvals (2 signatures)
-- - Authorized by  = step 3 approval
-- - P/O confirmed by = left blank

START TRANSACTION;

INSERT INTO workflows (code, name, model_type, is_active, created_at, updated_at)
SELECT 'po', 'PO Online Approval', 'App\\Models\\Po\\PoHeader', 1, NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM workflows WHERE code = 'po'
);

SET @po_workflow_id := (SELECT id FROM workflows WHERE code = 'po' LIMIT 1);

-- Step 1: purchase submit
INSERT INTO workflow_steps (
    workflow_id, step_no, `key`, name, min_approvals, is_parallel, sla_hours,
    next_on_approve, next_on_reject, is_active, created_at, updated_at
)
SELECT @po_workflow_id, 1, 'purchase_submit', 'Purchase Submit', 1, 0, NULL, 2, 998, 1, NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM workflow_steps WHERE workflow_id = @po_workflow_id AND step_no = 1
);

-- Step 2: purchase approvals, require 2 people
INSERT INTO workflow_steps (
    workflow_id, step_no, `key`, name, min_approvals, is_parallel, sla_hours,
    next_on_approve, next_on_reject, is_active, created_at, updated_at
)
SELECT @po_workflow_id, 2, 'purchase_approve', 'Purchase Approval', 2, 1, 24, 3, 1, 1, NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM workflow_steps WHERE workflow_id = @po_workflow_id AND step_no = 2
);

-- Step 3: requesting department head approves and closes process
INSERT INTO workflow_steps (
    workflow_id, step_no, `key`, name, min_approvals, is_parallel, sla_hours,
    next_on_approve, next_on_reject, is_active, created_at, updated_at
)
SELECT @po_workflow_id, 3, 'dept_manager_approve', 'Department Manager Approval', 1, 0, 24, 999, 1, 1, NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM workflow_steps WHERE workflow_id = @po_workflow_id AND step_no = 3
);

SET @po_step_1 := (SELECT id FROM workflow_steps WHERE workflow_id = @po_workflow_id AND step_no = 1 LIMIT 1);
SET @po_step_2 := (SELECT id FROM workflow_steps WHERE workflow_id = @po_workflow_id AND step_no = 2 LIMIT 1);
SET @po_step_3 := (SELECT id FROM workflow_steps WHERE workflow_id = @po_workflow_id AND step_no = 3 LIMIT 1);

-- Rule: purchase originator submits document
INSERT INTO workflow_step_rules (
    workflow_step_id, source_type, source_ref_id, department_scoped, condition_expr, priority, created_at, updated_at
)
SELECT @po_step_1, 'ORIGINATOR', NULL, 0, NULL, 1, NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM workflow_step_rules
    WHERE workflow_step_id = @po_step_1 AND source_type = 'ORIGINATOR'
);

-- Rule 1 for step 2: supervisor of purchase submitter
INSERT INTO workflow_step_rules (
    workflow_step_id, source_type, source_ref_id, department_scoped, condition_expr, priority, created_at, updated_at
)
SELECT @po_step_2, 'SUPERVISOR', NULL, 1, NULL, 1, NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM workflow_step_rules
    WHERE workflow_step_id = @po_step_2 AND source_type = 'SUPERVISOR'
);

-- Rule 2 for step 2: purchase assistant manager / manager
-- Replace __PURCHASE_DEPARTMENT_ID__ with the real department id for Purchase if needed.
INSERT INTO workflow_step_rules (
    workflow_step_id, source_type, source_ref_id, department_scoped, condition_expr, priority, created_at, updated_at
)
SELECT
    @po_step_2,
    'ROLE',
    NULL,
    0,
    JSON_OBJECT(
        'department_id', __PURCHASE_DEPARTMENT_ID__,
        'role_in', JSON_ARRAY('Assist Manager', 'Manager')
    ),
    2,
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM workflow_step_rules
    WHERE workflow_step_id = @po_step_2 AND source_type = 'ROLE' AND priority = 2
);

-- Rule for step 3: requesting department head
INSERT INTO workflow_step_rules (
    workflow_step_id, source_type, source_ref_id, department_scoped, condition_expr, priority, created_at, updated_at
)
SELECT
    @po_step_3,
    'ROLE',
    NULL,
    1,
    JSON_OBJECT(
        'role_in', JSON_ARRAY('Assist Manager', 'Manager')
    ),
    1,
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM workflow_step_rules
    WHERE workflow_step_id = @po_step_3 AND source_type = 'ROLE'
);

COMMIT;
