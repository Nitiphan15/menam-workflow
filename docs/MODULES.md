# Modules

Use this file to document modules as the project grows.

## Delivery Plan

Purpose:
- Manage delivery planning, revisions, truck assignment, PDF/email output.

Notes:
- Be careful with revision logic.
- Be careful with ship date filters.
- Be careful with email schedules.

## Production Planning

Purpose:
- Manage production planning and SKU/RM analysis.

Notes:
- Be careful with SQL Server/PostgreSQL data source separation.
- Be careful with workflow approval and planner permissions.

## Forecast

Purpose:
- Manage sales/planner forecast, RM usage, Avg6, K-factor, supplier mapping.

Notes:
- Heavy ERP queries should be optimized/cached.
- Division-based permission is important.

## PO Online

Purpose:
- Manage PO-related approval and attachments.

Notes:
- Grouping by date/department may be used.
- Detail page may include attachment upload.
- Workflow approval and department role logic are important.

## Workflow

Purpose:
- Shared approve/reject engine.

Notes:
- Server-side approver checks are required.
- Action history must be written.