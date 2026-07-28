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
        public string $operatorName = '',
        public array $networkTypes = [],
        public string $networkOperatorName = '',
        public string $networkType = '4G',
        public string $countryName = '',
        public bool $is5g = false,
        public bool $isFairUsagePolicy = false,
        public string $fairUsagePolicy = '',
        public string $description = '',
        public array $operatorNames = [],
        public array $includedServices = [],
    ) {}

    public function toArray(): array
    {
        $dataAmount = rtrim(rtrim(number_format($this->dataVolume, 2, '.', ''), '0'), '.') . ' ' . $this->dataUnit;
        return [
            'id' => $this->id,
            'package_id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'iso_code' => $this->isoCode,
            'country_code' => $this->isoCode,
            'country_name' => $this->countryName !== '' ? $this->countryName : $this->title,
            'data_volume' => $this->dataVolume,
            'data_unit' => $this->dataUnit,
            'data_amount' => $dataAmount,
            'validity_days' => $this->validityDays,
            'cost_price' => $this->costPrice,
            'price' => $this->costPrice,
            'currency' => $this->currency,
            'price_gnf' => $this->priceGnf,
            'operator_name' => $this->operatorName,
            'operator_names' => $this->operatorNames,
            'included_services' => $this->includedServices,
            'services' => $this->includedServices,
            'network_types' => $this->networkTypes,
            'is_5g' => $this->is5g,
            'is_fair_usage_policy' => $this->isFairUsagePolicy,
            'fair_usage_policy' => $this->fairUsagePolicy,
            'network' => [
                'operator_name' => $this->networkOperatorName,
                'network_type' => $this->networkType,
                'is_supported' => true,
            ],
            'details' => [
                'validity_policy' => "La période de validité commence lorsque l'eSIM se connecte à un réseau mobile dans sa zone de couverture. Si vous installez l'eSIM en dehors de sa zone de couverture, vous pouvez vous connecter à un réseau à votre arrivée.",
                'renewals' => 'Renouvelez automatiquement votre forfait eSIM lorsque vous avez besoin de plus de données. Activez ou désactivez les renouvellements selon vos besoins.',
                'ip_routing' => "L'adresse IP de l'eSIM peut apparaître en dehors de sa zone de couverture ; cela n'aura aucune incidence sur votre consommation de données.",
            ],
        ];
    }
}
