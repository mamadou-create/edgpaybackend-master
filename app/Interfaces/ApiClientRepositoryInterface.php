<?php

namespace App\Interfaces;

use App\Models\ApiClient;

interface ApiClientRepositoryInterface
{
     public function getAll();
    public function create(array $data): ApiClient;
    public function findByClientId(string $clientId): ?ApiClient;
    public function validateCredentials(string $clientId, string $clientSecret): bool;
    public function revoke(string $clientId): bool;
}