[CmdletBinding()]
param(
    [ValidateSet('Preflight', 'Prepare', 'Cutover', 'Rollback')]
    [string]$Action = 'Preflight',

    [switch]$ConfirmCutover,
    [switch]$ConfirmRollback,

    [string]$XamppRoot = 'E:\xampp',
    [string]$StagePhp = 'E:\xampp\upgrade-staging\php-8.2.35-20261023',
    [string]$StageApacheDlls = 'E:\xampp\upgrade-staging\apache-bin-dlls-8.2.35-20261023',
    [string]$BackupRoot = 'E:\xampp\upgrade-backups\php-8.2.12-before-8.2.35-20261023',
    [string]$RollbackPhp = 'E:\xampp\php-8.2.12-rollback-20261024',
    [string]$ApplicationRoot = 'E:\xampp\htdocs\menam-online',
    [string]$SmokeTestUrl = 'http://127.0.0.1/',

    [string[]]$TaskNames = @(
        'AlertRiskMFG',
        'Deadstock Alert',
        'Sales Forecast Planner',
        'Sales Weekly',
        'SendMailPlanrev0',
        'SendMailPlanrev1',
        'WOCR Alert'
    )
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$currentPhp = Join-Path $XamppRoot 'php'
$apacheRoot = Join-Path $XamppRoot 'apache'
$apacheBin = Join-Path $apacheRoot 'bin'
$phpExecutable = Join-Path $currentPhp 'php.exe'
$apacheExecutable = Join-Path $apacheBin 'httpd.exe'
$artisan = Join-Path $ApplicationRoot 'artisan'
$runtimeDllNames = @('nghttp2.dll', 'libssh2.dll', 'brotlicommon.dll', 'brotlidec.dll')
$taskState = @()
$apacheServiceName = $null
$cutoverStarted = $false
$apacheWasStopped = $false
$transcriptStarted = $false
$runStamp = Get-Date -Format 'yyyyMMdd-HHmmss'

function Write-Step {
    param([string]$Message)
    Write-Host "`n=== $Message ===" -ForegroundColor Cyan
}

function Assert-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Run PowerShell as Administrator on the production server.'
    }
}

