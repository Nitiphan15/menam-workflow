#Requires -Version 5.1
[CmdletBinding()]
param(
    [string]$MailPath = 'E:\xampp\htdocs\mail-daily',
    [string]$WorkflowPath = 'E:\xampp\htdocs\menam-online',
    [string]$PhpPath = 'E:\xampp\php\php.exe',
    [switch]$DryRun,
    [switch]$ValidateOnly
)
$ErrorActionPreference = 'Stop'
$marker = 'MailDaily Deadstock installer v1'
$jobs = @('import-snapshot','compare-current','capture-month-end')
$names = @('MailDaily Deadstock Import','MailDaily Deadstock Compare','MailDaily Deadstock MonthEnd')
$utf8 = New-Object System.Text.UTF8Encoding($false)

function Remove-DeadstockSchedules([string]$Content) {
    foreach ($job in $jobs) {
        $pattern = '(?ms)^        \$schedule->command\(''deadstock:' + [regex]::Escape($job) + '''\).*?->withoutOverlapping\(\);\r?\n'
        $count = [regex]::Matches($Content, $pattern).Count
        if ($count -gt 1) { throw "Duplicate declaration: $job" }
        $Content = [regex]::Replace($Content, $pattern, '')
        if ($Content.Contains("'deadstock:$job'")) { throw "Unrecognized schedule format: $job" }
    }
    return $Content
}

function Get-TaskXml([string]$Job, [string]$Account, [datetime]$Now) {
    $escape = { param($value) [Security.SecurityElement]::Escape([string]$value) }
    $lead = $Now.AddMinutes(5)
    if ($Job -eq 'import-snapshot') {
        $start = $Now.Date.AddHours(8).AddMinutes(3)
        if ($start -le $lead) { $start = $start.AddDays(1) }
        $trigger = '<CalendarTrigger><StartBoundary>' + $start.ToString('s') + '</StartBoundary><Enabled>true</Enabled><ScheduleByDay><DaysInterval>1</DaysInterval></ScheduleByDay></CalendarTrigger>'
    } elseif ($Job -eq 'compare-current') {
        $start = $Now.Date.AddHours($Now.Hour + 1)
        if ($start -le $lead) { $start = $start.AddHours(1) }
        $trigger = '<TimeTrigger><Repetition><Interval>PT1H</Interval><StopAtDurationEnd>false</StopAtDurationEnd></Repetition><StartBoundary>' + $start.ToString('s') + '</StartBoundary><Enabled>true</Enabled></TimeTrigger>'
    } else {
        $start = $Now.Date.AddDays([datetime]::DaysInMonth($Now.Year,$Now.Month)-$Now.Day).AddHours(23).AddMinutes(30)
        if ($start -le $lead) { $next = $Now.Date.AddMonths(1); $start = (Get-Date -Year $next.Year -Month $next.Month -Day ([datetime]::DaysInMonth($next.Year,$next.Month)) -Hour 23 -Minute 30 -Second 0) }
        $trigger = '<CalendarTrigger><StartBoundary>' + $start.ToString('s') + '</StartBoundary><Enabled>true</Enabled><ScheduleByMonth><DaysOfMonth><Day>Last</Day></DaysOfMonth><Months><January/><February/><March/><April/><May/><June/><July/><August/><September/><October/><November/><December/></Months></ScheduleByMonth></CalendarTrigger>'
    }
    $command = & $escape (Join-Path $env:SystemRoot 'System32\cmd.exe')
    $runner = Join-Path $MailPath 'scripts\deadstock-task-setup\run-deadstock.cmd'
    $arguments = & $escape ('/d /s /c ""' + $runner + '" ' + $Job + '"')
    $working = & $escape $MailPath
    $user = & $escape $Account
    return @"
<?xml version="1.0" encoding="UTF-16"?>
<Task version="1.2" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
<RegistrationInfo><Description>$marker : $Job</Description></RegistrationInfo>
<Triggers>$trigger</Triggers>
<Principals><Principal id="Author"><UserId>$user</UserId><LogonType>Password</LogonType><RunLevel>LeastPrivilege</RunLevel></Principal></Principals>
<Settings><MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy><DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries><StopIfGoingOnBatteries>false</StopIfGoingOnBatteries><StartWhenAvailable>false</StartWhenAvailable><Enabled>false</Enabled><ExecutionTimeLimit>PT35M</ExecutionTimeLimit></Settings>
<Actions Context="Author"><Exec><Command>$command</Command><Arguments>$arguments</Arguments><WorkingDirectory>$working</WorkingDirectory></Exec></Actions>
</Task>
"@
}

if ($ValidateOnly) {
    # Pure validation: no Task Scheduler connection, application execution or writes.
    foreach ($job in $jobs) {
        [xml]$xml = Get-TaskXml $job 'DOMAIN\test' ([datetime]'2026-02-28T10:15:00')
        if ($xml.Task.Actions.Exec.Arguments -notlike "*$job*") { throw 'Wrong task action' }
        if ($xml.Task.Settings.Enabled -ne 'false') { throw 'Tasks must register disabled' }
    }
    $fixture = "        `$schedule->command('wocr:pending-digest')->dailyAt('08:05');`r`n"
    foreach ($job in $jobs) { $fixture += "        `$schedule->command('deadstock:$job')`r`n            ->hourly()`r`n            ->withoutOverlapping();`r`n" }
    $result = Remove-DeadstockSchedules $fixture
    if ($result -match 'deadstock:' -or $result -notmatch '08:05') { throw 'Schedule transformation failed' }
    if ((Remove-DeadstockSchedules $result) -cne $result) { throw 'Transformation is not rerunnable' }
    [xml]$month = Get-TaskXml 'capture-month-end' 'DOMAIN\test' ([datetime]'2028-02-01T10:00:00')
    if ($month.Task.Triggers.CalendarTrigger.StartBoundary -ne '2028-02-29T23:30:00') { throw 'Leap-year schedule failed' }
    Write-Output 'PASS: task XML, disabled staging, WOCR preservation, rerun and leap-year checks.'
    exit 0
}

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Open PowerShell as Administrator on the SERVER, then run this script.' }
if ([TimeZoneInfo]::Local.BaseUtcOffset.TotalHours -ne 7 -or [TimeZoneInfo]::Local.SupportsDaylightSavingTime) { throw 'Server timezone must be UTC+07:00 without daylight saving (Bangkok).' }
foreach ($file in @($PhpPath, "$MailPath\artisan", "$WorkflowPath\artisan", "$WorkflowPath\app\Console\Kernel.php", "$MailPath\app\Console\Commands\RunWorkflowDeadstock.php")) {
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { throw "Required file missing: $file" }
}
$logs = Join-Path $MailPath 'storage\logs'
New-Item -ItemType Directory -Force -Path $logs | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
Start-Transcript -Path (Join-Path $logs "deadstock-install-$stamp.log") | Out-Null
$created = New-Object 'System.Collections.Generic.List[string]'
$kernelChanged = $false
$runnerChanged = $false
$complete = $false
try {
    # Keep dedicated Windows tasks separate from the full Laravel report schedule.
    $console = [IO.File]::ReadAllText("$MailPath\routes\console.php")
    if ($console -match 'deadstock-schedule\.php|deadstock-deployment-preflight\.php|workflow:deadstock') { throw 'mail-daily console.php still enables Deadstock scheduling; remove that hook before dedicated-task installation.' }
    $wrapper = [IO.File]::ReadAllText("$MailPath\app\Console\Commands\RunWorkflowDeadstock.php")
    if (-not $wrapper.Contains("'--sync-current'")) { throw 'Update the hourly wrapper: --sync-current is missing.' }
    Push-Location $MailPath
    try {
        foreach ($job in $jobs) {
            Write-Host "DRY TEST: $job (help only)"
            & $PhpPath artisan workflow:deadstock $job --dry-run --no-ansi
            if ($LASTEXITCODE -ne 0) { throw "Dry test failed: $job" }
        }
    } finally { Pop-Location }
    if ($DryRun) { Write-Host 'Dry tests passed. No tasks or schedules changed.'; $complete = $true; return }

    $service = New-Object -ComObject Schedule.Service
    $service.Connect()
    $folder = $service.GetFolder('\')
    $existing = @($folder.GetTasks(1) | Where-Object { $names -contains $_.Name })
    $kernelPath = "$WorkflowPath\app\Console\Kernel.php"
    $kernelBefore = [IO.File]::ReadAllText($kernelPath)
    $kernelAfter = Remove-DeadstockSchedules $kernelBefore
    if ($existing.Count -gt 0) {
        if ($existing.Count -eq 3 -and $kernelBefore -ceq $kernelAfter) {
            foreach ($task in $existing) {
                [xml]$definition = $task.Xml
                if (-not $task.Enabled -or $definition.Task.RegistrationInfo.Description -notlike "$marker : *") { throw 'Existing task differs from this installer; stopped without changes.' }
                $index = [array]::IndexOf($names,$task.Name)
                [xml]$expected = Get-TaskXml $jobs[$index] $definition.Task.Principals.Principal.UserId (Get-Date)
                foreach ($field in @('Command','Arguments','WorkingDirectory')) {
                    if ($definition.Task.Actions.Exec.$field -cne $expected.Task.Actions.Exec.$field) { throw 'Existing task action differs; stopped without changes.' }
                }
                if ($definition.Task.Settings.MultipleInstancesPolicy -ne 'IgnoreNew') { throw 'Existing task settings differ; stopped without changes.' }
            }
            Write-Host 'Already installed. No tasks changed.'
            $complete = $true
            return
        }
        throw 'Some target task names already exist or old schedules remain. Stopped without overwriting tasks.'
    }
    Write-Host 'Enter the Windows account/password used for report tasks. Password is not written to files or logs.'
    $credential = Get-Credential -UserName $identity.Name -Message 'Run all three Deadstock tasks as this account, even when logged off'
    if ($null -eq $credential) { throw 'Credential entry cancelled; no changes made.' }
    $backup = Join-Path $MailPath "storage\app\private\deadstock-install-$stamp"
    New-Item -ItemType Directory -Path $backup | Out-Null
    [IO.File]::WriteAllText((Join-Path $backup 'Kernel.php'), $kernelBefore, $utf8)
    $candidate = Join-Path $backup 'Kernel.after.php'
    [IO.File]::WriteAllText($candidate, $kernelAfter, $utf8)
    & $PhpPath -l $candidate
    if ($LASTEXITCODE -ne 0) { throw 'Modified Kernel failed syntax check.' }
    $runnerPath = Join-Path $MailPath 'scripts\deadstock-task-setup\run-deadstock.cmd'
    $runnerBefore = if (Test-Path -LiteralPath $runnerPath) { [IO.File]::ReadAllBytes($runnerPath) } else { $null }
    if ($null -ne $runnerBefore) { [IO.File]::WriteAllBytes((Join-Path $backup 'run-deadstock.cmd'), $runnerBefore) }
    $template = [IO.File]::ReadAllText((Join-Path $PSScriptRoot 'run-deadstock.template.cmd'))
    $runner = $template.Replace('__PHP__', $PhpPath).Replace('__MAIL__', $MailPath)
    $runnerChanged = $true
    [IO.File]::WriteAllText($runnerPath, $runner.Replace("`r`n","`n").Replace("`n","`r`n"), $utf8)
    $password = $credential.GetNetworkCredential().Password
    try {
        for ($i = 0; $i -lt 3; $i++) {
            $xmlText = Get-TaskXml $jobs[$i] $credential.UserName (Get-Date)
            [IO.File]::WriteAllText((Join-Path $backup ($jobs[$i]+'.xml')), $xmlText, [Text.Encoding]::Unicode)
            # TASK_CREATE only: never overwrite a task registered by someone else.
            $null = $folder.RegisterTask($names[$i], $xmlText, 2, $credential.UserName, $password, 1, $null)
            $created.Add($names[$i])
        }
    } finally { $password = $null; $credential = $null }
    if ([IO.File]::ReadAllText($kernelPath) -cne $kernelBefore) { throw 'Kernel changed during setup; stopped before cutover.' }
    $kernelChanged = $true
    [IO.File]::WriteAllText($kernelPath, $kernelAfter, $utf8)
    foreach ($name in $names) { $folder.GetTask($name).Enabled = $true }
    foreach ($name in $names) {
        $task = $folder.GetTask($name)
        if (-not $task.Enabled) { throw "Task was not enabled: $name" }
        Write-Host "$name | Next run: $($task.NextRunTime)"
    }
    $complete = $true
    Write-Host "SUCCESS: three tasks installed. Other tasks and WOCR unchanged. Backup: $backup"
    Write-Host 'No business job was run during installation. Dry tests used the installer account; inspect first scheduled-run logs for run-as account/DB access.'
} catch {
    Write-Host "INSTALL FAILED: $($_.Exception.Message)"
    $allStopped = $true
    foreach ($name in $created) {
        try { $folder.GetTask($name).Enabled = $false } catch { $allStopped = $false; Write-Warning "Rollback could not disable $name : $_"; continue }
        try { $folder.DeleteTask($name,0) } catch { Write-Warning "Disabled task could not be removed: $name : $_" }
    }
    if ($kernelChanged -and $allStopped) { [IO.File]::WriteAllText($kernelPath, $kernelBefore, $utf8) }
    if (-not $allStopped) { Write-Warning "Manual recovery required. Some tasks could not be disabled; old schedules were not restored to avoid duplicate execution. Backup: $backup" }
    if ($runnerChanged -and $allStopped) {
        if ($null -ne $runnerBefore) { [IO.File]::WriteAllBytes($runnerPath,$runnerBefore) }
        else { Remove-Item -LiteralPath $runnerPath }
    }
    throw
} finally { Stop-Transcript | Out-Null }
