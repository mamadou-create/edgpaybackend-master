<?php

namespace App\Services;

use App\Enums\PaymentLinkStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Interfaces\DjomyServiceInterface;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Exception;

class DjomyService implements DjomyServiceInterface
{
    public ?User $user;
    private ?string $baseUrl;
    private ?string $clientId;
    private ?string $clientSecret;
    private ?string $partnerDomain = null;
    private ?string $accessToken = null;
    private ?string $returnUrl = null;
    private ?string $cancelUrl = null;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.djomy.base_url') ?? '', '/');
        $this->clientId = config('services.djomy.client_id');
        $this->clientSecret = config('services.djomy.client_secret');
        $this->partnerDomain = config('services.djomy.domain', request()->getHost() ?? 'edgpayapi.mdinggn.com');
        $this->returnUrl = config('services.djomy.return_url');
        $this->cancelUrl = config('services.djomy.cancel_url');
        // ⚠️ Ne pas lire le cache ici: selon la config, le cache peut être en DB
        // (et donc requérir une connexion MySQL au boot).
        $this->accessToken = null;
        $this->user = Auth::guard()->user();
    }

    private function loadAccessTokenFromCache(): void
    {
        if (!empty($this->accessToken)) {
            return;
        }

        try {
            $cached = Cache::get('djomy_access_token');
            if (is_string($cached) && $cached !== '') {
                $this->accessToken = $cached;
            }
        } catch (\Throwable $e) {
            Log::warning('Djomy token cache read failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 🔐 Génère le header X-API-KEY au format <clientId>:<signature HMAC-SHA256>
     */
    private function generateApiKeyHeader(): string
    {
        $clientId = $this->clientId ?? '';
        $clientSecret = $this->clientSecret ?? '';

        $signature = hash_hmac('sha256', $clientId, $clientSecret);

        //dd("{$this->clientId}:{$signature}");
        return "{$clientId}:{$signature}";
    }

    /**
     * ⚙️ Construit les headers pour les requêtes
     */
    private function getHeaders(bool $withAuth = true): array
    {
        $headers = [
            'X-API-KEY' => $this->generateApiKeyHeader(),
            'X-PARTNER-DOMAIN' => $this->partnerDomain,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($withAuth && $this->accessToken) {
            $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        }

        return $headers;
    }

    /**
     * 🌐 Requête générique vers Djomy
     */
    private function makeRequest(string $method, string $endpoint, array $data = [], bool $withAuth = true): array
    {
        if (empty($this->baseUrl)) {
            return [
                'success' => false,
                'status'  => 500,
                'message' => 'Passerelle Djomy non configurée (DJOMY_BASE_URL manquante).',
                'data'    => [],
            ];
        }

        $url = "{$this->baseUrl}{$endpoint}";

        try {
            if ($withAuth && !$this->accessToken) {
                $this->loadAccessTokenFromCache();
            }

            if ($withAuth && !$this->accessToken) {
                $authResult = $this->authenticate();
                if (!$authResult['success']) {
                    return $authResult;
                }
            }

            $http = Http::withHeaders($this->getHeaders($withAuth))
                ->connectTimeout(3)
                ->timeout(8)
                ->retry(0, 0);

            $response = match (strtolower($method)) {
                'get'    => $http->get($url, $data),
                'post'   => $http->post($url, $data),
                'put'    => $http->put($url, $data),
                'delete' => $http->delete($url, $data),
                default  => throw new Exception("Méthode HTTP invalide : {$method}"),
            };

            $json = $response->json();

            Log::info('Djomy API Request', [
                'url' => $url,
                'method' => strtoupper($method),
                'status' => $response->status(),
            ]);

            return [
                'success' => $response->successful(),
                'status'  => $response->status(),
                'message' => $json['message'] ?? $response->body(),
                'data'    => $json['data'] ?? $json,
            ];
        } catch (Exception $e) {
            Log::error('Djomy API Exception', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status'  => 500,
                'message' => 'Erreur lors de la communication avec Djomy : ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 🔑 Authentification Djomy
     */
    public function authenticate(): array
    {
        if (empty($this->baseUrl)) {
            Log::error('Djomy Auth Exception', ['error' => 'DJOMY_BASE_URL non configurée dans .env']);
            return [
                'success' => false,
                'status'  => 500,
                'message' => 'Passerelle Djomy non configurée (DJOMY_BASE_URL manquante).',
                'data'    => [],
            ];
        }

        try {
            $url = "{$this->baseUrl}/v1/auth";
            $headers = [
                'X-API-KEY' => $this->generateApiKeyHeader(),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-PARTNER-DOMAIN' => $this->partnerDomain,
            ];

            // Selon la doc Djomy, le body de /v1/auth est vide.
            // Les credentials passent uniquement dans X-API-KEY.
            $body = new \stdClass(); // encode en {} au lieu de []

            Log::info('Djomy Auth Request', [
                'url'          => $url,
                'x_api_key'    => substr($headers['X-API-KEY'], 0, 30) . '...',
                'partner_domain' => $this->partnerDomain,
            ]);

            $response = Http::withHeaders($headers)
                ->connectTimeout(3)
                ->timeout(8)
                ->retry(0, 0)
                ->post($url, []);
            $json = $response->json();

            Log::info('Djomy Auth Response', [
                'status'   => $response->status(),
                'body_raw' => $response->body(),
                'json'     => $json,
            ]);

            if (in_array($response->status(), [200, 201]) && isset($json['data']['accessToken'])) {
                $this->accessToken = $json['data']['accessToken'];

                try {
                    Cache::put('djomy_access_token', $this->accessToken, $json['data']['expiresIn'] ?? 3600);
                } catch (\Throwable $e) {
                    Log::warning('Djomy token cache write failed', ['error' => $e->getMessage()]);
                }

                Log::info('Djomy authentication successful', [
                    'expires_in' => $json['data']['expiresIn'] ?? 3600
                ]);

                return [
                    'success' => true,
                    'message' => 'Authentification réussie',
                    'status'  => 200,
                    'data'    => [
                        'access_token' => $json['data']['accessToken'],
                        'token_type'   => $json['data']['tokenType'] ?? 'Bearer',
                        'expires_in'   => $json['data']['expiresIn'] ?? 3600,
                    ],
                ];
            }

            Log::error('Djomy authentication failed', [
                'status' => $response->status(),
                'response' => $json,
            ]);

            return [
                'success' => false,
                'message' => $json['message'] ?? 'Erreur d\'authentification Djomy',
                'errors'  => $json['errors'] ?? [],
                'status'  => $response->status(),
            ];
        } catch (Exception $e) {
            Log::error('Djomy Auth Exception', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Exception : ' . $e->getMessage(),
                'status'  => 500,
            ];
        }
    }

    /**
     * 💳 Crée un paiement direct avec vérification immédiate du statut
     */
    public function createPayment(array $data): array
    {

        try {
            // 🔹 Génération automatique du merchant_payment_reference
            $merchantReference = $this->generateMerchantPaymentReference();

            // 🔹 Création du paiement local
            $payment = Payment::create([
                'merchant_payment_reference' => $merchantReference,
                'payer_identifier' => $data['payerIdentifier'] ?? null,
                'payment_method' => $data['paymentMethod'] ?? null,
                'amount' => $data['amount'],
                'country_code' => $data['countryCode'],
                'currency_code' => $data['currencyCode'] ?? 'GNF',
                'description' => $data['description'],
                'status' => PaymentStatus::PENDING->value,
                'payment_type' => PaymentType::DIRECT->value,
                'service_type' =>  $data['serviceType'],
                'compteur_id' =>  $data['compteurId'],
                'phone' =>  $data['payerIdentifier'],
                'raw_request' => $data,
                'metadata' => $data,
                'user_id' => $this->user->id
            ]);

            Log::info('Payment created locally', ['payment_id' => $payment->id]);

            // 🔹 Préparation du payload pour l'API
            $apiPayload = array_merge($data, [
                'merchantPaymentReference' => $merchantReference,
            ]);

            // 🔹 Appel API
            $apiResult = $this->makeRequest('post', '/v1/payments', $apiPayload);

            // 🔹 Si l'API ne renvoie pas success = true → rollback
            if (empty($apiResult['success']) || $apiResult['success'] !== true) {
                Log::warning('API payment creation failed, rolling back...', ['response' => $apiResult]);

                // Enregistrement d'une trace hors transaction
                $payment->status = PaymentStatus::FAILED->value;
                $payment->raw_response = $apiResult;
                $payment->saveQuietly();

                return [
                    'success' => false,
                    'status' => $apiResult['status'] ?? 400,
                    'message' => $apiResult['message'] ?? 'Échec de la création du paiement.',
                    'payment' => $payment,
                ];
            }

            // 🔹 Si succès → mise à jour + commit
            $payment->update([
                'status' => PaymentStatus::PENDING->value,
                'transaction_id' => $apiResult['data']['transactionId'] ?? null,
                'payment_method' => $apiResult['data']['paymentMethod'] ?? $payment->payment_method,
                'raw_response' => $apiResult,
            ]);


            Log::info('Payment committed successfully', ['payment_id' => $payment->id]);

            // 🔄 VÉRIFICATION IMMÉDIATE DU STATUT
            $immediateStatusCheck = $this->performImmediateStatusCheck($payment);

            return array_merge($apiResult, [
                'payment' => $payment,
                'immediate_status_check' => $immediateStatusCheck,
                'data' => array_merge($apiResult['data'] ?? [], [
                    'local_payment_id' => $payment->id,
                    'merchant_reference' => $payment->merchant_payment_reference,
                    'current_status' => $payment->refresh()->status,
                ]),
            ]);
        } catch (Exception $e) {
            Log::error('Payment creation failed', ['error' => $e->getMessage(), 'data' => $data]);
            return [
                'success' => false,
                'status' => 500,
                'message' => 'Erreur lors de la création du paiement : ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 🌍 Crée un paiement avec gateway avec vérification immédiate du statut
     */
    public function createPaymentWithGateway(array $data): array
    {

        try {
            // 🔹 Génération automatique du merchant_payment_reference
            $merchantReference = $this->generateMerchantPaymentReference();

            // 🔹 Création locale
            $payment = Payment::create([
                'merchant_payment_reference' => $merchantReference,
                'payer_identifier' => $data['payerNumber'] ?? null,
                'payment_method' => $data['paymentMethod'] ?? PaymentMethod::OM->value,
                'amount' => $data['amount'],
                'country_code' => $data['countryCode'],
                'currency_code' => $data['currencyCode'] ?? 'GNF',
                'description' => $data['description'],
                'status' => PaymentStatus::PENDING->value,
                'payment_type' => PaymentType::GATEWAY->value,
                'service_type' =>  $data['serviceType'],
                'compteur_id' =>  $data['compteurId'],
                'phone' =>  $data['payerNumber'] ?? null,
                'raw_request' => $data,
                'metadata' => $data,
                'user_id' => $this->user->id,
            ]);

            Log::info('Gateway payment created locally', ['payment_id' => $payment->id]);

            // 🔹 Préparation du payload API
            $apiPayload = array_merge($data, [
                'merchantPaymentReference' => $merchantReference,
                'returnUrl' => $this->returnUrl,
                'cancelUrl' => $this->cancelUrl,
            ]);

            // 🔹 Appel API
            $apiResult = $this->makeRequest('post', '/v1/payments/gateway', $apiPayload);

            // 🔹 Si l'API échoue → rollback + enregistrement d'une trace
            if (empty($apiResult['success']) || $apiResult['success'] !== true) {
                Log::warning('Gateway API payment creation failed, rolling back...', ['response' => $apiResult]);

                // Sauvegarde hors transaction pour garder une trace
                $payment->status = PaymentStatus::FAILED->value;
                $payment->raw_response = $apiResult;
                $payment->saveQuietly();

                return [
                    'success' => false,
                    'status' => $apiResult['status'] ?? 400,
                    'message' => $apiResult['message'] ?? 'Échec de la création du paiement gateway.',
                    'data' => $apiResult['data'] ?? [],
                ];
            }

            // 🔹 Si succès → mise à jour des infos + commit
            $payment->update([
                'status' => PaymentStatus::PENDING->value,
                'transaction_id' => $apiResult['data']['transactionId'] ?? null,
                'external_reference' => $apiResult['data']['local_payment_id'] ?? null,
                'gateway_url' => $apiResult['data']['gatewayUrl'] ?? $apiResult['data']['redirectUrl'] ?? null,
                'payment_method' => $apiResult['data']['paymentMethod'] ?? $payment->payment_method,
                'raw_response' => $apiResult,
            ]);


            Log::info('Gateway payment committed successfully', ['payment_id' => $payment->id]);

            // 🔄 VÉRIFICATION IMMÉDIATE DU STATUT
            $immediateStatusCheck = $this->performImmediateStatusCheck($payment);

            return array_merge($apiResult, [
                'payment' => $payment,
                'immediate_status_check' => $immediateStatusCheck,
                'data' => array_merge($apiResult['data'] ?? [], [
                    'local_payment_id' => $payment->id,
                    'merchant_reference' => $payment->merchant_payment_reference,
                    'current_status' => $payment->refresh()->status,
                ]),
            ]);
        } catch (Exception $e) {
            Log::error('Gateway payment creation failed', ['error' => $e->getMessage(), 'data' => $data]);
            return [
                'success' => false,
                'status' => 500,
                'message' => 'Erreur lors de la création du paiement gateway : ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 🌍 Crée un paiement avec gateway avec vérification immédiate du statut
     */
    public function createPaymentWithGatewayExternal(array $data): array
    {

        try {
            // 🔹 Génération automatique du merchant_payment_reference
            $merchantReference = $this->generateMerchantPaymentReference();

            // 🔹 Création locale
            $payment = Payment::create([
                'merchant_payment_reference' => $merchantReference,
                'payer_identifier' => $data['payerNumber'] ?? null,
                'payment_method' => $data['paymentMethod'] ?? PaymentMethod::OM->value,
                'amount' => $data['amount'],
                'country_code' => $data['countryCode'],
                'currency_code' => $data['currencyCode'] ?? 'GNF',
                'description' => $data['description'],
                'status' => PaymentStatus::PENDING->value,
                'payment_type' => PaymentType::GATEWAY->value,
                'phone' =>  $data['payerNumber'] ?? null,
                'raw_request' => $data,
                'metadata' => $data,
                'user_id' => $this->user->id,
            ]);

            Log::info('Gateway payment created locally', ['payment_id' => $payment->id]);

            // 🔹 Préparation du payload API
            $apiPayload = array_merge($data, [
                'merchantPaymentReference' => $merchantReference,
                'returnUrl' => $this->returnUrl,
                'cancelUrl' => $this->cancelUrl,
            ]);

            // 🔹 Appel API
            $apiResult = $this->makeRequest('post', '/v1/payments/gateway', $apiPayload);

            // 🔹 Si l'API échoue → rollback + enregistrement d'une trace
            if (empty($apiResult['success']) || $apiResult['success'] !== true) {
                Log::warning('Gateway API payment creation failed, rolling back...', ['response' => $apiResult]);

                // Sauvegarde hors transaction pour garder une trace
                $payment->status = PaymentStatus::FAILED->value;
                $payment->raw_response = $apiResult;
                $payment->saveQuietly();

                return [
                    'success' => false,
                    'status' => $apiResult['status'] ?? 400,
                    'message' => $apiResult['message'] ?? 'Échec de la création du paiement gateway.',
                    'data' => $apiResult['data'] ?? [],
                ];
            }

            // 🔹 Si succès → mise à jour des infos + commit
            $payment->update([
                'status' => PaymentStatus::PENDING->value,
                'transaction_id' => $apiResult['data']['transactionId'] ?? null,
                'external_reference' => $apiResult['data']['local_payment_id'] ?? null,
                'gateway_url' => $apiResult['data']['gatewayUrl'] ?? $apiResult['data']['redirectUrl'] ?? null,
                'payment_method' => $apiResult['data']['paymentMethod'] ?? $payment->payment_method,
                'raw_response' => $apiResult,
            ]);


            Log::info('Gateway payment committed successfully', ['payment_id' => $payment->id]);

            // 🔄 VÉRIFICATION IMMÉDIATE DU STATUT
            $immediateStatusCheck = $this->performImmediateStatusCheck($payment);

            return array_merge($apiResult, [
                'payment' => $payment,
                'immediate_status_check' => $immediateStatusCheck,
                'data' => array_merge($apiResult['data'] ?? [], [
                    'local_payment_id' => $payment->id,
                    'merchant_reference' => $payment->merchant_payment_reference,
                    'current_status' => $payment->refresh()->status,
                ]),
            ]);
        } catch (Exception $e) {
            Log::error('Gateway payment creation failed', ['error' => $e->getMessage(), 'data' => $data]);
            return [
                'success' => false,
                'status' => 500,
                'message' => 'Erreur lors de la création du paiement gateway : ' . $e->getMessage(),
            ];
        }
    }



    /**
     * 🔎 Statut d'un paiement avec mapping correct des statuts Djomy
     */
    public function getPaymentStatus(string $paymentId): array
    {
        Log::info('Starting payment status check', ['payment_id' => $paymentId]);

        try {
            // 🚀 Appel à l'API Djomy
            $apiResult = $this->makeRequest('get', "/v1/payments/{$paymentId}/status");

            Log::debug('Djomy API response received', [
                'payment_id' => $paymentId,
                'api_success' => $apiResult['success'],
                'api_status' => $apiResult['status'],
                'djomy_status' => $apiResult['data']['status'] ?? 'unknown'
            ]);

            if (!$apiResult['success']) {
                Log::error('Djomy API status check failed', [
                    'payment_id' => $paymentId,
                    'api_status' => $apiResult['status'],
                    'api_message' => $apiResult['message'] ?? 'No message'
                ]);

                return [
                    'success' => false,
                    'status' => $apiResult['status'],
                    'message' => $apiResult['message'] ?? 'Erreur lors de la vérification du statut Djomy',
                    'data' => $apiResult['data'] ?? [],
                ];
            }

            // 🔍 Recherche du paiement local
            $payment = Payment::where('transaction_id', $paymentId)
                ->first();

            if (!$payment) {
                Log::warning('Local payment not found for status check', [
                    'payment_id' => $paymentId,
                    'search_criteria' => ['external_reference', 'merchant_payment_reference']
                ]);

                return [
                    'success' => true,
                    'status' => 200,
                    'message' => 'Statut Djomy récupéré mais paiement local non trouvé',
                    'data' => $apiResult['data'] ?? [],
                ];
            }

            Log::debug('Local payment found', [
                'local_payment_id' => $payment->id,
                'current_status' => $payment->status,
                'merchant_reference' => $payment->merchant_payment_reference
            ]);

            // 🗺️ Mapping du statut selon la documentation Djomy
            $djomyStatus = $apiResult['data']['status'] ?? null;
            $localStatus = $this->mapDjomyStatusToLocal($djomyStatus);

            Log::debug('Status mapping Djomy → Local', [
                'djomy_status' => $djomyStatus,
                'mapped_local_status' => $localStatus,
                'mapping_rule' => $this->getMappingRule($djomyStatus)
            ]);

            // 📝 Préparation des données de mise à jour
            $updateData = [
                'status' => $localStatus,
                'raw_response' => array_merge($payment->raw_response ?? [], [
                    'status_check' => $apiResult['data'],
                    'last_checked_at' => now()->toISOString(),
                ]),
            ];

            // Mettre à jour la méthode de paiement si disponible
            if (isset($apiResult['data']['paymentMethod'])) {
                $updateData['payment_method'] = $apiResult['data']['paymentMethod'];
            }

            if (!$apiResult['success']) {
                $payment->increment('processing_attempts');
                return $this->errorResult($payment, $apiResult['error']);
            }

            // 5️⃣ Marquer comme traité
            // $this->markAsProcessed($payment, $apiResult);

            // 💾 Mise à jour du paiement avec logs détaillés
            Log::info('Payment status before update', [
                'payment_id' => $payment->id,
                'current_status' => $payment->status,
                'djomy_status' => $djomyStatus,
                'will_update_to' => $localStatus
            ]);

            $updateResult = $payment->update($updateData);

            // Recharger le modèle pour voir les changements
            $payment->refresh();

            Log::info('Payment status after update', [
                'payment_id' => $payment->id,
                'new_status' => $payment->status,
                'update_success' => $updateResult
            ]);

            if ($updateResult) {
                Log::info('Payment status updated successfully', [
                    'payment_id' => $payment->id,
                    'external_reference' => $paymentId,
                    'djomy_status' => $djomyStatus,
                    'local_status' => $localStatus,
                ]);
            } else {
                Log::error('Payment update failed', [
                    'payment_id' => $payment->id,
                    'update_data' => $updateData
                ]);
            }

            // 🎉 Retour de la réponse
            return [
                'success' => true,
                'status' => 200,
                'message' => 'Statut du paiement récupéré et mis à jour avec succès',
                'data' => array_merge($apiResult['data'], [
                    'local_payment_id' => $payment->id,
                    'local_status' => $localStatus,
                    'local_payment' => $payment,
                    'update_success' => $updateResult,
                    'status_mapping' => [
                        'djomy' => $djomyStatus,
                        'local' => $localStatus,
                        'rule' => $this->getMappingRule($djomyStatus)
                    ]
                ]),
            ];
        } catch (Exception $e) {
            Log::error('Payment status check failed with exception', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'status' => 500,
                'message' => 'Erreur lors de la récupération du statut : ' . $e->getMessage(),
            ];
        }
    }

    /**
     * ❌ Cancel Payment - Version corrigée qui récupère d'abord la transaction
     */
    public function cancelPayment(string $paymentId): array
    {
         Log::info('Starting payment status check', ['payment_id' => $paymentId]);

        try {
            // 🚀 Appel à l'API Djomy
            $apiResult = $this->makeRequest('get', "/v1/payments/{$paymentId}/status");

            Log::debug('Djomy API response received', [
                'payment_id' => $paymentId,
                'api_success' => $apiResult['success'],
                'api_status' => $apiResult['status'],
                'djomy_status' => $apiResult['data']['status'] ?? 'unknown'
            ]);

            if (!$apiResult['success']) {
                Log::error('Djomy API status check failed', [
                    'payment_id' => $paymentId,
                    'api_status' => $apiResult['status'],
                    'api_message' => $apiResult['message'] ?? 'No message'
                ]);

                return [
                    'success' => false,
                    'status' => $apiResult['status'],
                    'message' => $apiResult['message'] ?? 'Erreur lors de la vérification du statut Djomy',
                    'data' => $apiResult['data'] ?? [],
                ];
            }

            // 🔍 Recherche du paiement local
            $payment = Payment::where('transaction_id', $paymentId)
                ->first();

            if (!$payment) {
                Log::warning('Local payment not found for status check', [
                    'payment_id' => $paymentId,
                    'search_criteria' => ['external_reference', 'merchant_payment_reference']
                ]);

                return [
                    'success' => true,
                    'status' => 200,
                    'message' => 'Statut Djomy récupéré mais paiement local non trouvé',
                    'data' => $apiResult['data'] ?? [],
                ];
            }

            Log::debug('Local payment found', [
                'local_payment_id' => $payment->id,
                'current_status' => $payment->status,
                'merchant_reference' => $payment->merchant_payment_reference
            ]);

            // 🗺️ Mapping du statut selon la documentation Djomy
            $djomyStatus = $apiResult['data']['status'] ?? null;
            $localStatus = $this->mapDjomyStatusToLocal($djomyStatus);

            Log::debug('Status mapping Djomy → Local', [
                'djomy_status' => $djomyStatus,
                'mapped_local_status' => $localStatus,
                'mapping_rule' => $this->getMappingRule($djomyStatus)
            ]);

            // 📝 Préparation des données de mise à jour
            $updateData = [
                'status' => $localStatus,
                'raw_response' => array_merge($payment->raw_response ?? [], [
                    'status_check' => $apiResult['data'],
                    'last_checked_at' => now()->toISOString(),
                ]),
            ];

            // Mettre à jour la méthode de paiement si disponible
            if (isset($apiResult['data']['paymentMethod'])) {
                $updateData['payment_method'] = $apiResult['data']['paymentMethod'];
            }

            if (!$apiResult['success']) {
                $payment->increment('processing_attempts');
                return $this->errorResult($payment, $apiResult['error']);
            }

            // 5️⃣ Marquer comme traité
            // $this->markAsProcessed($payment, $apiResult);

            // 💾 Mise à jour du paiement avec logs détaillés
            Log::info('Payment status before update', [
                'payment_id' => $payment->id,
                'current_status' => $payment->status,
                'djomy_status' => $djomyStatus,
                'will_update_to' => $localStatus
            ]);

            $updateResult = $payment->update($updateData);

            // Recharger le modèle pour voir les changements
            $payment->refresh();

            Log::info('Payment status after update', [
                'payment_id' => $payment->id,
                'new_status' => $payment->status,
                'update_success' => $updateResult
            ]);

            if ($updateResult) {
                Log::info('Payment status updated successfully', [
                    'payment_id' => $payment->id,
                    'external_reference' => $paymentId,
                    'djomy_status' => $djomyStatus,
                    'local_status' => $localStatus,
                ]);
            } else {
                Log::error('Payment update failed', [
                    'payment_id' => $payment->id,
                    'update_data' => $updateData
                ]);
            }

            // 🎉 Retour de la réponse
            return [
                'success' => true,
                'status' => 200,
                'message' => 'Statut du paiement récupéré et mis à jour avec succès',
                'data' => array_merge($apiResult['data'], [
                    'local_payment_id' => $payment->id,
                    'local_status' => $localStatus,
                    'local_payment' => $payment,
                    'update_success' => $updateResult,
                    'status_mapping' => [
                        'djomy' => $djomyStatus,
                        'local' => $localStatus,
                        'rule' => $this->getMappingRule($djomyStatus)
                    ]
                ]),
            ];
        } catch (Exception $e) {
            Log::error('Payment status check failed with exception', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'status' => 500,
                'message' => 'Erreur lors de la récupération du statut : ' . $e->getMessage(),
            ];
        }
    }


    /**
     * 🔗 Génère un lien de paiement
     */
    public function generateLink(array $data): array
    {

        try {
            // 🔹 Création locale du lien avant appel API
            $paymentLink = PaymentLink::create([
                'amount_to_pay' => $data['amountToPay'],
                'link_name' => $data['linkName'],
                'phone_number' => $data['phoneNumber'],
                'description' => $data['description'],
                'country_code' => $data['countryCode'],
                'payment_link_usage_type' => $data['paymentLinkUsageType'],
                'expires_at' => $data['expiresAt'] ?? null,
                'date_from' => $data['dateFrom'],
                'valid_until' => $data['validUntil'] ?? null,
                'custom_fields' => $data['customFields'] ?? [],
                'status' => PaymentLinkStatus::PENDING->value,
                'raw_request' => $data,
                'user_id' => $this->user->id,
            ]);

            Log::info('Payment link created locally', ['link_id' => $paymentLink->id]);

            // 🔹 Appel à l’API externe
            $apiResult = $this->makeRequest('post', '/v1/links', $data);

            // 🔹 Si l’API échoue, rollback + sauvegarde du statut échoué
            if (empty($apiResult['success']) || $apiResult['success'] !== true) {
                Log::warning('Payment link API creation failed, rolling back...', ['response' => $apiResult]);

                // Sauvegarde hors transaction pour garder une trace
                $paymentLink->status = PaymentLinkStatus::FAILED->value;
                $paymentLink->raw_response = $apiResult;
                $paymentLink->saveQuietly();

                return [
                    'success' => false,
                    'status' => $apiResult['status'] ?? 400,
                    'message' => $apiResult['message'] ?? 'Échec de la génération du lien de paiement.',
                    'payment_link' => $paymentLink,
                ];
            }

            // 🔹 Mise à jour locale avec les données de l’API si succès
            $paymentLink->update([
                'status' => PaymentLinkStatus::PENDING->value,
                'reference' => $apiResult['data']['reference'] ?? null,
                'payment_link_reference' => $apiResult['data']['paymentLinkReference'] ?? null,
                'external_link_id' => $apiResult['data']['id'] ?? $apiResult['data']['localLinkId'] ?? null,
                'link_url' => $apiResult['data']['url'] ?? $apiResult['data']['paymentPageUrl'] ?? null,
                'raw_response' => $apiResult,
            ]);


            Log::info('Payment link committed successfully', ['link_id' => $paymentLink->id]);

            return array_merge($apiResult, [
                'payment_link' => $paymentLink,
                'data' => array_merge($apiResult['data'] ?? [], [
                    'local_link_id' => $paymentLink->id,
                ]),
            ]);
        } catch (Exception $e) {
            Log::error('Payment link creation failed', ['error' => $e->getMessage(), 'data' => $data]);

            return [
                'success' => false,
                'status' => 500,
                'message' => 'Erreur lors de la génération du lien de paiement : ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 🔎 Statut d'un paiement avec mapping correct des statuts Djomy
     */
    public function getPaymentLinkStatus(string $paymentId): array
    {
        Log::info('Starting payment status check', ['payment_id' => $paymentId]);

        try {
            // 🚀 Appel à l'API Djomy
            $apiResult = $this->makeRequest('get', "/v1/payments/{$paymentId}/status");

            Log::debug('Djomy API response received', [
                'payment_id' => $paymentId,
                'api_success' => $apiResult['success'],
                'api_status' => $apiResult['status'],
                'djomy_status' => $apiResult['data']['status'] ?? 'unknown'
            ]);

            if (!$apiResult['success']) {
                Log::error('Djomy API status check failed', [
                    'payment_id' => $paymentId,
                    'api_status' => $apiResult['status'],
                    'api_message' => $apiResult['message'] ?? 'No message'
                ]);

                return [
                    'success' => false,
                    'status' => $apiResult['status'],
                    'message' => $apiResult['message'] ?? 'Erreur lors de la vérification du statut Djomy',
                    'data' => $apiResult['data'] ?? [],
                ];
            }

            // 🔍 Recherche du paiement local
            $payment = PaymentLink::where('reference', $paymentId)
                ->orWhere('payment_link_reference', $paymentId)
                ->first();

            if (!$payment) {
                Log::warning('Local payment not found for status check', [
                    'payment_id' => $paymentId,
                    'search_criteria' => ['external_reference', 'merchant_payment_reference']
                ]);

                return [
                    'success' => true,
                    'status' => 200,
                    'message' => 'Statut Djomy récupéré mais paiement local non trouvé',
                    'data' => $apiResult['data'] ?? [],
                ];
            }

            Log::debug('Local payment found', [
                'local_payment_id' => $payment->id,
                'current_status' => $payment->status,
                'merchant_reference' => $payment->merchant_payment_reference
            ]);

            // 🗺️ Mapping du statut selon la documentation Djomy
            $djomyStatus = $apiResult['data']['status'] ?? null;
            $localStatus = $this->mapDjomyStatusToLocal($djomyStatus);

            Log::debug('Status mapping Djomy → Local', [
                'djomy_status' => $djomyStatus,
                'mapped_local_status' => $localStatus,
                'mapping_rule' => $this->getMappingRule($djomyStatus)
            ]);

            // 📝 Préparation des données de mise à jour
            $updateData = [
                'status' => $localStatus,
                'raw_response' => array_merge($payment->raw_response ?? [], [
                    'status_check' => $apiResult['data'],
                    'last_checked_at' => now()->toISOString(),
                ]),
            ];

            // Mettre à jour la méthode de paiement si disponible
            if (isset($apiResult['data']['paymentMethod'])) {
                $updateData['payment_method'] = $apiResult['data']['paymentMethod'];
            }

            // 💾 Mise à jour du paiement avec logs détaillés
            Log::info('Payment status before update', [
                'payment_id' => $payment->id,
                'current_status' => $payment->status,
                'djomy_status' => $djomyStatus,
                'will_update_to' => $localStatus
            ]);

            $updateResult = $payment->update($updateData);

            // Recharger le modèle pour voir les changements
            $payment->refresh();

            Log::info('Payment status after update', [
                'payment_id' => $payment->id,
                'new_status' => $payment->status,
                'update_success' => $updateResult
            ]);

            if ($updateResult) {
                Log::info('Payment status updated successfully', [
                    'payment_id' => $payment->id,
                    'external_reference' => $paymentId,
                    'djomy_status' => $djomyStatus,
                    'local_status' => $localStatus,
                ]);
            } else {
                Log::error('Payment update failed', [
                    'payment_id' => $payment->id,
                    'update_data' => $updateData
                ]);
            }

            // 🎉 Retour de la réponse
            return [
                'success' => true,
                'status' => 200,
                'message' => 'Statut du paiement récupéré et mis à jour avec succès',
                'data' => array_merge($apiResult['data'], [
                    'local_payment_id' => $payment->id,
                    'local_status' => $localStatus,
                    'local_payment' => $payment,
                    'update_success' => $updateResult,
                    'status_mapping' => [
                        'djomy' => $djomyStatus,
                        'local' => $localStatus,
                        'rule' => $this->getMappingRule($djomyStatus)
                    ]
                ]),
            ];
        } catch (Exception $e) {
            Log::error('Payment status check failed with exception', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'status' => 500,
                'message' => 'Erreur lors de la récupération du statut : ' . $e->getMessage(),
            ];
        }
    }



    /**
     * 📄 Récupère les infos d'un lien
     */
    public function getLink(string $linkId): array
    {
        try {
            $apiResult = $this->makeRequest('get', "/v1/links/{$linkId}");
            $paymentLink = PaymentLink::where('reference', $linkId)
                ->orWhere('payment_link_reference', $linkId)->first();

            // 🗺️ Mapping du statut selon la documentation Djomy
            $djomyStatus = $apiResult['data']['status'] ?? null;

            $localStatus = $this->mapDjomyStatusLinkToLocal($djomyStatus);

            // 🔹 Si l’API échoue, rollback + sauvegarde du statut échoué
            if (empty($apiResult['success']) || $apiResult['success'] !== true) {
                Log::warning('Payment link API creation failed, rolling back...', ['response' => $apiResult]);

                if ($paymentLink) {
                    // Sauvegarde hors transaction pour garder une trace
                    $paymentLink->status = PaymentLinkStatus::FAILED->value;
                    $paymentLink->raw_response = $apiResult;
                    $paymentLink->saveQuietly();
                }

                return [
                    'success' => false,
                    'status' => $apiResult['status'] ?? 400,
                    'message' => $apiResult['message'] ?? 'Échec de la génération du lien de paiement.',
                    'payment_link' => $paymentLink,
                ];
            }

            if ($paymentLink) {
                $paymentLink->update([
                    'status' => $localStatus,
                    'transaction_id' => $apiResult['data']['transactionId'] ?? null,
                    'raw_response' => array_merge($paymentLink->raw_response ?? [], [
                        'status_check' => $apiResult['data'],
                        'last_checked_at' => now()->toISOString(),
                    ]),
                ]);

                Log::info('Payment link updated with status check', ['link_id' => $paymentLink->id]);
            }

            return array_merge($apiResult, ['data' => array_merge($apiResult['data'] ?? [], ['local_link' => $paymentLink])]);
        } catch (Exception $e) {
            Log::error('Payment link retrieval failed', ['link_id' => $linkId, 'error' => $e->getMessage()]);
            return ['success' => false, 'status' => 500, 'message' => 'Erreur lors de la récupération du lien : ' . $e->getMessage()];
        }
    }

    /**
     * 📋 Liste des liens
     */
    public function getLinks(): array
    {
        try {
            $apiResult = $this->makeRequest('get', '/v1/links');
            return $apiResult;
        } catch (Exception $e) {
            Log::error('Payment links retrieval failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'status' => 500, 'message' => 'Erreur lors de la récupération des liens : ' . $e->getMessage()];
        }
    }

    /**
     * 🗺️ Mappe les statuts Djomy vers les statuts locaux basé sur la documentation
     */
    protected function mapDjomyStatusToLocal(?string $djomyStatus): string
    {
        if (!$djomyStatus) {
            return PaymentStatus::PENDING->value;
        }

        return match (strtoupper($djomyStatus)) {
            'CREATED' => PaymentStatus::PENDING->value,      // Créé mais pas encore soumis
            'PENDING' => PaymentStatus::PROCESSING->value,   // En cours de traitement
            'AUTHORIZED' => PaymentStatus::PROCESSING->value, // Autorisé - en attente de capture
            'CAPTURED' => PaymentStatus::SUCCESS->value,     // Montant reçu = succès
            'SUCCESS' => PaymentStatus::SUCCESS->value,      // Paiement réussi
            'FAILED' => PaymentStatus::FAILED->value,        // Échec
            default => PaymentStatus::PENDING->value,
        };
    }

    /**
     * 🔹 Mapping des statuts Djomy vers statuts locaux
     */
    protected function mapDjomyStatusLinkToLocal(?string $djomyStatus): string
    {
        return match ($djomyStatus) {
            'CREATED', 'PENDING'            => PaymentLinkStatus::PENDING->value,
            'PAID', 'SUCCESS', 'ENABLED'    => PaymentLinkStatus::PAID->value,
            'FAILED'                        => PaymentLinkStatus::FAILED->value,
            'CANCELLED'                     => PaymentLinkStatus::CANCELLED->value,
            'EXPIRED'                       => PaymentLinkStatus::EXPIRED->value,
            default                         => PaymentLinkStatus::PENDING->value,
        };
    }




    /**
     * 🔍 Retourne la règle de mapping utilisée pour le débogage
     */
    private function getMappingRule(?string $djomyStatus): string
    {
        if (!$djomyStatus) {
            return 'No status → PENDING';
        }

        return match (strtoupper($djomyStatus)) {
            'CREATED' => 'CREATED → PENDING (créé mais pas encore soumis)',
            'PENDING' => 'PENDING → PROCESSING (en cours de traitement)',
            'AUTHORIZED' => 'AUTHORIZED → PROCESSING (autorisé - en attente capture)',
            'CAPTURED' => 'CAPTURED → SUCCESS (montant reçu)',
            'SUCCESS' => 'SUCCESS → SUCCESS (paiement réussi)',
            'FAILED' => 'FAILED → FAILED (échec)',
            default => 'Unknown → PENDING',
        };
    }

    private function generateMerchantPaymentReference(): string
    {
        // Exemple : MDING-20251020-<UUID court>
        return 'MDING-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -8));
    }

    /**
     * 🔄 Vérification immédiate du statut après création
     */
    private function performImmediateStatusCheck(Payment $payment): array
    {
        try {
            Log::info('Performing immediate status check after payment creation', [
                'payment_id' => $payment->id,
                'transaction_id' => $payment->transaction_id
            ]);

            // Attendre 2 secondes avant de vérifier (laisser le temps à l'API de traiter)
            sleep(2);

            if (!$payment->transaction_id) {
                Log::warning('No transaction ID available for immediate status check', [
                    'payment_id' => $payment->id
                ]);
                return [
                    'success' => false,
                    'message' => 'Transaction ID non disponible pour la vérification immédiate'
                ];
            }

            // Utiliser la méthode existante getPaymentStatus
            $statusResult = $this->getPaymentStatus($payment->transaction_id);

            Log::info('Immediate status check completed', [
                'payment_id' => $payment->id,
                'transaction_id' => $payment->transaction_id,
                'status_check_success' => $statusResult['success'],
                'final_status' => $payment->refresh()->status
            ]);

            return [
                'success' => $statusResult['success'],
                'message' => $statusResult['message'] ?? 'Vérification immédiate effectuée',
                'initial_status' => PaymentStatus::PENDING->value,
                'checked_status' => $statusResult['data']['local_status'] ?? null,
                'djomy_status' => $statusResult['data']['status'] ?? null
            ];
        } catch (Exception $e) {
            Log::error('Immediate status check failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'message' => 'Échec de la vérification immédiate: ' . $e->getMessage()
            ];
        }
    }


    /**
     * ✅ Vérifie si un statut est final (ne nécessite plus de mise à jour)
     */
    private function isFinalStatus(string $status): bool
    {
        return in_array($status, [
            PaymentStatus::SUCCESS->value,
            PaymentStatus::FAILED->value,
            PaymentStatus::CANCELLED->value,
            PaymentStatus::EXPIRED->value,
        ]);
    }

    /**
     * Marquer le paiement comme traité
     */
    private function markAsProcessed(Payment $payment, array $apiResult): void
    {
        $payment->update([
            'processed_at' => now(),
            'processing_attempts' => $payment->processing_attempts + 1
        ]);

        Log::info('Payment marked as processed', [
            'payment_id' => $payment->id,
            'final_status' => $payment->status,
            'processed_at' => now()->toISOString()
        ]);
    }

    /**
     * Format de résultat d'erreur
     */
    private function errorResult(Payment $payment, string $error): array
    {
        return [
            'success' => false,
            'payment_id' => $payment->id,
            'error' => $error
        ];
    }
}
