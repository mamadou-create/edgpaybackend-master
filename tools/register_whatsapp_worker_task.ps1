param(
    [string]$TaskName = 'EdgPayWhatsAppWorker',
    [string]$Queue = 'whatsapp,default'
)

$ErrorActionPreference = 'Stop'

$scriptPath = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot 'start_whatsapp_worker.ps1'))

if (-not (Test-Path $scriptPath)) {
    Write-Error "Script worker introuvable: $scriptPath"
    exit 1
}

$taskArguments = "-NoProfile -ExecutionPolicy Bypass -File `"$scriptPath`" -Queue `"$Queue`""
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $taskArguments
$trigger = New-ScheduledTaskTrigger -AtLogOn
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew

try {
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Description 'Worker Laravel pour les messages WhatsApp sortants EdgPay' -Force | Out-Null
    Write-Host "Tâche planifiée enregistrée: $TaskName" -ForegroundColor Green
}
catch {
    Write-Error "Impossible d'enregistrer la tâche planifiée $TaskName. Exécuter PowerShell en mode administrateur ou autoriser la création de tâches planifiées pour cet utilisateur."
    throw
}