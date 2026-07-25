<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiraloAuthService
{
    private const CACHE_KEY_PREFIX = 'airalo:oauth:access_token';
    private const DEFAULT_CACHE_TTL_SECONDS = 3300;

    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $environment;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.airalo.base_url', ''), '/');
        $this->clientId = trim((string) config('services.airalo.client_id', ''));
        $this->clientSecret = trim((string) config('services.airalo.client_secret', ''));
        $this->environment = strtolower(trim((string) config('services.airalo.env', 'production')));
    }

    /**
     * Returns a valid Airalo OAuth2 access token.
     *
     * @throws RuntimeException
     */
    public function getAccessToken(bool $forceRefresh = false): string
    {
        $this->assertConfigurationIsValid();

        if (!$forceRefresh) {
            $cachedToken = Cache::get($this->cacheKey());
            if (is_string($cachedToken) && $cachedToken !== '') {
                return $cachedToken;
            }
        }

        return $this->requestAndCacheToken();
    }

    public function invalidateTokenCache(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * @throws RuntimeException
     */
    private function requestAndCacheToken(): string
    {
        $url = $this->baseUrl . '/v2/token';

        try {
            $response = Http::acceptJson()
                ->asForm()
                ->timeout(15)
                ->post($url, [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type' => 'client_credentials',
                ]);

            $payload = $response->json();

            if (!$response->successful()) {
                if (in_array($response->status(), [400, 401], true)) {
                    throw new RuntimeException('Airalo OAuth authentication failed: invalid client credentials.');
                }

                $apiMessage = is_array($payload)
                    ? (string) ($payload['message'] ?? $payload['error_description'] ?? $payload['error'] ?? 'Unknown Airalo error')
                    : 'Unknown Airalo error';

                throw new RuntimeException(
                    sprintf('Airalo OAuth request failed with status %d: %s', $response->status(), $apiMessage)
                );
            }

            $accessToken = $this->extractAccessToken($payload);
            if ($accessToken === '') {
                throw new RuntimeException('Airalo OAuth response is invalid: access_token is missing.');
            }

            $ttlSeconds = $this->extractTokenCacheTtlSeconds($payload);
            Cache::put($this->cacheKey(), $accessToken, now()->addSeconds($ttlSeconds));

            return $accessToken;
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'Airalo OAuth request failed due to a network or unexpected error: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /**
     * @throws RuntimeException
     */
    private function assertConfigurationIsValid(): void
    {
        if ($this->baseUrl === '') {
            throw new RuntimeException('Airalo configuration error: AIRALO_BASE_URL is missing.');
        }

        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new RuntimeException('Airalo configuration error: AIRALO_CLIENT_ID and AIRALO_CLIENT_SECRET are required.');
        }
    }

    private function extractAccessToken(mixed $payload): string
    {
        if (!is_array($payload)) {
            return '';
        }

        $topLevel = (string) ($payload['access_token'] ?? '');
        if ($topLevel !== '') {
            return $topLevel;
        }

        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            return (string) ($data['access_token'] ?? '');
        }

        return '';
    }

    private function cacheKey(): string
    {
        $suffix = $this->environment !== '' ? $this->environment : 'production';

        return self::CACHE_KEY_PREFIX . ':' . $suffix;
    }

    private function extractTokenCacheTtlSeconds(mixed $payload): int
    {
        $expiresIn = null;

        if (is_array($payload)) {
            $direct = $payload['expires_in'] ?? null;
            if (is_numeric($direct)) {
                $expiresIn = (int) $direct;
            }

            if ($expiresIn === null && isset($payload['data']) && is_array($payload['data'])) {
                $nested = $payload['data']['expires_in'] ?? null;
                if (is_numeric($nested)) {
                    $expiresIn = (int) $nested;
                }
            }
        }

        if ($expiresIn === null || $expiresIn <= 0) {
            return self::DEFAULT_CACHE_TTL_SECONDS;
        }

        // Buffer d'une minute pour eviter d'utiliser un token en limite d'expiration.
        $ttlWithBuffer = $expiresIn - 60;

        // Evite un TTL trop court en cas de reponse API anormale.
        if ($ttlWithBuffer < 300) {
            return 300;
        }

        return $ttlWithBuffer;
    }
}
