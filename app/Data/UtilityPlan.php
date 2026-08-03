<?php

namespace App\Data;

final readonly class UtilityPlan
{
    public function __construct(
        public int $id,
        public int $billerId,
        public int $amountId,
        public float $amount,
        public string $description,
        public string $currency,
    ) {}

    public static function fromReloadly(array $data, int $billerId, string $currency): self
    {
        return new self(
            id: (int) $data['id'],
            billerId: $billerId,
            amountId: (int) $data['id'],
            amount: (float) $data['amount'],
            description: (string) ($data['description'] ?? ''),
            currency: $currency,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'biller_id' => $this->billerId,
            'amount_id' => $this->amountId,
            'amount' => $this->amount,
            'description' => $this->description,
            'currency' => $this->currency,
        ];
    }
}