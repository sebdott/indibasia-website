param([int]$Port = 8082)
$ErrorActionPreference = 'Stop'
$phpExecutable = Join-Path $PSScriptRoot '.tools\php\php.exe'
if (!(Test-Path -LiteralPath $phpExecutable)) { $phpExecutable = (Get-Command php -ErrorAction Stop).Source }
Set-Location -LiteralPath $PSScriptRoot
& $phpExecutable -S "127.0.0.1:$Port" -t public public/router.php
