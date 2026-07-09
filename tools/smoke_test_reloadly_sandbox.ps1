Param(
    [string]$BaseUrl = "http://127.0.0.1:8000",
    [string]$Login,
    [string]$Password,
    [string]$PaymentProvider = "ORANGE",
    [string]$PaymentChannel = "MOBILE_MONEY",
    [string]$PayerMsisdn = "622123456",
    [string]$RecipientPhone = "622123456",
    [string]$RecipientCountryCode = "GN",
    [int]$OperatorId = 201,
    [string]$OperatorName = "Orange Guinea",
    [double]$Amount = 10000,
    [string]$Currency = "GNF",
    [switch]$SkipReloadlyAuth,
    [switch]$SkipWebhook,
    [switch]$InsecureAllowUnsignedWebhook,
    [string]$WebhookSecret
)

$ErrorActionPreference = "Stop"

function Step([string]$message) {
    Write-Host "`n==> $message" -ForegroundColor Cyan
}

function Fail([string]$message) {
    Write-Host "[FAIL] $message" -ForegroundColor Red
    exit 1
}

function Ok([string]$message) {
    Write-Host "[OK] $message" -ForegroundColor Green
}

function To-JsonBody($obj) {
    return ($obj | ConvertTo-Json -Depth 10)
}

function Invoke-Api(
    [string]$Method,
    [string]$Url,
    [hashtable]$Headers,
    $Body = $null
) {
    if ($null -ne $Body) {
        return Invoke-RestMethod -Method $Method -Uri $Url -Headers $Headers -Body (To-JsonBody $Body) -ContentType "application/json"
    }

    return Invoke-RestMethod -Method $Method -Uri $Url -Headers $Headers
}

function New-CorrelationId {
    return [guid]::NewGuid().ToString()
}

function New-IdempotencyKey {
    return [guid]::NewGuid().ToString("N")
}

function New-HmacSha256Hex([string]$secret, [string]$payload) {
    $hmac = New-Object System.Security.Cryptography.HMACSHA256
    $hmac.Key = [System.Text.Encoding]::UTF8.GetBytes($secret)
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($payload)
    $hash = $hmac.ComputeHash($bytes)
    return -join ($hash | ForEach-Object { $_.ToString("x2") })
}

if ([string]::IsNullOrWhiteSpace($Login) -or [string]::IsNullOrWhiteSpace($Password)) {
    Fail "Renseigne -Login et -Password (compte API actif)."
}

Step "Authentification API locale (/api/v1/login)"
$loginPayload = @{
    login = $Login
    password = $Password
}

$loginResp = Invoke-Api -Method "POST" -Url "$BaseUrl/api/v1/login" -Headers @{} -Body $loginPayload
$token = $loginResp.data.access_token
if ([string]::IsNullOrWhiteSpace($token)) {
    Fail "Token JWT absent dans la réponse login."
}
Ok "JWT obtenu"

$authHeaders = @{
    Authorization = "Bearer $token"
}

if (-not $SkipReloadlyAuth) {
    Step "Test auth Reloadly proxyée (/api/v1/reloadly/auth)"
    $corr = New-CorrelationId
    $idem = New-IdempotencyKey
    $headers = $authHeaders.Clone()
    $headers["X-Correlation-ID"] = $corr
    $headers["X-Idempotency-Key"] = $idem

    $reloadlyAuth = Invoke-Api -Method "POST" -Url "$BaseUrl/api/v1/reloadly/auth" -Headers $headers
    if (-not $reloadlyAuth.success) {
        Fail "Auth Reloadly échouée via API locale."
    }

    if ([string]::IsNullOrWhiteSpace($reloadlyAuth.data.access_token)) {
        Fail "Auth Reloadly ok=false/aucun access_token remonté."
    }

    Ok "Auth Reloadly validée"
}

