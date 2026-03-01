<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApiClient\CreateClientRequest;
use App\Http\Requests\ApiClient\TokenRequest;
use App\Services\ApiClientService;
use App\Classes\ApiResponseClass;
use App\Models\ApiClient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ApiClientController extends Controller
{
    public function __construct(
        private ApiClientService $apiClientService
    ) {}

    /**
     * Authentification User avec phone et password
     */
    public function tokenClient(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|string',
            'client_secret' => 'required|string',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError('Validation Error', $validator->errors(), 422);
        }

        Log::info('User token request', ['client_id' => $request->client_id]);

        $user = User::where('phone', $request->client_id)->first();

        if (!$user) {
            Log::warning('User not found', ['client_id' => $request->client_id]);
            return ApiResponseClass::unauthorized('Identifiants invalides');
        }

        // Vérification du password
        if (!Hash::check($request->client_secret, $user->password)) {
            Log::warning('Password verification failed', ['client_id' => $request->client_id]);
            return ApiResponseClass::unauthorized('Identifiants invalides');
        }

        // Vérifier que le compte est activé
        if (!$user->isActivated()) {
            Log::warning('User account not activated', ['phone' => $request->phone]);
            return ApiResponseClass::sendError('Compte non activé', null, 403);
        }

        try {
            Log::info('Attempting JWT token generation for user');

            // Générer le token avec le guard api-user
            $token = auth()->guard('api-user')->login($user);

            Log::info('JWT token generated successfully for user', [
                'user_id' => $user->id,
                'phone' => $user->phone
            ]);

            return response()->json([
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => 60 * 60, // 60 minutes
            ]);
        } catch (\Exception $e) {
            Log::error('JWT Error for user: ' . $e->getMessage(), [
                'phone' => $request->phone,
                'error' => $e->getTraceAsString()
            ]);
            return ApiResponseClass::serverError('Erreur lors de la génération du token');
        }
    }

    /**
     * 🔹 Créer un nouveau User API
     */
    public function createClientWithToken(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'display_name' => 'required|string',
            ]);

            if ($validator->fails()) {
                return ApiResponseClass::validationError('Validation Error', $validator->errors(), 422);
            }

            $result = $this->apiClientService->createClientWithToken($request->all());

            return ApiResponseClass::sendResponse([
                'client_id' => $result['client_id'],
                'client_secret' => $result['client_secret'],
                'name' => $result['client']->name,
            ], 'Client API créé avec succès. Sauvegardez le client_secret, il ne sera plus affiché.');
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la création du client API');
        }
    }

    public function token(Request $request)
    {
        $request->validate([
            'client_id' => 'required|string',
            'client_secret' => 'required|string',
        ]);

        $client = ApiClient::where('client_id', $request->client_id)->first();

        // dd($client);

        if (!$client || !Hash::check($request->client_secret, $client->client_secret)) {
            return response()->json(['error' => 'Client credentials invalides'], 422);
        }

        if ($client->revoked) {
            return response()->json(['error' => 'Client révoqué'], 401);
        }

        // if ($client->expires_at && now()->gt($client->expires_at)) {
        //     return response()->json(['error' => 'Client expiré'], 401);
        // }

        // On génère le token JWT pour ce client en utilisant le guard 'api-client'
        $token = auth()->guard('api-client')->login($client);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 60 * 60, // 60 minutes
        ]);
    }


    /**
     * 🔹 Créer un nouveau client API
     */
    public function createClient(CreateClientRequest $request): JsonResponse
    {
        try {

            $result = $this->apiClientService->createClient($request->all());

            return ApiResponseClass::sendResponse([
                'client_id' => $result['client_id'],
                'client_secret' => $result['client_secret'],
                'name' => $result['client']->name,
                'scopes' => $result['client']->scopes,
                'expires_at' => $result['client']->expires_at,
            ], 'Client API créé avec succès. Sauvegardez le client_secret, il ne sera plus affiché.');
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la création du client API');
        }
    }

    /**
     * 🔹 Obtenir un token d'accès (Client Credentials Grant)
     */
    public function getToken(TokenRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $tokenData = $this->apiClientService->generateToken(
                $validated['client_id'],
                $validated['client_secret']
            );

            if (!$tokenData) {
                return ApiResponseClass::unauthorized('Identifiants client invalides');
            }

            return ApiResponseClass::sendResponse($tokenData, 'Token généré avec succès');
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la génération du token');
        }
    }

    /**
     * 🔹 Révoquer un client API
     */
    public function revokeClient(Request $request, string $clientId): JsonResponse
    {
        try {
            $success = $this->apiClientService->revokeClient($clientId);

            if (!$success) {
                return ApiResponseClass::notFound('Client API non trouvé');
            }

            return ApiResponseClass::sendResponse([], 'Client API révoqué avec succès');
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la révocation du client');
        }
    }

    /**
     * 🔹 Lister les clients API de l'utilisateur
     */
    public function listClients(): JsonResponse
    {
        try {

            $clients = $this->apiClientService->getAll();
            return ApiResponseClass::sendResponse($clients, 'Liste des clients API');
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des clients');
        }
    }
}
