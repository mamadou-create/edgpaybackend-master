<?php

namespace Tests\Feature\Services;

use App\Services\AiraloApiClientService;
use App\Services\AiraloPackagesService;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiraloPackagesServiceTest extends TestCase
{
    #[Test]
    public function it_gets_packages_by_country_and_maps_them_to_dtos(): void
    {
        config(['cache.default' => 'array']);
        Cache::forget('airalo:packages:catalog:v3:' . config('app.env'));

        $fakeClient = new FakeAiraloApiClientService([
            'data' => [
                [
                    'id' => 'sn-1',
                    'title' => 'Senegal 1 GB',
                    'country_code' => 'SN',
                    'data' => '1 GB',
                    'validity_days' => 7,
                    'price' => 4.5,
                    'currency' => 'USD',
                ],
                [
                    'id' => 'ci-1',
                    'title' => 'CIV 2GB',
                    'country_code' => 'CIV',
                    'data_amount' => 2,
                    'data_unit' => 'GB',
                    'validity_days' => 15,
                    'price' => 8.0,
                    'currency' => 'USD',
                ],
            ],
        ]);

        $service = new AiraloPackagesService($fakeClient);
        $packages = $service->getPackagesByCountry('sn');

        $this->assertCount(1, $packages);
        $this->assertSame('sn-1', $packages[0]->id);
        $this->assertSame('Senegal 1 GB', $packages[0]->title);
        $this->assertSame('SN', $packages[0]->isoCode);
        $this->assertSame(1.0, $packages[0]->dataVolume);
        $this->assertSame('GB', $packages[0]->dataUnit);
        $this->assertSame(7, $packages[0]->validityDays);
        $this->assertSame(4.5, $packages[0]->costPrice);
        $this->assertSame('USD', $packages[0]->currency);
    }

    #[Test]
    public function it_separates_global_packages_from_regional_ones(): void
    {
        config(['cache.default' => 'array']);
        Cache::forget('airalo:packages:catalog:v3:' . config('app.env'));

        $fakeClient = new FakeAiraloApiClientService([
            'data' => [
                [
                    'id' => 'global-1',
                    'title' => 'Global Discover 3 GB',
                    'zone_code' => 'GLOBAL',
                    'data' => '3 GB',
                    'validity_days' => 30,
                    'price' => 20,
                    'currency' => 'USD',
                    'type' => 'global',
                ],
                [
                    'id' => 'eu-1',
                    'title' => 'Europe 5 GB',
                    'zone_code' => 'EU',
                    'data' => '5 GB',
                    'validity_days' => 30,
                    'price' => 18,
                    'currency' => 'USD',
                    'category' => 'region',
                ],
                [
                    'id' => 'fr-1',
                    'title' => 'France 1 GB',
                    'country_code' => 'FR',
                    'data' => '1 GB',
                    'validity_days' => 7,
                    'price' => 5,
                    'currency' => 'USD',
                ],
            ],
        ]);

        $service = new AiraloPackagesService($fakeClient);

        $globalPackages = $service->getGlobalPackages();
        $this->assertCount(1, $globalPackages);
        $this->assertSame('global-1', $globalPackages[0]->id);

        $regionalPackages = $service->getRegionalPackages();
        $this->assertCount(1, $regionalPackages);
        $this->assertSame('eu-1', $regionalPackages[0]->id);
    }

    #[Test]
    public function it_normalizes_explicit_megabyte_amounts_without_overriding_explicit_gigabytes(): void
    {
        config(['cache.default' => 'array']);
        Cache::forget('airalo:packages:catalog:v3:' . config('app.env'));

        $fakeClient = new FakeAiraloApiClientService([
            'data' => [
                [
                    'country_code' => 'SN',
                    'title' => 'Senegal',
                    'operators' => [[
                        'packages' => [
                            [
                                'id' => 'sn-mb-1',
                                'amount_mb' => 1024,
                                'day' => 7,
                                'prices' => [['amount' => 3, 'currency' => 'USD']],
                            ],
                            [
                                'id' => 'sn-gb-300',
                                'amount' => 300,
                                'data_unit' => 'GB',
                                'day' => 7,
                                'prices' => [['amount' => 4, 'currency' => 'USD']],
                            ],
                        ],
                    ]],
                ],
            ],
        ]);

        $packages = (new AiraloPackagesService($fakeClient))->getPackagesByCountry('SN');

        $this->assertSame(1.0, $packages[0]->dataVolume);
        $this->assertSame('GB', $packages[0]->dataUnit);
        $this->assertSame(300.0, $packages[1]->dataVolume);
        $this->assertSame('GB', $packages[1]->dataUnit);
    }

    #[Test]
    public function it_classifies_global_catalog_entries_by_slug_and_exposes_fair_usage_policy(): void
    {
        config(['cache.default' => 'array']);
        Cache::forget('airalo:packages:catalog:v3:' . config('app.env'));

        $fakeClient = new FakeAiraloApiClientService([
            'data' => [
                [
                    'slug' => 'world',
                    'title' => 'World',
                    'type' => 'global',
                    'operators' => [['packages' => [[
                        'id' => 'world-1',
                        'amount' => 5,
                        'is_fair_usage_policy' => true,
                        'fair_usage_policy' => 'Débit réduit après 3 GB par jour.',
                        'day' => 30,
                        'prices' => [['amount' => 20, 'currency' => 'USD']],
                    ]]]],
                ],
                [
                    'slug' => 'europe',
                    'title' => 'Europe',
                    'type' => 'global',
                    'operators' => [['packages' => [[
                        'id' => 'europe-1',
                        'amount' => 3,
                        'day' => 15,
                        'prices' => [['amount' => 10, 'currency' => 'USD']],
                    ]]]],
                ],
            ],
        ]);

        $service = new AiraloPackagesService($fakeClient);
        $globalPackages = $service->getGlobalPackages();
        $regionalPackages = $service->getRegionalPackages();

        $this->assertCount(1, $globalPackages);
        $this->assertSame('world-1', $globalPackages[0]->id);
        $this->assertTrue($globalPackages[0]->isFairUsagePolicy);
        $this->assertSame('Débit réduit après 3 GB par jour.', $globalPackages[0]->fairUsagePolicy);
        $this->assertCount(1, $regionalPackages);
        $this->assertSame('europe-1', $regionalPackages[0]->id);
    }

    #[Test]
    public function it_keeps_destinations_in_their_official_airalo_type_scope(): void
    {
        config(['cache.default' => 'array']);
        Cache::forget('airalo:packages:catalog:v3:' . config('app.env'));

        $fakeClient = new FakeAiraloApiClientService([
            'data' => [
                [
                    'country_code' => 'GN',
                    'title' => 'Guinea',
                    'operators' => [
                        [
                            'type' => 'local',
                            'title' => 'Local eSIM',
                            'packages' => [
                                [
                                    'id' => 'gn-1',
                                    'title' => 'Guinea 1GB 7j',
                                    'amount' => 1,
                                    'day' => 7,
                                    'prices' => [['amount' => 3.0, 'currency' => 'USD']],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'slug' => 'eurolink',
                    'title' => 'Eurolink',
                    'type' => 'regional',
                    'countries' => [
                        ['code' => 'FR'], ['code' => 'DE'], ['code' => 'ES'], ['code' => 'IT'],
                    ],
                    'operators' => [
                        [
                            'packages' => [
                                [
                                    'id' => 'eurolink-1',
                                    'title' => 'Eurolink 3GB 30j',
                                    'amount' => 3,
                                    'day' => 30,
                                    'prices' => [['amount' => 15.0, 'currency' => 'USD']],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'slug' => 'discover-plus',
                    'title' => 'Discover+',
                    'type' => 'global',
                    'countries' => array_map(
                        static fn (int $index): array => ['code' => sprintf('C%02d', $index)],
                        range(1, 20),
                    ),
                    'operators' => [
                        [
                            'packages' => [
                                [
                                    'id' => 'discover-1',
                                    'title' => 'Discover+ 5GB 30j',
                                    'amount' => 5,
                                    'day' => 30,
                                    'prices' => [['amount' => 25.0, 'currency' => 'USD']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $service = new AiraloPackagesService($fakeClient);

        $countries = $service->getCountries();
        $categoriesByName = [];
        $typesByName = [];
        foreach ($countries as $country) {
            $categoriesByName[$country['name']] = $country['category'];
            $typesByName[$country['name']] = $country['type'];
        }

        $this->assertSame('local', $categoriesByName['Guinée'] ?? null);
        $this->assertSame('regional', $categoriesByName['Eurolink'] ?? null);
        $this->assertSame('global', $categoriesByName['Mondial'] ?? null);
        $this->assertSame('local', $typesByName['Guinée'] ?? null);
        $this->assertSame('regional', $typesByName['Eurolink'] ?? null);
        $this->assertSame('global', $typesByName['Mondial'] ?? null);

        $regionalPackages = $service->getRegionalPackages();
        $this->assertCount(1, $regionalPackages);
        $this->assertSame('eurolink-1', $regionalPackages[0]->id);

        $globalPackages = $service->getGlobalPackages();
        $this->assertCount(1, $globalPackages);
        $this->assertSame('discover-1', $globalPackages[0]->id);
    }

    #[Test]
    public function it_uses_cache_for_one_hour_to_avoid_redundant_api_calls(): void
    {
        config(['cache.default' => 'array']);
        Cache::forget('airalo:packages:catalog:v3:' . config('app.env'));

        $fakeClient = new FakeAiraloApiClientService([
            'data' => [
                [
                    'id' => 'fr-1',
                    'title' => 'France 1 GB',
                    'country_code' => 'FR',
                    'data' => '1 GB',
                    'validity_days' => 7,
                    'price' => 5,
                    'currency' => 'USD',
                ],
            ],
        ]);

        $service = new AiraloPackagesService($fakeClient);

        $first = $service->getPackagesByCountry('FR');
        $second = $service->getPackagesByCountry('FR');

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertSame(3, $fakeClient->calls);
    }

    #[Test]
    public function it_localizes_iso_country_names_to_french_for_destinations(): void
    {
        config(['cache.default' => 'array']);
        Cache::forget('airalo:packages:catalog:v3:' . config('app.env'));

        $service = new AiraloPackagesService(new FakeAiraloApiClientService([
            'data' => [[
                'id' => 'gn-1',
                'title' => 'Guinea 1 GB',
                'country_name' => 'Guinea',
                'country_code' => 'GN',
                'data' => '1 GB',
                'validity_days' => 7,
                'price' => 4.5,
                'currency' => 'USD',
            ]],
        ]));

        $countries = $service->getCountries();

        $this->assertCount(1, $countries);
        $this->assertSame('Guinée', $countries[0]['name']);
    }

    #[Test]
    public function it_flattens_nested_catalog_data_from_operators_packages(): void
    {
        config([
            'cache.default' => 'array',
            'services.airalo.gnf_rate' => 9000,
            'services.airalo.gnf_margin_percent' => 10,
        ]);
        Cache::forget('airalo:packages:catalog:v3:' . config('app.env'));

        $fakeClient = new FakeAiraloApiClientService([
            'data' => [
                [
                    'country_code' => 'SN',
                    'title' => 'Senegal',
                    'operators' => [
                        [
                            'type' => 'local',
                            'name' => 'Turk Telekom (Avea)',
                            'networks' => [
                                ['types' => ['4G', '5G']],
                            ],
                            'packages' => [
                                [
                                    'id' => 'sn_pkg_1',
                                    'title' => 'Senegal 3GB 7j',
                                    'amount' => 3,
                                    'day' => 7,
                                    'prices' => [
                                        ['amount' => 5.5, 'currency' => 'USD'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $service = new AiraloPackagesService($fakeClient);
        $packages = $service->getPackagesByCountry('SN');

        $this->assertCount(1, $packages);
        $this->assertSame('sn_pkg_1', $packages[0]->id);
        $this->assertSame('Senegal 3GB 7j', $packages[0]->title);
        $this->assertSame('SN', $packages[0]->isoCode);
        $this->assertSame(3.0, $packages[0]->dataVolume);
        $this->assertSame('GB', $packages[0]->dataUnit);
        $this->assertSame(7, $packages[0]->validityDays);
        $this->assertSame(5.5, $packages[0]->costPrice);
        $this->assertSame('USD', $packages[0]->currency);
        $this->assertSame(54450, $packages[0]->priceGnf);
        $this->assertSame('Turk Telekom (Avea)', $packages[0]->operatorName);
        $this->assertSame(['4G', '5G'], $packages[0]->networkTypes);
        $this->assertTrue($packages[0]->is5g);
    }

    #[Test]
    public function it_ignores_operator_titles_that_are_just_the_destination_product_name(): void
    {
        config(['cache.default' => 'array']);
        Cache::forget('airalo:packages:catalog:v3:' . config('app.env'));

        $fakeClient = new FakeAiraloApiClientService([
            'data' => [
                [
                    'country_code' => 'GN',
                    'title' => 'Guinea',
                    'operators' => [
                        [
                            'type' => 'local',
                            // Airalo nomme parfois le produit eSIM d'apres la destination
                            // (ex: "Guinea Miyahu"), ce qui n'est pas un vrai operateur reseau.
                            'title' => 'Guinea Miyahu',
                            'packages' => [
                                [
                                    'id' => 'gn_pkg_1',
                                    'title' => 'Guinea 1GB 7j',
                                    'amount' => 1,
                                    'day' => 7,
                                    'prices' => [
                                        ['amount' => 3.0, 'currency' => 'USD'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $service = new AiraloPackagesService($fakeClient);
        $packages = $service->getPackagesByCountry('GN');

        $this->assertCount(1, $packages);
        $this->assertSame('', $packages[0]->operatorName);
    }

    #[Test]
    public function it_prefers_coverage_network_brand_over_product_title_for_operator_name(): void
    {
        config(['cache.default' => 'array']);
        Cache::forget('airalo:packages:catalog:v3:' . config('app.env'));

        $fakeClient = new FakeAiraloApiClientService([
            'data' => [
                [
                    'country_code' => 'GN',
                    'title' => 'Guinea',
                    'operators' => [
                        [
                            'type' => 'local',
                            'title' => 'Guinea Miyahu',
                            'coverages' => [
                                ['name' => 'Orange', 'networks' => [['type' => '4G']]],
                            ],
                            'packages' => [
                                [
                                    'id' => 'gn_pkg_2',
                                    'title' => 'Guinea 2GB 15j',
                                    'amount' => 2,
                                    'day' => 15,
                                    'prices' => [
                                        ['amount' => 6.0, 'currency' => 'USD'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $service = new AiraloPackagesService($fakeClient);
        $packages = $service->getPackagesByCountry('GN');

        $this->assertCount(1, $packages);
        $this->assertSame('Orange', $packages[0]->operatorName);
        $this->assertSame(['4G'], $packages[0]->networkTypes);
    }

    #[Test]
    public function it_prefers_explicit_operator_name_over_info_fallback(): void
    {
        config(['cache.default' => 'array']);
        Cache::forget('airalo:packages:catalog:v3:' . config('app.env'));

        $fakeClient = new FakeAiraloApiClientService([
            'data' => [[
                'country_code' => 'FR',
                'operators' => [[
                    'name' => 'SFR',
                    'info' => ['Operates on the Orange network in France.'],
                    'packages' => [[
                        'id' => 'fr-explicit',
                        'amount' => 1,
                        'day' => 7,
                        'prices' => [['amount' => 5.0, 'currency' => 'EUR']],
                    ]],
                ]],
            ]],
        ]);

        $package = (new AiraloPackagesService($fakeClient))->getPackagesByCountry('FR')[0];

        $this->assertSame('SFR', $package->operatorName);
        $this->assertSame(['SFR'], $package->operatorNames);
    }

    #[Test]
    public function it_extracts_multiple_operator_names_from_info_without_country_codes(): void
    {
        config(['cache.default' => 'array']);
        Cache::forget('airalo:packages:catalog:v3:' . config('app.env'));

        $fakeClient = new FakeAiraloApiClientService([
            'data' => [[
                'country_code' => 'US',
                'operators' => [[
                    'name' => null,
                    'info' => [
                        '5G Data-only eSIM.',
                        'Operates on T-Mobile, Verizon, and U.S. Cellular networks in the United States of America.',
                    ],
                    'packages' => [[
                        'id' => 'us-info-fallback',
                        'amount' => 1,
                        'day' => 7,
                        'prices' => [['amount' => 5.0, 'currency' => 'USD']],
                    ]],
                ]],
            ]],
        ]);

        $package = (new AiraloPackagesService($fakeClient))->getPackagesByCountry('US')[0];

        $this->assertSame(['T-Mobile', 'Verizon', 'U.S. Cellular'], $package->operatorNames);
        $this->assertSame('T-Mobile', $package->operatorName);
        $this->assertSame(['5G'], $package->networkTypes);
    }

    #[Test]
    public function it_maps_airalo_data_voice_and_text_services_with_quotas(): void
    {
        config(['cache.default' => 'array']);
        Cache::forget('airalo:packages:catalog:v3:' . config('app.env'));

        $fakeClient = new FakeAiraloApiClientService([
            'data' => [[
                'country_code' => 'US',
                'operators' => [[
                    'name' => 'Example Mobile',
                    'packages' => [[
                        'id' => 'us-combo',
                        'data' => '10 GB',
                        'voice' => 75,
                        'text' => 30,
                        'amount' => 10,
                        'day' => 365,
                        'prices' => [['amount' => 50.0, 'currency' => 'USD']],
                    ]],
                ]],
            ]],
        ]);

        $package = (new AiraloPackagesService($fakeClient))->getPackagesByCountry('US')[0];

        $this->assertSame([
            'data' => ['status' => 'included', 'quota' => '10 GB'],
            'calls' => ['status' => 'included', 'quota' => '75 minutes'],
            'sms' => ['status' => 'included', 'quota' => '30 SMS'],
        ], $package->includedServices);
    }

    #[Test]
    public function it_distinguishes_null_services_from_missing_service_fields(): void
    {
        config(['cache.default' => 'array']);
        Cache::forget('airalo:packages:catalog:v3:' . config('app.env'));

        $fakeClient = new FakeAiraloApiClientService([
            'data' => [
                [
                    'country_code' => 'FR',
                    'operators' => [[
                        'packages' => [[
                            'id' => 'fr-data-only',
                            'data' => '1 GB',
                            'voice' => null,
                            'text' => null,
                            'amount' => 1,
                            'day' => 7,
                            'prices' => [['amount' => 5.0, 'currency' => 'EUR']],
                        ]],
                    ]],
                ],
                [
                    'country_code' => 'DE',
                    'operators' => [[
                        'packages' => [[
                            'id' => 'de-unknown-services',
                            'amount' => 1,
                            'day' => 7,
                            'prices' => [['amount' => 5.0, 'currency' => 'EUR']],
                        ]],
                    ]],
                ],
            ],
        ]);

        $service = new AiraloPackagesService($fakeClient);
        $dataOnly = $service->getPackagesByCountry('FR')[0];
        $unknown = $service->getPackagesByCountry('DE')[0];

        $this->assertSame('not_included', $dataOnly->includedServices['calls']['status']);
        $this->assertSame('not_included', $dataOnly->includedServices['sms']['status']);
        $this->assertSame('unspecified', $unknown->includedServices['calls']['status']);
        $this->assertSame('unspecified', $unknown->includedServices['sms']['status']);
    }

    #[Test]
    public function it_maps_boolean_service_values_without_numeric_labels(): void
    {
        config(['cache.default' => 'array']);
        Cache::forget('airalo:packages:catalog:v3:' . config('app.env'));

        $fakeClient = new FakeAiraloApiClientService([
            'data' => [[
                'country_code' => 'JP',
                'operators' => [[
                    'packages' => [[
                        'id' => 'jp-boolean-services',
                        'data' => '500 MB',
                        'voice' => true,
                        'text' => false,
                        'amount' => 0.5,
                        'day' => 7,
                        'prices' => [['amount' => 5.0, 'currency' => 'USD']],
                    ]],
                ]],
            ]],
        ]);

        $package = (new AiraloPackagesService($fakeClient))->getPackagesByCountry('JP')[0];

        $this->assertSame(['status' => 'included', 'quota' => null], $package->includedServices['calls']);
        $this->assertSame(['status' => 'not_included', 'quota' => null], $package->includedServices['sms']);
    }

    #[Test]
    public function it_does_not_invent_an_operator_when_only_country_data_exists(): void
    {
        config(['cache.default' => 'array']);
        Cache::forget('airalo:packages:catalog:v3:' . config('app.env'));

        $fakeClient = new FakeAiraloApiClientService([
            'data' => [[
                'country_code' => 'GN',
                'operator_name' => 'GN',
                'operators' => [[
                    'name' => null,
                    'info' => ['4G Data-only eSIM.'],
                    'packages' => [[
                        'id' => 'gn-no-operator',
                        'amount' => 1,
                        'day' => 7,
                        'prices' => [['amount' => 5.0, 'currency' => 'USD']],
                    ]],
                ]],
            ]],
        ]);

        $package = (new AiraloPackagesService($fakeClient))->getPackagesByCountry('GN')[0];

        $this->assertSame([], $package->operatorNames);
        $this->assertSame('', $package->operatorName);
        $this->assertSame(['4G'], $package->networkTypes);
    }

    #[Test]
    public function it_maps_airalo_operator_info_to_network_types(): void
    {
        config(['cache.default' => 'array']);
        Cache::forget('airalo:packages:catalog:v3:' . config('app.env'));

        $fakeClient = new FakeAiraloApiClientService([
            'data' => [
                [
                    'country_code' => 'AF',
                    'title' => 'Afghanistan',
                    'operators' => [[
                        'name' => 'Roshan',
                        'info' => ['LTE'],
                        'packages' => [[
                            'id' => 'afghanistan-1gb-3days',
                            'amount' => 1,
                            'day' => 3,
                            'prices' => [['amount' => 5.0, 'currency' => 'EUR']],
                        ]],
                    ]],
                ],
            ],
        ]);

        $packages = (new AiraloPackagesService($fakeClient))->getPackagesByCountry('AF');

        $this->assertCount(1, $packages);
        $this->assertSame('Roshan', $packages[0]->operatorName);
        $this->assertSame(['LTE'], $packages[0]->networkTypes);
        $this->assertSame('Roshan', $packages[0]->networkOperatorName);
        $this->assertSame('LTE', $packages[0]->networkType);
        $this->assertSame('Afghanistan', $packages[0]->countryName);
    }

    #[Test]
    public function it_uses_strict_network_defaults_when_first_operator_has_no_info(): void
    {
        config(['cache.default' => 'array']);
        Cache::forget('airalo:packages:catalog:v3:' . config('app.env'));

        $fakeClient = new FakeAiraloApiClientService([
            'data' => [
                [
                    'country_code' => 'DE',
                    'title' => 'Germany',
                    'operators' => [[
                        'networks' => [['type' => 'LTE']],
                        'packages' => [[
                            'id' => 'germany-1gb-3days',
                            'amount' => 1,
                            'day' => 3,
                            'prices' => [['amount' => 5.0, 'currency' => 'EUR']],
                        ]],
                    ]],
                ],
            ],
        ]);

        $package = (new AiraloPackagesService($fakeClient))->getPackagesByCountry('DE')[0];

        $this->assertSame('', $package->networkOperatorName);
        $this->assertSame('4G', $package->networkType);
        $this->assertSame(['LTE'], $package->networkTypes);
    }
}

class FakeAiraloApiClientService extends AiraloApiClientService
{
    public int $calls = 0;

    public function __construct(private readonly array $payload)
    {
    }

    public function getPackages(array $query = []): array
    {
        $this->calls++;

        return $this->payload;
    }

    public function getPackagesByType(string $type): array
    {
        $this->calls++;
        $normalizedType = $type === 'regional' ? 'regional' : $type;
        $data = $this->payload['data'] ?? [];

        return [
            'data' => array_values(array_filter($data, function (array $item) use ($normalizedType): bool {
                $itemType = strtolower((string) ($item['type'] ?? $item['category'] ?? 'local'));
                $itemType = $itemType === 'region' ? 'regional' : $itemType;

                return $itemType === $normalizedType;
            })),
        ];
    }
}
