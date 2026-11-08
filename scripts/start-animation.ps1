param([int]$Port = 8189)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$pythonExe = Join-Path $projectRoot 'tools/comfy-venv/Scripts/python.exe'
$comfyRoot = Join-Path $projectRoot 'tools/ComfyUI'
$logRoot = Join-Path $projectRoot 'storage/logs'
if (!(Test-Path -LiteralPath $pythonExe)) { throw 'Install the local illustrator first.' }
$probe = New-Object System.Net.Sockets.TcpClient
try { $probe.Connect('127.0.0.1', $Port); Write-Output ('Animation server already listening on port ' + $Port); exit } catch {} finally { $probe.Dispose() }
New-Item -ItemType Directory -Force -Path $logRoot | Out-Null
$arguments = '"' + (Join-Path $comfyRoot 'main.py') + '" --listen 127.0.0.1 --port ' + $Port + ' --lowvram --force-fp32 --disable-dynamic-vram --use-split-cross-attention --disable-auto-launch'
$animationProcess = Start-Process -FilePath $pythonExe -ArgumentList $arguments -WorkingDirectory $comfyRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logRoot 'animation.out.log') -RedirectStandardError (Join-Path $logRoot 'animation.err.log') -PassThru
Write-Output ('Animation server starting on 127.0.0.1:' + $Port + ' (PID ' + $animationProcess.Id + ').')
