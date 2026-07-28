<?php

namespace Tests\Feature\Airalo;

use App\Data\AiraloPackageData;
use App\Exceptions\AiraloApiException;
use App\Services\AiraloPackagesService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiraloPackageControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function authenticated_user_can_get_country_packages_with_normalized_json(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'api');

        $this->mock(AiraloPackagesService::class, function ($mock): void {
            $mock->shouldReceive('getPackagesByCountry')
                ->once()
                ->with('SN')
                ->andReturn([
                    new AiraloPackageData(
                        id: 'sn-1',
                        title: 'Senegal 1 GB',
                        isoCode: 'SN',
                        dataVolume: 1.0,
                        dataUnit: 'GB',
                        validityDays: 7,
                        costPrice: 4.5,
                        currency: 'USD',
                        operatorName: 'Orange',
                        networkTypes: ['LTE'],
                        networkOperatorName: 'Orange',
                        networkType: 'LTE',
                        countryName: 'Sénégal',
                        includedServices: [
                            'data' => ['status' => 'included', 'quota' => '1 GB'],
                            'calls' => ['status' => 'not_included', 'quota' => null],
                            'sms' => ['status' => 'unspecified', 'quota' => null],
                        ],
                    ),
                ]);
        });

        $response = $this->getJson('/api/v1/airalo/packages/country/SN');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', 'sn-1')
            ->assertJsonPath('data.0.title', 'Senegal 1 GB')
            ->assertJsonPath('data.0.iso_code', 'SN')
            ->assertJsonPath('data.0.data_volume', 1)
            ->assertJsonPath('data.0.data_unit', 'GB')
            ->assertJsonPath('data.0.validity_days', 7)
            ->assertJsonPath('data.0.cost_price', 4.5)
            ->assertJsonPath('data.0.currency', 'USD')
            ->assertJsonPath('data.0.package_id', 'sn-1')
            ->assertJsonPath('data.0.country_code', 'SN')
            ->assertJsonPath('data.0.country_name', 'Sénégal')
            ->assertJsonPath('data.0.data_amount', '1 GB')
            ->assertJsonPath('data.0.price', 4.5)
            ->assertJsonPath('data.0.network.operator_name', 'Orange')
            ->assertJsonPath('data.0.network.network_type', 'LTE')
            ->assertJsonPath('data.0.network.is_supported', true)
            ->assertJsonPath('data.0.included_services.data.status', 'included')
            ->assertJsonPath('data.0.included_services.data.quota', '1 GB')
            ->assertJsonPath('data.0.included_services.calls.status', 'not_included')
            ->assertJsonPath('data.0.included_services.sms.status', 'unspecified')
            ->assertJsonPath('data.0.details.validity_policy', "La période de validité commence lorsque l'eSIM se connecte à un réseau mobile dans sa zone de couverture. Si vous installez l'eSIM en dehors de sa zone de couverture, vous pouvez vous connecter à un réseau à votre arrivée.")
            ->assertJsonPath('data.0.details.renewals', 'Renouvelez automatiquement votre forfait eSIM lorsque vous avez besoin de plus de données. Activez ou désactivez les renouvellements selon vos besoins.')
            ->assertJsonPath('data.0.details.ip_routing', "L'adresse IP de l'eSIM peut apparaître en dehors de sa zone de couverture ; cela n'aura aucune incidence sur votre consommation de données.");
    }

    #[Test]
    public function authenticated_user_can_get_global_packages(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'api');

        $this->mock(AiraloPackagesService::class, function ($mock): void {
            $mock->shouldReceive('getGlobalPackages')
                ->once()
                ->andReturn([
                    new AiraloPackageData(
                        id: 'global-1',
                        title: 'Global Discover 3 GB',
                        isoCode: 'GLOBAL',
                        dataVolume: 3.0,
                        dataUnit: 'GB',
                        validityDays: 30,
                        costPrice: 20.0,
                        currency: 'USD',
                        networkTypes: ['LTE'],
                    ),
                ]);
        });

        $response = $this->getJson('/api/v1/airalo/packages/global');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', 'global-1')
            ->assertJsonPath('data.0.operator_name', '')
            ->assertJsonPath('data.0.network_types', ['LTE'])
            ->assertJsonPath('data.0.network.operator_name', '')
            ->assertJsonPath('data.0.network.network_type', '4G')
            ->assertJsonPath('data.0.network.is_supported', true);
    }

    #[Test]
    public function authenticated_user_can_get_regional_packages(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'api');

        $this->mock(AiraloPackagesService::class, function ($mock): void {
            $mock->shouldReceive('getRegionalPackages')
                ->once()
                ->andReturn([
                    new AiraloPackageData(
                        id: 'eurolink-1',
                        title: 'Eurolink 3 GB',
                        isoCode: 'REGION_ABCDEF1234',
                        dataVolume: 3.0,
                        dataUnit: 'GB',
                        validityDays: 30,
                        costPrice: 15.0,
                        currency: 'USD',
                    ),
                ]);
        });

        $response = $this->getJson('/api/v1/airalo/packages/regional');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', 'eurolink-1');
    }

    #[Test]
    public function authenticated_user_can_get_esim_countries(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'api');

        $this->mock(AiraloPackagesService::class, function ($mock): void {
            $mock->shouldReceive('getCountries')
                ->once()
                ->andReturn([
                    [
                        'iso_code' => 'GN',
                        'name' => 'Guinée',
                        'type' => 'local',
                        'category' => 'local',
                        'starting_price' => 4.5,
                        'currency' => 'USD',
                    ],
                ]);
        });

        $response = $this->getJson('/api/v1/airalo/countries');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.iso_code', 'GN')
            ->assertJsonPath('data.0.name', 'Guinée')
            ->assertJsonPath('data.0.type', 'local')
            ->assertJsonPath('data.0.starting_price', 4.5);
    }

    #[Test]
    public function it_returns_400_for_invalid_country_code(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'api');

        $response = $this->getJson('/api/v1/airalo/packages/country/SN-1');

        $response
            ->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Code pays invalide. Utilisez un code ISO alpha-2 ou alpha-3 (ex: SN, FR, CIV).');
    }

    #[Test]
    public function it_returns_500_when_airalo_service_fails(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'api');

        $this->mock(AiraloPackagesService::class, function ($mock): void {
            $mock->shouldReceive('getPackagesByCountry')
                ->once()
                ->with('FR')
                ->andThrow(new AiraloApiException(500, 'Airalo upstream unavailable'));
        });

        $response = $this->getJson('/api/v1/airalo/packages/country/FR');

        $response
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 500)
            ->assertJsonPath('code', 'AIRALO_500')
            ->assertJsonStructure(['airalo_message']);
    }
}
