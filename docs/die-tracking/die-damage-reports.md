# DIE damage records and reports

Branch: `codex/die-damage-reports`. Intended PR target: `ai/menam-workflow`.

## Behavior

Authenticated users can open **DIE Reports** → records, create, or analysis. Each record represents one damage event; the same site/DIE may have multiple events, including on the same date. All authenticated users can maintain these records, consistent with the existing broadly accessible DIE module. No new role codes are introduced.

Records store the Summary fields from `DIE เสีย 2024.xlsx`, plus Site, event ID, notes and user/time metadata. The workbook is a reference only; no historical data is imported. Delete retains the row with `deleted_at`. Version checks reject concurrent updates/deletes with HTTP 409. Saved values drive historical reports; lookup does not refresh existing records automatically.

## Source mapping

- PCM `equipment`: current brand, vendor and sizes displayed as suggestions. The user explicitly accepts current brand/sizes for the event. Excel Supplier corresponds to Brand in the verified sample D00001756 (REC KOREA), rather than PCM vendor (country).
- PCM transfer history: WO candidates and distinct EU document count through the damage date. This count means issue documents, not production rounds or quantity.
- MFG WO/BOM: fill a single unambiguous grade/material supplier. Multiple values remain manual. BOM is the current stored BOM for the historical WO, not a versioned event-date BOM.
- MFG receives: show candidate machines through the damage date. These are WO-level candidates; the operator confirms which machine applies to this DIE.
- Weight, price, CODE, customer, die type, damage reason, fracture details and tensile values remain manual when not mapped. Missing data is null, not zero. PCM `meter` is not assumed to be kilograms.
- Server-side lookup snapshot records source/date and retrieved values; clients cannot submit arbitrary provenance.

## Reports and export

Fourteen report choices cover monthly counts, reasons, fracture categories, DIE types, brand, machines, grades, customers, material problems and event-level weight/cost. Month keys include year. Missing dimensions appear as ไม่ระบุ. Fracture reports count records with a nonempty fracture category. Deleted records are excluded.

THB/Kg = event price / event production kg when kg > 0 and price is known. Size Diff = current size - initial size. Weight is shown per event; cumulative weights for repeated DIE events are never summed. The report and `.xlsx` export share the same filters. Detail export includes all event fields; aggregate export contains the selected matrix. Strings are explicitly text to prevent spreadsheet formula execution and preserve identifiers. Reference helper tabs (price lists, dropdown sources, duplicate pivots) are not copied as reports.

## Installation

Use the configured `database.workflow_connection`. Back up the target Workflow database before schema changes. Install only this module's migration, not unrelated pending migrations:

```powershell
& C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_09_07_000001_create_die_damages_table.php
```

No existing business tables are modified. The migration creates `die_damages`; normal migration tracking prevents a repeat run. Do not run `down` against populated production data: it drops the event table.

Current local validation: PCM and MFG connect. Workflow SQL Server is blocked by `SQLSTATE[08001] ... Encryption not supported on the client`. No live migration or live event writes were performed. Fix the Workflow ODBC/TLS environment before installation. Do not weaken production TLS to work around this error.

## Validation

```powershell
& C:\xampp\php\php.exe vendor/bin/phpunit --filter DieDamage
& C:\xampp\php\php.exe artisan route:list --name=die-reports
```

Validation passed: 9 tests, 94 assertions. Tests use SQLite in memory (no live business DB): authentication, repeat events, validation, null/zero calculations, concurrent updates/deletes, soft deletion, source provenance, date cutoff, ambiguous BOM, all report views, shared filters and generated XLSX values.

Browser inspection used rendered Blade pages with fixture data; form layout and zero-weight recalculation passed. Live read-only lookup of W / D00001756 at 2024-01-05 returned WO W2302858, grade 316, WALSIN and two issue documents, matching the workbook example. No live records were written. After installation, verify lookup for both W/P sites, create/edit/delete a controlled event and reconcile filtered report/export counts. Historical data completeness requires operator review.
