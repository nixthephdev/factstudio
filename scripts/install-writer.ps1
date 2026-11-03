param()
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$projectRoot = Split-Path -Parent $PSScriptRoot
$toolRoot = Join-Path $projectRoot 'tools'
$writerRoot = Join-Path $toolRoot 'ollama'
$writerExe = Join-Path $writerRoot 'ollama.exe'
$logRoot = Join-Path $projectRoot 'storage/logs'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
if (!(Test-Path -LiteralPath $writerExe)) {
    $releaseInfo = Invoke-RestMethod -Uri 'https://api.github.com/repos/ollama/ollama/releases/latest'
    $asset = $releaseInfo.assets | Where-Object { $_.name -eq 'ollama-windows-amd64.zip' } | Select-Object -First 1
    if (!$asset -or $asset.digest -notmatch '^sha256:([a-fA-F0-9]{64})$') { throw 'No verifiable Windows portable release was found on the official Ollama repository.' }
    $expectedHash = $Matches[1]
    $archivePath = Join-Path $toolRoot 'ollama-windows-amd64.zip'
    Write-Output ('Downloading portable Ollama: ' + [math]::Round($asset.size / 1GB, 2) + ' GB...')
    $downloadClient = New-Object System.Net.WebClient
    try { $downloadClient.DownloadFile($asset.browser_download_url, $archivePath) } finally { $downloadClient.Dispose() }
    if ((Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash -ne $expectedHash) { throw 'Ollama download checksum verification failed.' }
    Expand-Archive -LiteralPath $archivePath -DestinationPath $writerRoot -Force
}
$env:OLLAMA_MODELS = Join-Path $toolRoot 'ollama-models'
$env:OLLAMA_HOST = '127.0.0.1:11434'
$env:OLLAMA_NO_CLOUD = '1'
$writerListening = Get-NetTCPConnection -State Listen -LocalPort 11434 -ErrorAction SilentlyContinue
if (!$writerListening) {
    Start-Process -FilePath $writerExe -ArgumentList 'serve' -WorkingDirectory $projectRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logRoot 'ollama.out.log') -RedirectStandardError (Join-Path $logRoot 'ollama.err.log')
    Start-Sleep -Seconds 3
}
Write-Output 'Downloading the local qwen3:4b model (about 2.5 GB)...'
$pullProcess = Start-Process -FilePath $writerExe -ArgumentList @('pull','qwen3:4b') -WorkingDirectory $projectRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logRoot 'model-download.out.log') -RedirectStandardError (Join-Path $logRoot 'model-download.err.log') -Wait -PassThru
if ($pullProcess.ExitCode -ne 0) { throw 'Model download failed. See storage/logs/model-download.err.log.' }
Write-Output 'Local writer is ready on 127.0.0.1:11434. No system-wide installation was made.'
