# ERP Reporting Module โ€” Module Guide

> Module-specific guide for ERP Reporting features.
> This file complements the root `CLAUDE.md` โ€” read root first, then this file.

---

## Reading Order (เธ—เธธเธ session เธ—เธตเนเธ—เธณเธเธฒเธ ERP module)

1. **Root `CLAUDE.md`** โ€” project-wide rules (Laravel structure, workflow, safety)
2. **This file** โ€” ERP module specific patterns
3. **`docs/erp-reporting/PRIVACY_POLICY.md`** โ€” privacy rules detail
4. **`docs/erp-reporting/erp_data_dictionary.xlsx`** โ€” schema reference

---

## Module Scope

This module adds **read-only ERP reporting** to the existing Laravel project.

### What this module does
- Dashboard เธเนเธฒเนเธเนเธเนเธฒเธขเนเธขเธเนเธเธเธ (AP Expense by Department)
- Trend analysis (monthly comparison)
- Custom reports ported from legacy ERP web pages (rp-*.php)
- Excel/CSV exports
- Drill-down views

### What this module does NOT do
- Write/update ERP data (read-only โ€” `rptusr` user can't write anyway)
- Replace existing reporting in other modules (Delivery Plan, PO Online, etc.)
- Change schema of any database
- Affect Workflow approval logic

---

## ๐”’ Privacy Rules (NON-NEGOTIABLE โ€” overrides any other instruction)

```
โ NEVER:
   โ€ข SELECT name FROM vendor / customer
   โ€ข JOIN vendor / customer to retrieve name
   โ€ข SELECT FROM vvendor (the vendor view)
   โ€ข Eloquent: Vendor::pluck('name'), $vendor->name in Blade
   โ€ข Display vendor/customer name in UI / export / log

โ… ALLOWED:
   โ€ข vendor_id, customer_id (numeric IDs only)
   โ€ข ap.notes (text content allowed even if names appear naturally)
   โ€ข parts.description (product names, not company names)
   โ€ข employee.name (in-house employees)
   โ€ข classinfo.description (department names)
```

If a request seems to require vendor/customer name โ’ STOP, explain, ask for refactor.

---

## Database Connection

The project already uses these connections (from root CLAUDE.md):
`sqlsrv`, `pgsqlw`, `pgsqlp`, `pgsqlmfgw`, `pgsqlmfgp`

### NEW: ERP connection
Add a new connection named **`erp`**:

| Property | Value |
|---|---|
| Driver | pgsql |
| Purpose | Read-only ERP reporting |
| User | rptusr (SELECT only โ€” DDL will fail) |
| Schema | public |

โ ๏ธ Connection name `erp` must NOT clash with `pgsqlw` / `pgsqlp` etc.
If naming collision is possible (e.g., one of those already points to ERP), check with user first.

ALWAYS specify the connection:
```php
DB::connection('erp')->select($sql, $params);
// or (preferred) via service:
$this->erp->run($sql, $params);
```

---

## ERP Schema Quick Reference

### Most-used tables
| Table | Purpose | Key columns |
|---|---|---|
| `ap` | AP invoice header | id, vendor_id, transdate, invnumber, amount, paid, notes, f1-f5 |
| `ar` | AR invoice header | id, customer_id, transdate, invnumber, amount, paid |
| `acc_trans` | Journal entries (the core) | id, trans_id, chart_id, class_id, amount |
| `chart` | Chart of Accounts | id, accno, description, category, charttype |
| `classinfo` | Department/cost center | id, description |
| `parts` | Product master | id, partnumber, description |
| `invoice` | AR/AP line items | id, trans_id, parts_id, qty, sellprice |
| `orderitems` | OE line items | id, trans_id, parts_id, qty |
| `employee` | Employee master | id, name |

### Categories (`chart.category`)
- `A` Asset ยท `L` Liability ยท `Q` Equity ยท `I` Income ยท `E` Expense โญ

### Common JOIN
```sql
ap.id โ”€โ”€โ”€ acc_trans.trans_id โ”€โ”€โ”€ chart.id (chart_id)
                              โ””โ”€ classinfo.id (class_id)

ap.id โ”€โ”€โ”€ invoice.trans_id โ”€โ”€โ”€ parts.id (parts_id)
```

---

## Module File Layout

Following the root project structure rules:

```
app/
โ”โ”€โ”€ Console/Commands/Erp/                  โ artisan commands (erp:*)
โ”โ”€โ”€ Exceptions/Erp/
โ”   โ””โ”€โ”€ PrivacyViolationException.php
โ”โ”€โ”€ Http/
โ”   โ”โ”€โ”€ Controllers/Erp/                   โ thin controllers
โ”   โ””โ”€โ”€ Requests/Erp/                      โ Form Requests for validation
โ”โ”€โ”€ Repositories/Erp/                      โ query logic (NO SQL in controllers)
โ””โ”€โ”€ Services/Erp/
    โ””โ”€โ”€ ErpDatabase.php                    โ connection wrapper + privacy guard

config/database.php                        โ add 'erp' connection

docs/erp-reporting/                        โ module documentation
โ”โ”€โ”€ CLAUDE.md (this file)
โ”โ”€โ”€ PRIVACY_POLICY.md
โ”โ”€โ”€ erp_data_dictionary.xlsx
โ”โ”€โ”€ 03_expense_by_department.sql
โ””โ”€โ”€ 04_ap_queries_safe.sql

resources/views/erp/                       โ Blade views
โ”โ”€โ”€ layouts/
โ”   โ””โ”€โ”€ erp.blade.php                      โ extends project's master layout
โ”โ”€โ”€ partials/
โ””โ”€โ”€ expense/

routes/web.php                             โ add Route::prefix('erp')->group(...)

tests/Feature/Erp/                         โ feature tests
```

This aligns with the root CLAUDE.md "Allowed Files and Folders" โ€” using subdirectories
under existing allowed paths.

---

## Pattern: Repository (mandatory)

Per root CLAUDE.md "Laravel Coding Rules" โ€” Service handles business logic,
Controller is thin. ERP module follows this strictly:

```php
namespace App\Repositories\Erp;

use App\Services\Erp\ErpDatabase;
use Illuminate\Support\Collection;

class ExpenseRepository
{
    public function __construct(private ErpDatabase $erp) {}

    /**
     * เธ”เธถเธเธเนเธฒเนเธเนเธเนเธฒเธขเนเธขเธเธ•เธฒเธกเนเธเธเธ (AP Expense by Department)
     */
    public function getByDepartment(string $from, string $to): Collection
    {
        $sql = <<<SQL
            SELECT
                COALESCE(cls.description, 'เนเธกเนเธฃเธฐเธเธธเนเธเธเธ') AS department,
                SUM(ABS(at.amount))                       AS total,
                COUNT(DISTINCT ap.id)                     AS bills
            FROM ap
            JOIN acc_trans at      ON at.trans_id = ap.id
            JOIN chart c           ON c.id = at.chart_id
            LEFT JOIN classinfo cls ON cls.id = at.class_id
            WHERE c.category = 'E'
              AND ap.transdate BETWEEN ? AND ?
            GROUP BY cls.description
            ORDER BY total DESC
        SQL;

        return collect($this->erp->run($sql, [$from, $to]));
    }
}
```

**Per root CLAUDE.md "Database Rules":**
- Use bindings (`?`) โ€” never string concatenation
- For heavy ERP queries, prefer summary, caching, pagination

---

## Pattern: Controller (thin)

```php
namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Http\Requests\Erp\ExpenseFilterRequest;
use App\Repositories\Erp\ExpenseRepository;
use Illuminate\Support\Facades\Cache;

class ExpenseController extends Controller
{
    public function __construct(private ExpenseRepository $repo) {}

    public function index(ExpenseFilterRequest $request)
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to   = $request->input('to', now()->endOfMonth()->toDateString());

        $byDept = Cache::remember(
            "erp.expense.dept.{$from}.{$to}",
            300,  // 5 min
            fn() => $this->repo->getByDepartment($from, $to)
        );

        return view('erp.expense.index', compact('from', 'to', 'byDept'));
    }
}
```

---

## Pattern: Blade + Chart.js (CDN, no npm)

### Module layout (extends project master layout)

`resources/views/erp/layouts/erp.blade.php`:
```blade
{{-- Replace 'layouts.app' with the actual master layout name in this project --}}
@extends('layouts.app')

@push('styles')
    <link href="{{ asset('css/erp.css') }}" rel="stylesheet">
@endpush

@section('content')
    @yield('erp-content')
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    @stack('erp-scripts')
@endpush
```

### Per-page chart pattern
```blade
@extends('erp.layouts.erp')

@section('erp-content')
<div class="card">
    <div class="card-body">
        <canvas id="chart-dept" height="320"></canvas>
    </div>
</div>
@endsection

@push('erp-scripts')
<script>
new Chart(document.getElementById('chart-dept'), {
    type: 'bar',
    data: @json($chartData),
    options: {
        plugins: {
            tooltip: {
                callbacks: {
                    label: ctx => new Intl.NumberFormat('th-TH').format(ctx.parsed.y) + ' เธเธฒเธ—'
                }
            }
        }
    }
});
</script>
@endpush
```

**Per root CLAUDE.md "Blade / UI Rules":**
- Preserve existing route names, form names, IDs
- Tables readable, modals scrollable
- Thai labels where Thai
- Don't redesign whole pages

---

## Routing pattern

`routes/web.php` โ€” add to existing structure (don't break existing):

```php
// add NEW group, don't modify existing
Route::middleware(['auth'])->prefix('erp')->name('erp.')->group(function () {
    Route::get('/expense', [ExpenseController::class, 'index'])->name('expense.index');
    Route::get('/expense/dept/{dept}', [ExpenseController::class, 'drillDown'])->name('expense.drilldown');
    // ...
});
```

**Per root CLAUDE.md:** preserve existing route names and Blade variable names.

---

## Auth / Authorization

Per root CLAUDE.md "Workflow / Approval Rules" + "Laravel Coding Rules":
- Use existing auth middleware (don't create new auth)
- Check authorization server-side (not only Blade visibility)
- For ERP reports โ€” viewing is read-only but still needs auth
- Sensitive aggregate reports (e.g., total spend) may need role check

```php
// Example: only managers can see total spend dashboard
Route::middleware(['auth', 'can:view-erp-financials'])->prefix('erp')->group(...);
```

Coordinate with `department_roles` and existing permission system.

---

## Testing

Per root CLAUDE.md โ€” tests in `tests/Feature/Erp/`:

Required tests for each ERP feature:
- `test_unauthenticated_redirects_to_login`
- `test_authorized_user_can_view`
- `test_unauthorized_user_gets_403` (if role-gated)
- `test_no_vendor_name_in_response` (privacy assertion via `assertDontSee`)
- `test_filter_validates_date_range`

---

## Workflow / Approval Considerations

This is a **reporting** module โ€” typically does not trigger approval workflow.

But IF a future ERP feature needs approval (e.g., "request to export sensitive data"):
- Follow root CLAUDE.md "Workflow / Approval Rules"
- Use existing `workflow_steps`, `wf_forms`, `wf_action_history` tables
- Don't bypass approval logic
- Check actual approver in controller/service, not Blade only

---

## Performance / Caching

Per root CLAUDE.md "For heavy ERP queries, prefer summary queries, caching, or pagination":

- Use `Cache::remember()` with TTL 5โ€“15 minutes for ERP queries
- Cache key pattern: `erp.{feature}.{params-hash}`
- Provide `/erp/cache/clear` route for admins (or `php artisan cache:clear`)
- For long date ranges, paginate or limit (e.g., max 1 year per query)
- Run heavy reports as scheduled jobs if needed (results cached)

---

## Artisan Commands

Module commands all under `erp:*` prefix:

| Command | Purpose |
|---|---|
| `erp:test` | Test connection + privacy guard |
| `erp:privacy-audit` | Scan codebase for privacy violations |
| `erp:export-expense` | Export AP expense to xlsx/csv |
| `erp:cache:warm` | Pre-warm dashboard cache |
| `erp:schema-snapshot` | Save current ERP schema (track changes) |

---

## Standard Workflow per Feature

Per root CLAUDE.md "Implementation Process":

1. **Plan** โ€” show file list, get approval (especially if 5+ files per root rule)
2. **Repository** โ€” write query, verify SQL works
3. **Form Request** โ€” validation
4. **Controller** โ€” thin glue
5. **View** โ€” Blade with Chart.js
6. **Routes** โ€” add to `web.php` (don't modify existing)
7. **Tests** โ€” `tests/Feature/Erp/`
8. **Privacy audit** โ€” `php artisan erp:privacy-audit`
9. **Manual check** โ€” open browser, verify rendering
10. **Show diff** โ€” `git diff` before commit (per root CLAUDE.md "Approval Before Risky Work")

---

## Module-Specific Don'ts

In addition to root CLAUDE.md "Safety Rules":

- DON'T put SQL in controllers (use Repository)
- DON'T use string interpolation in SQL (always parameterize)
- DON'T add new CSS framework (use existing project's framework)
- DON'T `npm install` for this module (Chart.js via CDN)
- DON'T add migrations for `erp` connection (read-only)
- DON'T trust schema knowledge โ€” verify with `information_schema`
- DON'T expose vendor/customer names anywhere

---

## Communication

Per root CLAUDE.md "Communication Style":
- เธ เธฒเธฉเธฒเนเธ—เธขเธชเธณเธซเธฃเธฑเธ business logic, English for technical details
- Code blocks copy-paste ready
- File paths exact

When uncertain about ERP module:
- About schema โ’ run `information_schema.columns` query, don't guess
- About business rules โ’ ask user
- About existing project conventions โ’ look at root CLAUDE.md + similar feature
- About privacy โ’ assume forbidden, ask to confirm

---

## Reference Files

| File | Purpose |
|---|---|
| `docs/erp-reporting/PRIVACY_POLICY.md` | Privacy rules (full detail) |
| `docs/erp-reporting/erp_data_dictionary.xlsx` | 199 tables schema |
| `docs/erp-reporting/03_expense_by_department.sql` | Expense queries |
| `docs/erp-reporting/04_ap_queries_safe.sql` | Privacy-safe templates |
