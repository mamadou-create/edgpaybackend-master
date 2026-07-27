<?php

namespace Tests\Feature\Airalo;

use App\Services\AiraloApiClientService;
use App\Services\AiraloAuthService;
use App\Exceptions\AiraloApiException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    #[Test]
    public function it_preserves_detailed_airalo_validation_errors_in_redacted_diagnostics(): void
    {
        config([
            'services.airalo.base_url' => 'https://airalo.test',
            'services.airalo.client_id' => 'test-client',
            'services.airalo.client_secret' => 'test-secret',
        ]);
        Log::spy();

        Http::fake([
            'https://airalo.test/v2/token' => Http::response(['access_token' => 'test-token'], 200),
            'https://airalo.test/v2/orders/2192607*' => Http::response([
                'reason' => 'Request throttled. You have already placed an identical order',
                'iccid' => '89852350326101080144',
            ], 422),
        ]);

        $client = new AiraloApiClientService(new AiraloAuthService());

        try {
            $client->getOrder('2192607');
            $this->fail('Expected an Airalo API exception.');
        } catch (AiraloApiException $exception) {
            $this->assertSame('Request throttled. You have already placed an identical order', $exception->airaloMessage());
            $this->assertSame('[REDACTED]', $exception->payload()['iccid']);
        }

        Log::shouldHaveReceived('info')->withArgs(static function (string $message, array $context): bool {
            return $message === 'Airalo HTTP exchange'
                && $context['method'] === 'GET'
                && $context['url'] === 'https://airalo.test/v2/orders/2192607'
                && $context['headers']['Authorization'] === 'Bearer [REDACTED]'
                && $context['status'] === 422
                && $context['response_body']['iccid'] === '[REDACTED]';
        })->once();
    }

    #[Test]
    public function it_retrieves_orders_with_the_documented_sims_include_and_redacts_installation_data(): void
    {
        config([
            'services.airalo.base_url' => 'https://airalo.test',
            'services.airalo.client_id' => 'test-client',
            'services.airalo.client_secret' => 'test-secret',
        ]);
        Log::spy();

        Http::fake([
            'https://airalo.test/v2/token' => Http::response(['access_token' => 'test-token'], 200),
            'https://airalo.test/v2/orders/2192607*' => Http::response([
                'data' => [
                    'id' => 2192607,
                    'sims' => [[
                        'id' => 2508142,
                        'iccid' => '89852350326101340605',
                        'lpa' => 'sm-dp.example',
                        'matching_id' => 'MATCHING-ID-2192607',
                        'qrcode' => 'LPA:1$sm-dp.example$MATCHING-ID-2192607',
                        'qrcode_url' => 'https://airalo.test/qr/2192607',
                        'direct_apple_installation_url' => 'https://esimsetup.apple.com/example',
                    ]],
                ],
            ], 200),
        ]);

        $client = new AiraloApiClientService(new AiraloAuthService());
        $client->getOrder('2192607');

        Http::assertSent(static function (Request $request): bool {
            return $request->url() === 'https://airalo.test/v2/orders/2192607?include=sims'
                && $request->method() === 'GET';
        });
        Log::shouldHaveReceived('info')->withArgs(static function (string $message, array $context): bool {
            $sim = $context['response_body']['data']['sims'][0] ?? [];

            return $message === 'Airalo HTTP exchange'
                && $context['payload'] === ['include' => 'sims']
                && $sim['iccid'] === '[REDACTED]'
                && $sim['lpa'] === '[REDACTED]'
                && $sim['matching_id'] === '[REDACTED]'
                && $sim['qrcode'] === '[REDACTED]'
                && $sim['qrcode_url'] === '[REDACTED]'
                && $sim['direct_apple_installation_url'] === '[REDACTED]';
        })->once();
    }

    #[Test]
    public function it_requests_sim_instructions_in_french_by_default(): void
    {
        config([
            'services.airalo.base_url' => 'https://airalo.test',
            'services.airalo.client_id' => 'test-client',
            'services.airalo.client_secret' => 'test-secret',
        ]);

        Http::fake([
            'https://airalo.test/v2/token' => Http::response(['access_token' => 'test-token'], 200),
            'https://airalo.test/v2/sims/89852350326101340605/instructions' => Http::response([
                'data' => ['instructions' => []],
            ], 200),
        ]);

        $client = new AiraloApiClientService(new AiraloAuthService());
        $client->getSimInstructions('89852350326101340605');

        Http::assertSent(static fn (Request $request): bool =>
            $request->url() === 'https://airalo.test/v2/sims/89852350326101340605/instructions'
            && $request->method() === 'GET'
            && $request->hasHeader('Accept-Language', 'fr')
        );
    }
}