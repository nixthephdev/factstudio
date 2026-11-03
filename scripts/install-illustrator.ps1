param()
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$projectRoot = Split-Path -Parent $PSScriptRoot
$toolRoot = Join-Path $projectRoot 'tools'
$comfyRoot = Join-Path $toolRoot 'ComfyUI'
$environmentRoot = Join-Path $toolRoot 'comfy-venv'
$pythonExe = Join-Path $environmentRoot 'Scripts/python.exe'
$logRoot = Join-Path $projectRoot 'storage/logs'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
if (!(Test-Path -LiteralPath $pythonExe)) {
    Write-Output 'Creating a project-local Python 3.13 environment...'
    & py -3.13 -m venv $environmentRoot
    if ($LASTEXITCODE -ne 0) { throw 'Python 3.13 is required. Install it before running this installer.' }
}
if (!(Test-Path -LiteralPath (Join-Path $comfyRoot 'main.py'))) {
    Write-Output 'Downloading ComfyUI from its official repository...'
    & git clone --depth 1 'https://github.com/Comfy-Org/ComfyUI.git' $comfyRoot
    if ($LASTEXITCODE -ne 0) { throw 'ComfyUI download failed.' }
}
Write-Output 'Installing CUDA 12.6 PyTorch in the isolated environment (large download)...'
& $pythonExe -m pip install --disable-pip-version-check 'torch==2.9.1+cu126' 'torchvision==0.24.1+cu126' 'torchaudio==2.9.1+cu126' --index-url 'https://download.pytorch.org/whl/cu126'
if ($LASTEXITCODE -ne 0) { throw 'PyTorch installation failed.' }
$constraintsPath = Join-Path $toolRoot 'comfy-constraints.txt'
@('torch==2.9.1+cu126','torchvision==0.24.1+cu126','torchaudio==2.9.1+cu126') | Set-Content -LiteralPath $constraintsPath -Encoding ascii
Write-Output 'Installing ComfyUI dependencies...'
& $pythonExe -m pip install --disable-pip-version-check -r (Join-Path $comfyRoot 'requirements.txt') -c $constraintsPath --extra-index-url 'https://download.pytorch.org/whl/cu126'
if ($LASTEXITCODE -ne 0) { throw 'ComfyUI dependency installation failed. Check the console output.' }
$checkpointRoot = Join-Path $comfyRoot 'models/checkpoints'
New-Item -ItemType Directory -Force -Path $checkpointRoot | Out-Null
$checkpointPath = Join-Path $checkpointRoot 'v1-5-pruned-emaonly.safetensors'
$expectedHash = '6ce0161689b3853acaa03779ec93eafe75a02f4ced659bee03f50797806fa2fa'
if (!(Test-Path -LiteralPath $checkpointPath)) {
    Write-Output 'Downloading Stable Diffusion 1.5 checkpoint (4.27 GB)...'
    $downloadPath = $checkpointPath + '.download'
    $downloadClient = New-Object System.Net.WebClient
    try { $downloadClient.DownloadFile('https://huggingface.co/stable-diffusion-v1-5/stable-diffusion-v1-5/resolve/main/v1-5-pruned-emaonly.safetensors', $downloadPath) } finally { $downloadClient.Dispose() }
    if ((Get-FileHash -LiteralPath $downloadPath -Algorithm SHA256).Hash -ne $expectedHash) { throw 'Stable Diffusion checkpoint checksum verification failed.' }
    Move-Item -LiteralPath $downloadPath -Destination $checkpointPath
}
$comfyListening = Get-NetTCPConnection -State Listen -LocalPort 8188 -ErrorAction SilentlyContinue
if (!$comfyListening) {
    $arguments = '"' + (Join-Path $comfyRoot 'main.py') + '" --listen 127.0.0.1 --port 8188 --lowvram --force-fp32 --disable-auto-launch'
    Start-Process -FilePath $pythonExe -ArgumentList $arguments -WorkingDirectory $comfyRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logRoot 'comfy.out.log') -RedirectStandardError (Join-Path $logRoot 'comfy.err.log')
}
Write-Output 'ComfyUI is starting on 127.0.0.1:8188. Choose v1-5-pruned-emaonly.safetensors in FACT Studio Connections.'
