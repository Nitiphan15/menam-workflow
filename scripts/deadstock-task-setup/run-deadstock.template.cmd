@echo off
setlocal
set "job=%~1"
if "%job%"=="import-snapshot" goto valid
if "%job%"=="compare-current" goto valid
if "%job%"=="capture-month-end" goto valid
exit /b 2
:valid
cd /d "__MAIL__" || exit /b 1
if not exist "storage\logs" mkdir "storage\logs"
echo [%date% %time%] Starting %job%>>"storage\logs\deadstock-%job%-launcher.log"
"__PHP__" artisan workflow:deadstock %job% --no-interaction --no-ansi >>"storage\logs\deadstock-%job%-launcher.log" 2>&1
set "job_exit=%errorlevel%"
echo [%date% %time%] Exit=%job_exit%>>"storage\logs\deadstock-%job%-launcher.log"
exit /b %job_exit%
