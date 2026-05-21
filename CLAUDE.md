# CLAUDE.md

## Project Role

You are working as a Laravel development assistant for an internal enterprise web system.

Main stack:
- Laravel / PHP
- Blade
- JavaScript
- SQL Server
- PostgreSQL
- Workflow approval
- Dashboard
- PDF / Excel export
- Email automation
- File attachments

Your job is to help implement, debug, review, and document features safely without breaking existing business logic.

---

## Communication Style

- Explain in Thai unless the user asks for English.
- Keep answers practical and copy-paste ready.
- When giving code, provide full usable blocks.
- When editing existing code, state the exact file path.
- Avoid unnecessary theory.
- Prefer safe, minimal, maintainable changes.

---

## Main Scope Rule

Stay inside the current project scope.

Do not rewrite large parts of the system if a small safe fix is enough.

Do not make broad architecture changes unless explicitly requested.

---

## Allowed Files and Folders

You may inspect and work with:

```text
app/
routes/
resources/views/
resources/js/
resources/css/
database/migrations/
database/seeders/
database/factories/
config/
public/
tests/
composer.json
package.json
vite.config.js
docs/
```

You may create new files in:

```text
app/Http/Controllers/
app/Http/Requests/
app/Models/
app/Services/
app/Console/Commands/
resources/views/
database/migrations/
tests/Feature/
tests/Unit/
docs/
```

Only edit files directly related to the user's request.

---

## Restricted Files

Do not read, print, modify, copy, or commit secrets from:

```text
.env
.env.*
.env.production
.env.local
.env.backup
```

Do not modify these unless explicitly requested:

```text
vendor/
node_modules/
storage/framework/
storage/app/private/
bootstrap/cache/
composer.lock
package-lock.json
yarn.lock
```

If environment values are needed, only say which key is needed.

Example:

```text
Please check MAIL_FROM_ADDRESS in .env
```

Never display the actual secret value.

---

## Database Rules

This project may use:

```text
sqlsrv
pgsqlw
pgsqlp
pgsqlmfgw
pgsqlmfgp
```

Rules:
- Do not assume table structure without checking existing code first.
- Use query bindings instead of unsafe string concatenation.
- Be careful with SQL Server collation conflicts.
- Be careful with PostgreSQL ERP data.
- Do not run destructive SQL.
- Do not generate DROP, TRUNCATE, or DELETE scripts unless explicitly requested.
- If writing UPDATE scripts, include safe WHERE conditions.
- If creating SQL Server scripts, prefer IF EXISTS / IF NOT EXISTS checks where useful.
- For heavy ERP queries, prefer summary queries, caching, or pagination.

---

## Laravel Coding Rules

Preferred structure:

```text
Controller = request handling and response
Service = business logic
Model = relationships and simple query scopes
Blade = display only
Request = validation
Command = scheduled/background job
```

Rules:
- Keep controllers readable.
- Move complex business logic into services.
- Do not put heavy logic directly in Blade.
- Validate user input.
- Check authorization server-side, not only in Blade.
- Use transactions when updating multiple related tables.
- Preserve existing route names and Blade variable names unless required.
- Do not break existing pages while implementing a new feature.
- Avoid unrelated refactoring.

---

## Workflow / Approval Rules

Workflow logic may include:
- workflow_steps
- wf_forms
- wf_form_authorize
- wf_action_history
- department_roles
- department parent_id
- level_no
- approve / reject transitions
- current step
- next step
- authorized approver

Rules:
- Always verify the current user is an actual approver before allowing approve/reject.
- Do not rely only on Blade button visibility.
- Approval actions must be checked in controller/service.
- Record action history when approval status changes.
- Handle missing approver cases clearly.
- Do not silently skip approval steps unless the workflow rule says so.
- Be careful with parent department fallback logic.
- Prevent duplicate approval actions where possible.

---

## Blade / UI Rules

For Blade pages:
- Keep UI clean and practical.
- Preserve existing route names, form names, IDs, and JavaScript hooks.
- Use clear Thai labels where UI is Thai.
- Tables should be readable.
- Modals should scroll if content is long.
- Save buttons in large modals should remain easy to access.
- Do not redesign the whole page unless requested.

For dashboards:
- Make numbers easy to read.
- Avoid clutter.
- Use large headings and clear summary cards.

For PDF:
- Be careful with A4 layout, margins, Thai fonts, and table widths.
- Do not make changes that break print layout.

---

## Email / PDF / Excel Rules

Email:
- Do not hardcode recipients if config/env is already used.
- Do not expose private emails unnecessarily.
- Show CC/BCC logic clearly if requested.

PDF:
- Preserve Thai font setup if already configured.
- Be careful with DomPDF limitations.

Excel:
- Format MFG / PO / SO numbers as text when needed.
- Avoid Excel auto-converting values incorrectly.
- Keep exports readable.

---

## Command / Bash Rules

Safe commands:

```bash
php artisan route:list
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan migrate:status
php artisan test
composer dump-autoload
npm run build
git status
git diff
```

Do not run destructive commands:

```bash
php artisan migrate:fresh
php artisan db:wipe
php artisan migrate:refresh
rm -rf
git reset --hard
git clean -fd
```

Do not commit, push, pull, merge, or rebase unless explicitly requested.

---

## Agent Usage Rules

Use specialist agents when appropriate.

Recommended flow for large features:

