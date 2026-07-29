<?php

namespace App\Interfaces;

interface ReloadlyUtilityRepositoryInterface
{
    public function listBillers(?string $countryCode = null, ?string $type = null, int $page = 1, int $size = 50): array;
    public function getBiller(int $billerId): array;
    public function payBill(array $data): array;
    public function getTransactionStatus(int $transactionId): array;
    public function getBalance(): array;
    public function getTransactionHistory(string $userId, int $perPage = 20): array;
}