<?php

namespace Tests\Feature\Services;

use App\Services\AiraloAuthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class AiraloAuthServiceTest extends TestCase
{
    #[Test]
    public function it_requests_and_caches_airalo_access_token(): void
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
                'access_token' => 'airalo-token-123',
                'token_type' => 'Bearer',
                'expires_in' => 86400,
            ], 200),
        ]);

        $service = app(AiraloAuthService::class);

        $firstToken = $service->getAccessToken();
        $secondToken = $service->getAccessToken();

        $this->assertSame('airalo-token-123', $firstToken);
        $this->assertSame('airalo-token-123', $secondToken);

        Http::assertSentCount(1);
        $this->assertSame('airalo-token-123', Cache::get('airalo:oauth:access_token'));
    }

    #[Test]
    public function it_throws_clear_exception_for_invalid_airalo_credentials(): void
    {
        config([
            'services.airalo.base_url' => 'https://partners.airalo.test',
            'services.airalo.client_id' => 'invalid-id',
            'services.airalo.client_secret' => 'invalid-secret',
        ]);

        Cache::forget('airalo:oauth:access_token');

        Http::fake([
            'https://partners.airalo.test/v2/token' => Http::response([
                'error' => 'invalid_client',
                'message' => 'Invalid credentials',
            ], 401),
        ]);

        $service = app(AiraloAuthService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid client credentials');

        $service->getAccessToken();
    }
}
