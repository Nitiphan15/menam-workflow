[CmdletBinding()]
param(
    [string]$WorkflowPath = 'E:\xampp\htdocs\menam-online',
    [string]$PhpPath = 'C:\xampp\php\php.exe',
    [string]$TaskName = 'Menam Stock Withdrawal Daily Alert',
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'

trap {
    Write-Host ''
    Write-Host 'INSTALLATION FAILED' -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    Write-Host ''
    Read-Host 'Press Enter to close'
    exit 1
}

$artisanPath = Join-Path $WorkflowPath 'artisan'
$runnerPath = Join-Path $WorkflowPath 'scripts\stock-withdrawal-task\Run-StockWithdrawalDailyAlert.cmd'

foreach ($requiredPath in @($WorkflowPath, $PhpPath, $artisanPath, $runnerPath)) {
    if (-not (Test-Path -LiteralPath $requiredPath)) {
        throw "Required path not found: $requiredPath"
    }
}

$commandList = & $PhpPath $artisanPath list --raw 2>&1
$commandText = $commandList -join "`n"
if ($LASTEXITCODE -ne 0 -or $commandText -notmatch '(?m)^stock-withdrawal:send-daily-alert\s') {
    throw 'Artisan command stock-withdrawal:send-daily-alert is not registered.'
}

$envPath = Join-Path $WorkflowPath '.env'
if (-not (Select-String -LiteralPath $envPath -Pattern '^STOCK_WITHDRAWAL_FALLBACK_TO=\s*\S+' -Quiet)) {
    throw 'STOCK_WITHDRAWAL_FALLBACK_TO is missing or empty in Production .env.'
}

$timeZoneId = (Get-TimeZone).Id
if ($timeZoneId -ne 'SE Asia Standard Time') {
    throw "Server time zone must be SE Asia Standard Time, current: $timeZoneId"
}

Write-Host "Task       : $TaskName"
Write-Host 'Schedule   : Monday-Friday 08:35 Asia/Bangkok (server local time)'
Write-Host "Command    : $runnerPath"
Write-Host "Working dir: $WorkflowPath"

if ($DryRun) {
    Write-Host 'DRY RUN OK: paths, Artisan command and .env recipient validated. No task was created.' -ForegroundColor Green
    exit 0
}

$trigger = New-ScheduledTaskTrigger -Weekly -WeeksInterval 1 -DaysOfWeek Monday, Tuesday, Wednesday, Thursday, Friday -At '08:35'
$action = New-ScheduledTaskAction -Execute 'cmd.exe' -Argument ('/d /c "{0}"' -f $runnerPath) -WorkingDirectory $WorkflowPath
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 30)

$defaultUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$taskUser = Read-Host "Windows account for scheduled task [$defaultUser]"
if ([string]::IsNullOrWhiteSpace($taskUser)) { $taskUser = $defaultUser }
$securePassword = Read-Host "Password for $taskUser" -AsSecureString
$credential = [System.Management.Automation.PSCredential]::new($taskUser, $securePassword)
$plainPassword = $credential.GetNetworkCredential().Password

try {
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings `
        -User $taskUser -Password $plainPassword -RunLevel Highest -Force | Out-Null
} finally {
    $plainPassword = $null
    $credential = $null
    $securePassword = $null
}

$task = Get-ScheduledTask -TaskName $TaskName
$taskInfo = Get-ScheduledTaskInfo -TaskName $TaskName

Write-Host 'Installed successfully.' -ForegroundColor Green
Write-Host "State    : $($task.State)"
Write-Host "Next run : $($taskInfo.NextRunTime)"
Write-Host "Log      : $WorkflowPath\storage\logs\stock-withdrawal-daily-alert-task.log"
Write-Host 'No email was sent during installation.'
Write-Host ''
Read-Host 'Press Enter to close'
