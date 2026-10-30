@echo off
setlocal

set "WORKFLOW_PATH=E:\xampp\htdocs\menam-online"
set "PHP_EXE=C:\xampp\php\php.exe"
set "LOG_FILE=%WORKFLOW_PATH%\storage\logs\stock-withdrawal-daily-alert-task.log"

echo [%date% %time%] START stock-withdrawal:send-daily-alert>>"%LOG_FILE%"
cd /d "%WORKFLOW_PATH%"
"%PHP_EXE%" artisan stock-withdrawal:send-daily-alert >>"%LOG_FILE%" 2>&1
set "EXIT_CODE=%ERRORLEVEL%"
echo [%date% %time%] END exit=%EXIT_CODE%>>"%LOG_FILE%"
echo.>>"%LOG_FILE%"

exit /b %EXIT_CODE%
