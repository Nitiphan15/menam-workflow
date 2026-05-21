# ๐€ Quick Start โ€” Claude Code Prompts

> Prompts เธชเธณเน€เธฃเนเธเธฃเธนเธ copy-paste เนเธเนเนเธ”เนเธ—เธฑเธเธ—เธต เธชเธณเธซเธฃเธฑเธ ERP Reporting Module
> Stack: Laravel + Blade + Chart.js + XAMPP + PostgreSQL

---

## ๐“ Setup เธเธฃเธฑเนเธเนเธฃเธ (เธ—เธณเธเธฃเธฑเนเธเน€เธ”เธตเธขเธง)

### 1. เธงเธฒเธเนเธเธฅเนเนเธ Laravel project
```
your-laravel-project/                      โ root เธเธญเธ project เธ—เธตเนเธกเธตเธญเธขเธนเน
โ”โ”€โ”€ CLAUDE.md                              โ เธงเธฒเธเธ•เธฃเธเธเธตเน! (Claude Code เธญเนเธฒเธเธญเธฑเธ•เนเธเธกเธฑเธ•เธด)
โ”โ”€โ”€ docs/erp-reporting/                    โ เธชเธฃเนเธฒเธ folder เนเธซเธกเน
โ”   โ”โ”€โ”€ PRIVACY_POLICY.md
โ”   โ”โ”€โ”€ erp_data_dictionary.xlsx
โ”   โ”โ”€โ”€ 03_expense_by_department.sql
โ”   โ””โ”€โ”€ 04_ap_queries_safe.sql
โ””โ”€โ”€ ... (project files เน€เธ”เธดเธก) ...
```

### 2. Commit project state
```bash
cd C:\xampp\htdocs\your-project
git add .
git commit -m "chore: add ERP reporting docs and CLAUDE.md"
```

### 3. เน€เธเธดเธ” Claude Code
```bash
claude
```

---

## 1๏ธโฃ Initial Audit โ€” Prompt เนเธฃเธเธชเธธเธ”

```
เธชเธงเธฑเธชเธ”เธตเธเธฃเธฑเธ เธเธฑเธเธ•เนเธญเธเธเธฒเธฃเน€เธเธดเนเธก ERP Reporting Module เน€เธเนเธฒเนเธเนเธ Laravel project เธ—เธตเนเธกเธตเธญเธขเธนเน
เธเนเธญเธเน€เธฃเธดเนเธก เธเธญเนเธซเนเธเธธเธ“:

[STEP 1: เธญเนเธฒเธ docs]
1. เธญเนเธฒเธ CLAUDE.md เธ—เธตเน root
2. เธญเนเธฒเธ docs/erp-reporting/PRIVACY_POLICY.md

[STEP 2: เธชเธณเธฃเธงเธ project]
3. เธ”เธน composer.json:
   - Laravel version
   - PHP version constraint
   - Auth packages (Sanctum, Breeze, Jetstream, etc.)
4. เธ”เธน resources/views/layouts/ โ€” เธกเธต master layout เธเธทเนเธญเธญเธฐเนเธฃ, extend เธญเธฐเนเธฃ
5. เธ”เธน config/auth.php โ€” auth driver เนเธฅเธฐ provider เน€เธเนเธเธญเธฐเนเธฃ
6. เธ”เธน routes/web.php โ€” เธกเธต route group / middleware pattern เธญเธฐเนเธฃ
7. เธ”เธน existing controller เธญเธขเนเธฒเธเธเนเธญเธข 1 เธ•เธฑเธง โ€” เธ”เธน naming, namespace, pattern
8. เธ”เธน resources/views เธเธญเธ feature เน€เธ”เธดเธก 1 เธ•เธฑเธง โ€” เธ”เธน Blade conventions

[STEP 3: เธ•เธฃเธงเธ environment]
9. เธฃเธฑเธ php -v
10. เธฃเธฑเธ php -m | grep -i pgsql (เธ•เนเธญเธเธกเธต pdo_pgsql, pgsql)

[STEP 4: เธฃเธฒเธขเธเธฒเธ]
เธชเธฃเธธเธเนเธซเนเธเธฑเธเน€เธเนเธ markdown:
- Project Stack: ...
- Existing Conventions: ...
- Master Layout: ...
- Auth Pattern: ...
- Environment Status: โ…/โ (เธเธฃเนเธญเธก/เนเธกเนเธเธฃเนเธญเธก)
- เธเนเธญเน€เธชเธเธญเนเธเธฐ: integrate ERP module เธขเธฑเธเนเธเนเธซเนเน€เธเนเธฒเธเธฑเธ

โ ๏ธ เธซเนเธฒเธกเน€เธเธตเธขเธ code เนเธเธเธฑเนเธเธเธตเน โ€” เนเธเนเธญเนเธฒเธเนเธฅเธฐเธฃเธฒเธขเธเธฒเธ
โ ๏ธ เธ–เนเธฒเน€เธเธญเธเธฑเธเธซเธฒ (PHP เน€เธเนเธฒ, เนเธกเนเธกเธต pgsql extension, Laravel เน€เธเนเธฒเธกเธฒเธ) เธเธญเธเธเธฑเธเธเนเธญเธ
```

---

## 2๏ธโฃ Setup Database Connection

