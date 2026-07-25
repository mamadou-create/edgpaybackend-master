<?php

namespace Tests\Feature\Services;

use App\Exceptions\AiraloApiException;
use App\Services\AiraloApiClientService;
use Illuminate\Http\Client\Request;
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

        Cache::forget('airalo:oauth:access_token:production');

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
            return str_starts_with($request->url(), 'https://partners.airalo.test/v2/packages?')
                && str_contains($request->url(), 'limit=1')
                && str_contains($request->url(), 'page=1')
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

        Cache::forget('airalo:oauth:access_token:production');

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

        Cache::forget('airalo:oauth:access_token:production');

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
    public function it_keeps_airalo_meta_message_in_a_safe_exception_field(): void
    {
        config([
            'services.airalo.base_url' => 'https://partners.airalo.test',
            'services.airalo.client_id' => 'client-id-test',
            'services.airalo.client_secret' => 'client-secret-test',
            'cache.default' => 'array',
        ]);

        Cache::forget('airalo:oauth:access_token:production');
        Http::fake([
            'https://partners.airalo.test/v2/token' => Http::response([
                'data' => ['access_token' => 'token-valid', 'expires_in' => 86400],
            ], 200),
            'https://partners.airalo.test/v2/packages*' => Http::response([
                'data' => ['filter.type' => 'The selected type is not supported.'],
                'meta' => ['message' => 'the parameter is invalid'],
            ], 422),
        ]);

        try {
            app(AiraloApiClientService::class)->getPackages();
            $this->fail('Expected an AiraloApiException.');
        } catch (AiraloApiException $exception) {
            $this->assertSame(422, $exception->statusCode());
            $this->assertSame('the parameter is invalid', $exception->airaloMessage());
            $this->assertSame('AIRALO_422', $exception->errorCode());
        }
    }

    #[Test]
    public function it_retries_a_transient_airalo_server_error(): void
    {
        config([
            'services.airalo.base_url' => 'https://partners.airalo.test',
            'services.airalo.client_id' => 'client-id-test',
            'services.airalo.client_secret' => 'client-secret-test',
            'cache.default' => 'array',
            'services.airalo.retry_attempts' => 2,
        ]);

        Cache::forget('airalo:oauth:access_token:production');
        $packageCalls = 0;
        Http::fake(function (Request $request) use (&$packageCalls) {
            if (str_contains($request->url(), '/v2/token')) {
                return Http::response([
                    'data' => ['access_token' => 'token-valid', 'expires_in' => 86400],
                ], 200);
            }

            $packageCalls++;

            return $packageCalls === 1
                ? Http::response(['meta' => ['message' => 'temporary failure']], 503)
                : Http::response(['data' => [['id' => 'pkg-retried']]], 200);
        });

        $response = app(AiraloApiClientService::class)->getPackages();

        $this->assertSame('pkg-retried', $response['data'][0]['id']);
        $this->assertSame(2, $packageCalls);
    }

    #[Test]
    public function it_retries_a_rate_limited_airalo_request(): void
    {
        config([
            'services.airalo.base_url' => 'https://partners.airalo.test',
            'services.airalo.client_id' => 'client-id-test',
            'services.airalo.client_secret' => 'client-secret-test',
            'cache.default' => 'array',
            'services.airalo.retry_attempts' => 2,
        ]);

        Cache::forget('airalo:oauth:access_token:production');
        $packageCalls = 0;
        Http::fake(function (Request $request) use (&$packageCalls) {
            if (str_contains($request->url(), '/v2/token')) {
                return Http::response([
                    'data' => ['access_token' => 'token-valid', 'expires_in' => 86400],
                ], 200);
            }

            $packageCalls++;

            return $packageCalls === 1
                ? Http::response(['meta' => ['message' => 'too many requests']], 429)
                : Http::response(['data' => [['id' => 'pkg-rate-limit-ok']]], 200);
        });

        $response = app(AiraloApiClientService::class)->getPackages();

        $this->assertSame('pkg-rate-limit-ok', $response['data'][0]['id']);
        $this->assertSame(2, $packageCalls);
    }

    #[Test]
    public function it_ignores_the_unsupported_regional_filter_without_an_api_call(): void
    {
        Http::fake();

        $response = app(AiraloApiClientService::class)->getPackagesByType('regional');

        $this->assertSame([], $response['data']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_returns_a_sanitized_exception_after_connection_failures(): void
    {
        config([
            'services.airalo.base_url' => 'https://partners.airalo.test',
            'services.airalo.client_id' => 'client-id-test',
            'services.airalo.client_secret' => 'client-secret-test',
            'cache.default' => 'array',
            'services.airalo.retry_attempts' => 2,
        ]);

        Cache::forget('airalo:oauth:access_token:production');
        Http::fake([
            'https://partners.airalo.test/v2/token' => Http::response([
                'data' => ['access_token' => 'token-valid', 'expires_in' => 86400],
            ], 200),
            'https://partners.airalo.test/v2/packages*' => Http::failedConnection('Connection timed out'),
        ]);

        try {
            app(AiraloApiClientService::class)->getPackages();
            $this->fail('Expected an AiraloApiException.');
        } catch (AiraloApiException $exception) {
            $this->assertSame(500, $exception->statusCode());
            $this->assertSame('AIRALO_500', $exception->errorCode());
            $this->assertStringNotContainsString('Connection timed out', $exception->getMessage());
        }
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

        Cache::forget('airalo:oauth:access_token:production');

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