Step "Création intention airtime (/api/v1/purchase/airtime/intent)"
$intentCorr = New-CorrelationId
$intentIdem = New-IdempotencyKey
$intentHeaders = $authHeaders.Clone()
$intentHeaders["X-Correlation-ID"] = $intentCorr
$intentHeaders["X-Idempotency-Key"] = $intentIdem

$intentPayload = @{
    payment_provider = $PaymentProvider
    payment_channel = $PaymentChannel
    payer_msisdn = $PayerMsisdn
    recipient_phone = $RecipientPhone
    recipient_country_code = $RecipientCountryCode
    operator_id = $OperatorId
    operator_name = $OperatorName
    amount = $Amount
    currency = $Currency
    expires_in_minutes = 15
}

$intentResp = Invoke-Api -Method "POST" -Url "$BaseUrl/api/v1/purchase/airtime/intent" -Headers $intentHeaders -Body $intentPayload
if (-not $intentResp.success) {
    Fail "Échec création intention airtime."
}

$paymentReference = $intentResp.data.payment_reference
$paymentTransactionId = $intentResp.data.payment_transaction_id
if ([string]::IsNullOrWhiteSpace($paymentReference) -or [string]::IsNullOrWhiteSpace($paymentTransactionId)) {
    Fail "Références intent incomplètes (payment_reference/payment_transaction_id)."
}
Ok "Intent créée: payment_reference=$paymentReference"

if (-not $SkipWebhook) {
    Step "Simulation webhook paiement confirmé (/api/v1/webhook/payments/{provider})"

    $webhookPayloadObj = @{
        event = "payment.updated"
        event_id = "evt-" + (New-IdempotencyKey)
        payment_reference = $paymentReference
        provider_payment_id = "prov-" + (New-IdempotencyKey)
        status = "SUCCESS"
        amount = $Amount
        currency = $Currency
    }

    $webhookJson = To-JsonBody $webhookPayloadObj
    $webhookHeaders = @{
        "Content-Type" = "application/json"
    }

    if (-not $InsecureAllowUnsignedWebhook) {
        if ([string]::IsNullOrWhiteSpace($WebhookSecret)) {
            Fail "-WebhookSecret requis (ou utiliser -InsecureAllowUnsignedWebhook en local seulement)."
        }

        $signatureHex = New-HmacSha256Hex -secret $WebhookSecret -payload $webhookJson
        $webhookHeaders["X-Signature"] = "sha256=$signatureHex"
    }

    $webhookResp = Invoke-RestMethod -Method "POST" -Uri "$BaseUrl/api/v1/webhook/payments/$PaymentProvider" -Headers $webhookHeaders -Body $webhookJson -ContentType "application/json"

    if (-not $webhookResp.success) {
        Fail "Webhook non traité avec succès."
    }

    Ok "Webhook confirmé traité"
}

Step "Vérification ops santé + logs"
$opsHeaders = $authHeaders.Clone()
$opsHealth = Invoke-Api -Method "GET" -Url "$BaseUrl/api/v1/ops/payments/health" -Headers $opsHeaders
if (-not $opsHealth.success) {
    Fail "Health ops inaccessible ou erreur. Vérifie les permissions credits.manage."
}
Ok "Ops health disponible"

$opsLogs = Invoke-Api -Method "GET" -Url "$BaseUrl/api/v1/ops/payments/logs?per_page=20&page=1" -Headers $opsHeaders
if (-not $opsLogs.success) {
    Fail "Logs ops indisponibles. Vérifie les permissions credits.manage."
}

$logCount = ($opsLogs.data.items | Measure-Object).Count
Ok "Logs ops récupérés ($logCount éléments sur la page)"

Write-Host "`nSmoke test terminé avec succès." -ForegroundColor Green
Write-Host "Résumé:" -ForegroundColor Yellow
Write-Host " - payment_reference: $paymentReference"
Write-Host " - payment_transaction_id: $paymentTransactionId"
Write-Host " - correlation_id intent: $intentCorr"