```
เธ•เธฑเนเธเธเนเธฒ database connection 'erp' เนเธเนเธเธฃเน€เธเธ

Spec:
- Connection name: erp
- Driver: pgsql
- เนเธเน env vars (เนเธกเน hardcode):
  ERP_DB_HOST, ERP_DB_PORT, ERP_DB_DATABASE,
  ERP_DB_USERNAME, ERP_DB_PASSWORD, ERP_DB_SCHEMA

เธ—เธณเนเธซเน:

1. เนเธเน config/database.php โ€” เน€เธเธดเนเธก connection 'erp' เธ•เธฒเธก template เนเธ CLAUDE.md
2. เน€เธเธดเนเธกเนเธ .env.example (commit เนเธ”เน)
3. เนเธชเธ”เธ diff เธ—เธตเนเธเธฐเนเธเน โ€” เธฃเธญ OK เธเนเธญเธ apply
4. เธซเธฅเธฑเธ apply เน€เธชเธฃเนเธ เธ•เธญเธเธเธฑเธเธงเนเธฒเนเธซเนเธ—เธณเธญเธฐเนเธฃเธ•เนเธญ:
   - เนเธเน .env (เนเธชเน password เธเธฃเธดเธ)
   - เน€เธเธดเธ” pdo_pgsql เนเธ php.ini เธ–เนเธฒเธขเธฑเธเนเธกเนเน€เธเธดเธ”
   - test connection

เธญเธขเนเธฒเนเธเน .env เนเธ”เธขเธญเธฑเธ•เนเธเธกเธฑเธ•เธด
```

---

## 3๏ธโฃ เธชเธฃเนเธฒเธ Service + Test Connection

```
เธชเธฃเนเธฒเธ infrastructure layer เธชเธณเธซเธฃเธฑเธ ERP queries

Files:

[1] app/Services/Erp/ErpDatabase.php
   - method run(string $sql, array $params = []): array
   - method runOne(string $sql, array $params = []): ?object
   - middleware เธ•เธฃเธงเธ SQL เธเนเธญเธ execute:
     * เธ–เนเธฒเธกเธต "vendor.name" เธซเธฃเธทเธญ "customer.name" (case insensitive) โ’ throw PrivacyViolationException
     * เธ–เนเธฒเธกเธต "FROM vvendor" โ’ throw PrivacyViolationException
     * เนเธเน regex เธ•เธฃเธงเธเนเธซเนเธเธฃเธญเธเธเธฅเธธเธก whitespace variations
   - log query เธเนเธฒเธ Log::channel('erp')

[2] app/Exceptions/Erp/PrivacyViolationException.php
   - extends Exception
   - render() return 403 เธเธฃเนเธญเธก view 'errors.privacy' (เธชเธฃเนเธฒเธเธ”เนเธงเธข)

[3] resources/views/errors/privacy.blade.php
   - เนเธชเธ”เธเธเนเธญเธเธงเธฒเธกเธ เธฒเธฉเธฒเนเธ—เธขเธงเนเธฒเธ—เธณเนเธกเธ–เธนเธ block
   - extends layout เธเธญเธ project

[4] config/logging.php โ€” เน€เธเธดเนเธก channel 'erp'
   - daily file: storage/logs/erp-{date}.log
   - keep 30 days

[5] app/Console/Commands/Erp/TestConnection.php
   - signature: erp:test
   - เธ—เธณ:
     * test connection (SELECT 1)
     * count tables เนเธ schema 'public'
     * เธ—เธ”เธชเธญเธ privacy guard (run query เธ—เธตเนเธกเธต vendor.name โ’ เธ•เนเธญเธ throw)
     * เธ—เธ”เธชเธญเธเธงเนเธฒ user เน€เธเนเธ read-only (เธฅเธญเธ CREATE TABLE โ’ เธ•เนเธญเธ fail)
   - เนเธชเธ”เธเธเธฅเนเธเธ table

[6] tests/Unit/Services/ErpDatabaseTest.php
   - test_blocks_vendor_name
   - test_blocks_customer_name
   - test_blocks_vvendor_view
   - test_allows_normal_query (mock)

Workflow:
1. เนเธชเธ”เธ file list เธ—เธตเนเธเธฐเธชเธฃเนเธฒเธ
2. เธฃเธญ OK
3. เธ—เธณเธ—เธตเธฅเธฐเนเธเธฅเน โ€” เนเธชเธ”เธ code + เธญเธเธดเธเธฒเธขเธชเธฑเนเธเน
4. เธฃเธญ OK เธเนเธญเธเธ—เธณ next file
5. เธซเธฅเธฑเธเธเธฃเธ เนเธซเนเธเธฑเธ run php artisan erp:test เน€เธญเธ โ€” เธฃเธญเธเธฅ

โ ๏ธ เธซเนเธฒเธก run command เน€เธญเธ เธฃเธญเธเธฑเธ confirm เธเธฅเธเนเธญเธ
```

---

## 4๏ธโฃ Feature เนเธฃเธ: Dashboard เธเนเธฒเนเธเนเธเนเธฒเธขเนเธขเธเนเธเธเธ โญ

