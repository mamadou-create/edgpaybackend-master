<?php

namespace App\Repositories;

use App\Enums\ReloadlyProduct;
use App\Interfaces\ReloadlyRepositoryInterface;
use App\Models\ReloadlyTransaction;
use App\Models\User;
use App\Services\ReloadlyAuthService;
use App\Services\WalletService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReloadlyRepository implements ReloadlyRepositoryInterface
{
    private string $baseUrl;
    private ReloadlyProduct $product;
    private ?User $user;
    private ReloadlyAuthService $authService;
    private WalletService $walletService;

    private const TIMEOUT_SEARCH      = 30;
    private const TIMEOUT_TRANSACTION = 60;
    private const TIMEOUT_BALANCE     = 15;

    public function __construct(ReloadlyAuthService $authService, WalletService $walletService)
    {
        $this->product        = ReloadlyProduct::AIRTIME;
        $this->baseUrl         = $this->product->baseUrl();
        $this->user            = Auth::guard()->user();
        $this->authService     = $authService;
        $this->walletService   = $walletService;
    }

    /* ============================================================
     |  RECHERCHE OPÉRATEUR (auto-détection depuis un numéro)
     ============================================================ */
    public function searchOperator(string $phone, string $countryCode): array
    {
        try {
            $token = $this->authService->getToken($this->product);
            if (empty($token)) {
                return $this->error("Impossible d'obtenir le token d'authentification", 401);
            }

            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/com.reloadly.topups-v1+json'])
                ->timeout(self::TIMEOUT_SEARCH)
                ->retry(2, 500)
                ->get("{$this->baseUrl}/operators/auto-detect/phone/{$phone}/countries/{$countryCode}");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data'    => $response->json(),
                    'status'  => $response->status(),
                ];
            }

            if ($response->status() === 401) {
                $this->authService->forgetToken($this->product);
            }

            return $this->error($response->json('message', 'Opérateur introuvable'), $response->status());
        } catch (\Exception $e) {
            Log::error('Reloadly Search Operator Error: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    public function getOperatorProducts(int $operatorId): array
    {


        Log::info('Reloadly URL products', ['url' => "{$this->baseUrl}/operators/{$operatorId}"]);
        try {
            $token = $this->authService->getToken($this->product);
            if (empty($token)) {
                return $this->error("Impossible d'obtenir le token", 401);
            }

            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/com.reloadly.topups-v1+json'])
                ->timeout(self::TIMEOUT_SEARCH)
                ->retry(2, 500)
                ->get("{$this->baseUrl}/operators/{$operatorId}");

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json(), 'status' => $response->status()];
            }

            return $this->error($response->json('message', 'Aucun produit trouvé'), $response->status());
        } catch (\Exception $e) {
            Log::error('Reloadly getOperatorProducts Error: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    /* ============================================================
     |  TOP-UP (AVEC IDEMPOTENCE + WALLET DÉDIÉ RELOADLY)
     ============================================================ */
    public function makeTopup(array $data): array
    {
        // Validation
        if (!isset($data['amount']) || !is_numeric($data['amount'])) {
            return $this->error('Montant invalide', 400);
        }
        if (!isset($data['operatorId'], $data['recipientPhone'])) {
            return $this->error('operatorId et recipientPhone sont requis', 400);
        }

        // Définir $amount AVANT de l'utiliser
        $amount = (float) $data['amount'];

        // Clé d'idempotence
        $idempotencyKey = sha1(
            ($data['operatorId'] ?? '')
                . ($data['recipientPhone']['number'] ?? '')
                . $amount
                . now()->format('YmdHi')
        );

        // Création transaction PENDING
        $transaction = ReloadlyTransaction::firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'user_id'           => $this->user->id,
                'operator_id'       => $data['operatorId'],
                'recipient_phone'   => $data['recipientPhone']['number'] ?? '',
                'recipient_email'   => $data['recipientEmail'] ?? null,
                'requested_amount'  => $amount,
                'custom_identifier' => $data['customIdentifier'] ?? null,
                'api_status'        => 'PENDING',
            ]
        );

        // Protection anti-doublon
        if ($transaction->api_status !== 'PENDING') {
            Log::warning('Transaction Reloadly dupliquée', [
                'idempotency_key' => $idempotencyKey,
                'status'          => $transaction->api_status,
            ]);
            return $this->error('Transaction déjà traitée', 409);
        }

        // --- Débit du wallet AVANT l'appel API ---
        try {
            $this->walletService->withdrawReloadlyPayment(
                (int) round($amount),
                $this->user
            );
        } catch (\Throwable $e) {
            $transaction->update([
                'api_status'    => 'FAILED',
                'error_message' => 'Solde insuffisant ou erreur wallet: ' . $e->getMessage(),
            ]);
            return $this->error($e->getMessage(), 400);
        }

        // --- Authentification ---
        $token = $this->authService->getToken($this->product);
        if (empty($token)) {
            // Remboursement immédiat car aucun appel n'a été fait
            $this->walletService->refundReloadlyPayment(
                (int) round($amount),
                $this->user
            );
            $transaction->update([
                'api_status'    => 'FAILED',
                'error_message' => 'Authentification Reloadly impossible',
            ]);
            return $this->error('Authentification Reloadly impossible', 401);
        }

        // --- Appel API (pas de retry) ---
        try {
            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/com.reloadly.topups-v1+json'])
                ->timeout(self::TIMEOUT_TRANSACTION)
                ->post("{$this->baseUrl}/topups", $data);
        } catch (ConnectionException $e) {
            // Timeout -> statut inconnu, PAS de remboursement
            $transaction->update([
                'api_status'    => 'TIMEOUT',
                'error_message' => 'Timeout API : ' . $e->getMessage(),
            ]);
            Log::critical('Reloadly Topup - Timeout', [
                'idempotency_key' => $idempotencyKey,
                'amount'          => $amount,
            ]);
            return $this->error(
                'La transaction est en cours de traitement. Vérifiez son statut avant de réessayer.',
                504
            );
        } catch (\Exception $e) {
            // Autre exception réseau -> remboursement
            $this->walletService->refundReloadlyPayment(
                (int) round($amount),
                $this->user
            );
            $transaction->update([
                'api_status'    => 'FAILED',
                'error_message' => 'Exception HTTP: ' . $e->getMessage(),
            ]);
            return $this->error($e->getMessage(), 500);
        }

        // --- Traitement de la réponse ---
        return $this->handleTopupResponse($response, $transaction);
    }
    /* ============================================================
     |  HANDLER RÉPONSE TOP-UP
     ============================================================ */
    private function handleTopupResponse($response, ReloadlyTransaction $transaction): array
    {
        $apiData    = $response->json();
        $statusCode = $response->status();
        $businessSuccess = $response->successful() && (($apiData['status'] ?? null) === 'SUCCESSFUL');

        $updateData = [
            'api_response'            => $apiData,
            'reloadly_transaction_id' => $apiData['transactionId'] ?? null,
            'operator_name'           => $apiData['operatorName'] ?? null,
            'country_code'            => $apiData['countryCode'] ?? null,
            'delivered_amount'        => $apiData['deliveredAmount'] ?? null,
            'delivered_currency'      => $apiData['deliveredAmountCurrencyCode'] ?? null,
            'requested_currency'      => $apiData['requestedAmountCurrencyCode'] ?? null,
            'fee'                     => $apiData['fee'] ?? null,
            'discount'                => $apiData['discount'] ?? null,
            'transaction_date'        => $apiData['transactionDate'] ?? null,
            'api_status'              => $businessSuccess ? 'SUCCESS' : 'FAILED',
            'error_message'           => $businessSuccess ? null : ($apiData['message'] ?? $apiData['status'] ?? 'Erreur inconnue'),
        ];

        $transaction->update($updateData);

        if ($businessSuccess) {
            return [
                'success' => true,
                'data'    => $apiData,
                'status'  => $statusCode,
                'message' => 'Top-up effectué avec succès',
            ];
        }

        // Échec confirmé : on rembourse le wallet
        $this->walletService->refundReloadlyPayment(
            (int) round($transaction->requested_amount),
            $this->user
        );

        if ($statusCode === 401) {
            $this->authService->forgetToken($this->product);
        }

        Log::error('API Reloadly Topup - Erreur', [
            'status_code' => $statusCode,
            'response'    => $apiData,
        ]);

        return [
            'success' => false,
            'error'   => $apiData['message'] ?? 'Erreur API Reloadly',
            'status'  => $statusCode,
            'data'    => $apiData,
        ];
    }

    /* ============================================================
 |  FORFAITS DATA (BUNDLES)
 ============================================================ */
    public function getDataPlans(int $operatorId): array
    {
        try {
            $token = $this->authService->getToken($this->product);
            if (empty($token)) {
                return $this->error("Impossible d'obtenir le token d'authentification", 401);
            }

            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/com.reloadly.topups-v1+json'])
                ->timeout(self::TIMEOUT_SEARCH)
                ->retry(2, 500)
                ->get("{$this->baseUrl}/operators/{$operatorId}");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data'    => $response->json(),
                    'status'  => $response->status(),
                ];
            }

            return $this->error($response->json('message', 'Aucun forfait data trouvé'), $response->status());
        } catch (\Exception $e) {
            Log::error('Reloadly getDataPlans Error: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    /* ============================================================
 |  PROMOTIONS
 ============================================================ */
    public function getPromotions(int $operatorId): array
    {
        try {
            $token = $this->authService->getToken($this->product);
            if (empty($token)) {
                return $this->error("Impossible d'obtenir le token d'authentification", 401);
            }

            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/com.reloadly.topups-v1+json'])
                ->timeout(self::TIMEOUT_SEARCH)
                ->retry(2, 500)
                ->get("{$this->baseUrl}/promotions", ['operatorId' => $operatorId]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data'    => $response->json(),
                    'status'  => $response->status(),
                ];
            }

            return $this->error($response->json('message', 'Aucune promotion trouvée'), $response->status());
        } catch (\Exception $e) {
            Log::error('Reloadly getPromotions Error: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    /* ============================================================
 |  COMMISSIONS
 ============================================================ */
    public function getCommissions(?int $operatorId = null): array
    {
        try {
            $token = $this->authService->getToken($this->product);
            if (empty($token)) {
                return $this->error("Impossible d'obtenir le token d'authentification", 401);
            }

            $query = [];
            if ($operatorId !== null) {
                $query['operatorId'] = $operatorId;
            }

            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/com.reloadly.topups-v1+json'])
                ->timeout(self::TIMEOUT_SEARCH)
                ->retry(2, 500)
                ->get("{$this->baseUrl}/operators/commissions", $query);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data'    => $response->json(),
                    'status'  => $response->status(),
                ];
            }

            return $this->error($response->json('message', 'Erreur lors de la récupération des commissions'), $response->status());
        } catch (\Exception $e) {
            Log::error('Reloadly getCommissions Error: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    /* ============================================================
 |  VÉRIFICATION D'UNE TRANSACTION
 ============================================================ */
    public function verifyTransaction(int|string $reloadlyTransactionId): array
    {
        try {
            $token = $this->authService->getToken($this->product);
            if (empty($token)) {
                return $this->error("Impossible d'obtenir le token d'authentification", 401);
            }

            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/com.reloadly.topups-v1+json'])
                ->timeout(self::TIMEOUT_SEARCH)
                ->retry(2, 500)
                ->get("{$this->baseUrl}/topups/{$reloadlyTransactionId}/status");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data'    => $response->json(),
                    'status'  => $response->status(),
                ];
            }

            return $this->error($response->json('message', 'Transaction introuvable'), $response->status());
        } catch (\Exception $e) {
            Log::error('Reloadly verifyTransaction Error: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    /* ============================================================
     |  SOLDE RELOADLY (côté fournisseur)
     ============================================================ */
    public function getBalance(): array
    {
        try {
            $token = $this->authService->getToken($this->product);
            if (empty($token)) {
                return $this->error("Impossible d'obtenir le token d'authentification", 401);
            }

            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/com.reloadly.topups-v1+json'])
                ->timeout(self::TIMEOUT_BALANCE)
                ->retry(2, 500)
                ->get("{$this->baseUrl}/accounts/balance");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data'    => $response->json(),
                    'status'  => $response->status(),
                ];
            }

            return $this->error('Erreur lors de la récupération du solde Reloadly', $response->status());
        } catch (\Exception $e) {
            Log::error('Reloadly Get Balance Error: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    /* ============================================================
     |  LISTE DES OPÉRATEURS PAR PAYS
     ============================================================ */
    public function getOperatorsByCountry(string $countryCode): array
    {
        try {
            $token = $this->authService->getToken($this->product);
            if (empty($token)) {
                return $this->error("Impossible d'obtenir le token d'authentification", 401);
            }

            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/com.reloadly.topups-v1+json'])
                ->timeout(self::TIMEOUT_SEARCH)
                ->retry(2, 500)
                ->get("{$this->baseUrl}/operators/countries/{$countryCode}");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data'    => $response->json(),
                    'status'  => $response->status(),
                ];
            }

            return $this->error($response->json('message', 'Aucun opérateur trouvé'), $response->status());
        } catch (\Exception $e) {
            Log::error('Reloadly Get Operators By Country Error: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    /* ============================================================
     |  CRÉDIT MANUEL DU WALLET RELOADLY (ex: recharge admin)
     ============================================================ */
    public function creditWallet(int $amount, ?string $description = null): array
    {
        try {
            if (!$this->user) {
                return $this->error('Aucun utilisateur connecté', 401);
            }

            $this->walletService->creditReloadlyPayment(
                $amount,
                $this->user,
                $description ?? "Crédit manuel Reloadly"
            );

            return [
                'success' => true,
                'message' => "Wallet Reloadly crédité de {$amount} GNF avec succès",
            ];
        } catch (\Throwable $e) {
            Log::error('Reloadly Credit Wallet Error: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    /* ============================================================
     |  HISTORIQUE DES TRANSACTIONS
     ============================================================ */
    public function getTransactionHistory(string $userId, int $perPage = 20): array
    {
        try {
            $transactions = ReloadlyTransaction::with(['user'])->where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return ['success' => true, 'data' => $transactions];
        } catch (\Exception $e) {
            Log::error('Get Reloadly Transaction History Error: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }


    private function error(string $message, int $status = 400): array
    {
        return [
            'success' => false,
            'error'   => $message,
            'status'  => $status,
        ];
    }
}
