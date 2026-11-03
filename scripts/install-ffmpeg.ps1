param()
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$projectRoot = Split-Path -Parent $PSScriptRoot
$toolRoot = Join-Path $projectRoot 'tools'
$archivePath = Join-Path $toolRoot 'ffmpeg-release-essentials.zip'
$extractRoot = Join-Path $toolRoot 'ffmpeg-download'
$targetRoot = Join-Path $toolRoot 'ffmpeg'
if (Test-Path -LiteralPath (Join-Path $targetRoot 'bin/ffmpeg.exe')) { Write-Output 'FFmpeg is already installed.'; exit 0 }
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
New-Item -ItemType Directory -Force -Path $toolRoot | Out-Null
Write-Output 'Downloading FFmpeg Windows essentials from gyan.dev...'
Invoke-WebRequest -UseBasicParsing -Uri 'https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip' -OutFile $archivePath
$hashText = (Invoke-WebRequest -UseBasicParsing -Uri 'https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip.sha256').Content
$expectedHash = [regex]::Match([string]$hashText, '[a-fA-F0-9]{64}').Value
$actualHash = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash
if (!$expectedHash -or $actualHash -ne $expectedHash) { throw 'FFmpeg checksum verification failed.' }
Expand-Archive -LiteralPath $archivePath -DestinationPath $extractRoot -Force
$downloadedRoot = Get-ChildItem -LiteralPath $extractRoot -Directory | Select-Object -First 1
if (!$downloadedRoot -or !(Test-Path -LiteralPath (Join-Path $downloadedRoot.FullName 'bin/ffmpeg.exe'))) { throw 'Unexpected FFmpeg archive layout.' }
New-Item -ItemType Directory -Force -Path $targetRoot | Out-Null
Copy-Item -LiteralPath (Join-Path $downloadedRoot.FullName 'bin') -Destination $targetRoot -Recurse -Force
Copy-Item -LiteralPath (Join-Path $downloadedRoot.FullName 'LICENSE') -Destination $targetRoot -ErrorAction SilentlyContinue
Write-Output 'FFmpeg installed under tools/ffmpeg. No system settings changed.'
