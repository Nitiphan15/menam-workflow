# Server task handoff — 2026-09-09

This server-specific plan supersedes the shared every-minute scheduler example in `deadstock-scheduler-mail-daily.md`. Preserve all other Windows tasks and their current timing.

## Verified server paths

- Client `M:` maps to `\\192.168.1.173\e$\xampp`.
- Active workflow: `E:\xampp\htdocs\menam-online` (not the older menam-workflow folder).
- Reports: `E:\xampp\htdocs\mail-daily`.
- PHP: `E:\xampp\php\php.exe`.
- Production WOCR Laravel schedule is 08:05; do not overwrite it with the development Kernel.

## Actual state

The server has individual Windows report tasks, several of which invoke Laravel `schedule:run` only at their configured times. A continuously running every-minute scheduler was not found in the inspected root tasks. An additional every-minute invocation of the full report schedule can change report cadence and duplicate directly invoked commands; it is not part of this change.

New wrapper/config/schedule files were staged in server mail-daily. The temporary help-only preflight hook was removed because the scheduler did not run it during the observation window. Server `routes/console.php` was restored exactly from backup. The production workflow Kernel and batch file were not changed. No three-job cutover or successful server dry test has occurred.

Backup: `E:\xampp\htdocs\mail-daily\storage\app\private\deadstock-scheduler-backup-20260909-110351`.

Local validation remains 5 tests / 16 assertions and three successful help-only dry-runs. This does not establish production DB connectivity.

## Remote-management blocker

SMB 445 and RPC endpoint mapper 135 are reachable. WinRM 5985 did not connect. Both `schtasks /Query /S 192.168.1.173` and Task Scheduler COM connection failed:

```text
The network path was not found. (0x80070035)
```

Task definitions could be read through the administrative SMB share. No Windows task definition files were modified. IT needs to verify/enable the authorized Remote Scheduled Tasks Management channel, including RPC connectivity and account permissions. Reachable TCP 135 alone does not prove the full channel works; the exact cause is not yet established.

## Target: three dedicated Windows tasks

Each task invokes only its Deadstock wrapper in mail-daily. No every-minute full scheduler task is needed.

| Task | Timing |
| --- | --- |
| MailDaily Deadstock Import | Daily 08:03 |
| MailDaily Deadstock Compare | Every hour at minute 00 |
| MailDaily Deadstock MonthEnd | Last day of month 23:30 |

Updated requirement: the hourly compare wrapper now passes `--sync-current`. It first pulls current ERP WIRE/PLUS items absent from every snapshot into the latest populated snapshot, using the same service as the Review page button, then compares that snapshot. It requires an existing populated snapshot baseline. No fourth task is needed. This is prepared code, not an installed/active server task.

After remote management is available, first verify all three wrapper commands with `--dry-run` **on the server** through the approved execution channel and review their JSONL logs. Do not use `schtasks /Run` on an existing reporting task as a dry test.

The following creation commands are templates; replace `DOMAIN\SERVICE_ACCOUNT` with the approved run-as account. `/RP *` prompts for its password. Verify the server timezone is Asia/Bangkok (UTC+07:00) because Windows trigger times use the server clock. Do not run these until the dry test succeeds and the coordinated cutover is ready.

```powershell
schtasks /Create /S 192.168.1.173 /TN "MailDaily Deadstock Import" /TR "E:\xampp\php\php.exe E:\xampp\htdocs\mail-daily\artisan workflow:deadstock import-snapshot" /SC DAILY /ST 08:03 /RU "DOMAIN\SERVICE_ACCOUNT" /RP *
schtasks /Create /S 192.168.1.173 /TN "MailDaily Deadstock Compare" /TR "E:\xampp\php\php.exe E:\xampp\htdocs\mail-daily\artisan workflow:deadstock compare-current" /SC HOURLY /MO 1 /ST 00:00 /RU "DOMAIN\SERVICE_ACCOUNT" /RP *
schtasks /Create /S 192.168.1.173 /TN "MailDaily Deadstock MonthEnd" /TR "E:\xampp\php\php.exe E:\xampp\htdocs\mail-daily\artisan workflow:deadstock capture-month-end" /SC MONTHLY /MO LASTDAY /M * /ST 23:30 /RU "DOMAIN\SERVICE_ACCOUNT" /RP *
```

Create the tasks during a quiet window, verify registration, then remove only the three Deadstock declarations from the production `menam-online/app/Console/Kernel.php` before their next due time. Leave WOCR unchanged. For this dedicated-task setup, do **not** add `require deadstock-schedule.php` to production mail-daily's normal `routes/console.php`; otherwise existing report triggers may also invoke these jobs. If using XML or the UI, set multiple instances to IgnoreNew and an execution limit appropriate to the 1800-second wrapper timeout. Keep the original reporting tasks untouched.

Reference: https://learn.microsoft.com/en-us/windows-server/administration/windows-commands/schtasks-create

Do not run `tmp/deadstock-migration/cutover-server.ps1` for this revised plan: that earlier script activates the shared Laravel schedule. Complete the dedicated-task cutover above instead.
