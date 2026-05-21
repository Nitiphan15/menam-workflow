param(
    [string] $EnvPath = ".env"
)

$ErrorActionPreference = "Stop"

$root = Split-Path -Parent $PSScriptRoot

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw "php was not found. Run the SQL files manually in SSMS."
}

& php (Join-Path $root "scripts\run_fc_division_forecast_sql.php")
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}
