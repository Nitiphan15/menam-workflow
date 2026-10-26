# Deadstock scheduler ownership

The three Deadstock schedules now live in `mail-daily/routes/deadstock-schedule.php`:

| Job | Asia/Bangkok schedule |
| --- | --- |
| `workflow:deadstock import-snapshot` | Daily 08:03 |
| `workflow:deadstock compare-current` | Hourly at minute 00 |
| `workflow:deadstock capture-month-end` | Last calendar day at 23:30 |

`menam-workflow` still schedules WOCR at 08:00 on weekdays. Keep its OS scheduler enabled for WOCR.

This moves scheduling ownership, not review business logic. The wrapper runs the existing `menam-workflow` Artisan commands and preserves their exit codes. Both checkouts must be on the same server and accessible to the scheduled-task account. The existing 08:00 report still performs its own snapshot import; the 08:03 job remains the existing follow-up import. This change does not serialize separate jobs or change comparison/import rules.

## Deploy together

1. Deploy the mail-daily command `app/Console/Commands/RunWorkflowDeadstock.php`, config `config/workflow_deadstock.php`, route file `routes/deadstock-schedule.php`, and its `require` in `routes/console.php`. Preserve other report schedules. Deploy the updated `run-scheduler.bat` and optional test `tests/Feature/WorkflowDeadstockTest.php`.
2. Configure `DEADSTOCK_WORKFLOW_PATH` in mail-daily to the actual server checkout, and `DEADSTOCK_PHP_BINARY` to the server PHP executable. `DEADSTOCK_WORKFLOW_JOB_TIMEOUT` defaults to 1800 seconds. Refresh Laravel config cache using the normal deployment process if configuration is cached.
3. `run-scheduler.bat` uses its own folder as working directory. Its default PHP is `C:\xampp\php\php.exe`; set the Windows environment variable `MAIL_DAILY_PHP_BINARY` or adjust this default for servers using another drive. This variable is read by the batch file, not from Laravel `.env`.
4. Verify all three dry-run commands below. This validates PHP, command registration, bootstrap and process/log handling only; it does not prove ERP connectivity, snapshot availability or business writes.
5. Deploy removal of the three schedules from `menam-workflow/app/Console/Kernel.php` in the same cutover window, before the next due time. Do not leave both sets scheduled. Keep all original command/service files.
6. Verify `artisan schedule:list` in both projects. Mail-daily should list all three wrappers; menam-workflow should list only WOCR.
7. Reuse the server's existing mail-daily Windows task if it already runs `run-scheduler.bat` every minute. Do not create three separate Windows tasks. Verify its PHP path, account, permissions and working directory. `onOneServer()` requires a supported working cache lock backend; multiple scheduler hosts need a shared cache.

The wrapper supplies `DEADSTOCK_MAIL_DAILY_PATH` to the child so the month-end job finds the owning report checkout. Retain valid server settings in both applications for direct/manual commands too. No credentials are copied by this change.

## Safe verification

Run from mail-daily, using the server PHP executable:

```powershell
php artisan workflow:deadstock import-snapshot --dry-run
php artisan workflow:deadstock compare-current --dry-run
php artisan workflow:deadstock capture-month-end --dry-run
php artisan schedule:list
php vendor/bin/phpunit tests/Feature/WorkflowDeadstockTest.php
```

Dry-run invokes the original command with `--help`; it never enters its handler. Do not use `schedule:run`, `schedule:test` or `report:deadstock --snapshot-only` as a dry-run: these can send reports and/or write business data. Tests use a temporary fake Artisan executable, including a simulated failure; they do not run ERP operations.

## Logs and status

- `mail-daily/storage/logs/deadstock-{job}-YYYY-MM-DD.jsonl`: timestamp, run ID, dry-run flag, started, stdout/stderr, succeeded/failed and exit code. Use the most recent `succeeded` record with `dry_run=false` for last actual success.
- `mail-daily/storage/logs/deadstock-{job}-scheduler.log`: Laravel scheduled process output, including failures before the wrapper can initialize.
- `mail-daily/storage/logs/scheduler-run.log`: batch start/end and scheduler output/errors.
- Application/service errors can also appear in `menam-workflow/storage/logs/laravel.log`.

Task Scheduler exit 0 only confirms the scheduler launcher, not every background job. Inspect the per-job logs for completion. Exceptions, child failures and timeouts are logged; a killed process or host crash can leave a `started` record without a completion record. Existing business commands may report no data as successful; wrapper success follows their existing exit semantics. Logs append; apply the server's normal retention policy.

## Manage Windows tasks without Remote Desktop

If an existing mail-daily OS task already runs every minute, deployment of the Laravel schedule is sufficient; no new Windows task is required.

From an authorized admin workstation, inspect existing tasks first:

```powershell
schtasks /Query /S SERVER_NAME /FO LIST /V
```

If no task exists, this is a template only: replace server, path and service account before running. `/RP *` prompts for the run-as account password rather than storing it in the command text.

```powershell
schtasks /Create /S SERVER_NAME /TN "MailDaily Scheduler" /TR "E:\xampp\htdocs\mail-daily\run-scheduler.bat" /SC MINUTE /MO 1 /RU "DOMAIN\svc-mail" /RP *
```

The path is on the server. The creating account needs remote task-management permissions; the run-as account needs batch-logon rights plus access to PHP, both projects, logs, databases and report files. Network/domain policy and Remote Scheduled Tasks Management firewall access must permit the connection. If disabled, IT must enable an authorized management channel; creating a task remotely cannot bypass this requirement. Do not use `/Run` for verification because it can execute due production jobs.

Microsoft reference: https://learn.microsoft.com/en-us/windows-server/administration/windows-commands/schtasks-create

No remote server was contacted or configured in this change.

## Rollback

First disable/remove the three mail-daily schedule declarations. Then restore the original three declarations in menam-workflow (08:03 daily, hourly, month-end 23:30, Asia/Bangkok, with onOneServer/withoutOverlapping). Keep only one scheduler owner active. No database migration is required.

## Local verification 2026-09-09

- PHPUnit: 5 tests, 16 assertions passed, including exit 7 with stderr, missing workflow, successful run and all three dry-run guards.
- All three real wrapper dry-runs: exit 0.
- Both live `schedule:list` outputs verified; no production business command executed.
- Local verification transcript: `tmp/deadstock-migration/verification.log` in menam-workflow.
- Both checkouts use branch `codex/deadstock-mail-daily-scheduler`. Existing unrelated local edits were preserved; changes have not been committed, pushed or deployed.