```
เธชเธฃเนเธฒเธ feature: "เธเนเธฒเนเธเนเธเนเธฒเธขเนเธขเธเนเธเธเธ"

URL: /erp/expense
Auth: เนเธเน middleware เน€เธ”เธตเธขเธงเธเธฑเธ feature เธญเธทเนเธเน เนเธ project (เธญเนเธฒเธเธเธฒเธ existing routes)

Reference SQL: docs/erp-reporting/03_expense_by_department.sql Query 1, 3, 4

Files to create:

[1] app/Repositories/Erp/ExpenseRepository.php
   - constructor: inject ErpDatabase
   - getByDepartment(string $from, string $to): Collection
   - getMonthlyTrend(int $months = 6): Collection
   - getDetailByDepartment(string $deptName, string $from, string $to): Collection
   - all SQL parameterized เธเนเธฒเธ ErpDatabase service
   - cache layer เธ—เธตเน method level (Cache::remember 300s)

[2] app/Http/Requests/Erp/ExpenseFilterRequest.php
   - validate from (date), to (date), to >= from
   - default values if empty: from = startOfMonth, to = endOfMonth

[3] app/Http/Controllers/Erp/ExpenseController.php
   - method index(ExpenseFilterRequest $req)
     * call repo
     * prepare chart data (labels + datasets)
     * return view
   - method drillDown(string $dept, ExpenseFilterRequest $req)
     * เธฃเธฒเธขเธฅเธฐเน€เธญเธตเธขเธ”เธเธญเธเนเธเธเธ

[4] resources/views/erp/layouts/erp.blade.php
   - extends master layout เธเธญเธ project (เธ—เธตเน Claude Code เน€เธเธญเนเธ Step 1)
   - @push('styles') เนเธฅเธฐ @push('scripts')
   - load Chart.js CDN: https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js
   - เธกเธต @yield('erp-content')
   - เธกเธต breadcrumb partial (เธฃเธญเธเธฃเธฑเธ section title)

[5] resources/views/erp/expense/index.blade.php
   - extends erp.layouts.erp
   - filter bar: date from/to (default = เน€เธ”เธทเธญเธเธเธฑเธเธเธธเธเธฑเธ), submit button
   - 2 cards in row:
     * Card 1: bar chart "เธเนเธฒเนเธเนเธเนเธฒเธขเนเธขเธเนเธเธเธ" (horizontal bar)
     * Card 2: doughnut chart "เธชเธฑเธ”เธชเนเธงเธ"
   - 1 card: line chart "เนเธเธงเนเธเนเธก 6 เน€เธ”เธทเธญเธ"
   - เธ•เธฒเธฃเธฒเธ summary เธ”เนเธฒเธเธฅเนเธฒเธ (เนเธเธเธ / เธเธณเธเธงเธเธเธดเธฅ / เธขเธญเธ”เธฃเธงเธก / %)
   - @push('erp-scripts') เธชเธณเธซเธฃเธฑเธ chart code
   - เธเธญเธฅเธฑเธกเธเนเธ—เธฑเนเธเธซเธกเธ”เน€เธเนเธเนเธ—เธข
   - เธ•เธฑเธงเน€เธฅเธเนเธชเน number_format()

[6] routes/web.php โ€” เน€เธเธดเนเธกเน€เธเนเธฒเนเธเนเธ group เน€เธ”เธดเธก (เธ–เนเธฒเธกเธต auth group):
   ```php
   Route::middleware(['auth'])->prefix('erp')->name('erp.')->group(function () {
       Route::get('/expense', [ExpenseController::class, 'index'])->name('expense.index');
       Route::get('/expense/dept/{dept}', [ExpenseController::class, 'drillDown'])->name('expense.drilldown');
   });
   ```
   - **เธญเธขเนเธฒ** เนเธเน middleware/group เน€เธ”เธดเธก โ€” เน€เธเธดเนเธกเนเธซเธกเนเน€เธ—เนเธฒเธเธฑเนเธ

[7] tests/Feature/Erp/ExpenseTest.php
   - test_unauthenticated_redirects_to_login
   - test_authenticated_can_view_expense
   - test_default_date_is_current_month
   - test_filter_validates_date_format
   - test_no_vendor_name_in_response (assertDontSee)
   - test_drill_down_returns_correct_dept

Workflow:
1. เธเนเธญเธเน€เธฃเธดเนเธก:
   - เนเธชเธ”เธ file list เธ—เธฑเนเธ 7 เนเธเธฅเน
   - เนเธชเธ”เธเธ•เธฑเธงเธญเธขเนเธฒเธ 1 chart เธ—เธตเนเธเธฐเธ—เธณเธซเธเนเธฒเธ•เธฒ (description)
   - เธ–เธฒเธก CSS framework เธเธญเธ project (Bootstrap?, Tailwind?, custom?) เน€เธเธทเนเธญเนเธเน class เนเธซเนเธ•เธฃเธ
2. เธ—เธณ Repository เธเนเธญเธ + commit
3. เธ—เธณ Request + Controller + commit
4. เธ—เธณ Layout + commit
5. เธ—เธณ View + commit
6. เธ—เธณ Routes + commit
7. เธ—เธณ Tests + run + commit

เธซเนเธฒเธก:
- เนเธเนเนเธเธฅเน outside เธเธญเธ list เธเนเธฒเธเธเธ
- เธ•เธดเธ”เธ•เธฑเนเธ package เนเธซเธกเน
- เนเธเน master layout เธเธญเธ project
- เน€เธเธดเนเธก CSS framework

เธฃเธฐเธซเธงเนเธฒเธเธ—เธณ:
- เธซเธฅเธฑเธ commit เนเธ•เนเธฅเธฐเธเธฃเธฑเนเธ เธเธญเธเธเธฑเธ + เธฃเธญ confirm
- เธ–เนเธฒเน€เธเธญเธเธฑเธเธซเธฒ (column เนเธกเนเธ•เธฃเธ, query เธเธดเธ”) โ’ STOP เธ–เธฒเธก
```

---

