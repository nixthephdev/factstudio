param([switch]$Remove)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$taskName = 'FACT Studio hosted office worker'
if ($Remove) { Unregister-ScheduledTask -TaskName $taskName -Confirm:$false; Write-Output 'Hosted worker task removed.'; exit }
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "' + (Join-Path $PSScriptRoot 'run-hub-worker.ps1') + '"') -WorkingDirectory $projectRoot
$trigger = New-ScheduledTaskTrigger -AtLogOn -User ([Security.Principal.WindowsIdentity]::GetCurrent().Name)
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -StartWhenAvailable -Hidden
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Description 'Connects the office GPU studio to its hosted control room while this user is logged in.' -Force | Out-Null
Write-Output 'Hosted worker registered for sign-in. Use scripts/start-hub-worker.ps1 to start now; keep the office PC awake.'
