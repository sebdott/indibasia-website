param([int]$Port = 8082, [switch]$RemoteDatabase)
$ErrorActionPreference = 'Stop'
$phpExecutable = Join-Path $PSScriptRoot '.tools\php\php.exe'
if (!(Test-Path -LiteralPath $phpExecutable)) { $phpExecutable = (Get-Command php -ErrorAction Stop).Source }
Set-Location -LiteralPath $PSScriptRoot
$databaseKeys = @('DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_CHARSET', 'DB_SSL_CA', 'CMS_ENABLED', 'CMS_BOOTSTRAP_FILE', 'CMS_INSTALL_FILE')
$previousEnvironment = @{}
foreach ($key in $databaseKeys) { $previousEnvironment[$key] = [Environment]::GetEnvironmentVariable($key, 'Process') }
try {
    if (!$RemoteDatabase) {
        if (!(Test-Path -LiteralPath (Join-Path $PSScriptRoot '.env'))) { throw 'Run make up first to prepare local MySQL and .env.' }
        # Capture resolved Compose settings in memory; never print credentials.
        $composeSettings = docker compose config --format json | ConvertFrom-Json
        if ($LASTEXITCODE -ne 0) { throw 'Local Docker configuration could not be read.' }
        foreach ($property in $composeSettings.services.web.environment.PSObject.Properties) {
            [Environment]::SetEnvironmentVariable($property.Name, [string]$property.Value, 'Process')
        }
        $databasePort = $composeSettings.services.db.ports | Where-Object { $_.target -eq 3306 } | Select-Object -First 1
        $env:DB_HOST = '127.0.0.1'
        $env:DB_PORT = [string]$databasePort.published
        $env:CMS_BOOTSTRAP_FILE = Join-Path $PSScriptRoot 'storage/local-admin-bootstrap.txt'
        $env:CMS_INSTALL_FILE = Join-Path $PSScriptRoot 'storage/local-cms-installed.json'
    }
    & $phpExecutable -S "127.0.0.1:$Port" -t public public/router.php
    $phpExitCode = $LASTEXITCODE
} finally {
    foreach ($key in $databaseKeys) { [Environment]::SetEnvironmentVariable($key, $previousEnvironment[$key], 'Process') }
}
exit $phpExitCode