## 5๏ธโฃ Privacy Audit Tool

```
เธชเธฃเนเธฒเธ artisan command เธ•เธฃเธงเธ privacy violations เธญเธฑเธ•เนเธเธกเธฑเธ•เธด

Command: php artisan erp:privacy-audit

Spec:

[1] app/Console/Commands/Erp/PrivacyAudit.php
   - signature: erp:privacy-audit {--json} {--quiet}

   - เธชเนเธเธ paths:
     * app/**/*.php
     * resources/views/**/*.blade.php
     * routes/*.php
     * database/migrations/**/*.php

   - เธ•เธฃเธงเธ patterns (case insensitive):
     * SQL: "vendor.name", "customer.name", "FROM vvendor"
     * SQL: "JOIN vendor" (เน€เธ•เธทเธญเธ โ€” เธ•เธฃเธงเธเธงเนเธฒเธ”เธถเธ name เธซเธฃเธทเธญเน€เธเธฅเนเธฒ)
     * Eloquent: "Vendor::pluck('name')", "->vendor->name"
     * Blade: "{{ $vendor->name }}", "{{ $customer->name }}"

   - Output:
     * default: print เนเธ•เนเธฅเธฐ violation (file:line:pattern)
     * --json: JSON array
     * --quiet: count เน€เธ—เนเธฒเธเธฑเนเธ

   - Exit code:
     * 0 = clean
     * 1 = violations found

[2] tests/Feature/Console/PrivacyAuditTest.php
   - เธชเธฃเนเธฒเธ temp file เธ—เธตเนเธกเธต violation โ’ assert detect เนเธ”เน
   - เธชเธฃเนเธฒเธ temp file เธชเธฐเธญเธฒเธ” โ’ assert เนเธกเน false positive

[3] เน€เธเธดเนเธกเน€เธเนเธฒ scheduled run (optional):
   - เนเธกเนเธ•เนเธญเธเธ—เธณ scheduled โ€” เนเธเนเธ—เธณเน€เธเนเธ manual command

Workflow:
1. เธ—เธณ command + test
2. เธฃเธฑเธ command เธเธ project เธเธฑเธเธเธธเธเธฑเธ
3. เธ–เนเธฒเน€เธเธญ violations:
   - เธซเธขเธธเธ” เธซเนเธฒเธกเนเธเนเน€เธญเธ
   - เนเธชเธ”เธเธฃเธฒเธขเธเธฒเธฃเนเธซเนเธเธฑเธเธ”เธน
   - เน€เธชเธเธญเธงเธดเธเธตเนเธเนเนเธ•เนเธฅเธฐเธเธธเธ”
   - เธฃเธญ decision

เธซเนเธฒเธก implement --fix option (privacy fix เธ•เนเธญเธ human review เน€เธชเธกเธญ)
```

---

## 6๏ธโฃ Export to Excel/CSV

```
เธชเธฃเนเธฒเธ artisan command export เธเนเธฒเนเธเนเธเนเธฒเธขเน€เธเนเธเนเธเธฅเน

Commands:
- php artisan erp:export-expense --from=2026-04-01 --to=2026-04-30 --format=xlsx
- php artisan erp:export-expense --from=2026-04-01 --to=2026-04-30 --format=csv --dept=ACCOUNT

Spec:

[1] composer require openspout/openspout
   โ ๏ธ เธเธญเธญเธเธธเธเธฒเธ•เธเนเธญเธ install
   เน€เธซเธ•เธธเธเธฅ: เน€เธเธฒเธเธงเนเธฒ phpoffice/phpspreadsheet เธกเธฒเธ เน€เธซเธกเธฒเธฐเธเธฑเธ export เธญเธขเนเธฒเธเน€เธ”เธตเธขเธง

[2] app/Console/Commands/Erp/ExportExpense.php
   - signature: erp:export-expense {--from=} {--to=} {--format=xlsx} {--dept=}
   - validation: from, to required, format in [xlsx, csv]
   - reuse ExpenseRepository
   - save: storage/app/erp-exports/expense_{from}_{to}_{Ymd_His}.{ext}
   - output:
     * path เน€เธ•เนเธกเธเธญเธเนเธเธฅเน
     * row count
     * file size

   - xlsx structure:
     * Sheet 1 "เธฃเธฒเธขเธฅเธฐเน€เธญเธตเธขเธ”": เธ—เธธเธ row เธเธฃเนเธญเธกเธซเธฑเธงเธเธญเธฅเธฑเธกเธเนเธ เธฒเธฉเธฒเนเธ—เธข
     * Sheet 2 "เธชเธฃเธธเธเนเธเธเธ": pivot summary
     * Sheet 3 "เธชเธฃเธธเธเธเธฑเธเธเธต": เนเธขเธเธ•เธฒเธก chart of account

   - csv: UTF-8 with BOM (Excel เน€เธเธดเธ”เนเธ”เนเธ เธฒเธฉเธฒเนเธ—เธขเธ–เธนเธ), single sheet

[3] log: storage/logs/erp-export.log
   - timestamp, command params, file path, rows, duration

[4] test: เธฅเธญเธ command เธเธ data เธเธฃเธดเธ (small range) โ€” เธฃเธญ confirm

Workflow:
1. เธเธญ install openspout โ€” เธฃเธญ OK
2. เธ—เธณ command (เนเธกเนเธกเธต subcommand เธเธฐ เธเธเน€เธ”เธตเธขเธง)
3. เธ—เธณ test (mock ErpDatabase)
4. เธฃเธญเธเธฑเธ run เธเธฃเธดเธ

เธซเนเธฒเธก:
- export เธ—เธตเนเธฅเธฐเน€เธกเธดเธ” privacy
- เน€เธเนเธเนเธเธฅเน export เนเธ git (เน€เธเธดเนเธก .gitignore: storage/app/erp-exports/*)
```

