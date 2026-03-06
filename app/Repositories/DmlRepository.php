<?php

namespace App\Repositories;

use App\Enums\CommissionEnum;
use App\Models\User;
use App\Models\DmlTransaction;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use App\Interfaces\DmlRepositoryInterface;
use App\Mail\PaymentAnomalyMail;
use App\Services\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class DmlRepository implements DmlRepositoryInterface
{
    private string $baseUrl;
    private ?User $user;
    private WalletService $walletService;

    public function __construct(WalletService $walletService)
    {
        $baseUrl = config('services.dml.base_url');
        $this->baseUrl = is_string($baseUrl) ? rtrim($baseUrl, '/') : '';
        $this->user = Auth::guard()->user();
        $this->walletService = $walletService;

        if ($this->baseUrl === '') {
            Log::warning('DML_BASE_URL non configuré (services.dml.base_url). Certaines fonctionnalités DML peuvent échouer.');
        }
    }

    /* ============================================================
     |  AUTHENTIFICATION
     ============================================================ */
    private function dmlRequest(?string $token = null): PendingRequest
    {
        $verifySsl = (bool) config('services.dml.verify_ssl', true);

        $request = Http::connectTimeout(3)
            ->timeout(8)
            ->retry(0, 0)
            ->acceptJson();

        if (!$verifySsl) {
            $request = $request->withoutVerifying();
        }

        if (is_string($token) && $token !== '') {
            $request = $request->withToken($token);
        }

        return $request;
    }

    private function authenticateAndGetToken(): string
    {
        if ($this->baseUrl === '') {
            Log::error('Impossible d\'authentifier DML: DML_BASE_URL manquant');
            return '';
        }

        $staticToken = config('services.dml.token');
        if (is_string($staticToken) && trim($staticToken) !== '') {
            return trim($staticToken);
        }

        $telephone = config('services.dml.login_phone');
        $password = config('services.dml.login_password');

        if (empty($telephone) || empty($password)) {
            Log::error('Identifiants DML manquants dans la configuration (DML_LOGIN_PHONE / DML_LOGIN_PASSWORD)');
            return '';
        }

        try {
            $attempts = [
                ['telephone1' => $telephone, 'password' => $password],
                ['telephone' => $telephone, 'password' => $password],
            ];

            foreach ($attempts as $payload) {
                $response = $this->dmlRequest()->post("{$this->baseUrl}/login", $payload);

                if (!$response->successful()) {
                    continue;
                }

                $data = $response->json();
                if (is_array($data) && isset($data['access_token']) && is_string($data['access_token']) && $data['access_token'] !== '') {
                    Log::info('Nouveau token DML obtenu avec succès');
                    return $data['access_token'];
                }
            }

            Log::error('Échec de l\'authentification DML (access_token absent ou réponse non-success)');
            return '';
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'authentification DML: ' . $e->getMessage());
            return '';
        }
    }

    /* ============================================================
     |  LOGIN UTILISATEUR
     ============================================================ */
    public function login(string $telephone, string $password): array
    {
        if ($this->baseUrl === '') {
            return [
                'status' => false,
                'error' => 'Service DML non configuré (DML_BASE_URL manquant)',
                'status_code' => 503,
            ];
        }

        try {
            $response = $this->dmlRequest()->post("{$this->baseUrl}/login", [
                    'telephone1' => $telephone,
                    'password' => $password
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'status' => true,
                    'access_token' => $data['access_token'] ?? null,
                    'token_type' => $data['token_type'] ?? 'bearer',
                    'expires_in' => $data['expires_in'] ?? 3600,
                    'message' => 'Authentification réussie'
                ];
            }

            $errorData = $response->json();
            return [
                'status' => false,
                'error' => $errorData['message'] ?? 'Échec de l\'authentification',
                'status_code' => $response->status()
            ];
        } catch (\Exception $e) {
            Log::error('DML Login Error: ' . $e->getMessage());
            return [
                'status' => false,
                'error' => $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /* ============================================================
     |  RECHERCHE CLIENT PREPAYÉ
     ============================================================ */
    public function searchPrepaidCustomer(string $rstValue): array
    {
        try {
            Log::info('🔍 DML Repository - Search Prepaid Customer', ['rst_value' => $rstValue]);

            $token = $this->authenticateAndGetToken();
            if (empty($token)) {
                return $this->error('Impossible d\'obtenir le token d\'authentification', 401);
            }

            $response = $this->dmlRequest($token)->post("{$this->baseUrl}/prepaid/searchCustomer", [
                    'rst_value' => $rstValue
                ]);

            return $this->handleApiResponseSearch($response, [
                'transaction_type' => 'prepaid',
                'rst_value' => $rstValue,
                'provider' => CommissionEnum::EDG
            ]);
        } catch (\Exception $e) {
            Log::error('DML Search Prepaid Customer Error: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    /* ============================================================
     |  SAUVEGARDE TRANSACTION PREPAYÉE (AVEC IDEMPOTENCE)
     ============================================================ */
    public function savePrepaidTransaction(array $data): array
    {
        // Vérifications préliminaires
        if (!$this->hasInternet()) {
            return $this->error('Aucune connexion Internet', 503);
        }
        if (!isset($data['amt']) || !is_numeric($data['amt'])) {
            return $this->error('Montant invalide', 400);
        }
        $amount = (int) $data['amt'];

        // Génération de la clé d'idempotence (basée sur compteur + montant + heure)
        $idempotencyKey = sha1(
            ($data['rst_value'] ?? '') .
            $amount .
            now()->format('YmdH') // fenêtre d'une heure
        );

        // Création de la transaction en statut PENDING
        $transaction = DmlTransaction::firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'payment_id'        => $data['payment_id'] ?? null,
                'transaction_type' => 'prepaid',
                'rst_value'        => $data['rst_value'] ?? null,
                'amount'           => $amount,
                'user_id'          => $this->user->id,
                'api_status'       => 'PENDING',
            ]
        );

        // Si la transaction existe déjà et n'est pas en PENDING, on bloque
        if ($transaction->api_status !== 'PENDING') {
            Log::warning('Transaction dupliquée (prépayée)', [
                'idempotency_key' => $idempotencyKey,
                'status'          => $transaction->api_status
            ]);
            $this->sendDuplicateAlert($transaction, $amount, 'Prépayé (DML)', $idempotencyKey);
            return $this->error('Transaction déjà traitée', 409);
        }

        // Retrait du wallet
        $balanceBefore = $this->user?->fresh(['wallet'])->wallet?->cash_available ?? -1;
        try {
            $this->walletService->withdrawDmlPayment($amount, CommissionEnum::EDG, $this->user);
        } catch (\Throwable $e) {
            $transaction->update(['api_status' => 'FAILED', 'error_message' => $e->getMessage()]);
            return $this->error($e->getMessage(), 400);
        }
        // Détection anomalie : wallet non débité malgré succès apparent
        $balanceAfterDebit = $this->user?->fresh(['wallet'])->wallet?->cash_available ?? -1;
        if ($balanceBefore >= 0 && $balanceAfterDebit >= $balanceBefore) {
            $this->sendAnomalyAlert(
                'Wallet non débité (prépayé)',
                $transaction,
                $amount,
                "Solde avant: {$balanceBefore} GNF — Solde après: {$balanceAfterDebit} GNF. Le wallet ne semble pas avoir été débité alors que le paiement DML allait être traité."
            );
        }

        // Authentification
        $token = $this->authenticateAndGetToken();
        if (empty($token)) {
            $this->refundTransaction($transaction, $amount, 'Authentification DML impossible');
            return $this->error('Authentification DML impossible', 401);
        }

        // Appel API
        try {
            $response = $this->dmlRequest($token)->post("{$this->baseUrl}/prepaid/saveTransaction", $data);
        } catch (\Exception $e) {
            $this->refundTransaction($transaction, $amount, 'Exception HTTP: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }

        // Traitement de la réponse via le handler spécialisé (qui mettra à jour la transaction)
        $extraData = [
            'rst_value'     => $data['rst_value'] ?? null,
            'amount'        => $amount,
            'code'          => $data['code'] ?? null,
            'customer_name' => $data['name'] ?? null,
            'phone'         => $data['phone'] ?? null,
            'buy_last_date' => $data['buy_last_date'] ?? null,
        ];

        try {
            return $this->handlePrepaidApiResponse($response, $transaction, $extraData);
        } catch (\Throwable $e) {
            $this->refundTransaction($transaction, $amount, 'Réponse DML prépayée invalide: ' . $e->getMessage());
            return $this->error('Aucune réponse exploitable de DML (prépayé). Remboursement automatique effectué.', 502);
        }
    }

    /* ============================================================
     |  RECHERCHE CLIENT POSTPAYÉ
     ============================================================ */
    public function searchPostPaymentCustomer(string $rstCode): array
    {
        try {
            Log::info('🔍 DML Repository - Search PostPayment Customer', ['rst_code' => $rstCode]);

            $token = $this->authenticateAndGetToken();
            if (empty($token)) {
                return $this->error('Impossible d\'obtenir le token d\'authentification', 401);
            }

            $response = $this->dmlRequest($token)->post("{$this->baseUrl}/postpayment/searchCustomer", [
                    'rst_code' => $rstCode
                ]);

            return $this->handleApiResponseSearch($response, [
                'transaction_type' => 'postpayment',
                'rst_code' => $rstCode,
                'provider' => CommissionEnum::EDG
            ]);
        } catch (\Exception $e) {
            Log::error('DML Search PostPayment Customer Error: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    /* ============================================================
     |  SAUVEGARDE TRANSACTION POSTPAYÉE (AVEC IDEMPOTENCE)
     ============================================================ */
    public function savePostPaymentTransaction(array $data): array
    {
        if (!$this->hasInternet()) {
            return $this->error('Aucune connexion Internet', 503);
        }

        $amount = $data['montant'] ?? 0;
        if (!is_numeric($amount) || $amount < 1000) {
            return $this->error('Montant invalide (minimum 1 000 GNF)', 400);
        }
        $amount = (int) $amount;

        // Génération de la clé d'idempotence
        $idempotencyKey = sha1(
            ($data['rst_value'] ?? '') .
            $amount .
            now()->format('YmdH')
        );

        $transaction = DmlTransaction::firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'payment_id'       => $data['payment_id'] ?? null,
                'transaction_type' => 'postpayment',
                'rst_code'         => $data['rst_value'] ?? null,
                'montant'          => $amount,
                'user_id'          => $this->user->id,
                'api_status'       => 'PENDING',
            ]
        );

        if ($transaction->api_status !== 'PENDING') {
            Log::warning('Transaction dupliquée (postpayée)', [
                'idempotency_key' => $idempotencyKey,
                'status'          => $transaction->api_status
            ]);
            $this->sendDuplicateAlert($transaction, $amount, 'Postpayé (DML)', $idempotencyKey);
            return $this->error('Transaction déjà traitée', 409);
        }

        // Retrait wallet
        $balanceBefore = $this->user?->fresh(['wallet'])->wallet?->cash_available ?? -1;
        try {
            $this->walletService->withdrawDmlPayment($amount, CommissionEnum::EDG, $this->user);
        } catch (\Throwable $e) {
            $transaction->update(['api_status' => 'FAILED', 'error_message' => $e->getMessage()]);
            return $this->error($e->getMessage(), 400);
        }
        // Détection anomalie : wallet non débité malgré succès apparent
        $balanceAfterDebit = $this->user?->fresh(['wallet'])->wallet?->cash_available ?? -1;
        if ($balanceBefore >= 0 && $balanceAfterDebit >= $balanceBefore) {
            $this->sendAnomalyAlert(
                'Wallet non débité (postpayé)',
                $transaction,
                $amount,
                "Solde avant: {$balanceBefore} GNF — Solde après: {$balanceAfterDebit} GNF. Le wallet ne semble pas avoir été débité alors que le paiement DML allait être traité."
            );
        }

        // Authentification
        $token = $this->authenticateAndGetToken();
        if (empty($token)) {
            $this->refundTransaction($transaction, $amount, 'Authentification DML impossible');
            return $this->error('Authentification DML impossible', 401);
        }

        // Appel API
        try {
            $response = $this->dmlRequest($token)->post("{$this->baseUrl}/postpayment/saveTransaction", $data);
        } catch (\Exception $e) {
            $this->refundTransaction($transaction, $amount, 'Exception HTTP: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }

        // Préparation des données supplémentaires pour le handler
        $extraData = [
            'rst_code'       => $data['rst_value'] ?? null,
            'amount'         => $amount,
            'montant'        => $amount,
            'code'           => $data['code'] ?? null,
            'customer_name'  => $data['name'] ?? null,
            'device'         => $data['device'] ?? null,
            'phone'          => $data['phone'] ?? null,
            'total_arrear'   => $data['total_arrear'] ?? null,
            'reste_a_payer'  => $data['reste_a_payer'] ?? null,
            'name'           => $data['name'] ?? null,
        ];

        try {
            return $this->handlePostpaidApiResponse($response, $transaction, $extraData);
        } catch (\Throwable $e) {
            $this->refundTransaction($transaction, $amount, 'Réponse DML postpayée invalide: ' . $e->getMessage());
            return $this->error('Aucune réponse exploitable de DML (postpayé). Remboursement automatique effectué.', 502);
        }
    }

    /* ============================================================
     |  RÉCUPÉRATION D'UNE TRANSACTION PAR RÉFÉRENCE
     ============================================================ */
    public function getTransaction(string $refFacture): array
    {
        try {
            $token = $this->authenticateAndGetToken();
            if (empty($token)) {
                return $this->error('Impossible d\'obtenir le token d\'authentification', 401);
            }

            $response = $this->dmlRequest($token)->post("{$this->baseUrl}/postpayment/getTransaction", [
                    'ref_facture' => $refFacture
                ]);

            return $this->handleGetTransactionResponse($response);
        } catch (\Exception $e) {
            Log::error('DML Get Transaction Error: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    /* ============================================================
     |  SOLDE DML
     ============================================================ */
    public function getBalance(): array
    {
        try {
            $token = $this->authenticateAndGetToken();
            if (empty($token)) {
                return $this->error('Service DML non configuré ou authentification impossible (vérifiez DML_BASE_URL et DML_TOKEN ou DML_LOGIN_PHONE/DML_LOGIN_PASSWORD)', 503);
            }

            $response = $this->dmlRequest($token)->get("{$this->baseUrl}/getBalance");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'status' => $response->status()
                ];
            }

            return $this->error('Erreur lors de la récupération du solde', $response->status());
        } catch (\Exception $e) {
            Log::error('DML Get Balance Error: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    /* ============================================================
     |  HISTORIQUE DES TRANSACTIONS
     ============================================================ */
    public function getTransactionHistory(string $userId, int $perPage = 20): array
    {
        try {
            $transactions = DmlTransaction::where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return [
                'success' => true,
                'data' => $transactions
            ];
        } catch (\Exception $e) {
            Log::error('Get DML Transaction History Error: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    /* ============================================================
     |  RECHERCHE DE TRANSACTION PAR RÉFÉRENCE
     ============================================================ */
    public function findTransactionByReference(string $reference): ?array
    {
        try {
            $transaction = DmlTransaction::where('code', $reference)
                ->orWhere('ref_facture', $reference)
                ->first();

            return $transaction ? $transaction->toArray() : null;
        } catch (\Exception $e) {
            Log::error('Find DML Transaction Error: ' . $e->getMessage());
            return null;
        }
    }

    /* ============================================================
     |  STOCKAGE DIRECT D'UNE TRANSACTION (utilisé par les anciens handlers)
     |  Conservé pour compatibilité, mais plus appelé dans le flux principal.
     ============================================================ */
    public function storeTransaction(array $transactionData): array
    {
        try {
            if (!$this->user) {
                Log::error('Tentative de sauvegarde de transaction sans utilisateur connecté');
                return $this->error('Utilisateur non authentifié', 401);
            }

            $transactionData['user_id'] = $this->user->id;

            Log::debug('📝 DML Repository - Données avant création', [
                'transaction_type' => $transactionData['transaction_type'] ?? 'unknown',
                'keys' => array_keys($transactionData),
            ]);

            $transaction = DmlTransaction::create($transactionData);

            Log::info('✅ DML Repository - Transaction sauvegardée', [
                'transaction_id' => $transaction->id,
                'type' => $transactionData['transaction_type'] ?? 'unknown',
                'customer_name' => $transaction->customer_name ?? 'N/A',
                'trans_id' => $transaction->trans_id ?? null,
            ]);

            return [
                'success' => true,
                'data' => $transaction->toArray()
            ];
        } catch (\Exception $e) {
            Log::error('Store DML Transaction Error: ' . $e->getMessage(), [
                'transactionData' => $transactionData
            ]);
            return $this->error($e->getMessage(), 500);
        }
    }

    /* ============================================================
     |  HANDLER SPÉCIFIQUE POUR TRANSACTION PREPAYÉE
     |  Reçoit la transaction existante et la met à jour.
     ============================================================ */
    private function handlePrepaidApiResponse($response, DmlTransaction $transaction, array $extraData): array
    {
        $apiDataRaw = $response->json();
        $apiData = is_array($apiDataRaw) ? $apiDataRaw : [];
        $hasValidResponse = !empty($apiData);
        $statusCode = $response->status();
        $success = $response->successful() && $hasValidResponse;
        $errorMessage = $hasValidResponse
            ? ($apiData['message'] ?? 'Erreur inconnue')
            : 'Aucune réponse exploitable de DML';

        Log::debug('📊 DML Repository - Traitement réponse API prépayée', [
            'status_code' => $statusCode,
            'has_data'    => isset($apiData['data']),
            'has_valid_response' => $hasValidResponse,
        ]);

        // Préparer les données à fusionner avec l'existant
        $updateData = [
            'api_response'  => $hasValidResponse ? $apiData : ['raw_body' => $response->body()],
            'api_status'    => $success ? 'SUCCESS' : 'FAILED',
            'error_message' => $success ? null : $errorMessage,
        ];

        // Extraire les données spécifiques
        $extracted = $this->extractPrepaidApiResponseData($apiData);
        $updateData = array_merge($updateData, $extracted, $extraData);

        // Mettre à jour la transaction
        $transaction->update($updateData);

        if ($success) {
            // Si succès, on peut retourner la réponse formatée
            return [
                'success' => true,
                'data'    => $apiData['data'] ?? $apiData,
                'status'  => $statusCode,
                'message' => $apiData['message'] ?? 'Opération réussie',
            ];
        }

        // En cas d'échec, on a déjà mis le statut FAILED, mais il faut rembourser
        $this->refundTransaction($transaction, $extraData['amount'] ?? 0, $errorMessage);

        Log::error('❌ API DML Prépayée - Erreur', [
            'status_code' => $statusCode,
            'error'       => $errorMessage,
        ]);

        return [
            'success' => false,
            'error'   => $errorMessage,
            'status'  => $statusCode,
            'data'    => $apiData,
        ];
    }

    /* ============================================================
     |  HANDLER SPÉCIFIQUE POUR TRANSACTION POSTPAYÉE
     |  Reçoit la transaction existante et la met à jour.
     ============================================================ */
    private function handlePostpaidApiResponse($response, DmlTransaction $transaction, array $extraData): array
    {
        $apiDataRaw = $response->json();
        $apiData = is_array($apiDataRaw) ? $apiDataRaw : [];
        $hasValidResponse = !empty($apiData);
        $statusCode = $response->status();
        $success = $response->successful() && $hasValidResponse;
        $errorMessage = $hasValidResponse
            ? ($apiData['message'] ?? 'Erreur inconnue')
            : 'Aucune réponse exploitable de DML';

        Log::debug('📊 DML Repository - Traitement réponse API postpayée', [
            'status_code' => $statusCode,
            'has_data'    => isset($apiData['data']),
            'has_valid_response' => $hasValidResponse,
        ]);

        $updateData = [
            'api_response'  => $hasValidResponse ? $apiData : ['raw_body' => $response->body()],
            'api_status'    => $success ? 'SUCCESS' : 'FAILED',
            'error_message' => $success ? null : $errorMessage,
        ];

        $extracted = $this->extractPostpaidApiResponseData($apiData);
        // Ne pas écraser 'reste_a_payer' qui vient du frontend
        if (isset($extraData['reste_a_payer'])) {
            $extracted['reste_a_payer'] = $extraData['reste_a_payer'];
        }
        $updateData = array_merge($updateData, $extracted, $extraData);

        $transaction->update($updateData);

        if ($success) {
            return [
                'success' => true,
                'data'    => $apiData['data'] ?? $apiData,
                'status'  => $statusCode,
                'message' => $apiData['message'] ?? 'Opération réussie',
            ];
        }

        $this->refundTransaction($transaction, $extraData['amount'] ?? 0, $errorMessage);

        Log::error('❌ API DML Postpayée - Erreur', [
            'status_code' => $statusCode,
            'error'       => $errorMessage,
        ]);

        return [
            'success' => false,
            'error'   => $errorMessage,
            'status'  => $statusCode,
            'data'    => $apiData,
        ];
    }

    /* ============================================================
     |  EXTRACTION DES DONNÉES SPÉCIFIQUES (prépayé)
     ============================================================ */
    private function extractPrepaidApiResponseData(array $apiData): array
    {
        $data = $apiData['data'] ?? $apiData;

        return [
            'trans_id'      => $data['trans_id'] ?? null,
            'trans_time'    => isset($data['trans_time']) ? Carbon::createFromFormat('Y-m-d H:i:s', $data['trans_time']) : null,
            'ref_code'      => $data['ref_code'] ?? null,
            'device'        => $data['device'] ?? null,
            'kwh'           => $data['kwh'] ?? null,
            'kwh_amt'       => $data['kwh_amt'] ?? null,
            'fee_amt'       => $data['fee_amt'] ?? null,
            'arrear_amt'    => $data['arrear_amt'] ?? null,
            'net_amt'       => $data['amt'] ?? null,
            'tokens'        => $data['tokens'] ?? null,
            'verify_code'   => $data['verify_code'] ?? null,
            'state'         => $data['state'] ?? null,
            'seed'          => $data['seed'] ?? null,
            'code'          => $data['code'] ?? null,
            'reg_date'      => isset($data['reg_date']) ? Carbon::createFromFormat('Y-m-d', $data['reg_date']) : null,
            'customer_name' => $data['name'] ?? null,
            'buy_times'     => $data['buy_times'] ?? null,
            'buy_last_date' => isset($data['buy_last_date']) ? Carbon::createFromFormat('Y-m-d H:i:s', $data['buy_last_date']) : null,
        ];
    }

    /* ============================================================
     |  EXTRACTION DES DONNÉES SPÉCIFIQUES (postpayé)
     ============================================================ */
    private function extractPostpaidApiResponseData(array $apiData): array
    {
        $data = $apiData['data'] ?? $apiData;

        return [
            'trans_id'       => $data['trans_id'] ?? null,
            'trans_time'     => isset($data['trans_time']) ? Carbon::createFromFormat('Y-m-d H:i:s', $data['trans_time']) : null,
            'code'           => $data['code'] ?? null,
            'customer_name'  => $data['name'] ?? null,
            'device'         => $data['device'] ?? null,
            'ref_code'       => $data['code'] ?? null,
            'ref_facture'    => $data['bill_code'] ?? null,
            'verify_code'    => $data['verify_code'] ?? null,
            'net_amt'        => $data['total_arrear'] ?? null,
            'customer_bills' => $data['customerBill'] ?? $data,
        ];
    }

    /* ============================================================
     |  GESTION DE LA RÉPONSE POUR LES RECHERCHES (sans stockage)
     ============================================================ */
    private function handleApiResponseSearch($response, array $context): array
    {
        $data = $response->json();

        if ($response->successful()) {
            $responseData = [
                'success' => true,
                'data'    => $data['data'] ?? $data,
                'status'  => $response->status(),
                'message' => $data['message'] ?? 'Opération réussie',
            ];

            if (isset($data['provider'])) {
                $responseData['provider'] = $data['provider'];
            }

            return $responseData;
        }

        return [
            'success' => false,
            'error'   => $data['message'] ?? 'Erreur API DML',
            'status'  => $response->status(),
            'data'    => $data,
        ];
    }

    /* ============================================================
     |  RÉPONSE POUR getTransaction
     ============================================================ */
    private function handleGetTransactionResponse($response): array
    {
        $apiData = $response->json();
        $statusCode = $response->status();

        if ($response->successful()) {
            return [
                'success' => true,
                'data'    => $apiData['data'] ?? $apiData,
                'status'  => $statusCode,
                'message' => $apiData['message'] ?? 'Opération réussie',
            ];
        }

        Log::error('❌ API DML GetTransaction - Erreur', [
            'status_code' => $statusCode,
            'error'       => $apiData['message'] ?? 'Erreur inconnue',
        ]);

        return [
            'success' => false,
            'error'   => $apiData['message'] ?? 'Erreur API DML',
            'status'  => $statusCode,
            'data'    => $apiData,
        ];
    }

    /* ============================================================
     |  REMBOURSEMENT ET MISE À JOUR DE LA TRANSACTION
     ============================================================ */
    private function refundTransaction(DmlTransaction $transaction, int $amount, string $reason = ''): void
    {
        if ($amount <= 0) {
            Log::warning('Remboursement ignoré: montant invalide', [
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'reason' => $reason,
            ]);
            $transaction->update([
                'api_status'    => 'FAILED',
                'error_message' => $reason ?: 'Transaction échouée (montant remboursement invalide)'
            ]);
            return;
        }

        try {
            $this->walletService->refundPayment($amount, CommissionEnum::EDG, $this->user);
            $transaction->update([
                'api_status'    => 'FAILED',
                'error_message' => $reason ?: 'Remboursement effectué'
            ]);
            Log::info('Remboursement effectué', [
                'transaction_id' => $transaction->id,
                'amount'         => $amount,
                'reason'         => $reason
            ]);
        } catch (\Exception $e) {
            Log::critical('Échec du remboursement', [
                'transaction_id' => $transaction->id,
                'error'          => $e->getMessage()
            ]);
            // Alerte admin : wallet débité mais remboursement impossible → incohérence financière
            $this->sendAnomalyAlert(
                'Remboursement impossible',
                $transaction,
                $amount,
                "Le wallet a été débité de {$amount} GNF mais le remboursement a échoué. Raison initiale: {$reason}. Erreur remboursement: {$e->getMessage()}"
            );
        }
    }

    /* ============================================================
     |  ALERTE ANOMALIE PAIEMENT
     ============================================================ */
    private function sendAnomalyAlert(string $anomalyType, DmlTransaction $transaction, int $amount, string $details): void
    {
        try {
            $admins = \App\Models\User::whereHas('role', fn($q) => $q->where('is_super_admin', true))
                ->whereNotNull('email')
                ->get();

            foreach ($admins as $admin) {
                Mail::to($admin->email)->send(new PaymentAnomalyMail(
                    $anomalyType,
                    $this->user,
                    $amount,
                    $transaction,
                    $details
                ));
            }

            Log::warning('Alerte anomalie paiement envoyée', [
                'type'           => $anomalyType,
                'transaction_id' => $transaction->id,
                'amount'         => $amount,
                'admins_count'   => $admins->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Envoi alerte anomalie paiement échoué', [
                'error'          => $e->getMessage(),
                'anomaly_type'   => $anomalyType,
                'transaction_id' => $transaction->id ?? null,
            ]);
        }
    }

    /* ============================================================
     |  ALERTE TRANSACTION DUPLIQUÉE
     ============================================================ */
    private function sendDuplicateAlert(DmlTransaction $transaction, int $amount, string $paymentType, string $idempotencyKey): void
    {
        try {
            $admins = \App\Models\User::whereHas('role', fn($q) => $q->where('is_super_admin', true))
                ->whereNotNull('email')
                ->get();

            $userLabel = $this->user?->display_name ?? $this->user?->phone ?? 'Inconnu';
            $rstValue  = $transaction->rst_value ?? $transaction->rst_code ?? '—';

            foreach ($admins as $admin) {
                Mail::to($admin->email)->send(new PaymentAnomalyMail(
                    "Transaction dupliquée ({$paymentType})",
                    $this->user,
                    $amount,
                    $transaction,
                    "L'utilisateur {$userLabel} a tenté de soumettre une transaction déjà traitée.\n"
                    . "Compteur: {$rstValue} — Montant: {$amount} GNF\n"
                    . "Statut actuel de la transaction: {$transaction->api_status}\n"
                    . "Clé d'idempotence: {$idempotencyKey}"
                ));
            }

            Log::warning('Alerte transaction dupliquée envoyée aux admins', [
                'transaction_id'  => $transaction->id,
                'api_status'      => $transaction->api_status,
                'idempotency_key' => $idempotencyKey,
                'admins_count'    => $admins->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Envoi alerte transaction dupliquée échoué', [
                'error'          => $e->getMessage(),
                'transaction_id' => $transaction->id,
            ]);
        }
    }

    /* ============================================================
     |  UTILITAIRES
     ============================================================ */
    private function error(string $message, int $status = 400): array
    {
        return [
            'success' => false,
            'error'   => $message,
            'status'  => $status
        ];
    }

    private function hasInternet(): bool
    {
        try {
            Http::timeout(5)->get('https://www.google.com');
            return true;
        } catch (\Exception $e) {
            Log::error('🌐 Pas de connexion Internet', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function getDataTypes(array $data): array
    {
        $types = [];
        foreach ($data as $key => $value) {
            $types[$key] = gettype($value);
        }
        return $types;
    }
}