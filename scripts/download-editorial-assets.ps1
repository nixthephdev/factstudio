param()
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$assetRoot = Join-Path $projectRoot 'storage/editorial-assets'
New-Item -ItemType Directory -Force -Path $assetRoot | Out-Null
$assets = @{
    'sun.mp4' = 'https://svs.gsfc.nasa.gov/vis/a000000/a003900/a003983/SDOAIA304A_Jewelbox_HD720.mp4'
    'earth.jpg' = 'https://svs.gsfc.nasa.gov/vis/a010000/a011000/a011084/s4-1920.jpg'
}
foreach ($name in $assets.Keys) {
    $destination = Join-Path $assetRoot $name
    if ((Test-Path -LiteralPath $destination) -and (Get-Item -LiteralPath $destination).Length -gt 0) { continue }
    Write-Output ('Downloading NASA asset: ' + $name)
    $temporary = $destination + '.download'
    & curl.exe --fail --location --silent --show-error --retry 1 --connect-timeout 15 --max-time 180 --output $temporary $assets[$name]
    if ($LASTEXITCODE -ne 0) { throw ('NASA download failed: ' + $name) }
    Move-Item -LiteralPath $temporary -Destination $destination -Force
}
Write-Output 'NASA editorial assets are ready. Run php bin/editorial.php to render the curated short.'
