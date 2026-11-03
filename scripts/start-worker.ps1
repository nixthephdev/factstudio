param([Parameter(Mandatory=$true)][string]$PhpPath)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$consolePath = Join-Path $projectRoot 'bin/console.php'
$logRoot = Join-Path $projectRoot 'storage/logs'
$runId = [guid]::NewGuid().ToString('N')
$arguments = '"' + $consolePath + '" work'
Start-Process -FilePath $PhpPath -ArgumentList $arguments -WorkingDirectory $projectRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logRoot ($runId + '.out.log')) -RedirectStandardError (Join-Path $logRoot ($runId + '.err.log'))
Write-Output 'Worker launched.'
