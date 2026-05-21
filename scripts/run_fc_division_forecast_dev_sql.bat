@echo off
setlocal

cd /d "%~dp0\.."
powershell -NoProfile -ExecutionPolicy Bypass -File "scripts\run_fc_division_forecast_dev_sql.ps1"

endlocal
