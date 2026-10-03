param([double]$Delay = 1, [int]$Workers = 4, [switch]$RetryFailed)
$ErrorActionPreference = 'Stop'
$phpExecutable = Join-Path $PSScriptRoot '.tools\php\php.exe'
$arguments = @()
if (Test-Path -LiteralPath $phpExecutable) {
    $arguments += @('-d', ('extension_dir=' + (Join-Path $PSScriptRoot '.tools\php\ext')))
    $caFile = Join-Path $PSScriptRoot '.tools\windows-ca.pem'
    if (Test-Path -LiteralPath $caFile) { $arguments += @('-d', "curl.cainfo=$caFile") }
} else { $phpExecutable = (Get-Command php -ErrorAction Stop).Source }
$arguments += @((Join-Path $PSScriptRoot 'tools\mirror.php'), "--delay=$Delay", "--workers=$Workers")
if ($RetryFailed) { $arguments += '--retry-failed' }
Set-Location -LiteralPath $PSScriptRoot
& $phpExecutable @arguments
