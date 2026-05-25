# Branch Update Summary - 2026-05-25

Branch: `codex-formfc-division-group-master`

## Reason

This branch collects the latest workflow updates needed before publishing, with a special focus on making the application usable from intranet-only client machines. Several users can reach the local Menam site but cannot reach public CDNs, so shared layout assets now load from local files instead of external URLs.

## Offline / Intranet Asset Updates

- Added local vendor assets under `public/vendor` for Bootstrap, Font Awesome, Flatpickr, Tom Select, Frappe Gantt, dhtmlxGantt, jQuery, SweetAlert2, Chart.js, and chartjs-plugin-datalabels.
- Updated the shared layout to load CSS and JavaScript from `asset('vendor/...')` paths instead of public CDN URLs.
- Added local dhtmlxGantt font files and rewrote its CSS font references so it does not call Google Fonts.
- Added `public/css/layout-base.css` for shared layout/sidebar CSS, reducing inline style risk and providing fallback display rules if Bootstrap CSS does not load.
- Updated PA dashboard and WOCR originator form pages so page-specific CDN references no longer bypass the shared local asset strategy.
- Added `public/vendor/OFFLINE_ASSETS_README.md` with production copy instructions.

## Encoding And Layout Fixes

- Removed UTF-8 BOM output from Blade/config files that could create whitespace before `<!DOCTYPE html>`.
- Restored readable Thai text in the shared layout after encoding drift.
- Added a dedicated `mobile-menu-trigger` class so the mobile menu button cannot create extra desktop spacing when Bootstrap display utilities are unavailable.

## Workflow / Report Updates Included In This Branch

- Added/updated FC division group controller, master views, and SQL setup scripts.
- Added VC account master and division-group/account display support.
- Added customer payment term effective date and grace-day support with migration and UI/service updates.
- Added production risk controller/views and menu entries.
- Updated deadstock report/config/review support.
- Updated menu entries for added dashboard/admin/report surfaces.

## Validation Run Locally

- `php artisan view:cache`
- `php artisan view:clear`
- `php -l config\menu.php`
- BOM scan across `resources/views`, `routes`, `config`, and `app`

## Production Copy Notes For Offline Assets

Copy these paths to production when deploying the offline asset fix:

- `public/vendor`
- `public/css/layout-base.css`
- `resources/views/layouts/layout.blade.php`
- `resources/views/layouts/_sidebar.blade.php`
- `resources/views/formpa/dashboard.blade.php`
- `resources/views/formwocr/originator2.blade.php`

After copy/deploy, run:

```powershell
php artisan view:clear
```
