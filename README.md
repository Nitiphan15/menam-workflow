# Menam Workflow

Internal Laravel workflow and reporting system for Menam operational processes.

This repository is the source of truth for the `menam-workflow` application. Production deployment should pull from GitHub instead of copying files manually.

## Stack

- PHP 8.0.2+
- Laravel 9
- Composer
- Node.js / npm / Vite
- SQL Server and MySQL connections, depending on module
- Laravel Excel / PhpSpreadsheet
- DomPDF / FPDI-FPDF

## Main Modules

- Admin, users, roles, departments, and permissions
- FormDP delivery planning and inquiry
- FormFC forecast workflows, planner forecast, supplier shortage, and division approval
- FormPP and FormWOCR workflow/docbox screens
- FormWOS dashboards, sales weekly, sales unit summary, order due date, and deadstock review
- FormPKG packaging usage and packaging analysis
- FormAccounting customer payment terms and loss provision reports
- FormVC variable cost reports
- FormCCR cost center reports
- FormMLA machine load and availability planning
- PO approval, printing, export, and profile signatures

## Local Setup

```powershell
git clone https://github.com/Nitiphan15/menam-workflow.git
cd menam-workflow
git checkout ai/menam-workflow

composer install
npm install
copy .env.example .env
php artisan key:generate
```

Update `.env` for the local database, mail, ERP, and file settings.

Then run:

```powershell
php artisan migrate
npm run build
php artisan optimize:clear
php artisan serve
```

## Production Deployment

Recommended production branch for now:

```text
ai/menam-workflow
```

First-time production checkout should be done into a new folder, then tested before switching traffic:

```powershell
cd M:\htdocs
git clone https://github.com/Nitiphan15/menam-workflow.git menam-workflow-git
cd menam-workflow-git
git checkout ai/menam-workflow
```

Copy or recreate the production `.env` from the current production application. Do not commit `.env`.

Install and warm the app:

```powershell
composer install --no-dev --optimize-autoloader
npm install
npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

For normal production updates:

```powershell
cd M:\htdocs\menam-workflow
git status -sb
git fetch origin
git pull --ff-only origin ai/menam-workflow
composer install --no-dev --optimize-autoloader
npm install
npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Stop if `git status -sb` shows unexpected production edits. Commit or move those changes before pulling.

## Production Notes

- Keep production `.env` only on the server.
- Keep `storage/` and `bootstrap/cache/` writable by the web server.
- `storage/fonts/.gitignore` preserves the DomPDF font cache directory while ignoring generated font files.
- Runtime logs, sessions, caches, local backups, Claude worktrees, and production comparison folders are intentionally ignored.
- Run required SQL scripts from `database/sql/` only after confirming the target environment.
- FC Division production SQL helper:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\run_fc_division_forecast_sql.ps1
```

## Git Hygiene

Before pushing:

```powershell
git status -sb
git diff --cached --check
```

Useful validation:

```powershell
php -l path\to\file.php
php artisan route:list
php artisan config:clear
php artisan view:clear
```

Never commit:

- `.env`
- `vendor/`
- `node_modules/`
- `storage/logs/`
- `storage/framework/` generated cache/session/view files
- `storage/fonts/` generated font cache files
- `.claude/`
- `dumpfromprod/`
- `fromprod/`
- `backups/`
- `prepare deploy/`

## Repository

- GitHub: https://github.com/Nitiphan15/menam-workflow
- Current main working branch: `ai/menam-workflow`
