<?php

namespace App\Interfaces;

interface ReloadlyServiceInterface
{
    public function authenticate(): array;

    public function detectOperator(string $phone, string $countryCode = 'GN'): array;

    public function getDataPlans(int $operatorId, ?string $recipientPhone = null): array;

    public function topupAirtime(array $payload): array;

    public function topupData(array $payload): array;

    public function getPromotions(int $operatorId): array;

    public function getCommissions(?int $operatorId = null): array;

    public function verifyTransaction(int|string $reloadlyTransactionId): array;
}
