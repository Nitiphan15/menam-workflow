# Database Rules

## Connections

Common connection names:

| Connection | Purpose |
|---|---|
| sqlsrv | Application / workflow database |
| pgsqlw | MENAM WIRE ERP data |
| pgsqlp | MENAM PLUS ERP data |
| pgsqlmfgw | WIRE manufacturing data |
| pgsqlmfgp | PLUS manufacturing data |

## General Rules

- Always check existing code before assuming table or column names.
- Use parameter binding.
- Avoid unsafe string concatenation.
- Do not hardcode database credentials.
- Do not run destructive SQL unless explicitly requested.
- Do not generate DROP / TRUNCATE / DELETE scripts unless explicitly requested.
- If UPDATE is needed, include a safe WHERE condition.
- For SQL Server scripts, prefer IF EXISTS / IF NOT EXISTS guards.
- For PostgreSQL ERP queries, avoid heavy full-table scans where possible.

## SQL Server Notes

- Be careful with collation conflicts.
- Use COLLATE only where necessary.
- Application/workflow data usually belongs in SQL Server.
- Workflow-related data may include:
  - workflow_steps
  - wf_forms
  - wf_form_authorize
  - wf_action_history
  - department_roles
  - users
  - departments

## PostgreSQL ERP Notes

- ERP data should be treated as source/reference data.
- Be careful with cancelled orders, closed documents, and negative values.
- Heavy queries should be cached, summarized, paginated, or filtered by date when possible.
- For WIRE/PLUS combined views, keep company source visible when useful.

## Query Review Checklist

- Are joins correct?
- Are filters correct?
- Is date filtering correct?
- Is cancelled/closed status handled?
- Is data duplicated by joins?
- Are user permissions applied?
- Are values formatted correctly for Excel/PDF?
- Is the query safe from injection?
- Does it need an index?