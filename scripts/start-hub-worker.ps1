param([switch]$MonitorOnly)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$phpPath = (Get-Command php.exe -ErrorAction Stop).Source
$logRoot = Join-Path $projectRoot 'storage/logs'
New-Item -ItemType Directory -Force -Path $logRoot | Out-Null
& (Join-Path $PSScriptRoot 'start-ai.ps1')
$mode = if ($MonitorOnly) { 'monitor' } else { 'run' }
$arguments = '"' + (Join-Path $projectRoot 'bin/hub.php') + '" ' + $mode
Start-Process -FilePath $phpPath -ArgumentList $arguments -WorkingDirectory $projectRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logRoot 'hub.out.log') -RedirectStandardError (Join-Path $logRoot 'hub.err.log')
Write-Output ('Hosted connection started in ' + $mode + ' mode. Check the dashboard for the office heartbeat.')
