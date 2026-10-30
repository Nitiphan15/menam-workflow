# Stock Withdrawal Daily Alert Task

Production schedule: Monday-Friday at 08:35 Asia/Bangkok.

1. Deploy this folder to `E:\xampp\htdocs\menam-online\scripts\stock-withdrawal-task`.
2. Confirm Production `.env` contains `STOCK_WITHDRAWAL_FALLBACK_TO`.
3. Double-click `Setup-StockWithdrawalDailyAlert.bat` and approve elevation.
4. Enter the Windows report/service account and password when prompted.
5. Confirm Task Scheduler contains `Menam Stock Withdrawal Daily Alert` and its next run is correct.

The installer performs preflight checks and does not send email. The scheduled runner invokes:

```text
C:\xampp\php\php.exe E:\xampp\htdocs\menam-online\artisan stock-withdrawal:send-daily-alert
```

Log:

```text
E:\xampp\htdocs\menam-online\storage\logs\stock-withdrawal-daily-alert-task.log
```

Only one scheduler owner may be active. Do not also enable the same 08:35 schedule in Laravel Kernel or mail-daily.
