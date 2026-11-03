param([switch]$Remove)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$taskHash = [Security.Cryptography.SHA256]::Create()
try { $taskSuffix = ([BitConverter]::ToString($taskHash.ComputeHash([Text.Encoding]::UTF8.GetBytes($projectRoot)))).Replace('-','').Substring(0,12) } finally { $taskHash.Dispose() }
$taskName = 'FACT Studio - ' + $taskSuffix
if ($Remove) { Unregister-ScheduledTask -TaskName $taskName -Confirm:$false; Write-Output 'FACT Studio schedule removed.'; exit }
$schedulePath = Join-Path $PSScriptRoot 'run-schedule.ps1'
$taskAction = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "' + $schedulePath + '"') -WorkingDirectory $projectRoot
$taskTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 5)
$taskSettings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 2) -StartWhenAvailable
Register-ScheduledTask -TaskName $taskName -Action $taskAction -Trigger $taskTrigger -Settings $taskSettings -Description 'Runs local FACT Studio production while this Windows user is logged in.' -Force | Out-Null
Write-Output 'Windows task installed. Set your production time and enable the schedule in FACT Studio.'