---

## 7๏ธโฃ เน€เธเธดเนเธกเธฃเธฒเธขเธเธฒเธเนเธซเธกเนเธเธฒเธ ERP เน€เธเนเธฒ

```
เธเธฑเธเธ•เนเธญเธเธเธฒเธฃ port เธฃเธฒเธขเธเธฒเธ [เธเธทเนเธญเธฃเธฒเธขเธเธฒเธ] เธเธฒเธ ERP เน€เธเนเธฒเธกเธฒเน€เธเนเธ Laravel feature

เธเนเธญเธกเธนเธฅ ERP เน€เธ”เธดเธก:
- URL: http://192.168.1.40/cpa/[FILE].php?[PARAMS]
- เธ•เธฑเธงเธญเธขเนเธฒเธ: http://192.168.1.40/cpa/rp-XXX.php?from=2026-04-01&to=2026-04-30

Output เธ—เธตเนเน€เธซเนเธ (เธเธฒเธ ERP เน€เธ”เธดเธก):
[paste header columns]

Sample data 3-5 rows:
[paste rows]

เธซเธกเธฒเธขเน€เธซเธ•เธธเธเธดเน€เธจเธฉ:
[เน€เธเนเธ "filter เธ•เธฒเธกเนเธเธเธเนเธ”เน" / "เธกเธต subtotal" / "เน€เธฃเธตเธขเธเธ•เธฒเธก..."]

เธเธฑเนเธเธ•เธญเธ:

[Step 1: เธงเธดเน€เธเธฃเธฒเธฐเธซเน]
1. เน€เธ”เธฒ table/column เธ—เธตเนเนเธเน (เธญเนเธฒเธเธญเธดเธ Data Dictionary sheet "Tables (Public)")
2. เธ•เธฃเธงเธ privacy: เธฃเธฒเธขเธเธฒเธเธเธตเนเนเธชเธ”เธเธเธทเนเธญ vendor/customer เนเธซเธก?
   - เธ–เนเธฒเนเธเน โ’ STOP เน€เธชเธเธญเนเธเธ aggregate/anonymized เนเธ—เธ เธฃเธญ decision
   - เธ–เนเธฒเนเธกเน โ’ เธ—เธณเธ•เนเธญ
3. เนเธชเธ”เธ column mapping:
   ```
   ERP column          โ’ SQL expression
   โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€โ”€
   "เธงเธฑเธเธ—เธตเน"            โ’ ap.transdate
   "เน€เธฅเธเธ—เธตเนเธเธดเธฅ"          โ’ ap.invnumber
   ...
   ```
4. เนเธชเธ”เธ JOIN เธ—เธตเนเธเธฐเนเธเน + เน€เธซเธ•เธธเธเธฅ

[Step 2: SQL]
5. เน€เธเธตเธขเธ SQL เธ•เธฒเธก pattern เนเธ 04_ap_queries_safe.sql
6. เธเธญเนเธซเนเธเธฑเธ run SQL เนเธ pgAdmin โ’ paste เธเธฅเธเธฅเธฑเธเนเธซเนเธ”เธน
7. เธ–เนเธฒเธเธฅเธ•เธฃเธ โ’ เธ—เธณเธ•เนเธญ; เธ–เนเธฒเธเธดเธ” โ’ debug

[Step 3: Laravel feature]
8. เธ•เธฑเนเธเธเธทเนเธญ pattern เน€เธ”เธตเธขเธงเธเธฑเธ feature เธญเธทเนเธ:
   - URL: /erp/{module}/{report-slug}
   - Repository: app/Repositories/Erp/{Name}Repository.php
   - Controller: app/Http/Controllers/Erp/{Name}Controller.php
   - View: resources/views/erp/{module}/{report-slug}.blade.php
9. เธ—เธณเธ•เธฒเธก workflow เธเธญเธ Feature #4
10. Test + privacy audit เธเนเธญเธ commit

โ ๏ธ Constraints:
- เธซเนเธฒเธกเนเธเน feature เน€เธ”เธดเธก
- reuse erp.layouts.erp
- เน€เธเธทเนเธญ user filter เธ—เธตเนเธฃเธฒเธขเธเธฒเธเน€เธ”เธดเธกเธกเธต
- เธ–เนเธฒ column "เธซเธกเธฒเธขเน€เธซเธ•เธธ" เนเธเธฃเธฒเธขเธเธฒเธเน€เธ”เธดเธกเน€เธเนเธ HTML โ’ escape เนเธ Blade เธ”เนเธงเธข {{ }} เธ•เธฒเธกเธเธเธ•เธด
```

---

## 8๏ธโฃ Debug

