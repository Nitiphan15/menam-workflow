# Project Context

This is an internal Laravel enterprise system.

## Main Areas

- Delivery Plan
- Truck assignment
- Production Planning
- Forecast
- PO Online
- Workflow approval
- Department approval
- Sales division permission
- Planner logic
- Stock / WIP / SO / FG data
- Email automation
- PDF export
- Excel export
- Dashboard

## User Groups

Possible user groups:
- Sales
- Planner
- Stock
- Logistics
- Purchase
- Supervisor
- Assistant Manager
- Manager
- Head of Department
- Admin

## System Priorities

- Correct business logic
- Safe workflow approval
- Clear permission checks
- Reliable SQL Server/PostgreSQL queries
- Good Blade usability
- Stable PDF/Excel output
- Minimal risky changes

## Important Safety Notes

- Permission must be checked server-side.
- Approval must be done by actual approver only.
- PostgreSQL ERP data should be treated as source/reference data.
- SQL Server is usually used for application/workflow data.
- Avoid destructive commands and destructive SQL.