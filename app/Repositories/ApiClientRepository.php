<?php

namespace App\Repositories;

use App\Interfaces\ApiClientRepositoryInterface;
use App\Models\ApiClient;
use Illuminate\Support\Facades\Hash;

class ApiClientRepository implements ApiClientRepositoryInterface
{
    
    public function getAll()
    {
        return ApiClient::orderBy('name', 'asc')->get();
    }

    public function create(array $data): ApiClient
    {
        return ApiClient::create($data);
    }

    public function findByClientId(string $clientId): ?ApiClient
    {
        return ApiClient::where('client_id', $clientId)->first();
    }

    public function validateCredentials(string $clientId, string $clientSecret): bool
    {
        $client = $this->findByClientId($clientId);
        
        if (!$client || $client->revoked || !$client->isValid()) {
            return false;
        }

        return Hash::check($clientSecret, $client->client_secret);
    }

    public function revoke(string $clientId): bool
    {
        $client = $this->findByClientId($clientId);
        
        if (!$client) {
            return false;
        }

        $client->update(['revoked' => true]);
        return true;
    }
}