```
[เธเธทเนเธญ feature/route] เธกเธตเธเธฑเธเธซเธฒ

เธญเธฒเธเธฒเธฃ:
[describe เธญเธฐเนเธฃเน€เธเธดเธ”เธเธถเนเธ เน€เธเนเธ "render เนเธฅเนเธง chart เธงเนเธฒเธ" / "Error 500" / "เธ•เธฑเธงเน€เธฅเธเนเธกเนเธ•เธฃเธเธเธฑเธ ERP เน€เธ”เธดเธก"]

เธงเธดเธเธต reproduce:
1. เนเธเธ—เธตเน /erp/...
2. เนเธชเน filter ...
3. เธเธฅเธ—เธตเนเนเธ”เน: ...

Expected: [เธเธฒเธ”เธซเธงเธฑเธเธงเนเธฒ]
Actual: [เธ—เธตเนเน€เธเนเธเธเธฃเธดเธ]

เธเนเธงเธขเธ—เธณ:
1. เธ”เธน storage/logs/laravel.log (50 เธเธฃเธฃเธ—เธฑเธ”เธฅเนเธฒเธชเธธเธ”)
2. เธ”เธน storage/logs/erp-*.log
3. เธ–เนเธฒเธเนเธฒเธเธฐเน€เธเธตเนเธขเธง query โ’ enable query log temporarily
   ```php
   DB::connection('erp')->listen(fn($q) => logger()->channel('erp')->debug($q->sql, $q->bindings));
   ```
4. เธ–เนเธฒเธเนเธฒเธเธฐเน€เธเธตเนเธขเธง frontend โ’ check browser console + Network tab
5. เธงเธดเน€เธเธฃเธฒเธฐเธซเนเธชเธฒเน€เธซเธ•เธธเธ—เธตเนเน€เธเนเธเนเธเนเธ”เน
6. เน€เธชเธเธญ 2-3 เธ—เธฒเธเนเธเน + pros/cons
7. เธฃเธญ OK เธเนเธญเธเนเธเน

โ ๏ธ เธซเนเธฒเธกเนเธเนเน€เธญเธเนเธ”เธขเธญเธฑเธ•เนเธเธกเธฑเธ•เธด โ€” analyze first

เธ–เนเธฒเน€เธเนเธ performance:
- เธฃเธฑเธ EXPLAIN ANALYZE
- เน€เธชเธเธญ caching เธเนเธญเธ (Cache::remember)
- เธซเนเธฒเธกเน€เธชเธเธญ CREATE INDEX (read-only user)
- เธซเนเธฒเธกเน€เธชเธเธญ schema change

เธ–เนเธฒเน€เธเนเธ query เธเธฅเธเธดเธ”:
- เน€เธ—เธตเธขเธเธเธฑเธ ERP เน€เธ”เธดเธก
- เธ•เธฃเธงเธ JOIN type (LEFT vs INNER เธ—เธณเนเธซเน row เธซเธฒเธข/เธเนเธณ)
- เธ•เธฃเธงเธ aggregate (SUM เธเนเธณเน€เธกเธทเนเธญ JOIN N:M)
- เธ•เธฃเธงเธ filter (เธเธฃเธญเธเธเธฅเธธเธกเธซเธฃเธทเธญเน€เธเธฅเนเธฒ)

เธ–เนเธฒเน€เธเนเธ Blade/Chart:
- echo dd($chartData) เธ”เธน structure
- เธ•เธฃเธงเธ {!! !!} vs {{ }}
- เธ•เธฃเธงเธ @json() vs json_encode()
```

---

## 9๏ธโฃ Refactor Legacy Code

```
เธกเธต SQL/code เน€เธ”เธดเธกเธ—เธตเนเธญเธฒเธเธฅเธฐเน€เธกเธดเธ” privacy:

[paste code]

เธเนเธงเธขเธ—เธณ:
1. เธฃเธฐเธเธธเธงเนเธฒเธฅเธฐเน€เธกเธดเธ”เธเนเธญเนเธซเธเนเธ PRIVACY_POLICY
2. เน€เธชเธเธญ refactor 3 เนเธเธ:

   เนเธเธ A: Minimum Change
   - เนเธเนเนเธเนเธชเนเธงเธเธ—เธตเน violation
   - เธฃเธฑเธเธฉเธฒ UX เธเธฑเธเธเธธเธเธฑเธเนเธซเนเธกเธฒเธเธ—เธตเนเธชเธธเธ”

   เนเธเธ B: Aggregate Refactor
   - เน€เธเธฅเธตเนเธขเธเน€เธเนเธ aggregate report เนเธกเนเธฃเธฐเธเธธเธเธทเนเธญ
   - เนเธชเธ”เธเธ•เธฑเธงเน€เธฅเธเธฃเธงเธก เนเธกเนเนเธชเธ”เธเธฃเธฒเธขเธ•เธฑเธง

   เนเธเธ C: Drop Feature
   - เธ–เนเธฒ refactor เนเธกเนเนเธ”เนเนเธฅเนเธงเธขเธฑเธเธกเธตเธเธฃเธฐเนเธขเธเธเน
   - เธซเธฃเธทเธญเธ–เนเธฒเธเธงเธฃเน€เธฅเธดเธเนเธเน

3. เธฃเธฐเธเธธเธเธฅเธเธฃเธฐเธ—เธ user:
   - เธเนเธญเธกเธนเธฅเธ—เธตเนเธซเธฒเธขเนเธเธเธทเธญเธญเธฐเนเธฃ
   - workflow เธ—เธตเนเธ•เนเธญเธเน€เธเธฅเธตเนเธขเธ
   - communication plan

4. เธฃเธญ decision

เธซเนเธฒเธกเนเธเนเน€เธญเธเนเธ”เธขเธญเธฑเธ•เนเธเธกเธฑเธ•เธด
```

---

## ๐” Deploy Checklist

