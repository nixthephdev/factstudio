param([string]$BaseUrl = 'http://localhost:8085/')
$ErrorActionPreference = 'Stop'
$webSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$rootResponse = Invoke-WebRequest -UseBasicParsing -Uri $BaseUrl -WebSession $webSession
if ($rootResponse.StatusCode -ne 200) { throw 'Homepage failed.' }
$csrfMatch = [regex]::Match($rootResponse.Content, 'name="csrf-token" content="([a-f0-9]+)"')
if (!$csrfMatch.Success) { throw 'CSRF token was not rendered.' }
foreach ($pageName in @('overview','library','automation','connections','guide')) {
    $response = Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl + '?view=' + $pageName) -WebSession $webSession
    if ($response.StatusCode -ne 200 -or $response.Content -match '(Fatal error|Warning:|Parse error)') { throw ('Page failed: ' + $pageName) }
    Write-Output ('PASS: page ' + $pageName)
}
$state = Invoke-RestMethod -Uri ($BaseUrl + 'index.php?action=api') -WebSession $webSession
foreach ($secret in @('eleven_api_key','google_client_secret','google_refresh_token','youtube_api_key','facebook_token','instagram_token')) {
    if ($state.settings.PSObject.Properties.Name -contains $secret) { throw ('Secret exposed: ' + $secret) }
}
Write-Output 'PASS: browser API omits stored credentials'
try {
    Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl + 'index.php?action=api') -Method Post -ContentType 'application/json' -Body '{"operation":"settings","values":{"niche":"should not save"}}' -WebSession $webSession | Out-Null
    throw 'POST without CSRF was accepted.'
} catch [System.Net.WebException] { if ([int]$_.Exception.Response.StatusCode -ne 403) { throw } }
Write-Output 'PASS: settings mutation requires CSRF token'
foreach ($privatePath in @('storage/studio.sqlite','storage/secret.key','app/Settings.php','scripts/demo-voice.ps1','tools/ffmpeg/bin/ffmpeg.exe')) {
    try { Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl + $privatePath) | Out-Null; throw ('Private file exposed: ' + $privatePath) }
    catch [System.Net.WebException] { if ([int]$_.Exception.Response.StatusCode -notin @(403,404)) { throw } }
}
Write-Output 'PASS: private code, credentials and tool files are not served'
try {
    Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl + 'index.php?action=media&id=1&kind=video&expires=9999999999&token=invalid') -Headers @{Host='untrusted.example'} | Out-Null
    throw 'An untrusted host was accepted.'
} catch [System.Net.WebException] { if ([int]$_.Exception.Response.StatusCode -ne 403) { throw } }
Write-Output 'PASS: untrusted host and invalid media signature rejected'
try {
    Invoke-WebRequest -UseBasicParsing -Uri $BaseUrl -Headers @{'X-Forwarded-For'='203.0.113.10'} | Out-Null
    throw 'A forwarded dashboard request was accepted.'
} catch [System.Net.WebException] { if ([int]$_.Exception.Response.StatusCode -ne 403) { throw } }
Write-Output 'PASS: public proxy requests cannot reach the dashboard'
$video = $state.jobs | Where-Object { $_.data.video } | Select-Object -First 1
if ($video) {
    $mediaUrl = $BaseUrl + 'index.php?action=media&id=' + $video.id + '&kind=video'
    $head = Invoke-WebRequest -UseBasicParsing -Uri $mediaUrl -Method Head
    if ($head.Headers['Content-Type'] -ne 'video/mp4' -or [int]$head.Headers['Content-Length'] -le 0) { throw 'Video headers are invalid.' }
    $rangeRequest = [System.Net.HttpWebRequest]::Create($mediaUrl)
    $rangeRequest.AddRange(0,99)
    $rangeResponse = $rangeRequest.GetResponse()
    try { if ([int]$rangeResponse.StatusCode -ne 206 -or $rangeResponse.ContentLength -ne 100) { throw 'Video range streaming failed.' } } finally { $rangeResponse.Dispose() }
    Write-Output 'PASS: real video supports HEAD and byte-range playback'
}
Write-Output 'HTTP smoke checks passed. No generation or publishing calls were made.'
