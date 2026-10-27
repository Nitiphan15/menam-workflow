# Production PHP 8.2.35 upgrade

Target: production server `192.168.1.173`, XAMPP root `E:\xampp`.

Run PowerShell as Administrator on the production server. Do not run the cutover through mapped drive `M:`.

## Review and preparation

Read-only preflight:

```powershell
Set-Location 'E:\xampp\htdocs\menam-online'
powershell.exe -ExecutionPolicy Bypass -File '.\scripts\php-security-upgrade\production-php-upgrade.ps1' -Action Preflight
```

The production checkout may not contain this script yet. It can instead be run from its copied absolute location.

Prepare staged `php.ini` with `session.upload_progress.enabled=0` and validate PHP 8.2.35 without touching the live runtime:

```powershell
powershell.exe -ExecutionPolicy Bypass -File 'E:\path\production-php-upgrade.ps1' -Action Prepare
```

## Cutover

Only after `Preflight` and `Prepare` pass:

```powershell
powershell.exe -ExecutionPolicy Bypass -File 'E:\path\production-php-upgrade.ps1' -Action Cutover -ConfirmCutover
```

The script:

1. Saves and disables the seven known PHP scheduled tasks.
2. Requests a graceful Laravel queue restart and refuses to force-kill remaining jobs.
3. Stops the Apache Windows service and verifies ports 80/443 are free.
4. Renames the current PHP folder to the rollback path and moves staged PHP into place.
5. Installs the four staged Apache dependency DLLs.
6. Validates PHP modules and Apache configuration before startup.
7. Starts Apache and checks HTTP plus a read-only database `SELECT 1`.
8. Restores each scheduled task to its previous enabled/disabled state.
9. Automatically restores PHP and Apache DLLs if a post-switch check fails.

Logs are written under:

```text
E:\xampp\upgrade-backups\php-8.2.12-before-8.2.35-20261023\logs
```

Do not remove the rollback PHP folder until authenticated login, PDF, Excel, SQL Server/PostgreSQL, queues, schedules, and Apache/PHP logs have been checked.
