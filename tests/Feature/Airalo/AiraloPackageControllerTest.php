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
            ->assertJsonPath('data.0.currency', 'USD');
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
                    ),
                ]);
        });

        $response = $this->getJson('/api/v1/airalo/packages/global');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', 'global-1');
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
            ->assertJsonPath('success', false);
    }
}
