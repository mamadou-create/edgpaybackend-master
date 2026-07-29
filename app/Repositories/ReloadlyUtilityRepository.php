<?php

namespace App\Repositories;

use App\Enums\CommissionEnum;
use App\Enums\ReloadlyProduct;
use App\Interfaces\ReloadlyUtilityRepositoryInterface;
use App\Models\ReloadlyUtilityTransaction;
use App\Models\User;
use App\Services\ReloadlyAuthService;
use App\Services\WalletService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReloadlyUtilityRepository implements ReloadlyUtilityRepositoryInterface
{
    private string $baseUrl;
    private ReloadlyProduct $product;
    private ?User $user;
    private WalletService $walletService;
    private ReloadlyAuthService $authService;
    private const ACCEPT_HEADER = 'application/com.reloadly.utilities-v1+json';
    private const TIMEOUT_SEARCH = 30;
    private const TIMEOUT_TRANSACTION = 60;
    private const TIMEOUT_BALANCE = 15;

    public function __construct(WalletService $walletService, ReloadlyAuthService $authService)
    {
        $this->product = ReloadlyProduct::UTILITIES;
        $this->baseUrl = $this->product->baseUrl();
        $this->user = Auth::guard()->user();
        $this->walletService = $walletService;
        $this->authService = $authService;
    }

    public function listBillers(?string $countryCode = null, ?string $type = null, int $page = 1, int $size = 50): array
    {
        try {
            $token = $this->authService->getToken($this->product);
            if (empty($token)) return $this->error('Authentification Reloadly (utilities) impossible', 401);
            $query = array_filter(['countryISOCode' => $countryCode, 'type' => $type, 'page' => $page, 'size' => $size]);
            $response = Http::withToken($token)->withHeaders(['Accept' => self::ACCEPT_HEADER])->timeout(self::TIMEOUT_SEARCH)->retry(2, 500)->get("{$this->baseUrl}/billers", $query);
            if ($response->successful()) return ['success' => true, 'data' => $response->json(), 'status' => $response->status()];
            if ($response->status() === 401) $this->authService->forgetToken($this->product);
            return $this->error($response->json('message', 'Erreur lors de la récupération des billers'), $response->status());
        } catch (\Exception $e) { Log::error('Reloadly Utility ListBillers Error: ' . $e->getMessage()); return $this->error($e->getMessage(), 500); }
    }

    public function getBiller(int $billerId): array
    { try { $token = $this->authService->getToken($this->product); if (empty($token)) return $this->error('Authentification Reloadly (utilities) impossible', 401); $response = Http::withToken($token)->withHeaders(['Accept' => self::ACCEPT_HEADER])->timeout(self::TIMEOUT_SEARCH)->retry(2, 500)->get("{$this->baseUrl}/billers/{$billerId}"); if ($response->successful()) return ['success' => true, 'data' => $response->json(), 'status' => $response->status()]; return $this->error($response->json('message', 'Biller introuvable'), $response->status()); } catch (\Exception $e) { Log::error('Reloadly Utility GetBiller Error: ' . $e->getMessage()); return $this->error($e->getMessage(), 500); } }

    public function payBill(array $data): array
    {
        if (!isset($data['billerId'], $data['amount'], $data['subscriberAccountNumber'])) return $this->error('billerId, amount et subscriberAccountNumber sont requis', 400);
        if (!is_numeric($data['amount'])) return $this->error('Montant invalide', 400);
        $amount = (float) $data['amount'];
        $idempotencyKey = sha1($data['billerId'] . $data['subscriberAccountNumber'] . $amount . now()->format('YmdHi'));
        $referenceId = $data['referenceId'] ?? substr($idempotencyKey, 0, 20);
        $transaction = ReloadlyUtilityTransaction::firstOrCreate(['idempotency_key' => $idempotencyKey], ['user_id' => $this->user->id, 'biller_id' => $data['billerId'], 'subscriber_account_number' => $data['subscriberAccountNumber'], 'amount' => $amount, 'use_local_amount' => $data['useLocalAmount'] ?? false, 'reference_id' => $referenceId, 'api_status' => 'PENDING']);
        if ($transaction->api_status !== 'PENDING') return $this->error('Transaction déjà traitée', 409);
        try { $this->walletService->withdrawDmlPayment($amount, CommissionEnum::EDG, $this->user); } catch (\Throwable $e) { $transaction->update(['api_status' => 'FAILED', 'error_message' => $e->getMessage()]); return $this->error($e->getMessage(), 400); }
        $token = $this->authService->getToken($this->product);
        if (empty($token)) { $this->refundTransaction($transaction, $amount, 'Authentification Reloadly impossible'); return $this->error('Authentification Reloadly impossible', 401); }
        try { $response = Http::withToken($token)->withHeaders(['Accept' => self::ACCEPT_HEADER])->timeout(self::TIMEOUT_TRANSACTION)->post("{$this->baseUrl}/pay", ['subscriberAccountNumber' => $data['subscriberAccountNumber'], 'amount' => $amount, 'billerId' => $data['billerId'], 'referenceId' => $referenceId, 'useLocalAmount' => $data['useLocalAmount'] ?? false]); }
        catch (\Illuminate\Http\Client\ConnectionException $e) { $transaction->update(['api_status' => 'TIMEOUT', 'error_message' => 'Timeout API : ' . $e->getMessage()]); return $this->error('Le paiement est en cours de traitement. Vérifiez son statut avant de réessayer.', 504); }
        catch (\Exception $e) { $this->refundTransaction($transaction, $amount, 'Exception HTTP: ' . $e->getMessage()); return $this->error($e->getMessage(), 500); }
        return $this->handlePayResponse($response, $transaction);
    }

    private function handlePayResponse($response, ReloadlyUtilityTransaction $transaction): array
    {
        $apiData = $response->json(); $statusCode = $response->status(); $apiStatus = $apiData['status'] ?? null; $isSuccess = $response->successful() && $apiStatus === 'SUCCESSFUL'; $isProcessing = $response->successful() && $apiStatus === 'PROCESSING';
        $transaction->update(['api_response' => $apiData, 'reloadly_transaction_id' => $apiData['id'] ?? null, 'biller_name' => $apiData['billerName'] ?? null, 'country_code' => $apiData['countryCode'] ?? null, 'transaction_date' => $apiData['date'] ?? null, 'api_status' => $isSuccess ? 'SUCCESS' : ($isProcessing ? 'PROCESSING' : 'FAILED'), 'error_message' => (!$isSuccess && !$isProcessing) ? ($apiData['message'] ?? 'Erreur inconnue') : null]);
        if ($isSuccess) return ['success' => true, 'data' => $apiData, 'status' => $statusCode, 'message' => 'Paiement de facture effectué avec succès'];
        if ($isProcessing) return ['success' => true, 'data' => $apiData, 'status' => $statusCode, 'message' => 'Paiement en cours de traitement chez le fournisseur'];
        if ($statusCode === 401) $this->authService->forgetToken($this->product);
        $this->refundTransaction($transaction, (float) $transaction->amount, $apiData['message'] ?? 'Échec paiement facture');
        return ['success' => false, 'error' => $apiData['message'] ?? 'Erreur API Reloadly Utilities', 'status' => $statusCode, 'data' => $apiData];
    }

    public function getTransactionStatus(int $transactionId): array
    { try { $token = $this->authService->getToken($this->product); if (empty($token)) return $this->error('Authentification Reloadly (utilities) impossible', 401); $response = Http::withToken($token)->withHeaders(['Accept' => self::ACCEPT_HEADER])->timeout(self::TIMEOUT_SEARCH)->retry(2, 500)->get("{$this->baseUrl}/transactions/{$transactionId}"); if ($response->successful()) { $apiData = $response->json(); $local = ReloadlyUtilityTransaction::where('reloadly_transaction_id', $transactionId)->first(); if ($local && in_array($local->api_status, ['TIMEOUT', 'PROCESSING'])) { if (($apiData['status'] ?? null) === 'SUCCESSFUL') $local->update(['api_status' => 'SUCCESS', 'api_response' => $apiData]); elseif (($apiData['status'] ?? null) === 'FAILED') { $local->update(['api_status' => 'FAILED', 'api_response' => $apiData]); $this->refundTransaction($local, (float) $local->amount, 'Synchronisation: échec confirmé'); } } return ['success' => true, 'data' => $apiData, 'status' => $response->status()]; } return $this->error($response->json('message', 'Transaction introuvable'), $response->status()); } catch (\Exception $e) { Log::error('Reloadly Utility GetTransactionStatus Error: ' . $e->getMessage()); return $this->error($e->getMessage(), 500); } }

    public function getBalance(): array
    { try { $token = $this->authService->getToken($this->product); if (empty($token)) return $this->error('Authentification Reloadly (utilities) impossible', 401); $response = Http::withToken($token)->withHeaders(['Accept' => self::ACCEPT_HEADER])->timeout(self::TIMEOUT_BALANCE)->retry(2, 500)->get("{$this->baseUrl}/accounts/balance"); if ($response->successful()) return ['success' => true, 'data' => $response->json(), 'status' => $response->status()]; return $this->error('Erreur lors de la récupération du solde Utilities', $response->status()); } catch (\Exception $e) { Log::error('Reloadly Utility GetBalance Error: ' . $e->getMessage()); return $this->error($e->getMessage(), 500); } }

    public function getTransactionHistory(string $userId, int $perPage = 20): array
    { try { return ['success' => true, 'data' => ReloadlyUtilityTransaction::where('user_id', $userId)->orderBy('created_at', 'desc')->paginate($perPage)]; } catch (\Exception $e) { Log::error('Get Reloadly Utility Transaction History Error: ' . $e->getMessage()); return $this->error($e->getMessage(), 500); } }

    private function refundTransaction(ReloadlyUtilityTransaction $transaction, float $amount, string $reason = ''): void
    { try { $this->walletService->refundPayment((int) $amount, CommissionEnum::EDG, $this->user); $transaction->update(['api_status' => 'FAILED', 'error_message' => $reason ?: 'Remboursement effectué']); } catch (\Exception $e) { Log::critical('Échec remboursement Utility', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]); } }
    private function error(string $message, int $status = 400): array { return ['success' => false, 'error' => $message, 'status' => $status]; }
}
