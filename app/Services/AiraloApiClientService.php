<?php

namespace App\Services;

use App\Exceptions\AiraloApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class AiraloApiClientService
{
    private string $baseUrl;

    public function __construct(private readonly AiraloAuthService $authService)
    {
        $this->baseUrl = rtrim((string) config('services.airalo.base_url', ''), '/');
    }

    /**
     * @throws AiraloApiException
     */
    public function get(string $endpoint, array $query = []): array
    {
        return $this->request('GET', $endpoint, $query);
    }

    /**
     * @throws AiraloApiException
     */
    public function post(string $endpoint, array $payload = []): array
    {
        return $this->request('POST', $endpoint, $payload);
    }

    /**
     * First business method for Airalo eSIM catalog.
     *
     * @throws AiraloApiException
     */
    public function getPackages(array $query = []): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = max(1, min(100, (int) ($query['limit'] ?? 100)));
        $allPackages = [];
        $firstResponse = null;

        do {
            $response = $this->get('/v2/packages', [
                ...$query,
                'page' => $page,
                'limit' => $limit,
            ]);
            $firstResponse ??= $response;

            $data = $response['data'] ?? [];
            if (!is_array($data)) {
                break;
            }
            $allPackages = [...$allPackages, ...$data];

            $pagination = $response['meta'] ?? $response['pagination'] ?? [];
            $lastPage = is_array($pagination) && is_numeric($pagination['last_page'] ?? null)
                ? (int) $pagination['last_page']
                : null;
            $hasNextPage = is_array($pagination) && ($pagination['has_next_page'] ?? false) === true;

            if ($lastPage !== null) {
                $shouldContinue = $page < $lastPage;
            } else {
                $shouldContinue = $hasNextPage;
            }
            $page++;
        } while ($shouldContinue && $page <= 200);

        return [
            ...($firstResponse ?? []),
            'data' => $allPackages,
        ];
    }

    /**
     * Retrieves one official Airalo package scope through filter[type].
     *
     * @throws AiraloApiException
     */
    public function getPackagesByType(string $type): array
    {
        $normalizedType = strtolower(trim($type));
        if ($normalizedType === 'regional') {
            return ['data' => []];
        }

        if (!in_array($normalizedType, ['local', 'global'], true)) {
            throw new AiraloApiException(500, 'Airalo package type is invalid.');
        }

        return $this->getPackages(['filter[type]' => $normalizedType]);
    }

    /**
     * @throws AiraloApiException
     */
    public function createOrder(string $packageId, int $quantity = 1, ?string $description = null): array
    {
        $payload = [
            'package_id' => $packageId,
            'quantity' => max(1, $quantity),
        ];

        if ($description !== null && trim($description) !== '') {
            $payload['description'] = trim($description);
        }

        return $this->post('/v2/orders', $payload);
    }

    /**
     * @throws AiraloApiException
     */
    public function getOrder(string $orderId): array
    {
        return $this->get('/v2/orders/' . rawurlencode($orderId), ['include' => 'sims']);
    }

    /**
     * @throws AiraloApiException
     */
    public function getSim(string $iccid): array
    {
        return $this->get('/v2/sims/' . rawurlencode($iccid));
    }

    /**
     * @throws AiraloApiException
     */
    public function getSimInstructions(string $iccid, string $language = 'fr'): array
    {
        $normalizedLanguage = trim($language);

        return $this->request(
            'GET',
            '/v2/sims/' . rawurlencode($iccid) . '/instructions',
            [],
            false,
            $normalizedLanguage === '' ? [] : ['Accept-Language' => $normalizedLanguage],
        );
    }

    /**
     * Airalo limits SIM endpoint requests to ten per minute for each ICCID.
     *
     * @throws AiraloApiException
     */
    public function getSimPackages(string $iccid): array
    {
        $cacheKey = 'airalo:sim-packages:' . hash_hmac(
            'sha256',
            $iccid,
            (string) config('app.key'),
        );

        return Cache::remember($cacheKey, now()->addMinute(), function () use ($iccid): array {
            return $this->get('/v2/sims/' . rawurlencode($iccid) . '/packages');
        });
    }

    /**
     * @throws AiraloApiException
     */
    private function request(
        string $method,
        string $endpoint,
        array $data = [],
        bool $hasRetriedAfterUnauthorized = false,
        array $headers = [],
    ): array
    {
        if ($this->baseUrl === '') {
            throw new AiraloApiException(500, 'Airalo configuration error: AIRALO_BASE_URL is missing.');
        }

        $token = $this->authService->getAccessToken($hasRetriedAfterUnauthorized);
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        try {
            $request = $this->buildRequest($token, $headers);
            try {
                $response = match (strtoupper($method)) {
                    'GET' => $request->get($url, $data),
                    'POST' => $request->post($url, $data),
                    default => throw new AiraloApiException(500, 'Airalo API client error: unsupported HTTP method ' . $method),
                };
            } catch (RequestException $exception) {
                $response = $exception->response;
            }

            $this->logHttpExchange($method, $url, $data, $response);

            if ($response->status() === 401 && !$hasRetriedAfterUnauthorized) {
                $this->authService->invalidateTokenCache();

                return $this->request($method, $endpoint, $data, true, $headers);
            }

            if (!$response->successful()) {
                throw $this->mapHttpErrorToException($response, $endpoint);
            }

            $json = $response->json();
            if (!is_array($json)) {
                return [];
            }

            return $json;
        } catch (AiraloApiException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::warning('Airalo network request failed.', [
                'endpoint' => $endpoint,
                'method' => strtoupper($method),
                'url' => $url,
                'payload' => $this->redactSensitivePayload($data),
                'exception_class' => $exception::class,
            ]);
            throw new AiraloApiException(
                500,
                'Airalo API request failed due to a network or unexpected error.',
                'Network or unexpected upstream error.',
                [],
                $exception
            );
        }
    }

    private function buildRequest(string $token, array $headers = []): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->withHeaders($headers)
            ->timeout(max(1, (int) config('services.airalo.timeout', 20)))
            ->retry(
                max(1, (int) config('services.airalo.retry_attempts', 3)),
                100,
                static function (\Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    return $exception instanceof RequestException
                        && in_array($exception->response->status(), [429, 500, 502, 503, 504], true);
                },
                throw: true,
            );
    }

    private function mapHttpErrorToException(Response $response, string $endpoint): AiraloApiException
    {
        $status = $response->status();
        $payload = $response->json();
        $payloadArray = is_array($payload) ? $payload : [];
        $safePayload = $this->redactSensitivePayload($payloadArray);

        $apiMessage = $this->extractApiMessage($safePayload);
        $safeApiMessage = $this->redactSensitiveText($apiMessage);

        $message = match ($status) {
            400 => 'Airalo request is invalid (400): ' . $safeApiMessage,
            401 => 'Airalo authentication failed or token expired after retry (401): ' . $safeApiMessage,
            402 => 'Airalo insufficient credit (402): ' . $safeApiMessage,
            422 => 'Airalo validation failed (422): ' . $safeApiMessage,
            500 => 'Airalo server error (500): ' . $safeApiMessage,
            default => sprintf('Airalo API error (%d) on %s: %s', $status, $endpoint, $safeApiMessage),
        };

        Log::warning('Airalo API error.', [
            'endpoint' => $endpoint,
            'status' => $status,
            'code' => 'AIRALO_' . $status,
            'airalo_response' => $safePayload,
        ]);

        return new AiraloApiException($status, $message, $safeApiMessage, $safePayload);
    }

    /**
     * Logs diagnostics without exposing OAuth tokens or eSIM activation data.
     *
     * @param array<string, mixed> $payload
     */
    private function logHttpExchange(string $method, string $url, array $payload, Response $response): void
    {
        $body = $response->json();
        $safeBody = is_array($body)
            ? $this->redactSensitivePayload($body)
            : $this->redactSensitiveText($response->body());

        Log::info('Airalo HTTP exchange', [
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer [REDACTED]',
            ],
            'payload' => $this->redactSensitivePayload($payload),
            'status' => $response->status(),
            'response_body' => $safeBody,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractApiMessage(array $payload): string
    {
        foreach (['message', 'reason', 'error_description', 'error', 'detail', 'title'] as $key) {
            $value = $payload['meta'][$key] ?? $payload[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        $errors = $payload['errors'] ?? $payload['meta']['errors'] ?? null;
        if (is_array($errors)) {
            $messages = [];
            array_walk_recursive($errors, static function (mixed $value) use (&$messages): void {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $messages[] = trim((string) $value);
                }
            });

            if ($messages !== []) {
                return implode('; ', array_unique($messages));
            }
        }

        return 'Airalo did not provide an error message.';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function redactSensitivePayload(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            $lowerKey = strtolower((string) $key);

            if ($this->isSensitiveKey($lowerKey)) {
                $redacted[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redactSensitivePayload($value);
                continue;
            }

            if (is_string($value)) {
                $redacted[$key] = $this->redactSensitiveText($value);
                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    private function isSensitiveKey(string $key): bool
    {
        $sensitiveKeys = [
            'client_secret',
            'access_token',
            'refresh_token',
            'authorization',
            'token',
            'qrcode',
            'qrcode_url',
            'qr_code_url',
            'ac_code',
            'activation_code',
            'matching_id',
            'iccid',
            'smdp_address',
            'sm_dp_address',
            'direct_address',
            'lpa',
            'direct_apple_installation_url',
            'smdp_address_and_activation_code',
            'qr_code_data',
            'qr_code',
        ];

        return in_array($key, $sensitiveKeys, true);
    }

    private function redactSensitiveText(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $patterns = [
            '/(access[_-]?token\s*[:=]\s*)([^\s,;]+)/i',
            '/(refresh[_-]?token\s*[:=]\s*)([^\s,;]+)/i',
            '/(client[_-]?secret\s*[:=]\s*)([^\s,;]+)/i',
            '/(authorization\s*[:=]\s*bearer\s+)([^\s,;]+)/i',
        ];

        $redacted = $value;
        foreach ($patterns as $pattern) {
            $redacted = (string) preg_replace($pattern, '$1[REDACTED]', $redacted);
        }

        return $redacted;
    }
}
