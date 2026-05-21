---

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
