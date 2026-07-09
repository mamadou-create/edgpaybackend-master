<?php

namespace App\Services;

use App\Interfaces\ReloadlyServiceInterface;
use App\Models\ApiLog;
use App\Models\OperatorCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReloadlyService implements ReloadlyServiceInterface
{
    private string $authBaseUrl;
    private string $topupsBaseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $audience;
    private int $timeoutSeconds;

    public function __construct()
    {
        $this->authBaseUrl = rtrim((string) config('services.reloadly.auth_base_url', ''), '/');
        $this->topupsBaseUrl = rtrim((string) config('services.reloadly.topups_base_url', ''), '/');
        $this->clientId = (string) config('services.reloadly.client_id', '');
        $this->clientSecret = (string) config('services.reloadly.client_secret', '');
        $this->audience = (string) config('services.reloadly.audience', 'https://topups.reloadly.com');
        $this->timeoutSeconds = (int) config('services.reloadly.timeout', 15);
    }

    public function authenticate(): array
    {
        if ($this->authBaseUrl === '' || $this->clientId === '' || $this->clientSecret === '') {
            return $this->errorResult(500, 'RELOADLY_NOT_CONFIGURED', 'Configuration Reloadly incomplète.');
        }

        $cached = Cache::get('reloadly:access_token');
        if (is_string($cached) && $cached !== '') {
            return [
                'success' => true,
                'status' => 200,
                'message' => 'Token Reloadly récupéré depuis le cache',
                'data' => ['access_token' => $cached],
            ];
        }

        $url = $this->authBaseUrl . '/oauth/token';
        $payload = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'client_credentials',
            'audience' => $this->audience,
        ];

        $start = microtime(true);
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout($this->timeoutSeconds)
                ->post($url, $payload);

            $duration = (int) ((microtime(true) - $start) * 1000);
            $body = $response->json();

            $this->logApiCall('reloadly-auth', '/oauth/token', 'POST', $response->status(), $duration, $payload, $body);

            if (!$response->successful() || !isset($body['access_token'])) {
                return $this->errorResult(
                    $response->status(),
                    'RELOADLY_AUTH_FAILED',
                    'Échec de l\'authentification Reloadly.',
                    $body
                );
            }

            $ttl = max(60, ((int) ($body['expires_in'] ?? 3600)) - 60);
            Cache::put('reloadly:access_token', (string) $body['access_token'], $ttl);

            return [
                'success' => true,
                'status' => 200,
                'message' => 'Authentification Reloadly réussie',
                'data' => [
                    'access_token' => (string) $body['access_token'],
                    'expires_in' => (int) ($body['expires_in'] ?? 3600),
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('Reloadly auth exception', ['error' => $e->getMessage()]);

            return $this->errorResult(500, 'RELOADLY_AUTH_EXCEPTION', 'Erreur lors de l\'authentification Reloadly.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function detectOperator(string $phone, string $countryCode = 'GN'): array
    {
        $phone = preg_replace('/\s+/', '', $phone) ?? $phone;
        if ($phone === '') {
            return $this->errorResult(422, 'INVALID_PHONE_NUMBER', 'Numéro de téléphone invalide.');
        }

        $cacheKey = sprintf('reloadly:operator:%s:%s', strtoupper($countryCode), $phone);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return [
                'success' => true,
                'status' => 200,
                'message' => 'Opérateur détecté depuis le cache',
                'data' => $cached,
            ];
        }

        $result = $this->authorizedGet('/operators/auto-detect/phone/' . urlencode($phone), [
            'countryCode' => strtoupper($countryCode),
        ]);

        if (!$result['success']) {
            return $result;
        }

        $operator = $result['data'];
        Cache::put($cacheKey, $operator, now()->addMinutes(30));

        if (isset($operator['id'])) {
            OperatorCache::updateOrCreate(
                [
                    'provider' => 'RELOADLY',
                    'operator_code' => (string) $operator['id'],
                    'country_code' => strtoupper($countryCode),
                ],
                [
                    'operator_name' => (string) ($operator['name'] ?? 'Unknown'),
                    'network' => (string) ($operator['bundle'] ?? ''),
                    'supports_airtime' => (bool) ($operator['supportsLocalAmounts'] ?? true),
                    'supports_data' => true,
                    'raw_payload' => $operator,
                    'last_synced_at' => now(),
                    'expires_at' => now()->addHours(6),
                ]
            );
        }

        return [
            'success' => true,
            'status' => 200,
            'message' => 'Opérateur détecté avec succès',
            'data' => $operator,
        ];
    }

    public function getDataPlans(int $operatorId, ?string $recipientPhone = null): array
    {
        $query = ['operatorId' => $operatorId];
        if ($recipientPhone !== null && trim($recipientPhone) !== '') {
            $query['suggestedAmountsMap'] = 'true';
        }

        $result = $this->authorizedGet('/operators/' . $operatorId . '/bundles', $query);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'status' => 200,
            'message' => 'Forfaits data récupérés avec succès',
            'data' => $result['data'],
        ];
    }

    public function topupAirtime(array $payload): array
    {
        $required = ['operatorId', 'amount', 'useLocalAmount', 'customIdentifier', 'recipientPhone'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $payload)) {
                return $this->errorResult(422, 'VALIDATION_ERROR', 'Champ requis manquant: ' . $field);
            }
        }

        $result = $this->authorizedPost('/topups', $payload);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'status' => 200,
            'message' => 'Recharge airtime exécutée avec succès',
            'data' => $result['data'],
        ];
    }

    public function topupData(array $payload): array
    {
        $required = ['operatorId', 'amount', 'useLocalAmount', 'customIdentifier', 'recipientPhone', 'bundleId'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $payload)) {
                return $this->errorResult(422, 'VALIDATION_ERROR', 'Champ requis manquant: ' . $field);
            }
        }

        $result = $this->authorizedPost('/topups', $payload);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'status' => 200,
            'message' => 'Recharge data exécutée avec succès',
            'data' => $result['data'],
        ];
    }

    public function getPromotions(int $operatorId): array
    {
        $result = $this->authorizedGet('/promotions', ['operatorId' => $operatorId]);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'status' => 200,
            'message' => 'Promotions Reloadly récupérées avec succès',
            'data' => $result['data'],
        ];
    }

    public function getCommissions(?int $operatorId = null): array
    {
        $query = [];
        if ($operatorId !== null) {
            $query['operatorId'] = $operatorId;
        }

        $result = $this->authorizedGet('/commissions', $query);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'status' => 200,
            'message' => 'Commissions Reloadly récupérées avec succès',
            'data' => $result['data'],
        ];
    }

    public function verifyTransaction(int|string $reloadlyTransactionId): array
    {
        $result = $this->authorizedGet('/topups/' . urlencode((string) $reloadlyTransactionId));
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'status' => 200,
            'message' => 'Transaction Reloadly vérifiée avec succès',
            'data' => $result['data'],
        ];
    }

    private function authorizedGet(string $endpoint, array $query = []): array
    {
        return $this->authorizedRequest('GET', $endpoint, [], $query);
    }

    private function authorizedPost(string $endpoint, array $payload = []): array
    {
        return $this->authorizedRequest('POST', $endpoint, $payload, []);
    }

    private function authorizedRequest(string $method, string $endpoint, array $payload = [], array $query = []): array
    {
        $auth = $this->authenticate();
        if (!$auth['success']) {
            return $auth;
        }

        $token = (string) ($auth['data']['access_token'] ?? '');
        if ($token === '') {
            return $this->errorResult(500, 'RELOADLY_TOKEN_MISSING', 'Token Reloadly introuvable.');
        }

        if ($this->topupsBaseUrl === '') {
            return $this->errorResult(500, 'RELOADLY_NOT_CONFIGURED', 'Topups base URL Reloadly manquante.');
        }

        $url = $this->topupsBaseUrl . $endpoint;
        $start = microtime(true);

        try {
            $request = Http::withHeaders([
                // Reloadly topups API expects this media type; generic application/json can return 406.
                'Accept' => 'application/com.reloadly.topups-v1+json',
            ])
                ->withToken($token)
                ->timeout($this->timeoutSeconds);

            $response = match (strtoupper($method)) {
                'GET' => $request->get($url, $query),
                'POST' => $request->post($url, $payload),
                default => throw new \RuntimeException('Méthode HTTP non supportée: ' . $method),
            };

            $duration = (int) ((microtime(true) - $start) * 1000);
            $body = $response->json();

            $this->logApiCall('reloadly-topups', $endpoint, strtoupper($method), $response->status(), $duration, [
                'query' => $query,
                'payload' => $payload,
            ], $body);

            if (!$response->successful()) {
                return $this->errorResult(
                    $response->status(),
                    'RELOADLY_PROVIDER_ERROR',
                    'Erreur fournisseur Reloadly.',
                    is_array($body) ? $body : ['raw' => $response->body()]
                );
            }

            return [
                'success' => true,
                'status' => $response->status(),
                'message' => 'Appel Reloadly réussi',
                'data' => $body,
            ];
        } catch (\Throwable $e) {
            Log::error('Reloadly request exception', [
                'endpoint' => $endpoint,
                'method' => $method,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResult(500, 'RELOADLY_NETWORK_ERROR', 'Erreur réseau lors de l\'appel Reloadly.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function errorResult(int $status, string $code, string $message, ?array $data = null): array
    {
        return [
            'success' => false,
            'status' => $status,
            'business_code' => $code,
            'message' => $message,
            'data' => $data ?? [],
        ];
    }

    private function logApiCall(
        string $service,
        string $endpoint,
        string $method,
        int $status,
        int $durationMs,
        array $requestPayload,
        mixed $responsePayload
    ): void {
        try {
            ApiLog::create([
                'service' => $service,
                'endpoint' => $endpoint,
                'method' => $method,
                'status_code' => $status,
                'duration_ms' => $durationMs,
                'correlation_id' => (string) (
                    request()->attributes->get('correlation_id')
                    ?? request()->header('X-Correlation-ID')
                    ?? Str::uuid()->toString()
                ),
                'idempotency_key' => (string) request()->header('X-Idempotency-Key', ''),
                'request_ip' => request()->ip(),
                'request_headers' => request()->headers->all(),
                'request_body' => $requestPayload,
                'response_body' => is_array($responsePayload) ? $responsePayload : ['raw' => (string) $responsePayload],
                'error_message' => $status >= 400 ? 'Provider/API call failed' : null,
                'user_id' => request()->user()?->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Unable to persist api_logs entry', ['error' => $e->getMessage()]);
        }
    }
}
