param()
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$phpPath = (Get-Command php.exe -ErrorAction Stop).Source
& (Join-Path $PSScriptRoot 'start-ai.ps1')
& $phpPath (Join-Path $projectRoot 'bin/hub.php') run >> (Join-Path $projectRoot 'storage/logs/hub-service.log') 2>&1
exit $LASTEXITCODE
