<?php

namespace App\Interfaces;

interface ReloadlyGiftCardRepositoryInterface
{
    public function listProducts(int $page = 1, int $size = 50, ?string $countryCode = null): array;
    public function getProduct(int $productId): array;
    public function orderGiftCard(array $data): array;
    public function getOrderStatus(int $transactionId): array;
    public function redeemCode(int $transactionId): array;
    public function getTransactionHistory(string $userId, int $perPage = 20): array;
}