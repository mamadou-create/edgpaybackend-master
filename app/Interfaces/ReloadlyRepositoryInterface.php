<?php

namespace App\Interfaces;

interface ReloadlyRepositoryInterface
{
    public function searchOperator(string $phone, string $countryCode): array;
    public function makeTopup(array $data): array;
    public function getOperatorsByCountry(string $countryCode): array;
    public function getOperatorProducts(int $operatorId): array;
    public function creditWallet(int $amount, ?string $description = null): array;
    public function getBalance(): array;
    public function getTransactionHistory(string $userId, int $perPage = 20): array;
    public function getDataPlans(int $operatorId): array;
    public function getPromotions(int $operatorId): array;
    public function getCommissions(?int $operatorId = null): array;
    public function verifyTransaction(int|string $reloadlyTransactionId): array;
}