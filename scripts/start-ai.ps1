param()
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$logRoot = Join-Path $projectRoot 'storage/logs'
$writerExe = Join-Path $projectRoot 'tools/ollama/ollama.exe'
if ((Test-Path -LiteralPath $writerExe) -and !(Get-NetTCPConnection -State Listen -LocalPort 11434 -ErrorAction SilentlyContinue)) {
    $env:OLLAMA_MODELS = Join-Path $projectRoot 'tools/ollama-models'
    $env:OLLAMA_HOST = '127.0.0.1:11434'
    $env:OLLAMA_NO_CLOUD = '1'
    Start-Process -FilePath $writerExe -ArgumentList 'serve' -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logRoot 'ollama.out.log') -RedirectStandardError (Join-Path $logRoot 'ollama.err.log')
    Write-Output 'Local writer started.'
}
$pythonExe = Join-Path $projectRoot 'tools/comfy-venv/Scripts/python.exe'
$comfyRoot = Join-Path $projectRoot 'tools/ComfyUI'
if ((Test-Path -LiteralPath $pythonExe) -and (Test-Path -LiteralPath (Join-Path $comfyRoot 'main.py')) -and !(Get-NetTCPConnection -State Listen -LocalPort 8188 -ErrorAction SilentlyContinue)) {
    $arguments = '"' + (Join-Path $comfyRoot 'main.py') + '" --listen 127.0.0.1 --port 8188 --lowvram --force-fp32 --disable-auto-launch'
    Start-Process -FilePath $pythonExe -ArgumentList $arguments -WorkingDirectory $comfyRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logRoot 'comfy.out.log') -RedirectStandardError (Join-Path $logRoot 'comfy.err.log')
    Write-Output 'Local illustrator started.'
}
Write-Output 'AI services use project-local installs when available. Install missing services before starting live production.'
