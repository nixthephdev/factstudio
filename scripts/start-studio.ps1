param([int]$Port = 8085)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$phpPath = (Get-Command php.exe -ErrorAction Stop).Source
if (Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue) { Write-Output ('A server is already listening on port ' + $Port + '. Open http://localhost:' + $Port + '/'); exit }
$arguments = '-S 127.0.0.1:' + $Port + ' "' + (Join-Path $projectRoot 'router.php') + '"'
Start-Process -FilePath $phpPath -ArgumentList $arguments -WorkingDirectory $projectRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $projectRoot 'storage/logs/server.out.log') -RedirectStandardError (Join-Path $projectRoot 'storage/logs/server.err.log')
Write-Output ('FACT Studio: http://localhost:' + $Port + '/')
