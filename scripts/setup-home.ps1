param([switch]$InstallAI)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
foreach ($folder in @('storage','storage/jobs','storage/logs','tools')) { New-Item -ItemType Directory -Force -Path (Join-Path $projectRoot $folder) | Out-Null }
$php = Get-Command php.exe -ErrorAction SilentlyContinue
if (!$php -and (Test-Path -LiteralPath 'C:\xampp\php\php.exe')) { $env:Path = 'C:\xampp\php;' + $env:Path; $php = Get-Command php.exe }
if (!$php) { throw 'Install XAMPP/PHP and add PHP to PATH first. This app requires PHP 8.0 or newer with pdo_sqlite, curl, openssl, mbstring, GD and zlib.' }
& (Join-Path $PSScriptRoot 'install-ffmpeg.ps1')
if ($InstallAI) {
    Write-Output 'Installing local AI services and models. Several GB will download. The illustrator setup requires Python 3.13, Git and a compatible NVIDIA GPU.'
    & (Join-Path $PSScriptRoot 'install-writer.ps1')
    & (Join-Path $PSScriptRoot 'install-illustrator.ps1')
}
Write-Output 'Setup complete. Restore your private connection backup before opening the studio for the first time. Then run scripts/start-ai.ps1 and scripts/start-studio.ps1.'
