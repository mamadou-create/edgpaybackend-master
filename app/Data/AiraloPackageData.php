<?php

namespace App\Data;

final readonly class AiraloPackageData
{
    public function __construct(
        public string $id,
        public string $title,
        public string $isoCode,
        public float $dataVolume,
        public string $dataUnit,
        public int $validityDays,
        public float $costPrice,
        public string $currency,
        public int $priceGnf = 0,
        public string $operatorName = 'Réseau local',
        public array $networkTypes = [],
        public bool $is5g = false,
        public bool $isFairUsagePolicy = false,
        public string $fairUsagePolicy = '',
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'iso_code' => $this->isoCode,
            'data_volume' => $this->dataVolume,
            'data_unit' => $this->dataUnit,
            'validity_days' => $this->validityDays,
            'cost_price' => $this->costPrice,
            'currency' => $this->currency,
            'price_gnf' => $this->priceGnf,
            'operator_name' => $this->operatorName,
            'network_types' => $this->networkTypes,
            'is_5g' => $this->is5g,
            'is_fair_usage_policy' => $this->isFairUsagePolicy,
            'fair_usage_policy' => $this->fairUsagePolicy,
        ];
    }
}
