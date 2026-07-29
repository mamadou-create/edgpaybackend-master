<?php

namespace App\Services;

use App\Enums\ReloadlyProduct;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReloadlyAuthService
{
    private const TIMEOUT_AUTH = 15;

    public function getToken(ReloadlyProduct $product): string
    {
        $cacheKey = $product->tokenCacheKey();
        $cachedToken = Cache::get($cacheKey);

        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        $clientId = config('services.reloadly.client_id');
        $clientSecret = config('services.reloadly.client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            Log::error('Reloadly product credentials are missing.');
            return '';
        }

        try {
            $response = Http::timeout(self::TIMEOUT_AUTH)
                ->retry(2, 500)
                ->post(config('services.reloadly.auth_url'), [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'client_credentials',
                    'audience' => $product->baseUrl(),
                ]);

            if (!$response->successful()) {
                Log::error('Reloadly product authentication failed.', [
                    'product' => $product->value,
                    'status' => $response->status(),
                ]);
                return '';
            }

            $token = (string) $response->json('access_token', '');
            $expiresIn = (int) $response->json('expires_in', 3600);

            if ($token !== '') {
                Cache::put($cacheKey, $token, max(1, (int) ($expiresIn * 0.95)));
            }

            return $token;
        } catch (\Throwable $exception) {
            Log::error('Reloadly product authentication error.', [
                'product' => $product->value,
                'message' => $exception->getMessage(),
            ]);

            return '';
        }
    }

    public function forgetToken(ReloadlyProduct $product): void
    {
        Cache::forget($product->tokenCacheKey());
    }
}