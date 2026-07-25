<?php

namespace App\Services;

use App\Data\AiraloPackageData;
use Illuminate\Support\Facades\Cache;

class AiraloPackagesService
{
    private const CACHE_KEY_ALL_PACKAGES = 'airalo:packages:catalog:v1';

    public function __construct(private readonly AiraloApiClientService $apiClient)
    {
    }

    /**
     * @return array<int, AiraloPackageData>
     */
    public function getPackagesByCountry(string $countryCode): array
    {
        $countryCode = strtoupper(trim($countryCode));
        if ($countryCode === '') {
            return [];
        }

        $rawPackages = $this->getCachedRawPackages();

        $filtered = array_filter($rawPackages, function (array $package) use ($countryCode): bool {
            $codes = $this->extractIsoCodes($package);

            return in_array($countryCode, $codes, true);
        });

        return array_values(array_map(fn (array $item): AiraloPackageData => $this->toDto($item), $filtered));
    }

    /**
     * @return array<int, AiraloPackageData>
     */
    public function getGlobalPackages(): array
    {
        $rawPackages = $this->getCachedRawPackages();

        $filtered = array_filter(
            $rawPackages,
            fn (array $package): bool => $this->extractCountryCategory($package) === 'global',
        );

        return array_values(array_map(fn (array $item): AiraloPackageData => $this->toDto($item), $filtered));
    }

    /**
     * @return array<int, AiraloPackageData>
     */
    public function getRegionalPackages(): array
    {
        $rawPackages = $this->getCachedRawPackages();

        $filtered = array_filter(
            $rawPackages,
            fn (array $package): bool => $this->extractCountryCategory($package) === 'regional',
        );

        return array_values(array_map(fn (array $item): AiraloPackageData => $this->toDto($item), $filtered));
    }

    public function invalidateCatalogCache(): void
    {
        Cache::forget($this->catalogCacheKey());
    }

