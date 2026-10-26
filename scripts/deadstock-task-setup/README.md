# Install Deadstock tasks on the server

Open **Windows PowerShell as Administrator on server 192.168.1.173** and run:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "E:\xampp\htdocs\mail-daily\scripts\deadstock-task-setup\Install-DeadstockTasks.ps1"
```

ExecutionPolicy applies to that PowerShell process only; it does not elevate your Windows account or change machine policy. Domain policy can still require a signed script.

Enter the approved Windows report/service account and its Windows password once when prompted. Use an account allowed to run batch jobs and access both applications, PHP, logs and ERP databases. Password is passed to Windows Task Scheduler; the installer does not save it in scripts, XML or logs.

The installer performs help-only dry tests, backs up the production Kernel, creates three initially disabled tasks, removes only the three old Deadstock Laravel declarations, then enables the tasks:

| Task | Time on the server (UTC+07:00) |
| --- | --- |
| MailDaily Deadstock Import | Daily 08:03 |
| MailDaily Deadstock Compare | Hourly at minute 00; includes `--sync-current` to pull missing ERP items first |
| MailDaily Deadstock MonthEnd | Last day of every month 23:30 |

Other Windows tasks and WOCR (08:05 in the production Kernel) are preserved. No full every-minute scheduler is added. Multiple instances of each task are ignored while it is running; separate jobs can still overlap, as before. No automatic catch-up is enabled. The first run is set at least five minutes after setup; an imminent occurrence can therefore be skipped. Check the printed Next run values.

Paths are preconfigured for this server: PHP `E:\xampp\php\php.exe`, reports `E:\xampp\htdocs\mail-daily`, workflow `E:\xampp\htdocs\menam-online`. Keep the prepared wrapper/config in mail-daily. Do not activate `routes/deadstock-schedule.php` in this dedicated-task setup.

To run **only** the dry tests:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "E:\xampp\htdocs\mail-daily\scripts\deadstock-task-setup\Install-DeadstockTasks.ps1" -DryRun
```

Dry tests load command help using the installer account. They do not send reports, pull ERP data or write business data, and cannot prove the scheduled account's DB permissions. Review the first scheduled-run logs. A populated snapshot baseline is required for hourly sync/compare.

Logs: `E:\xampp\htdocs\mail-daily\storage\logs\deadstock-install-*.log`, per-job `deadstock-*-launcher.log`, and `deadstock-{job}-YYYY-MM-DD.jsonl`. Installation backups: `storage\app\private\deadstock-install-*`.

An ordinary install failure removes newly created tasks and restores the Kernel. If rollback cannot disable a task, the script prints a manual-recovery warning and keeps the old schedules removed to avoid duplicates. Re-running a completed installation recognizes the managed tasks; conflicting or partially installed task names cause a stop rather than an overwrite. No existing unrelated tasks are deleted.

Prepared and locally checked: PowerShell 5.1 parsing, generated XML, WOCR preservation, rerunnable schedule removal and leap-year month-end. Task registration/production dry test occurs when you run the installer on the server; it has not been performed remotely.