```
เธชเธฃเนเธฒเธ deployment checklist เธชเธณเธซเธฃเธฑเธ ERP module

Output: docs/erp-reporting/DEPLOY_CHECKLIST.md

เธเธฃเธญเธเธเธฅเธธเธก:

## Pre-deploy
- [ ] git status clean
- [ ] all tests pass: php artisan test --filter=Erp
- [ ] privacy audit pass: php artisan erp:privacy-audit
- [ ] composer install --no-dev (เนเธ production)
- [ ] php artisan config:cache
- [ ] php artisan route:cache
- [ ] php artisan view:cache

## Database
- [ ] confirm rptusr เธกเธตเธชเธดเธ—เธเธดเน SELECT เธเธเธ—เธธเธ table เธ—เธตเนเนเธเน
- [ ] confirm CREATE/ALTER/DROP fail (test เนเธ tinker)
- [ ] php artisan erp:test pass
- [ ] PostgreSQL accessible from XAMPP server

## Environment
- [ ] .env: ERP_DB_* เธเธฃเธเธ—เธธเธเธ•เธฑเธง
- [ ] .env: APP_DEBUG=false
- [ ] .env: APP_ENV=production
- [ ] storage/logs/ writable

## Web Server (XAMPP)
- [ ] Apache config โ€” เธ–เนเธฒเธกเธต virtual host
- [ ] .htaccess โ€” pretty URL เธ—เธณเธเธฒเธ
- [ ] PHP extension: pdo_pgsql, pgsql enabled

## Smoke Test
- [ ] /erp/expense (เธ•เนเธญเธ redirect login เน€เธกเธทเนเธญเธขเธฑเธเนเธกเนเนเธ”เน login)
- [ ] login เนเธฅเนเธงเน€เธเนเธฒ /erp/expense โ€” เธฃเธญ render เธชเธณเน€เธฃเนเธ
- [ ] filter date โ€” chart update
- [ ] export โ€” เนเธเธฅเน download เนเธ”เน
- [ ] check storage/logs/erp-*.log โ€” เนเธกเนเธกเธต error

## Backup
- [ ] zip project เธ—เธฑเนเธ folder เนเธงเนเธเนเธญเธ
- [ ] backup .env เนเธขเธ
- [ ] note current git commit hash

## Rollback Plan
- [ ] เธเธฑเนเธเธ•เธญเธ revert เธ–เนเธฒเน€เธเธดเธ”เธเธฑเธเธซเธฒ:
  1. ...
  2. ...

## Monitoring
- [ ] log location: storage/logs/laravel.log + erp-*.log
- [ ] alert: เนเธเธฃเธ”เธน? เธชเนเธเธ—เธฒเธเนเธซเธ?
- [ ] performance: เธเธฃเธดเธกเธฒเธ“ traffic เธ—เธตเนเธเธฒเธ”

เธ เธฒเธฉเธฒ: เนเธ—เธข
Format: markdown checkbox
```

---

## ๐ เธ–เนเธฒ Claude Code เธ—เธณเธเธดเธ”เธ—เธฒเธ

```
เธซเธขเธธเธ”เธเนเธญเธ

เธญเธขเนเธฒเน€เธเธตเธขเธ code เน€เธเธดเนเธก
เธญเธเธดเธเธฒเธขเธงเนเธฒเธ•เธฑเธ”เธชเธดเธเนเธเธญเธขเนเธฒเธเนเธฃเนเธเธเธฑเนเธเธ•เธญเธเธ—เธตเนเธเนเธฒเธเธกเธฒ
เธฃเธญ explanation เธเธญเธเธเธฑเธเธเนเธญเธเธ—เธณเธ•เนเธญ

เธ–เนเธฒเน€เธเธฅเธตเนเธขเธเนเธเธฅเธเนเธเธฅเนเนเธเนเธฅเนเธง เนเธชเธ”เธ git diff เนเธซเนเธ”เธน
เธเธฑเธเธเธฐเธ•เธฑเธ”เธชเธดเธเนเธเธงเนเธฒเธเธฐ revert เธซเธฃเธทเธญเธ—เธณเธ•เนเธญ
```

---

## ๐’ก Tips เธเธฒเธฃเนเธเนเธเธฑเธ Claude Code

### เน€เธฃเธดเนเธกเธ—เธธเธ session เธ”เนเธงเธข context
```
เธญเนเธฒเธ CLAUDE.md เนเธฅเธฐ docs/erp-reporting/PRIVACY_POLICY.md เธเนเธญเธ
เนเธฅเนเธงเธเธญเธเธเธฑเธเธงเนเธฒเน€เธเนเธฒเนเธเธเธ privacy เธญเธขเนเธฒเธเนเธฃ
```

### เธเธญเธ scope เธเธฑเธ”เน
```
เธชเธณเธซเธฃเธฑเธ task เธเธตเน เธซเนเธฒเธกเนเธ•เธฐเนเธเธฅเนเธเธญเธ folder:
- app/Http/Controllers/Erp/
- app/Repositories/Erp/
- resources/views/erp/

เธ–เนเธฒเธเธณเน€เธเนเธเธ•เนเธญเธเนเธเนเนเธเธฅเนเธญเธทเนเธ STOP เธเธญ permission เธเนเธญเธ
```

### เนเธเน git
```bash
# เธเนเธญเธ task เนเธซเธกเน
git status
git add . && git commit -m "wip: before <task>"

# เธซเธฅเธฑเธ Claude Code เนเธเน
git diff
git diff --stat        # เธชเธฃเธธเธเนเธเธฅเนเธ—เธตเนเน€เธเธฅเธตเนเธขเธ
git add . && git commit -m "feat(erp): ..."
```

