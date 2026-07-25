<?php

namespace Tests\Feature\Services;

use App\Exceptions\AiraloApiException;
use App\Services\AiraloApiClientService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiraloApiClientServiceTest extends TestCase
{
    #[Test]
    public function it_fetches_packages_successfully(): void
    {
        config([
            'services.airalo.base_url' => 'https://partners.airalo.test',
            'services.airalo.client_id' => 'client-id-test',
            'services.airalo.client_secret' => 'client-secret-test',
            'cache.default' => 'array',
        ]);

        Cache::forget('airalo:oauth:access_token');

        Http::fake([
            'https://partners.airalo.test/v2/token' => Http::response([
                'access_token' => 'token-initial',
                'token_type' => 'Bearer',
                'expires_in' => 86400,
            ], 200),
            'https://partners.airalo.test/v2/packages*' => Http::response([
                'data' => [
                    ['id' => 'pkg-1', 'name' => 'Guinea 1GB'],
                ],
            ], 200),
        ]);

        $service = app(AiraloApiClientService::class);
        $response = $service->getPackages(['limit' => 1]);

        $this->assertIsArray($response);
        $this->assertSame('pkg-1', $response['data'][0]['id'] ?? null);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://partners.airalo.test/v2/packages?limit=1'
                && $request->hasHeader('Authorization', 'Bearer token-initial')
                && $request->hasHeader('Accept', 'application/json');
        });
    }

    #[Test]
    public function it_retries_once_when_packages_call_returns_401_then_succeeds(): void
    {
        config([
            'services.airalo.base_url' => 'https://partners.airalo.test',
            'services.airalo.client_id' => 'client-id-test',
            'services.airalo.client_secret' => 'client-secret-test',
            'cache.default' => 'array',
        ]);

        Cache::forget('airalo:oauth:access_token');

        Http::fakeSequence()
            ->push([
                'access_token' => 'token-old',
                'token_type' => 'Bearer',
                'expires_in' => 86400,
            ], 200)
            ->push([
                'message' => 'Unauthorized',
            ], 401)
            ->push([
                'access_token' => 'token-new',
                'token_type' => 'Bearer',
                'expires_in' => 86400,
            ], 200)
            ->push([
                'data' => [
                    ['id' => 'pkg-retry-ok'],
                ],
            ], 200);

        $service = app(AiraloApiClientService::class);
        $response = $service->getPackages();

        $this->assertSame('pkg-retry-ok', $response['data'][0]['id'] ?? null);
        Http::assertSentCount(4);
    }

    #[Test]
    public function it_throws_airalo_exception_for_422_validation_error(): void
    {
        config([
            'services.airalo.base_url' => 'https://partners.airalo.test',
            'services.airalo.client_id' => 'client-id-test',
            'services.airalo.client_secret' => 'client-secret-test',
            'cache.default' => 'array',
        ]);

        Cache::forget('airalo:oauth:access_token');

        Http::fake([
            'https://partners.airalo.test/v2/token' => Http::response([
                'access_token' => 'token-valid',
                'token_type' => 'Bearer',
                'expires_in' => 86400,
            ], 200),
            'https://partners.airalo.test/v2/packages*' => Http::response([
                'message' => 'Invalid query parameter',
            ], 422),
        ]);

        $service = app(AiraloApiClientService::class);

        $this->expectException(AiraloApiException::class);
        $this->expectExceptionMessage('validation failed (422)');

        $service->getPackages(['limit' => -1]);
    }

    #[Test]
    public function it_throws_airalo_exception_when_401_persists_after_retry(): void
    {
        config([
            'services.airalo.base_url' => 'https://partners.airalo.test',
            'services.airalo.client_id' => 'client-id-test',
            'services.airalo.client_secret' => 'client-secret-test',
            'cache.default' => 'array',
        ]);

        Cache::forget('airalo:oauth:access_token');

        Http::fakeSequence()
            ->push([
                'access_token' => 'token-old',
                'token_type' => 'Bearer',
                'expires_in' => 86400,
            ], 200)
            ->push([
                'message' => 'Unauthorized',
            ], 401)
            ->push([
                'access_token' => 'token-new',
                'token_type' => 'Bearer',
                'expires_in' => 86400,
            ], 200)
            ->push([
                'message' => 'Unauthorized',
            ], 401);

        $service = app(AiraloApiClientService::class);

        $this->expectException(AiraloApiException::class);
        $this->expectExceptionMessage('token expired after retry (401)');

        $service->getPackages();
    }
}
