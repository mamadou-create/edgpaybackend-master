<?php

namespace App\Data;

final readonly class ReloadlyBillerDTO
{
    /** @param list<UtilityPlan> $localFixedAmounts */
    public function __construct(
        public int $id,
        public string $name,
        public string $countryISOCode,
        public string $type,
        public string $serviceType,
        public bool $localAmountSupported,
        public ?string $localTransactionCurrencyCode,
        public ?string $internationalTransactionCurrencyCode,
        public string $denominationType,
        public array $localFixedAmounts,
        public ?float $minLocalTransactionAmount,
        public ?float $maxLocalTransactionAmount,
        public bool $requiresInvoice,
    ) {}

    public static function fromReloadly(array $data): self
    {
        $id = (int) $data['id'];
        $localCurrency = self::nullableString(
            $data['localTransactionCurrencyCode']
                ?? $data['localTransactionCurencyCode']
                ?? null,
        );
        $plans = [];

        foreach ($data['localFixedAmounts'] ?? [] as $plan) {
            if (!is_array($plan) || !isset($plan['id'], $plan['amount']) || $localCurrency === null) {
                continue;
            }

            $plans[] = UtilityPlan::fromReloadly($plan, $id, $localCurrency);
        }

        return new self(
            id: $id,
            name: (string) $data['name'],
            countryISOCode: (string) ($data['countryISOCode'] ?? $data['countryIsoCode'] ?? $data['countryCode']),
            type: (string) $data['type'],
            serviceType: (string) $data['serviceType'],
            localAmountSupported: (bool) ($data['localAmountSupported'] ?? false),
            localTransactionCurrencyCode: $localCurrency,
            internationalTransactionCurrencyCode: self::nullableString($data['internationalTransactionCurrencyCode'] ?? null),
            denominationType: (string) $data['denominationType'],
            localFixedAmounts: $plans,
            minLocalTransactionAmount: self::nullableFloat($data['minLocalTransactionAmount'] ?? null),
            maxLocalTransactionAmount: self::nullableFloat($data['maxLocalTransactionAmount'] ?? null),
            requiresInvoice: (bool) ($data['requiresInvoice'] ?? false),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}