function Assert-ExactPathUnderRoot {
    param(
        [string]$Path,
        [string]$Root
    )

    $fullPath = [IO.Path]::GetFullPath($Path)
    $fullRoot = [IO.Path]::GetFullPath($Root).TrimEnd('\') + '\'
    if (-not $fullPath.StartsWith($fullRoot, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Unsafe path outside $fullRoot : $fullPath"
    }
}

function Assert-PathExists {
    param([string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) {
        throw "Required path does not exist: $Path"
    }
}

function Invoke-CheckedCommand {
    param(
        [string]$FilePath,
        [string[]]$Arguments = @(),
        [string]$WorkingDirectory
    )

    $oldLocation = Get-Location
    $oldErrorActionPreference = $ErrorActionPreference
    try {
        if ($WorkingDirectory) {
            Set-Location -LiteralPath $WorkingDirectory
        }

        # Windows PowerShell 5.1 wraps native stderr as ErrorRecord objects. With
        # ErrorActionPreference=Stop, a PHP startup warning can terminate this
        # function before LASTEXITCODE is inspected. Capture both streams and
        # decide command success from its native exit code; callers that require
        # a warning-free runtime still call Assert-NoStartupWarnings explicitly.
        $ErrorActionPreference = 'Continue'
        $output = @(& $FilePath @Arguments 2>&1)
        $exitCode = $LASTEXITCODE
        $output | ForEach-Object { Write-Host $_ }

        if ($exitCode -ne 0) {
            throw "Command failed with exit code $exitCode`: $FilePath $($Arguments -join ' ')"
        }

        return $output
    }
    finally {
        $ErrorActionPreference = $oldErrorActionPreference
        Set-Location -LiteralPath $oldLocation
    }
}

function Get-ApacheServiceName {
    $escapedRoot = [regex]::Escape($apacheExecutable)
    $service = @(Get-CimInstance Win32_Service | Where-Object {
        $_.PathName -match $escapedRoot
    })

    if ($service.Count -ne 1) {
        throw "Expected exactly one Apache service for $apacheExecutable; found $($service.Count)."
    }

    return $service[0].Name
}

function Wait-ServiceState {
    param(
        [string]$Name,
        [ValidateSet('Running', 'Stopped')]
        [string]$State,
        [int]$TimeoutSeconds = 30
    )

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    do {
        $service = Get-Service -Name $Name
        if ($service.Status.ToString() -eq $State) {
            return
        }
        Start-Sleep -Seconds 1
    } while ((Get-Date) -lt $deadline)

    throw "Service $Name did not reach state $State within $TimeoutSeconds seconds."
}

function Get-RelevantPhpProcesses {
    return @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object {
        $_.ExecutablePath -and
        $_.ExecutablePath.Equals($phpExecutable, [StringComparison]::OrdinalIgnoreCase)
    })
}

function Get-ApacheProcesses {
    return @(Get-CimInstance Win32_Process -Filter "Name='httpd.exe'" | Where-Object {
        $_.ExecutablePath -and
        $_.ExecutablePath.Equals($apacheExecutable, [StringComparison]::OrdinalIgnoreCase)
    })
}

function Get-TargetTasks {
    $tasks = foreach ($taskName in $TaskNames) {
        $task = Get-ScheduledTask -TaskPath '\' -TaskName $taskName -ErrorAction Stop
        [pscustomobject]@{
            TaskPath  = $task.TaskPath
            TaskName  = $task.TaskName
            WasEnabled = ($task.State.ToString() -ne 'Disabled')
            State     = $task.State.ToString()
        }
    }
    return @($tasks)
}

function Backup-And-DisableTasks {
    $script:taskState = @(Get-TargetTasks)
    $taskBackupPath = Join-Path $BackupRoot "scheduled-tasks-$runStamp"
    New-Item -ItemType Directory -Path $taskBackupPath -ErrorAction Stop | Out-Null

    foreach ($item in $script:taskState) {
        Export-ScheduledTask -TaskPath $item.TaskPath -TaskName $item.TaskName |
            Set-Content -LiteralPath (Join-Path $taskBackupPath "$($item.TaskName).xml") -Encoding UTF8

        Stop-ScheduledTask -TaskPath $item.TaskPath -TaskName $item.TaskName -ErrorAction SilentlyContinue
        Disable-ScheduledTask -TaskPath $item.TaskPath -TaskName $item.TaskName | Out-Null
    }

    $script:taskState |
        ConvertTo-Json -Depth 3 |
        Set-Content -LiteralPath (Join-Path $taskBackupPath 'task-state-before.json') -Encoding UTF8

    Write-Host "Scheduled-task backup: $taskBackupPath"
}

function Restore-TaskState {
    if ($script:taskState.Count -eq 0) {
        Write-Warning 'No in-memory scheduled-task state is available; tasks were not changed.'
        return
    }

    foreach ($item in $script:taskState) {
        if ($item.WasEnabled) {
            Enable-ScheduledTask -TaskPath $item.TaskPath -TaskName $item.TaskName | Out-Null
        }
        else {
            Disable-ScheduledTask -TaskPath $item.TaskPath -TaskName $item.TaskName | Out-Null
        }
    }
}

function Stop-ApacheSafely {
    Stop-Service -Name $script:apacheServiceName
    Wait-ServiceState -Name $script:apacheServiceName -State Stopped
    $script:apacheWasStopped = $true
    Start-Sleep -Seconds 2

    $processes = @(Get-ApacheProcesses)
    if ($processes.Count -ne 0) {
        throw "Apache service stopped but $($processes.Count) httpd process(es) remain. No force-kill was attempted."
    }

    $listeners = @(Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Where-Object {
        $_.LocalPort -in 80, 443
    })
    if ($listeners.Count -ne 0) {
        throw 'Ports 80/443 are still listening after Apache stopped.'
    }

}

function Start-ApacheAndWait {
    Start-Service -Name $script:apacheServiceName
    Wait-ServiceState -Name $script:apacheServiceName -State Running
    Start-Sleep -Seconds 7

    $processes = @(Get-ApacheProcesses)
    if ($processes.Count -lt 2) {
        throw "Expected Apache parent and worker processes; found $($processes.Count)."
    }

    $listeners = @(Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Where-Object {
        $_.LocalPort -in 80, 443
    })
    if ($listeners.Count -eq 0) {
        throw 'Apache is running but ports 80/443 are not listening.'
    }

    $script:apacheWasStopped = $false
}

function Assert-NoStartupWarnings {
    param([object[]]$Output)
    $text = $Output -join "`n"
    if ($text -match 'PHP Startup|Unable to load dynamic library|Fatal error|Entry Point Not Found') {
        throw 'PHP validation output contains a startup, DLL, fatal, or entry-point error.'
    }
}

function Test-StagedPhp {
    Assert-PathExists -Path (Join-Path $StagePhp 'php.exe')
    Assert-PathExists -Path (Join-Path $StagePhp 'php.ini')
    Assert-PathExists -Path (Join-Path $StagePhp 'extras\browscap.ini')

    $versionOutput = Invoke-CheckedCommand -FilePath (Join-Path $StagePhp 'php.exe') -Arguments @('-n', '-v')
    if (($versionOutput -join "`n") -notmatch 'PHP 8\.2\.35') {
        throw 'Staged PHP is not version 8.2.35.'
    }

    $moduleOutput = Invoke-CheckedCommand -FilePath (Join-Path $StagePhp 'php.exe') -Arguments @(
        '-c', (Join-Path $StagePhp 'php.ini'),
        '-d', "extension_dir=$StagePhp\ext",
        '-d', "browscap=$StagePhp\extras\browscap.ini",
        '-m'
    )
    Assert-NoStartupWarnings -Output $moduleOutput

    $requiredModules = @('curl', 'gd', 'intl', 'mbstring', 'mysqli', 'pdo_mysql', 'pdo_pgsql', 'pdo_sqlsrv', 'pgsql', 'sqlsrv', 'zip')
    $loadedModules = @($moduleOutput | ForEach-Object { $_.ToString().Trim().ToLowerInvariant() })
    $missingModules = @($requiredModules | Where-Object { $loadedModules -notcontains $_ })
    if ($missingModules.Count -ne 0) {
        throw "Staged PHP is missing modules: $($missingModules -join ', ')"
    }
}

function Assert-UploadProgressMitigation {
    $iniPath = Join-Path $StagePhp 'php.ini'
    $match = Select-String -LiteralPath $iniPath -Pattern '^\s*session\.upload_progress\.enabled\s*=\s*0\s*$'
    if (-not $match) {
        throw 'Staged php.ini must contain session.upload_progress.enabled=0. Run -Action Prepare first.'
    }
}

function Assert-ProductionIniPaths {
    param([string]$IniPath)

    $driveLessPaths = @(Select-String -LiteralPath $IniPath -Pattern '^\s*[^;][^=]*=\s*"?\\xampp\\')
    if ($driveLessPaths.Count -ne 0) {
        $details = ($driveLessPaths | ForEach-Object { "line $($_.LineNumber): $($_.Line.Trim())" }) -join '; '
        throw "php.ini still contains drive-less XAMPP paths: $details"
    }
}

function Prepare-StagedPhp {
    $iniPath = Join-Path $StagePhp 'php.ini'
    Assert-PathExists -Path $iniPath

    $content = @(Get-Content -LiteralPath $iniPath)
    $pathSettings = @(
        @{ Pattern = '^\s*include_path\s*=.*$';       Value = "include_path=$XamppRoot\php\PEAR" },
        @{ Pattern = '^\s*extension_dir\s*=.*$';      Value = "extension_dir=`"$XamppRoot\php\ext`"" },
        @{ Pattern = '^\s*upload_tmp_dir\s*=.*$';     Value = "upload_tmp_dir=`"$XamppRoot\tmp`"" },
        @{ Pattern = '^\s*error_log\s*=.*$';          Value = "error_log=`"$XamppRoot\php\logs\php_error_log`"" },
        @{ Pattern = '^\s*browscap\s*=.*$';           Value = "browscap=`"$XamppRoot\php\extras\browscap.ini`"" },
        @{ Pattern = '^\s*session\.save_path\s*=.*$'; Value = "session.save_path=`"$XamppRoot\tmp`"" },
        @{ Pattern = '^\s*curl\.cainfo\s*=.*$';       Value = "curl.cainfo=`"$XamppRoot\apache\bin\curl-ca-bundle.crt`"" },
        @{ Pattern = '^\s*openssl\.cafile\s*=.*$';    Value = "openssl.cafile=`"$XamppRoot\apache\bin\curl-ca-bundle.crt`"" }
    )

    foreach ($item in $pathSettings) {
        $matchingLines = @($content | Where-Object { $_ -match $item.Pattern })
        if ($matchingLines.Count -ne 1) {
            throw "Expected exactly one active php.ini setting matching $($item.Pattern); found $($matchingLines.Count)."
        }
        $content = @($content -replace $item.Pattern, $item.Value)
    }

    $uploadProgressSetting = @($content | Where-Object { $_ -match '^\s*session\.upload_progress\.enabled\s*=' })
    if ($uploadProgressSetting.Count -gt 1) {
        throw "Expected at most one session.upload_progress.enabled setting; found $($uploadProgressSetting.Count)."
    }
    if ($uploadProgressSetting.Count -eq 1) {
        $content = @($content -replace '^\s*session\.upload_progress\.enabled\s*=.*$', 'session.upload_progress.enabled=0')
    }
    else {
        $content += ''
        $content += 'session.upload_progress.enabled=0'
    }

    Set-Content -LiteralPath $iniPath -Value $content -Encoding ASCII

    Assert-UploadProgressMitigation
    Assert-ProductionIniPaths -IniPath $iniPath
    Test-StagedPhp
}

function Test-CurrentProductionState {
    Assert-PathExists -Path $currentPhp
    Assert-PathExists -Path $apacheRoot
    Assert-PathExists -Path $ApplicationRoot
    Assert-PathExists -Path $BackupRoot
    Assert-PathExists -Path $StagePhp
    Assert-PathExists -Path $StageApacheDlls

    foreach ($dllName in $runtimeDllNames) {
        Assert-PathExists -Path (Join-Path $StageApacheDlls $dllName)
    }

    if (Test-Path -LiteralPath $RollbackPhp) {
        throw "Rollback target already exists: $RollbackPhp"
    }

    $script:apacheServiceName = Get-ApacheServiceName
    $service = Get-Service -Name $script:apacheServiceName
    if ($service.Status -ne 'Running') {
        throw "Apache service $($script:apacheServiceName) is not running before cutover."
    }

    $taskInventory = Get-TargetTasks
    $taskInventory | Format-Table TaskName, State, WasEnabled

    $currentVersion = Invoke-CheckedCommand -FilePath $phpExecutable -Arguments @('-n', '-v')
    if (($currentVersion -join "`n") -notmatch 'PHP 8\.2\.12') {
        throw 'Current production PHP is not the expected version 8.2.12.'
    }
}

function Restore-ApacheRuntimeDlls {
    foreach ($dllName in $runtimeDllNames) {
        $backupDll = Join-Path (Join-Path $BackupRoot 'apache\bin') $dllName
        $liveDll = Join-Path $apacheBin $dllName

        if (Test-Path -LiteralPath $backupDll) {
            Copy-Item -LiteralPath $backupDll -Destination $liveDll -Force
        }
        elseif (Test-Path -LiteralPath $liveDll) {
            Remove-Item -LiteralPath $liveDll -Force
        }
    }
}

function Invoke-CoreRollback {
    Write-Step 'Automatic rollback'

    if (-not $script:apacheWasStopped) {
        $service = Get-Service -Name $script:apacheServiceName -ErrorAction SilentlyContinue
        if ($service -and $service.Status -ne 'Stopped') {
            Stop-ApacheSafely
        }
    }

    $failedPhp = Join-Path $XamppRoot "php-8.2.35-failed-$runStamp"
    if ((Test-Path -LiteralPath $currentPhp) -and -not (Test-Path -LiteralPath $failedPhp)) {
        Move-Item -LiteralPath $currentPhp -Destination $failedPhp
    }

    if ((Test-Path -LiteralPath $RollbackPhp) -and -not (Test-Path -LiteralPath $currentPhp)) {
        Move-Item -LiteralPath $RollbackPhp -Destination $currentPhp
    }

    Restore-ApacheRuntimeDlls
    Start-ApacheAndWait

    $response = Invoke-WebRequest -UseBasicParsing -Uri $SmokeTestUrl -TimeoutSec 20
    if ($response.StatusCode -ne 200) {
        throw "Rollback HTTP smoke test returned $($response.StatusCode)."
    }

    Restore-TaskState
    Write-Host "Rollback completed. Failed PHP retained at: $failedPhp" -ForegroundColor Yellow
}

function Test-NewProductionRuntime {
    $versionOutput = Invoke-CheckedCommand -FilePath $phpExecutable -Arguments @('-v')
    Assert-NoStartupWarnings -Output $versionOutput
    if (($versionOutput -join "`n") -notmatch 'PHP 8\.2\.35') {
        throw 'Live PHP is not version 8.2.35.'
    }

    $moduleOutput = Invoke-CheckedCommand -FilePath $phpExecutable -Arguments @('-m')
    Assert-NoStartupWarnings -Output $moduleOutput

    $apacheOutput = Invoke-CheckedCommand -FilePath $apacheExecutable -Arguments @('-t')
    Assert-NoStartupWarnings -Output $apacheOutput
}

function Test-Application {
    $response = Invoke-WebRequest -UseBasicParsing -Uri $SmokeTestUrl -TimeoutSec 20
    if ($response.StatusCode -ne 200) {
        throw "HTTP smoke test returned $($response.StatusCode)."
    }
    Write-Host "HTTP $($response.StatusCode) url=$SmokeTestUrl"

    $dbProbe = 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $kernel=$app->make(Illuminate\Contracts\Console\Kernel::class); $kernel->bootstrap(); $name=config("database.default"); $row=Illuminate\Support\Facades\DB::connection($name)->selectOne("SELECT 1 AS probe"); echo "connection={$name} probe={$row->probe}\n";'
    Invoke-CheckedCommand -FilePath $phpExecutable -Arguments @('-r', $dbProbe) -WorkingDirectory $ApplicationRoot | Out-Null
}

Assert-Administrator
Assert-ExactPathUnderRoot -Path $StagePhp -Root $XamppRoot
Assert-ExactPathUnderRoot -Path $StageApacheDlls -Root $XamppRoot
Assert-ExactPathUnderRoot -Path $BackupRoot -Root $XamppRoot
Assert-ExactPathUnderRoot -Path $RollbackPhp -Root $XamppRoot

switch ($Action) {
    'Preflight' {
        Write-Step 'Read-only preflight'
        Test-CurrentProductionState
        Test-StagedPhp
        Write-Host 'Preflight passed. No services, tasks, or runtime files were changed.' -ForegroundColor Green
    }

    'Prepare' {
        Write-Step 'Prepare staged PHP'
        Prepare-StagedPhp
        Write-Host 'Staged PHP prepared and validated. Production runtime was not changed.' -ForegroundColor Green
    }

    'Cutover' {
        if (-not $ConfirmCutover) {
            throw 'Cutover requires the explicit -ConfirmCutover switch.'
        }

        Assert-UploadProgressMitigation
        Test-CurrentProductionState
        Test-StagedPhp

        $logDirectory = Join-Path $BackupRoot 'logs'
        New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null
        Start-Transcript -LiteralPath (Join-Path $logDirectory "cutover-$runStamp.log") | Out-Null
        $transcriptStarted = $true

        try {
            Write-Step 'Backup and disable scheduled tasks'
            Backup-And-DisableTasks

            Write-Step 'Request graceful queue restart'
            if (Test-Path -LiteralPath $artisan) {
                Invoke-CheckedCommand -FilePath $phpExecutable -Arguments @('artisan', 'queue:restart') -WorkingDirectory $ApplicationRoot | Out-Null
            }
            Start-Sleep -Seconds 5

            $remainingPhp = @(Get-RelevantPhpProcesses | Where-Object {
                $_.CommandLine -match 'queue:(work|listen)|schedule:work|schedule:run'
            })
            if ($remainingPhp.Count -ne 0) {
                $remainingPhp | Select-Object ProcessId, CommandLine | Format-List
                throw 'PHP queue/scheduler processes remain. No force-kill was attempted.'
            }

            Write-Step 'Stop Apache'
            Stop-ApacheSafely

            Write-Step 'Atomic PHP folder switch'
            $cutoverStarted = $true
            Move-Item -LiteralPath $currentPhp -Destination $RollbackPhp
            Move-Item -LiteralPath $StagePhp -Destination $currentPhp

            Write-Step 'Install staged Apache runtime DLLs'
            foreach ($dllName in $runtimeDllNames) {
                Copy-Item -LiteralPath (Join-Path $StageApacheDlls $dllName) -Destination (Join-Path $apacheBin $dllName) -Force
            }

            Write-Step 'Validate PHP and Apache before startup'
            Test-NewProductionRuntime

            Write-Step 'Start Apache and smoke test'
            Start-ApacheAndWait
            Test-Application

            Write-Step 'Restore scheduled-task states'
            Restore-TaskState

            Write-Host 'CUTOVER SUCCESS: PHP 8.2.35 is live.' -ForegroundColor Green
            Write-Host "Rollback PHP retained at: $RollbackPhp"
        }
        catch {
            Write-Host "CUTOVER ERROR: $($_.Exception.Message)" -ForegroundColor Red
            if ($cutoverStarted) {
                Invoke-CoreRollback
            }
            else {
                $currentApacheService = Get-Service -Name $apacheServiceName -ErrorAction SilentlyContinue
                if ($currentApacheService -and $currentApacheService.Status -eq 'Stopped') {
                    Start-ApacheAndWait
                }
                Restore-TaskState
            }
            throw
        }
        finally {
            if ($transcriptStarted) {
                Stop-Transcript | Out-Null
            }
        }
    }

    'Rollback' {
        if (-not $ConfirmRollback) {
            throw 'Rollback requires the explicit -ConfirmRollback switch.'
        }

        $apacheServiceName = Get-ApacheServiceName
        $taskState = Get-TargetTasks
        Invoke-CoreRollback
    }
}
