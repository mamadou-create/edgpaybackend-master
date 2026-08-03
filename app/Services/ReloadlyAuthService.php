<?php

namespace App\Services;

use App\Enums\ReloadlyProduct;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\RequestException;

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
            Log::error('Reloadly configuration is incomplete.', [
                'product' => $product->value,
                'mode' => config('services.reloadly.mode', 'sandbox'),
                'client_id_present' => !empty($clientId),
                'client_secret_present' => !empty($clientSecret),
                'auth_url' => config('services.reloadly.auth_url'),
                'product_base_url' => $product->baseUrl(),
                'hint' => 'Vérifier RELOADLY_CLIENT_ID, RELOADLY_CLIENT_SECRET et le cache de configuration Laravel.',
            ]);
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
                    'mode' => config('services.reloadly.mode', 'sandbox'),
                    'auth_url' => config('services.reloadly.auth_url'),
                    'product_base_url' => $product->baseUrl(),
                    'response_message' => $response->json('message'),
                    'response_error' => $response->json('error'),
                ]);
                return '';
            }

            $token = (string) $response->json('access_token', '');
            $expiresIn = (int) $response->json('expires_in', 3600);

            if ($token !== '') {
                Cache::put($cacheKey, $token, max(1, (int) ($expiresIn * 0.95)));
            }

            return $token;
        } catch (RequestException $exception) {
            Log::error('Reloadly product authentication HTTP error.', [
                'product' => $product->value,
                'mode' => config('services.reloadly.mode', 'sandbox'),
                'status' => $exception->response?->status(),
                'response_message' => $exception->response?->json('message'),
                'response_error' => $exception->response?->json('error'),
                'response_error_description' => $exception->response?->json('error_description'),
            ]);

            return '';
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