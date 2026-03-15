param(
    [string]$BaseUrl = 'http://127.0.0.1:8000',
    [string]$EnvFile = (Join-Path $PSScriptRoot '..\.env'),
    [string]$Phone = '622555777',
    [string]$Message = '1'
)

$resolvedEnvFile = [System.IO.Path]::GetFullPath($EnvFile)

if (-not (Test-Path $resolvedEnvFile)) {
    Write-Error "Fichier .env introuvable: $resolvedEnvFile"
    exit 1
}

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

$verifyToken = [string]$values['WHATSAPP_VERIFY_TOKEN']
$appSecret = [string]$values['WHATSAPP_APP_SECRET']

if ([string]::IsNullOrWhiteSpace($verifyToken) -or [string]::IsNullOrWhiteSpace($appSecret)) {
    Write-Error 'WHATSAPP_VERIFY_TOKEN ou WHATSAPP_APP_SECRET manquant dans .env.'
    exit 2
}

$verifyUrl = "$BaseUrl/api/v1/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=$verifyToken&hub.challenge=424242"

try {
    $verifyResponse = Invoke-WebRequest -Uri $verifyUrl -Method Get -UseBasicParsing
} catch {
    Write-Error "Echec vérification webhook GET: $($_.Exception.Message)"
    exit 3
}

if ($verifyResponse.StatusCode -ne 200 -or $verifyResponse.Content.Trim() -ne '424242') {
    Write-Error 'La vérification GET du webhook a échoué.'
    exit 4
}

$payloadObject = @{
    phone = $Phone
    message = $Message
    timestamp = [DateTime]::UtcNow.ToString('o')
}

$payloadJson = $payloadObject | ConvertTo-Json -Compress
$hmac = New-Object System.Security.Cryptography.HMACSHA256
$hmac.Key = [System.Text.Encoding]::UTF8.GetBytes($appSecret)
$signatureBytes = $hmac.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($payloadJson))
$signature = -join ($signatureBytes | ForEach-Object { $_.ToString('x2') })

$headers = @{
    'X-Hub-Signature-256' = "sha256=$signature"
}

try {
    $postResponse = Invoke-RestMethod -Uri "$BaseUrl/api/v1/webhook/whatsapp" -Method Post -Headers $headers -ContentType 'application/json' -Body $payloadJson
} catch {
    Write-Error "Echec POST webhook signé: $($_.Exception.Message)"
    exit 5
}

Write-Host 'Webhook GET verify: OK' -ForegroundColor Green
Write-Host 'Webhook POST signé: OK' -ForegroundColor Green
Write-Host ("Intent retourné: {0}" -f $postResponse.data.intent)
Write-Host ("Réponse chatbot: {0}" -f $postResponse.data.reply)

exit 0