    /**
    * @return array<int, array{iso_code: string, name: string, type: string, category: string, starting_price: float, currency: string}>
     */
    public function getCountries(): array
    {
        $countries = [];

        foreach ($this->getCachedRawPackages() as $package) {
            $category = $this->extractCountryCategory($package);
            $isoCode = $this->extractPrimaryIsoCode($package);
            if ($category === 'global') {
                $isoCode = 'GLOBAL';
            } elseif ($category === 'regional') {
                $isoCode = 'REGION_' . strtoupper(substr(md5($this->extractCountryName($package, 'Région')), 0, 10));
            } elseif (!$this->isCountryCode($isoCode)) {
                continue;
            }

            [$price, $currency] = $this->extractPriceAndCurrency($package);
            $key = strtoupper($isoCode);
            $name = $category === 'global'
                ? 'Mondial'
                : $this->extractCountryName($package, $key);

            if (!isset($countries[$key])) {
                $countries[$key] = [
                    'iso_code' => $key,
                    'name' => $name,
                    'type' => $category,
                    'category' => $category,
                    'starting_price' => $price,
                    'currency' => $currency,
                ];
                continue;
            }

            if ($price > 0 && ($countries[$key]['starting_price'] <= 0 || $price < $countries[$key]['starting_price'])) {
                $countries[$key]['starting_price'] = $price;
                $countries[$key]['currency'] = $currency;
            }
        }

        uasort($countries, static fn (array $first, array $second): int => strcmp($first['name'], $second['name']));

        return array_values($countries);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getCachedRawPackages(): array
    {
        /** @var array<int, array<string, mixed>> $cached */
        $cached = Cache::remember($this->catalogCacheKey(), now()->addMinutes($this->catalogCacheTtlMinutes()), function (): array {
            $typedCatalogs = [];
            foreach (['local', 'global'] as $type) {
                $response = $this->apiClient->getPackagesByType($type);
                $data = $response['data'] ?? [];

                if (!is_array($data)) {
                    continue;
                }

                $typedCatalogs[$type] = $this->normalizeCatalogItems($data, $type);
            }

            $allResponse = $this->apiClient->getPackages();
            $allData = $allResponse['data'] ?? [];
            $unscopedCatalog = is_array($allData)
                ? $this->normalizeCatalogItems($allData, 'regional')
                : [];
            $typedPackageIds = array_flip(array_filter(array_map(
                static fn (array $package): string => (string) ($package['id'] ?? $package['package_id'] ?? ''),
                [...($typedCatalogs['local'] ?? []), ...($typedCatalogs['global'] ?? [])],
            )));
            $regionalCatalog = array_values(array_filter(
                $unscopedCatalog,
                static fn (array $package): bool => !isset($typedPackageIds[(string) ($package['id'] ?? $package['package_id'] ?? '')]),
            ));

            return [
                ...($typedCatalogs['local'] ?? []),
                ...$regionalCatalog,
                ...($typedCatalogs['global'] ?? []),
            ];
        });

        return $cached;
    }

    private function catalogCacheTtlMinutes(): int
    {
        return max(1, (int) config('services.airalo.catalog_cache_ttl_minutes', 60));
    }

    private function catalogCacheKey(): string
    {
        return self::CACHE_KEY_ALL_PACKAGES . ':' . (string) config('app.env', 'production');
    }

    /**
     * @param array<int, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCatalogItems(array $data, string $type): array
    {
        $normalized = [];

        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            // Format deja plat (legacy / tests)
            if (isset($entry['id']) || isset($entry['package_id'])) {
                $entry['type'] = $type;
                $normalized[] = $entry;
                continue;
            }

            $operators = $entry['operators'] ?? null;
            if (!is_array($operators)) {
                continue;
            }

            foreach ($operators as $operator) {
                if (!is_array($operator)) {
                    continue;
                }

                $packages = $operator['packages'] ?? null;
                if (!is_array($packages)) {
                    continue;
                }

                foreach ($packages as $package) {
                    if (!is_array($package)) {
                        continue;
                    }

                    [$price, $currency] = $this->extractPriceAndCurrency($package);
                    $countryHint = (string) ($entry['title'] ?? $entry['name'] ?? $entry['slug'] ?? '');
                    $operatorName = $this->extractOperatorName($operator, $package, $countryHint);
                    $networkTypes = $this->extractNetworkTypes($operator, $package);

                    $normalized[] = [
                        'id' => (string) ($package['id'] ?? $package['package_id'] ?? ''),
                        'package_id' => (string) ($package['package_id'] ?? $package['id'] ?? ''),
                        'title' => (string) ($package['title'] ?? $entry['title'] ?? $entry['slug'] ?? 'Unknown package'),
                        'country_name' => (string) ($entry['title'] ?? $entry['name'] ?? $entry['slug'] ?? ''),
                        'type' => $type,
                        'country_code' => (string) ($entry['country_code'] ?? $entry['iso_code'] ?? ''),
                        'iso_code' => (string) ($entry['country_code'] ?? $entry['iso_code'] ?? ''),
                        'countries' => $operator['countries'] ?? $entry['countries'] ?? [],
                        'slug' => (string) ($entry['slug'] ?? ''),
                        'amount_mb' => $package['amount_mb'] ?? null,
                        'data_amount' => $package['amount'] ?? $package['data_amount'] ?? $package['dataAmount'] ?? null,
                        'data_unit' => $package['data_unit'] ?? $package['dataUnit'] ?? $package['unit'] ?? '',
                        'data' => $package['data'] ?? $package['amount'] ?? null,
                        'validity_days' => $package['day'] ?? $package['validity_days'] ?? null,
                        'price' => $price,
                        'cost_price' => $price,
                        'currency' => $currency,
                        'price_currency' => $currency,
                        'operator_name' => $operatorName,
                        'network_types' => $networkTypes,
                        'is_5g' => in_array('5G', $networkTypes, true),
                    ];
                }
            }
        }

        return array_values(array_filter($normalized, static function (array $item): bool {
            return trim((string) ($item['id'] ?? $item['package_id'] ?? '')) !== '';
        }));
    }

    private function toDto(array $item): AiraloPackageData
    {
        [$volume, $unit] = $this->extractDataVolumeAndUnit($item);

        return new AiraloPackageData(
            id: (string) ($item['id'] ?? $item['package_id'] ?? ''),
            title: (string) ($item['title'] ?? $item['name'] ?? $item['slug'] ?? 'Unknown package'),
            isoCode: $this->extractPrimaryIsoCode($item),
            dataVolume: $volume,
            dataUnit: $unit,
            validityDays: $this->extractValidityDays($item),
            costPrice: $this->extractCostPrice($item),
            currency: strtoupper((string) ($item['currency'] ?? $item['price_currency'] ?? 'USD')),
            priceGnf: $this->convertToGnf(
                $this->extractCostPrice($item),
                strtoupper((string) ($item['currency'] ?? $item['price_currency'] ?? 'USD')),
            ),
            operatorName: $this->extractOperatorName([], $item, (string) ($item['country_name'] ?? '')),
            networkTypes: $this->extractNetworkTypes([], $item),
            is5g: $this->is5g($item),
        );
    }

    private function extractOperatorName(array $operator, array $package, string $countryHint = ''): string
    {
        $operatorValue = $package['operator'] ?? $operator['operator'] ?? null;
        $operators = $package['operators'] ?? $operator['operators'] ?? null;
        $firstOperator = is_array($operators) && isset($operators[0]) && is_array($operators[0])
            ? $operators[0]
            : [];
        $coverageName = $this->extractCoverageOperatorName($operator, $package);
        $candidates = [
            $package['operator_name'] ?? null,
            $operator['operator_name'] ?? null,
            $coverageName,
            is_array($operatorValue) ? ($operatorValue['name'] ?? $operatorValue['title'] ?? null) : $operatorValue,
            $operator['name'] ?? null,
            $operator['title'] ?? null,
            $firstOperator['name'] ?? $firstOperator['title'] ?? null,
            isset($firstOperator['operator']) && is_array($firstOperator['operator'])
                ? ($firstOperator['operator']['name'] ?? $firstOperator['operator']['title'] ?? null)
                : ($firstOperator['operator'] ?? null),
        ];

        $normalizedHint = $this->normalizeForComparison($countryHint);

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $trimmed = trim($candidate);

            // Airalo nomme parfois le "produit" eSIM comme la destination elle-meme
            // (ex: "Guinea Miyahu"). Ce n'est pas un operateur reseau reel: on l'ignore.
            if ($normalizedHint !== '' && str_contains($this->normalizeForComparison($trimmed), $normalizedHint)) {
                continue;
            }

            return $trimmed;
        }

        return 'Réseau local';
    }

    /**
     * Airalo expose parfois la marque reseau reelle (Orange, MTN, ...) via une
     * liste "coverages" distincte du titre marketing du produit eSIM.
     */
    private function extractCoverageOperatorName(array $operator, array $package): ?string
    {
        foreach ([$package['coverages'] ?? null, $operator['coverages'] ?? null] as $coverages) {
            if (!is_array($coverages)) {
                continue;
            }

            foreach ($coverages as $coverage) {
                if (!is_array($coverage)) {
                    continue;
                }

                $name = $coverage['name'] ?? $coverage['network_name'] ?? null;
                if (is_string($name) && trim($name) !== '') {
                    return trim($name);
                }
            }
        }

        return null;
    }

    private function normalizeForComparison(string $value): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9]+/', ' ', $value) ?? '';

