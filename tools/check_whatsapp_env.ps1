param(
    [string]$EnvFile = (Join-Path $PSScriptRoot '..\.env')
)

$resolvedEnvFile = [System.IO.Path]::GetFullPath($EnvFile)

if (-not (Test-Path $resolvedEnvFile)) {
    Write-Error "Fichier .env introuvable: $resolvedEnvFile"
    exit 1
}

$requiredKeys = @(
    'WHATSAPP_VERIFY_TOKEN',
    'WHATSAPP_ACCESS_TOKEN',
    'WHATSAPP_PHONE_NUMBER_ID',
    'WHATSAPP_APP_SECRET'
)

$recommendedKeys = @(
    'WHATSAPP_VALIDATE_SIGNATURE',
    'WHATSAPP_QUEUE_OUTBOUND',
    'WHATSAPP_OUTBOUND_QUEUE',
    'QUEUE_CONNECTION'
)

$values = @{}

Get-Content $resolvedEnvFile | ForEach-Object {
    $line = $_.Trim()

    if ($line -eq '' -or $line.StartsWith('#')) {
        return
    }

    $parts = $line -split '=', 2
    if ($parts.Count -eq 2) {
        $values[$parts[0].Trim()] = $parts[1].Trim().Trim('"')
    }
}

$missingRequired = @($requiredKeys | Where-Object { -not $values.ContainsKey($_) -or [string]::IsNullOrWhiteSpace($values[$_]) })
$missingRecommended = @($recommendedKeys | Where-Object { -not $values.ContainsKey($_) -or [string]::IsNullOrWhiteSpace($values[$_]) })

$validateSignatureValue = ''
if ($values.ContainsKey('WHATSAPP_VALIDATE_SIGNATURE')) {
    $validateSignatureValue = [string]$values['WHATSAPP_VALIDATE_SIGNATURE']
}

$queueOutboundValue = ''
if ($values.ContainsKey('WHATSAPP_QUEUE_OUTBOUND')) {
    $queueOutboundValue = [string]$values['WHATSAPP_QUEUE_OUTBOUND']
}

if ($missingRequired.Count -eq 0) {
    Write-Host 'Variables WhatsApp obligatoires: OK' -ForegroundColor Green
} else {
    Write-Host 'Variables WhatsApp obligatoires manquantes:' -ForegroundColor Red
    $missingRequired | ForEach-Object { Write-Host " - $_" }
}

if ($missingRecommended.Count -eq 0) {
    Write-Host 'Variables recommandées: OK' -ForegroundColor Green
} else {
    Write-Host 'Variables recommandées manquantes ou vides:' -ForegroundColor Yellow
    $missingRecommended | ForEach-Object { Write-Host " - $_" }
}

if ($validateSignatureValue.ToLowerInvariant() -ne 'true') {
    Write-Host 'Attention: WHATSAPP_VALIDATE_SIGNATURE n''est pas activé.' -ForegroundColor Yellow
}

if ($queueOutboundValue.ToLowerInvariant() -ne 'true') {
    Write-Host 'Attention: WHATSAPP_QUEUE_OUTBOUND n''est pas activé.' -ForegroundColor Yellow
}

if ($missingRequired.Count -gt 0) {
    exit 2
}

exit 0