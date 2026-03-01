<?php

namespace App\Services;

use App\Interfaces\ApiClientRepositoryInterface;
use App\Interfaces\UserRepositoryInterface;
use App\Models\ApiClient;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

class ApiClientService
{
    public function __construct(
        private ApiClientRepositoryInterface $apiClientRepository,
        private UserRepositoryInterface $userRepositoryInterface
    ) {}


    public function createClientWithToken(array $data): array
    {
        try {

            // Récupérer le rôle avec le slug "api_client"
            $role = Role::where('slug', 'api_client')->first();

            if (!$role) {
                throw new \Exception("Le rôle api_client n'existe pas dans le système");
            }

            // Générer des credentials uniques
            $credentials = User::generateApiClientCredentials();

            $userData = [
                'password' => Hash::make($credentials['password']),
                'display_name' => $data['display_name'],
                'phone' => $credentials['phone'],
                'role_id' => $role->id,
                'status' => true,
                'email_verified_at' => now(),
            ];


            $user = User::create($userData);


            return [
                'client' => $user,
                'client_id' => $credentials['phone'],
                'client_secret' => $credentials['password'],
                'role' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                ],
            ];
        } catch (\Exception $e) {
            throw $e;
        }
    }


    public function createClient(array $data): array
    {
        $credentials = ApiClient::generateClientCredentials();

        $client = $this->apiClientRepository->create([
            'name' => $data['name'],
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'scopes' => $data['scopes'] ?? [],
            'expires_at' => isset($data['expires_in_days'])
                ? now()->addDays($data['expires_in_days'])
                : null,
        ]);

        return [
            'client' => $client,
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'], // Afficher seulement une fois
        ];
    }

    public function generateToken(string $clientId, string $clientSecret): ?array
    {
        if (!$this->apiClientRepository->validateCredentials($clientId, $clientSecret)) {
            return null;
        }

        $client = $this->apiClientRepository->findByClientId($clientId);

        $customClaims = [
            'client_id' => $client->client_id,
            'scopes' => $client->scopes,
            'type' => 'client_credentials'
        ];

        $token = JWTAuth::claims($customClaims)->setTTL(60)->fromUser($client);

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 60 * 60, // 60 minutes
            'scopes' => $client->scopes,
        ];
    }

    public function revokeClient(string $clientId): bool
    {
        return $this->apiClientRepository->revoke($clientId);
    }

    public function getAll()
    {
        return $this->apiClientRepository->getAll();
    }
}
