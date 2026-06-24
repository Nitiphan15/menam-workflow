IF OBJECT_ID('dbo.fc_rm_division_forecast_submissions', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.fc_rm_division_forecast_submissions (
        id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        sales_code NVARCHAR(20) NOT NULL,
        forecast_base_month DATE NOT NULL,
        form_no NVARCHAR(50) NOT NULL,
        wf_form_id BIGINT NULL,
        department_id INT NULL,
        status NVARCHAR(30) NOT NULL,
        submitted_at DATETIME2 NULL,
        submitted_by INT NULL,
        approved_at DATETIME2 NULL,
        approved_by INT NULL,
        rejected_at DATETIME2 NULL,
        rejected_by INT NULL,
        reject_reason NVARCHAR(1000) NULL,
        created_at DATETIME2 NULL,
        created_by INT NULL,
        updated_at DATETIME2 NULL,
        updated_by INT NULL
    );

    CREATE UNIQUE INDEX UX_fc_rm_division_forecast_submissions_month
        ON dbo.fc_rm_division_forecast_submissions (sales_code, forecast_base_month);

    CREATE INDEX IX_fc_rm_division_forecast_submissions_wf
        ON dbo.fc_rm_division_forecast_submissions (wf_form_id);
END;

IF NOT EXISTS (SELECT 1 FROM dbo.workflows WHERE LOWER(code) = 'fc')
BEGIN
    INSERT INTO dbo.workflows (code, name, is_active, created_at, updated_at)
    VALUES ('fc', 'FormFC Division Forecast Approval', 1, SYSDATETIME(), SYSDATETIME());
END;

DECLARE @workflowId BIGINT;
SELECT @workflowId = id FROM dbo.workflows WHERE LOWER(code) = 'fc' AND is_active = 1;

IF @workflowId IS NOT NULL
BEGIN
    IF NOT EXISTS (SELECT 1 FROM dbo.workflow_steps WHERE workflow_id = @workflowId AND step_no = 1)
    BEGIN
        INSERT INTO dbo.workflow_steps
            (workflow_id, step_no, [key], name, min_approvals, is_parallel, sla_hours, next_on_approve, next_on_reject, is_active, created_at, updated_at)
        VALUES
            (@workflowId, 1, 'submit', 'Division Submit', 1, 0, NULL, 2, 998, 1, SYSDATETIME(), SYSDATETIME());
    END;

    IF NOT EXISTS (SELECT 1 FROM dbo.workflow_steps WHERE workflow_id = @workflowId AND step_no = 2)
    BEGIN
        INSERT INTO dbo.workflow_steps
            (workflow_id, step_no, [key], name, min_approvals, is_parallel, sla_hours, next_on_approve, next_on_reject, is_active, created_at, updated_at)
        VALUES
            (@workflowId, 2, 'division_manager_approve', 'Division Manager Approval', 1, 0, NULL, 999, 998, 1, SYSDATETIME(), SYSDATETIME());
    END;
    ELSE
    BEGIN
        UPDATE dbo.workflow_steps
        SET [key] = 'division_manager_approve',
            name = 'Division Manager Approval',
            updated_at = SYSDATETIME()
        WHERE workflow_id = @workflowId
          AND step_no = 2;
    END;

    DECLARE @submitStepId BIGINT;
    DECLARE @approveStepId BIGINT;

    SELECT @submitStepId = id FROM dbo.workflow_steps WHERE workflow_id = @workflowId AND step_no = 1;
    SELECT @approveStepId = id FROM dbo.workflow_steps WHERE workflow_id = @workflowId AND step_no = 2;

    IF @submitStepId IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM dbo.workflow_step_rules WHERE workflow_step_id = @submitStepId)
    BEGIN
        INSERT INTO dbo.workflow_step_rules
            (workflow_step_id, source_type, source_ref_id, department_scoped, condition_expr, priority, created_at, updated_at)
        VALUES
            (@submitStepId, 'ORIGINATOR', NULL, 0, NULL, 1, SYSDATETIME(), SYSDATETIME());
    END;

    IF @approveStepId IS NOT NULL
    BEGIN
        DELETE FROM dbo.workflow_step_rules
        WHERE workflow_step_id = @approveStepId;

        INSERT INTO dbo.workflow_step_rules
            (workflow_step_id, source_type, source_ref_id, department_scoped, condition_expr, priority, created_at, updated_at)
        SELECT
            @approveStepId,
            'DEPARTMENT_MANAGER',
            NULL,
            0,
            N'{"department_id":' + CAST(id AS NVARCHAR(20)) + N'}',
            ROW_NUMBER() OVER (
                ORDER BY CASE code
                    WHEN 'SM' THEN 1
                    WHEN 'IP' THEN 2
                    WHEN 'EP' THEN 3
                    ELSE 9
                END
            ),
            SYSDATETIME(),
            SYSDATETIME()
        FROM dbo.departments
        WHERE code IN ('SM', 'IP', 'EP');
    END;
END;
