param(
    [string] $EnvPath = ".env"
)

$ErrorActionPreference = "Stop"

$root = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $root $EnvPath

if (-not (Test-Path -LiteralPath $envFile)) {
    throw "Cannot find env file: $envFile"
}

function Read-DotEnv {
    param([string] $Path)

    $values = @{}
    Get-Content -LiteralPath $Path | ForEach-Object {
        $line = $_.Trim()
        if ($line -eq "" -or $line.StartsWith("#") -or -not $line.Contains("=")) {
            return
        }

        $parts = $line.Split("=", 2)
        $key = $parts[0].Trim()
        $value = $parts[1].Trim()

        if (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'"))) {
            $value = $value.Substring(1, $value.Length - 2)
        }

        $values[$key] = $value
    }

    return $values
}

$envValues = Read-DotEnv -Path $envFile

$hostName = $envValues["SQLSRV_HOST"]
$port = $envValues["SQLSRV_PORT"]
$database = $envValues["SQLSRV_DATABASE"]
$username = $envValues["SQLSRV_USERNAME"]
$password = $envValues["SQLSRV_PASSWORD"]

if ([string]::IsNullOrWhiteSpace($hostName) -or [string]::IsNullOrWhiteSpace($database) -or [string]::IsNullOrWhiteSpace($username)) {
    throw "Missing one of SQLSRV_HOST, SQLSRV_DATABASE, SQLSRV_USERNAME in $envFile"
}

$server = $hostName
if (-not [string]::IsNullOrWhiteSpace($port)) {
    $server = "$hostName,$port"
}

$sqlFiles = @(
    "database\sql\create_fc_division_forecast_workflow.sql",
    "database\sql\create_fc_division_forecast_approval.sql"
)

if (-not (Get-Command sqlcmd -ErrorAction SilentlyContinue)) {
    if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
        throw "sqlcmd and php were not found. Install Microsoft sqlcmd or run the SQL files manually in SSMS."
    }

    Write-Host "sqlcmd was not found. Falling back to Laravel/PHP database connection ..."
    & php (Join-Path $root "scripts\run_fc_division_forecast_sql.php")
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
    exit 0
}

foreach ($relativeFile in $sqlFiles) {
    $sqlFile = Join-Path $root $relativeFile
    if (-not (Test-Path -LiteralPath $sqlFile)) {
        throw "Cannot find SQL file: $sqlFile"
    }

    Write-Host "Running $relativeFile on $server / $database ..."
    & sqlcmd -S $server -d $database -U $username -P $password -b -i $sqlFile
}

Write-Host "Done. FormFC Division workflow tables are ready."