### เธเธฑเธเธเธฑเธเนเธซเน check เธเนเธญเธเน€เธเธตเธขเธ
```
เธเนเธญเธเน€เธเธตเธขเธ SQL เนเธ”เน เนเธซเน:
1. quote เธชเนเธงเธเธเธญเธ Data Dictionary เธ—เธตเนเธญเนเธฒเธเธญเธดเธ
2. เธฃเธฐเธเธธ table + columns เธ—เธตเนเธเธฐเนเธเน
3. เธฃเธฐเธเธธ JOIN เนเธฅเธฐเน€เธซเธ•เธธเธเธฅ
4. เธฃเธญ OK
```

### เธ—เธ”เธชเธญเธ assumption
```
เธเนเธญเธเน€เธเธตเธขเธ feature เธ—เธฑเนเธเธเนเธญเธ เนเธซเนเธ—เธ”เธชเธญเธ assumption เธชเธณเธเธฑเธเธเนเธญเธ:
1. เธฃเธฑเธ SQL เธ—เธ”เธชเธญเธ โ€” paste เธเธฅเนเธซเนเธเธฑเธเธ”เธน
2. เธ–เนเธฒเธเธฅเธ•เธฃเธ expectation โ’ เธ—เธณเธ•เนเธญ
3. เธ–เนเธฒเธเธฅเนเธกเนเธ•เธฃเธ โ’ debug เธซเธฃเธทเธญเธ–เธฒเธก
```

---

## ๐“ Pre-flight Checklist (เธเนเธญเธ session เนเธฃเธ)

- [ ] CLAUDE.md เธญเธขเธนเนเธ—เธตเน root เธเธญเธ Laravel project
- [ ] docs/erp-reporting/ เธกเธตเธเธฃเธ 4 เนเธเธฅเน
- [ ] composer.json เธกเธต Laravel version เธเธฑเธ”เน€เธเธ
- [ ] git status clean
- [ ] PHP extension: `php -m | grep -i pgsql` เน€เธซเนเธ `pdo_pgsql` เนเธฅเธฐ `pgsql`
- [ ] Network: `Test-NetConnection 192.168.1.40 -Port 5432` (PowerShell) โ’ success
- [ ] backup เนเธงเน: zip เธ—เธฑเนเธ folder

เธเธฃเนเธญเธก โ’ เน€เธฃเธดเนเธก Prompt #1!

---

## ๐ฏ Roadmap เนเธเธฐเธเธณ

เธ—เธณเธ•เธฒเธกเธฅเธณเธ”เธฑเธเธเธตเนเนเธ 1-2 เธชเธฑเธเธ”เธฒเธซเน:

| เธชเธฑเธเธ”เธฒเธซเนเธ—เธตเน | Day | Prompt | เธเธฅเธฅเธฑเธเธเน |
|---|---|---|---|
| 1 | 1 | #1, #2 | เธ•เธฑเนเธเธเนเธฒ + connection test pass |
| 1 | 2-3 | #3 | Service layer + privacy guard |
| 1 | 4-5 | #4 | Dashboard เธเนเธฒเนเธเนเธเนเธฒเธขเนเธขเธเนเธเธเธ เนเธเนเธเธฒเธเนเธ”เน |
| 2 | 1 | #5 | Privacy audit tool |
| 2 | 2 | #6 | Export tool |
| 2 | 3-4 | #7 (ร—N) | port เธฃเธฒเธขเธเธฒเธเน€เธเนเธฒเธ—เธตเธฅเธฐเธ•เธฑเธง |
| 2 | 5 | #10 | deploy checklist + go live |

---

## ๐ เธ–เนเธฒเธ•เธดเธ”เธเธฑเธเธซเธฒ

### เธเธฑเธเธซเธฒ DB connection
- เธ•เธฃเธงเธ network: `Test-NetConnection 192.168.1.40 -Port 5432`
- เธ•เธฃเธงเธ extension: `php -m | grep pgsql`
- เธ•เธฃเธงเธ .env: spell เธ–เธนเธเธซเธฃเธทเธญเน€เธเธฅเนเธฒ
- เธ•เธฃเธงเธ user/password เธเธฑเธ IT/admin DB

### เธเธฑเธเธซเธฒ Laravel เน€เธเนเธฒ
- Laravel < 9 เธญเธฒเธเนเธกเน support `php artisan` syntax เธเธฒเธเธญเธขเนเธฒเธ
- Repository pattern เธ—เธณเธเธฒเธเนเธ”เนเธ—เธธเธ version
- เธ–เนเธฒ PHP < 8.0 เธญเธฒเธเธ•เนเธญเธเธฅเธ” feature เน€เธเนเธ constructor promotion

### เธเธฑเธเธซเธฒ performance
- เนเธเน `Cache::remember()` เธเธฑเธ TTL เธ—เธตเนเน€เธซเธกเธฒเธฐเธชเธก
- เธเธณเธเธฑเธ” date range เนเธ filter (เธญเธขเนเธฒเนเธซเน user query เธ—เธฑเนเธเธเธต)
- เธ–เนเธฒ query เธเนเธฒเธกเธฒเธ เธเธญเนเธซเน admin DB add index (เธซเนเธฒเธกเธ—เธณเน€เธญเธ)

เธเธญเนเธซเนเธชเธเธธเธเธเธฑเธ project เธเธฃเธฑเธ! ๐€
