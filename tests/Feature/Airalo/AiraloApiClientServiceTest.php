<?php

namespace Tests\Feature\Airalo;

use App\Services\AiraloApiClientService;
use App\Services\AiraloAuthService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiraloApiClientServiceTest extends TestCase
{
    #[Test]
    public function it_fetches_and_aggregates_all_declared_package_pages(): void
    {
        config([
            'services.airalo.base_url' => 'https://airalo.test',
            'services.airalo.client_id' => 'test-client',
            'services.airalo.client_secret' => 'test-secret',
        ]);

        Http::fake(function (Request $request) {
            if ($request->url() === 'https://airalo.test/v2/token') {
                return Http::response(['access_token' => 'test-token'], 200);
            }

            $page = (int) ($request->data()['page'] ?? 1);

            return Http::response([
                'data' => [['id' => 'package-' . $page]],
                'meta' => ['current_page' => $page, 'last_page' => 2],
            ], 200);
        });

        $client = new AiraloApiClientService(new AiraloAuthService());
        $catalog = $client->getPackages();

        $this->assertSame([
            ['id' => 'package-1'],
            ['id' => 'package-2'],
        ], $catalog['data']);
        Http::assertSentCount(3);
    }

    #[Test]
    public function it_uses_the_official_type_filter_for_global_packages(): void
    {
        config([
            'services.airalo.base_url' => 'https://airalo.test',
            'services.airalo.client_id' => 'test-client',
            'services.airalo.client_secret' => 'test-secret',
        ]);

        Http::fake([
            'https://airalo.test/v2/token' => Http::response(['access_token' => 'test-token'], 200),
            'https://airalo.test/v2/packages*' => Http::response([
                'data' => [['id' => 'eurolink-1']],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200),
        ]);

        $client = new AiraloApiClientService(new AiraloAuthService());
        $catalog = $client->getPackagesByType('global');

        $this->assertSame([['id' => 'eurolink-1']], $catalog['data']);
        Http::assertSent(static function (Request $request): bool {
            if (!str_contains($request->url(), '/v2/packages')) {
                return false;
            }

            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['filter']['type'] ?? null) === 'global';
        });
    }
}