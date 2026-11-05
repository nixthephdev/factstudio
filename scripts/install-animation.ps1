param()
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$projectRoot = Split-Path -Parent $PSScriptRoot
$comfyRoot = Join-Path $projectRoot 'tools/ComfyUI'
$extensionRoot = Join-Path $comfyRoot 'custom_nodes/ComfyUI-AnimateDiff-Evolved'
if (!(Test-Path -LiteralPath (Join-Path $comfyRoot 'main.py'))) { throw 'Install the local illustrator first.' }
if (!(Test-Path -LiteralPath (Join-Path $extensionRoot '__init__.py'))) {
    & git clone --depth 1 https://github.com/Kosinkadink/ComfyUI-AnimateDiff-Evolved.git $extensionRoot
    if ($LASTEXITCODE -ne 0) { throw 'AnimateDiff extension download failed.' }
}
$revision = '3d4d00fc113225e1040f4d3bec504b6ec750c10c'
$models = @(
    @{ File='AnimateLCM_sd15_t2v.ckpt'; Folder='animatediff_models'; Hash='b46c3de62e5696af72c4056e3cdcbea12fbc19581c0aad7b6f2b027851148f5f' },
    @{ File='AnimateLCM_sd15_t2v_lora.safetensors'; Folder='loras'; Hash='8f90d840e075ff588a58e22c6586e2ae9a6f7922996ee6649a7f01072333afe4' }
)
foreach ($model in $models) {
    $folder = Join-Path $comfyRoot ('models/' + $model.Folder)
    New-Item -ItemType Directory -Force -Path $folder | Out-Null
    $target = Join-Path $folder $model.File
    if (Test-Path -LiteralPath $target) {
        if ((Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash -ne $model.Hash) { throw ('Existing model checksum mismatch: ' + $model.File) }
        Write-Output ('Already verified: ' + $model.File)
        continue
    }
    Write-Output ('Downloading official motion model: ' + $model.File)
    $url = 'https://huggingface.co/wangfuyun/AnimateLCM/resolve/' + $revision + '/' + $model.File
    & curl.exe --fail --location --retry 3 --continue-at - --output ($target + '.download') $url
    if ($LASTEXITCODE -ne 0) { throw ('Download failed: ' + $model.File) }
    if ((Get-FileHash -LiteralPath ($target + '.download') -Algorithm SHA256).Hash -ne $model.Hash) { throw ('Downloaded model checksum mismatch: ' + $model.File) }
    Move-Item -LiteralPath ($target + '.download') -Destination $target
    Write-Output ('SHA-256 verified: ' + $model.File)
}
Write-Output 'Motion models installed. Restart ComfyUI before generating animation.'
