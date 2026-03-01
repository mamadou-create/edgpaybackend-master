<?php
// app/Interfaces/DjomyServiceInterface.php

namespace App\Interfaces;

interface DjomyServiceInterface
{
    public function authenticate(): array;
    public function createPayment(array $data): array;
    public function createPaymentWithGateway(array $data): array;
    public function createPaymentWithGatewayExternal(array $data): array;
    public function getPaymentStatus(string $paymentId): array;
    public function getPaymentLinkStatus(string $paymentId): array;
    public function generateLink(array $data): array;
    public function getLink(string $linkId): array;
    public function getLinks(): array;
    public function cancelPayment(string $paymentId): array;
}
