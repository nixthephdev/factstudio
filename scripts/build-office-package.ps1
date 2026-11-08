param()
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$outputRoot = Join-Path $projectRoot 'storage/deploy'
New-Item -ItemType Directory -Force -Path $outputRoot | Out-Null
$archivePath = Join-Path $outputRoot 'FACT-Studio-office-worker.zip'
if (Test-Path -LiteralPath $archivePath) { Remove-Item -LiteralPath $archivePath }
Add-Type -AssemblyName System.IO.Compression.FileSystem
Add-Type -AssemblyName System.IO.Compression
$archive = [IO.Compression.ZipFile]::Open($archivePath, [IO.Compression.ZipArchiveMode]::Create)
try {
    $files = @()
    foreach ($folder in @('app','assets','bin','scripts','workflows')) { $files += Get-ChildItem -LiteralPath (Join-Path $projectRoot $folder) -File -Recurse }
    $files += Get-ChildItem -LiteralPath $projectRoot -File | Where-Object { $_.Extension -in @('.php','.md') -or $_.Name -eq '.htaccess' }
    foreach ($file in $files) {
        $entry = $file.FullName.Substring($projectRoot.Length + 1).Replace('\','/')
        [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $file.FullName, $entry, [IO.Compression.CompressionLevel]::Optimal) | Out-Null
    }
} finally { $archive.Dispose() }
Write-Output 'Office source package created at storage/deploy/FACT-Studio-office-worker.zip. Pairing and credentials stay in separate private files.'
