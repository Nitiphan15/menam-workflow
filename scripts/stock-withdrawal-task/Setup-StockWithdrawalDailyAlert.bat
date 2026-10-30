@echo off
setlocal

set "SCRIPT_DIR=%~dp0"
echo Installing Menam Stock Withdrawal alert task...
echo Schedule: Monday-Friday at 08:35
echo.

powershell.exe -NoProfile -ExecutionPolicy Bypass -Command ^
  "Start-Process powershell.exe -Verb RunAs -Wait -ArgumentList '-NoProfile -ExecutionPolicy Bypass -File ""%SCRIPT_DIR%Install-StockWithdrawalDailyAlert.ps1""'"

if errorlevel 1 (
  echo.
  echo Installation failed or was cancelled. Review the error shown in the Administrator window.
  pause
  exit /b 1
)

echo.
echo Installation window closed. Review its result above.
pause
