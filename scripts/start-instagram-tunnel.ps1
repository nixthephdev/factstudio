param()
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$projectRoot = Split-Path -Parent $PSScriptRoot
$toolRoot = Join-Path $projectRoot 'tools/cloudflared'
$binary = Join-Path $toolRoot 'cloudflared.exe'
$logRoot = Join-Path $projectRoot 'storage/logs'
$stateFile = Join-Path $projectRoot 'storage/instagram-tunnel.json'
$phpPath = 'C:\xampp\php\php.exe'
if (!(Test-Path -LiteralPath $phpPath)) { $phpPath = (Get-Command php.exe -ErrorAction Stop).Source }
New-Item -ItemType Directory -Force -Path $toolRoot,$logRoot | Out-Null
if (Test-Path -LiteralPath $stateFile) {
    $saved = Get-Content -LiteralPath $stateFile -Raw | ConvertFrom-Json
    $existing = Get-Process -Id $saved.processId -ErrorAction SilentlyContinue
    if ($existing -and $existing.Path -eq $binary) {
        & $phpPath (Join-Path $projectRoot 'bin/instagram-tunnel.php') $saved.url
        if ($LASTEXITCODE -ne 0) { throw 'Could not save Instagram address.' }
        Write-Output ('Instagram tunnel is running: ' + $saved.url)
        exit
    }
}
if (!(Test-Path -LiteralPath $binary)) {
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    Write-Output 'Downloading cloudflared from the official Cloudflare GitHub release...'
    $release = Invoke-RestMethod -Uri 'https://api.github.com/repos/cloudflare/cloudflared/releases/latest'
    $asset = $release.assets | Where-Object { $_.name -eq 'cloudflared-windows-amd64.exe' } | Select-Object -First 1
    if (!$asset -or $asset.digest -notmatch '^sha256:([a-fA-F0-9]{64})$') { throw 'Official release has no verifiable SHA-256 digest.' }
    $expectedHash = $Matches[1]
    $downloadPath = Join-Path $toolRoot 'cloudflared.download'
    # Bounded ranges also work on connections that stall on a full release download.
    $partPath = Join-Path $toolRoot 'cloudflared.part'
    $downloadStream = [IO.File]::Create($downloadPath)
    try {
        for ($offset = 0L; $offset -lt [long]$asset.size; $offset += 1048576) {
            $lastByte = [Math]::Min($offset + 1048575, [long]$asset.size - 1)
            & curl.exe --fail --location --silent --show-error --retry 2 --connect-timeout 15 --max-time 30 --range ($offset.ToString() + '-' + $lastByte.ToString()) --output $partPath $asset.browser_download_url
            if ($LASTEXITCODE -ne 0 -or (Get-Item -LiteralPath $partPath).Length -ne ($lastByte - $offset + 1)) { throw 'Cloudflared download failed.' }
            $partStream = [IO.File]::OpenRead($partPath)
            try { $partStream.CopyTo($downloadStream) } finally { $partStream.Dispose() }
            if (($lastByte + 1) % 8388608 -eq 0) { Write-Output ('Downloaded ' + [Math]::Round(($lastByte + 1) / 1MB) + ' MB...') }
        }
    } finally { $downloadStream.Dispose() }
    if ((Get-FileHash -LiteralPath $downloadPath -Algorithm SHA256).Hash -ne $expectedHash) { throw 'Cloudflared checksum verification failed.' }
    Move-Item -LiteralPath $downloadPath -Destination $binary
    Remove-Item -LiteralPath $partPath -ErrorAction SilentlyContinue
}
$localResponse = Invoke-WebRequest -UseBasicParsing -Uri 'http://localhost:8085/'
if ($localResponse.StatusCode -ne 200) { throw 'Start FACT Studio on port 8085 first.' }
$runId = [guid]::NewGuid().ToString('N')
$errorLog = Join-Path $logRoot ('tunnel-' + $runId + '.err.log')
$outputLog = Join-Path $logRoot ('tunnel-' + $runId + '.out.log')
$tunnelProcess = Start-Process -FilePath $binary -ArgumentList @('tunnel','--no-autoupdate','--url','http://127.0.0.1:8085','--protocol','http2') -WorkingDirectory $projectRoot -WindowStyle Hidden -RedirectStandardError $errorLog -RedirectStandardOutput $outputLog -PassThru
try {
    $deadline = (Get-Date).AddSeconds(45)
    $tunnelUrl = $null
    while ((Get-Date) -lt $deadline) {
        $tunnelProcess.Refresh()
        if ($tunnelProcess.HasExited) { throw ('Tunnel exited. Check ' + $errorLog) }
        if (Test-Path -LiteralPath $errorLog) {
            $tunnelLog = Get-Content -LiteralPath $errorLog -Raw
            if ($tunnelLog) {
                $urlMatch = [regex]::Match($tunnelLog, 'https://[a-z0-9-]+\.trycloudflare\.com')
                if ($urlMatch.Success -and $tunnelLog -match 'Registered tunnel connection') { $tunnelUrl = $urlMatch.Value; break }
            }
        }
        Start-Sleep -Milliseconds 500
    }
    if (!$tunnelUrl) { throw ('Tunnel did not connect. Check ' + $errorLog) }
    & $phpPath (Join-Path $projectRoot 'bin/instagram-tunnel.php') $tunnelUrl
    if ($LASTEXITCODE -ne 0) { throw 'Could not save Instagram address.' }
    @{processId=$tunnelProcess.Id;url=$tunnelUrl} | ConvertTo-Json | Set-Content -LiteralPath $stateFile
    Write-Output ('Instagram tunnel ready: ' + $tunnelUrl)
    Write-Output 'Keep this process running while Instagram fetches videos. Run this script again after restarting Windows; a new address will be saved automatically.'
} catch {
    if (!$tunnelProcess.HasExited) { Stop-Process -Id $tunnelProcess.Id }
    throw
}
