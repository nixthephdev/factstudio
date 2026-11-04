param([Parameter(Mandatory=$true)][string]$TextFile,[Parameter(Mandatory=$true)][string]$OutputFile)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Speech
$narrator = New-Object System.Speech.Synthesis.SpeechSynthesizer
try {
    $available = @($narrator.GetInstalledVoices() | ForEach-Object { $_.VoiceInfo.Name })
    if ($available -contains 'Microsoft Zira Desktop') { $narrator.SelectVoice('Microsoft Zira Desktop') }
    $narrator.SetOutputToWaveFile($OutputFile)
    $narrator.Rate = 0
    $narrator.Speak([IO.File]::ReadAllText($TextFile))
} finally { $narrator.Dispose() }
