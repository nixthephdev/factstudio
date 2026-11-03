param()
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$phpPath = (Get-Command php.exe -ErrorAction Stop).Source
& (Join-Path $PSScriptRoot 'start-ai.ps1')
$consolePath = Join-Path $projectRoot 'bin/console.php'
$arguments = '"' + $consolePath + '" tick'
$process = Start-Process -FilePath $phpPath -ArgumentList $arguments -WorkingDirectory $projectRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $projectRoot 'storage/logs/schedule.out.log') -RedirectStandardError (Join-Path $projectRoot 'storage/logs/schedule.err.log') -PassThru -Wait
exit $process.ExitCode