```text
tech-lead-orchestrator
project-architect
database-engineer
workflow-engineer
laravel-builder
blade-ui-developer
tester
code-reviewer
security-reviewer
```

For small tasks, call the specialist directly.

Examples:

```text
Use laravel-builder for controller/service/route implementation.
Use blade-ui-developer for Blade layout and modal work.
Use database-engineer for SQL Server/PostgreSQL queries.
Use workflow-engineer for approve/reject logic.
Use debugger for errors and logs.
Use code-reviewer before commit.
Use security-reviewer before deploying workflow or upload features.
```

---

## Implementation Process

Before editing:
1. Search existing files.
2. Understand current naming and structure.
3. Identify minimum files that need changes.
4. Avoid unrelated refactoring.

During editing:
1. Make small safe changes.
2. Preserve existing behavior.
3. Add validation and authorization where needed.
4. Use clear names.
5. Avoid duplicated code.

After editing:
1. List changed files.
2. Summarize what changed.
3. Mention assumptions.
4. Provide manual test steps.
5. Mention commands to verify.

---

## Safety Rules

Never:
- Expose .env secrets.
- Hardcode passwords, tokens, API keys, or database credentials.
- Remove authorization checks.
- Bypass approval logic.
- Delete data without explicit request.
- Rewrite unrelated modules.
- Change database schema without explaining impact.
- Run destructive commands.
- Commit or push without explicit request.

Always:
- Prefer safe minimal changes.
- Keep business logic correct.
- Check permissions.
- Use bindings for SQL.
- Respect existing project structure.
- Explain risky changes before applying them.

---

## Approval Before Risky Work

If a task requires:
- Editing more than 5 files
- Changing database schema
- Changing workflow approval logic
- Running a command that may affect data
- Large refactor
- Git commit/push

First produce a plan and wait for explicit user approval before making changes.

---

## Preferred Output Format

For development tasks:

```text
Summary
Files involved
Code / changes
Explanation
Test steps
Warnings or assumptions
```

For debugging tasks:

```text
Likely cause
Evidence
Fix
Files to change
Verification steps
```

For review tasks:

```text
Summary
Critical issues
High issues
Medium issues
Low issues
Suggested fixes
Test cases
```

---

## Business Context

This is an internal company tool.

Important areas may include:
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
- SQL Server application data
- PostgreSQL ERP data

Be careful with:
- Division-based data access
- Department-based approval
- Parent department fallback
- Revision logic
- Date-based filters
- Email schedules
- PDF layout
- Excel formatting
- Heavy ERP queries

## ERP Reporting Module

This project includes an ERP reporting sub-module that connects to an external
PostgreSQL ERP database (read-only) for reports and dashboards.

### Module Location
- Routes: `routes/web.php` under `Route::prefix('erp')` group
- Controllers: `app/Http/Controllers/Erp/`
- Repositories: `app/Repositories/Erp/`
- Services: `app/Services/Erp/`
- Views: `resources/views/erp/`
- Tests: `tests/Feature/Erp/`
- Documentation: `docs/erp-reporting/`

### Module Database Connection
ERP queries use connection name `erp` (PostgreSQL, read-only user `rptusr`).
Always specify the connection:

```php
DB::connection('erp')->select($sql, $params);
```

This is in addition to the existing `sqlsrv`, `pgsqlw`, `pgsqlp`, `pgsqlmfgw`,
`pgsqlmfgp` connections — do not confuse them.

### Critical Rule for ERP Module: Privacy Policy

```
❌ NEVER:
   • SELECT name FROM vendor / customer
   • JOIN vendor / customer to retrieve name
   • SELECT FROM vvendor (the vendor view)
   • Display vendor/customer name in UI, exports, or logs

✅ ALLOWED:
   • vendor_id, customer_id (numeric IDs only)
   • ap.notes (text content allowed even if names appear naturally)
   • parts.description, employee.name, classinfo.description
```

If a feature seems to require vendor/customer name → STOP and ask user to refactor
to aggregate or anonymized form.

### When working on ERP module — REQUIRED reading

If the user's task involves the ERP reporting module, read these BEFORE writing code:

1. `docs/erp-reporting/CLAUDE.md` — module-specific patterns and conventions
2. `docs/erp-reporting/PRIVACY_POLICY.md` — full privacy rules
3. `docs/erp-reporting/erp_data_dictionary.xlsx` — schema reference (199 tables)
4. `docs/erp-reporting/04_ap_queries_safe.sql` — query templates

How to detect "ERP module task":
- File path contains `Erp/` or `erp/`
- URL/route contains `/erp/`
- Question mentions: ERP, AP, AR, expense report, vendor_id, customer_id,
  classinfo, acc_trans, chart of accounts, dashboard
- Reference to `docs/erp-reporting/` files

### ERP module — Pattern Summary (quick reference)

For full pattern details see `docs/erp-reporting/CLAUDE.md`. Quick summary:

- **Repository pattern mandatory** — no SQL in controllers
- **Frontend**: Blade + Chart.js (CDN, no npm)
- **Layout**: `resources/views/erp/layouts/erp.blade.php` extends project master
- **Cache**: `Cache::remember()` 5–15 minutes for expensive queries
- **Test**: `tests/Feature/Erp/` covers auth + JSON shape + privacy assertions
- **Audit**: run `php artisan erp:privacy-audit` before commit