        return strtolower(trim($normalized));
    }

    /**
     * @return array<int, string>
     */
    private function extractNetworkTypes(array $operator, array $package): array
    {
        $types = [];
        $sources = [
            $package['network_types'] ?? null,
            $package['types'] ?? null,
            $operator['types'] ?? null,
        ];

        foreach ([$package['networks'] ?? null, $operator['networks'] ?? null] as $networks) {
            if (!is_array($networks)) {
                continue;
            }

            foreach ($networks as $network) {
                if (!is_array($network)) {
                    continue;
                }
                $sources[] = $network['types'] ?? $network['type'] ?? null;
                if (($network['is_5g'] ?? false) === true) {
                    $types[] = '5G';
                }
            }
        }

        foreach ([$package['coverages'] ?? null, $operator['coverages'] ?? null] as $coverages) {
            if (!is_array($coverages)) {
                continue;
            }

            foreach ($coverages as $coverage) {
                if (!is_array($coverage)) {
                    continue;
                }

                $coverageNetworks = $coverage['networks'] ?? null;
                if (!is_array($coverageNetworks)) {
                    continue;
                }

                foreach ($coverageNetworks as $coverageNetwork) {
                    if (is_array($coverageNetwork)) {
                        $sources[] = $coverageNetwork['type'] ?? null;
                    } elseif (is_string($coverageNetwork)) {
                        $sources[] = $coverageNetwork;
                    }
                }
            }
        }

        foreach ([$package['operators'] ?? null, $operator['operators'] ?? null] as $operators) {
            if (!is_array($operators)) {
                continue;
            }

            foreach ($operators as $nestedOperator) {
                if (!is_array($nestedOperator)) {
                    continue;
                }
                $sources[] = $nestedOperator['types'] ?? null;
                $sources[] = $nestedOperator['networks'] ?? null;
            }
        }

        foreach ($sources as $source) {
            foreach (is_array($source) ? $source : [$source] as $type) {
                if (is_array($type)) {
                    $type = $type['types'] ?? $type['type'] ?? null;
                }
                if (!is_string($type) || trim($type) === '') {
                    continue;
                }
                $types[] = strtoupper(trim($type));
            }
        }

        return array_values(array_unique($types));
    }

    private function is5g(array $item): bool
    {
        if (($item['is_5g'] ?? false) === true) {
            return true;
        }

        return in_array('5G', $this->extractNetworkTypes([], $item), true);
    }

    private function convertToGnf(float $amount, string $currency): int
    {
        return match ($currency) {
            'GNF' => (int) round($amount),
            'EUR' => (int) round($amount * 9300),
            default => (int) round($amount * 8600),
        };
    }

    /**
     * @return array<int, string>
     */
    private function extractIsoCodes(array $item): array
    {
        $codes = [];

        $direct = [
            $item['country_code'] ?? null,
            $item['countryCode'] ?? null,
            $item['country'] ?? null,
            $item['iso_code'] ?? null,
            $item['isoCode'] ?? null,
            $item['zone_code'] ?? null,
            $item['zoneCode'] ?? null,
        ];

        foreach ($direct as $value) {
            if (is_string($value) && trim($value) !== '') {
                $codes[] = strtoupper(trim($value));
            }
        }

        $coverage = $item['countries'] ?? $item['coverage'] ?? null;
        if (is_array($coverage)) {
            foreach ($coverage as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $code = $entry['code'] ?? $entry['country_code'] ?? $entry['iso_code'] ?? null;
                if (is_string($code) && trim($code) !== '') {
                    $codes[] = strtoupper(trim($code));
                }
            }
        }

        return array_values(array_unique($codes));
    }

    private function extractPrimaryIsoCode(array $item): string
    {
        $codes = $this->extractIsoCodes($item);

        return $codes[0] ?? 'GLOBAL';
    }

    private function isCountryCode(string $code): bool
    {
        return preg_match('/^[A-Z]{2,3}$/', $code) === 1;
    }

    private function extractCountryName(array $item, string $fallback): string
    {
        $isoCode = strtoupper(trim($fallback));
        if (preg_match('/^[A-Z]{2}$/', $isoCode) === 1) {
            $localizedName = \Locale::getDisplayRegion('und_' . $isoCode, 'fr');
            if (is_string($localizedName) && trim($localizedName) !== '' && strtoupper($localizedName) !== $isoCode) {
                return trim($localizedName);
            }
        }

        foreach (['country_name', 'countryName', 'name', 'title'] as $field) {
            $value = $item[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $fallback;
    }

    private function extractCountryCategory(array $item): string
    {
        $type = strtolower(trim((string) ($item['type'] ?? $item['category'] ?? '')));

        if (in_array($type, ['region', 'regional'], true)) {
            return 'regional';
        }

        if (in_array($type, ['global', 'worldwide'], true)) {
            return 'global';
        }

        if (strtolower(trim((string) ($item['slug'] ?? ''))) === 'discover') {
            return 'global';
        }

        return 'local';
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function extractDataVolumeAndUnit(array $item): array
    {
        $unit = strtoupper((string) ($item['data_unit'] ?? $item['dataUnit'] ?? ''));

        $amountMb = $item['amount_mb'] ?? null;
        if (is_numeric($amountMb)) {
            return [(float) $amountMb / 1024, 'GB'];
        }

        $volumeRaw = $item['data_amount']
            ?? $item['dataAmount']
            ?? $item['amount']
            ?? $item['volume']
            ?? $item['data']
            ?? null;

        if (is_numeric($volumeRaw)) {
            $volume = (float) $volumeRaw;
            if ($unit === '') {
                $unit = $volume >= 1 ? 'GB' : 'MB';
            }

            return [$volume, $unit];
        }

        if (is_string($volumeRaw)) {
            $normalized = strtoupper(trim($volumeRaw));
            if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(GB|MB)/', $normalized, $matches) === 1) {
                return [(float) $matches[1], $matches[2]];
            }
        }

        return [0.0, $unit !== '' ? $unit : 'GB'];
    }

    private function extractValidityDays(array $item): int
    {
        $validity = $item['validity_days'] ?? $item['validityDays'] ?? $item['duration'] ?? $item['days'] ?? null;

        if (is_numeric($validity)) {
            return (int) $validity;
        }

        if (is_string($validity) && preg_match('/(\d+)/', $validity, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function extractCostPrice(array $item): float
    {
        $price = $item['price'] ?? $item['cost_price'] ?? $item['costPrice'] ?? null;

        if (is_numeric($price)) {
            return (float) $price;
        }

        if (is_string($price)) {
            $normalized = preg_replace('/[^0-9\.]/', '', $price);
            if (is_string($normalized) && $normalized !== '' && is_numeric($normalized)) {
                return (float) $normalized;
            }
        }

        if (isset($item['prices']) && is_array($item['prices'])) {
            foreach ($item['prices'] as $priceEntry) {
                if (!is_array($priceEntry)) {
                    continue;
                }

                $value = $priceEntry['price'] ?? $priceEntry['amount'] ?? $priceEntry['value'] ?? null;
                if (is_numeric($value)) {
                    return (float) $value;
                }
            }
        }

        return 0.0;
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function extractPriceAndCurrency(array $package): array
    {
        $price = 0.0;
        $currency = 'USD';

        $directPrice = $package['price'] ?? $package['cost_price'] ?? null;
        if (is_numeric($directPrice)) {
            $price = (float) $directPrice;
        }

        $directCurrency = $package['currency'] ?? $package['price_currency'] ?? null;
        if (is_string($directCurrency) && trim($directCurrency) !== '') {
            $currency = strtoupper(trim($directCurrency));
        }

        if (isset($package['prices']) && is_array($package['prices'])) {
            foreach ($package['prices'] as $priceEntry) {
                if (!is_array($priceEntry)) {
                    continue;
                }

                $value = $priceEntry['price'] ?? $priceEntry['amount'] ?? $priceEntry['value'] ?? null;
                if ($price <= 0 && is_numeric($value)) {
                    $price = (float) $value;
                }

                $entryCurrency = $priceEntry['currency'] ?? $priceEntry['price_currency'] ?? null;
                if (is_string($entryCurrency) && trim($entryCurrency) !== '') {
                    $currency = strtoupper(trim($entryCurrency));
                    break;
                }
            }
        }

        return [$price, $currency];
    }
}
