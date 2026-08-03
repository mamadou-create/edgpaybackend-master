<?php

namespace App\Repositories;

use App\Enums\ReloadlyProduct;
use App\Models\User;
use App\Models\ReloadlyGiftcardTransaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use App\Interfaces\ReloadlyGiftCardRepositoryInterface;
use App\Services\WalletService;
use App\Services\ReloadlyAuthService;

class ReloadlyGiftCardRepository implements ReloadlyGiftCardRepositoryInterface
{
    private string $baseUrl;
    private ReloadlyProduct $product;
    private ?User $user;
    private WalletService $walletService;
    private ReloadlyAuthService $authService;

    private const ACCEPT_HEADER = 'application/com.reloadly.giftcards-v1+json';
    private const TIMEOUT_SEARCH = 30;
    private const TIMEOUT_ORDER = 60;
    private const TIMEOUT_BALANCE = 15;

    public function __construct(WalletService $walletService, ReloadlyAuthService $authService)
    {
        $this->product = ReloadlyProduct::GIFTCARDS;
        $this->baseUrl = $this->product->baseUrl();
        $this->user = Auth::guard()->user();
        $this->walletService = $walletService;
        $this->authService = $authService;
    }

    public function listProducts(int $page = 1, int $size = 50, ?string $countryCode = null): array
    {
        try {
            $token = $this->authService->getToken($this->product);
            if (empty($token)) return $this->error('Authentification Reloadly (giftcards) impossible', 401);
            $query = ['page' => $page, 'size' => $size];
            $url = $countryCode ? "{$this->baseUrl}/countries/{$countryCode}/products" : "{$this->baseUrl}/products";
            $response = Http::withToken($token)->withHeaders(['Accept' => self::ACCEPT_HEADER])->timeout(self::TIMEOUT_SEARCH)->retry(2, 500)->get($url, $countryCode ? [] : $query);
            if ($response->successful()) return ['success' => true, 'data' => $response->json(), 'status' => $response->status()];
            if ($response->status() === 401) $this->authService->forgetToken($this->product);
            return $this->error($response->json('message', 'Erreur lors de la récupération des produits'), $response->status());
        } catch (\Exception $e) { Log::error('Reloadly GiftCard ListProducts Error: ' . $e->getMessage()); return $this->error($e->getMessage(), 500); }
    }

    public function getProduct(int $productId): array
    {
        try {
            $token = $this->authService->getToken($this->product);
            if (empty($token)) return $this->error('Authentification Reloadly (giftcards) impossible', 401);
            $response = Http::withToken($token)->withHeaders(['Accept' => self::ACCEPT_HEADER])->timeout(self::TIMEOUT_SEARCH)->retry(2, 500)->get("{$this->baseUrl}/products/{$productId}");
            if ($response->successful()) return ['success' => true, 'data' => $response->json(), 'status' => $response->status()];
            return $this->error($response->json('message', 'Produit introuvable'), $response->status());
        } catch (\Exception $e) { Log::error('Reloadly GiftCard GetProduct Error: ' . $e->getMessage()); return $this->error($e->getMessage(), 500); }
    }

    public function orderGiftCard(array $data): array
    {
        if (!isset($data['productId'], $data['unitPrice'])) return $this->error('productId et unitPrice sont requis', 400);
        if (!is_numeric($data['unitPrice'])) return $this->error('unitPrice invalide', 400);
        $quantity = (int) ($data['quantity'] ?? 1);
        $unitPrice = (float) $data['unitPrice'];
        $baseAmount = (float) ($data['baseAmount'] ?? ($unitPrice * $quantity));
        $commissionAmount = (float) ($data['commissionAmount'] ?? 0);
        $walletAmount = (int) ($data['walletAmount'] ?? ceil($baseAmount + $commissionAmount));
        $totalAmount = $walletAmount;
        $idempotencyKey = sha1($data['productId'] . $unitPrice . $quantity . ($data['recipientEmail'] ?? '') . now()->format('YmdHi'));
        $transaction = ReloadlyGiftcardTransaction::firstOrCreate(['idempotency_key' => $idempotencyKey], [
            'user_id' => $this->user->id, 'product_id' => $data['productId'], 'quantity' => $quantity,
            'unit_price' => $unitPrice, 'base_amount' => $baseAmount, 'commission_amount' => $commissionAmount,
            'wallet_amount' => $walletAmount, 'wallet_currency' => $data['walletCurrency'] ?? 'GNF',
            'total_amount' => $totalAmount, 'sender_name' => $data['senderName'] ?? null,
            'recipient_email' => $data['recipientEmail'] ?? null, 'recipient_phone' => $data['recipientPhoneDetails']['number'] ?? null,
            'custom_identifier' => $data['customIdentifier'] ?? null, 'api_status' => 'PENDING',
        ]);
        if ($transaction->api_status !== 'PENDING') return $this->error('Transaction déjà traitée', 409);
        try {
            $this->walletService->withdrawGiftCardPayment($totalAmount, $this->user, [
                'base_amount' => $baseAmount,
                'commission_amount' => $commissionAmount,
                'total_user_price' => $baseAmount + $commissionAmount,
                'wallet_currency' => $data['walletCurrency'] ?? 'GNF',
            ]);
        }
        catch (\Throwable $e) { $transaction->update(['api_status' => 'FAILED', 'error_message' => $e->getMessage()]); return $this->error($e->getMessage(), 400); }
        $token = $this->authService->getToken($this->product);
        if (empty($token)) { $this->refundTransaction($transaction, $totalAmount, 'Authentification Reloadly impossible'); return $this->error('Authentification Reloadly impossible', 401); }
        try {
            $response = Http::withToken($token)->withHeaders(['Accept' => self::ACCEPT_HEADER])->timeout(self::TIMEOUT_ORDER)->post("{$this->baseUrl}/orders", [
                'productId' => $data['productId'], 'quantity' => $quantity, 'unitPrice' => $unitPrice,
                'customIdentifier' => $data['customIdentifier'] ?? ('gc-' . $idempotencyKey), 'senderName' => $data['senderName'] ?? null,
                'recipientEmail' => $data['recipientEmail'] ?? null, 'recipientPhoneDetails' => $data['recipientPhoneDetails'] ?? null,
                'preOrder' => $data['preOrder'] ?? false,
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) { $transaction->update(['api_status' => 'TIMEOUT', 'error_message' => 'Timeout API : ' . $e->getMessage()]); return $this->error('La commande est en cours de traitement. Vérifiez son statut avant de réessayer.', 504); }
        catch (\Exception $e) { $this->refundTransaction($transaction, $totalAmount, 'Exception HTTP: ' . $e->getMessage()); return $this->error($e->getMessage(), 500); }
        return $this->handleOrderResponse($response, $transaction);
    }

    private function handleOrderResponse($response, ReloadlyGiftcardTransaction $transaction): array
    {
        $apiData = $response->json(); $statusCode = $response->status(); $success = $response->successful() && isset($apiData['transactionId']);
        $transaction->update(['api_response' => $apiData, 'reloadly_transaction_id' => $apiData['transactionId'] ?? null, 'product_name' => $apiData['product']['productName'] ?? null, 'country_code' => $apiData['product']['countryCode']['isoName'] ?? null, 'currency_code' => $apiData['product']['recipientCurrencyCode'] ?? null, 'transaction_date' => $apiData['transactionCreatedTime'] ?? null, 'api_status' => $success ? 'SUCCESS' : 'FAILED', 'error_message' => $success ? null : ($apiData['message'] ?? 'Erreur inconnue')]);
        if ($success) return ['success' => true, 'data' => $apiData, 'status' => $statusCode, 'message' => 'Commande gift card effectuée avec succès'];
        if ($statusCode === 401) $this->authService->forgetToken($this->product);
        $this->refundTransaction($transaction, (float) $transaction->total_amount, $apiData['message'] ?? 'Échec commande gift card');
        return ['success' => false, 'error' => $apiData['message'] ?? 'Erreur API Reloadly GiftCard', 'status' => $statusCode, 'data' => $apiData];
    }

    public function getOrderStatus(int $transactionId): array
    {
        try { $token = $this->authService->getToken($this->product); if (empty($token)) return $this->error('Authentification Reloadly (giftcards) impossible', 401); $response = Http::withToken($token)->withHeaders(['Accept' => self::ACCEPT_HEADER])->timeout(self::TIMEOUT_SEARCH)->retry(2, 500)->get("{$this->baseUrl}/orders/transactions/{$transactionId}"); if ($response->successful()) { $local = ReloadlyGiftcardTransaction::where('reloadly_transaction_id', $transactionId)->first(); if ($local && $local->api_status === 'TIMEOUT') { $apiData = $response->json(); $success = ($apiData['status'] ?? null) === 'SUCCESSFUL'; $local->update(['api_status' => $success ? 'SUCCESS' : 'FAILED', 'api_response' => $apiData]); if (!$success) $this->refundTransaction($local, (float) $local->total_amount, 'Synchronisation après timeout: échec confirmé'); } return ['success' => true, 'data' => $response->json(), 'status' => $response->status()]; } return $this->error($response->json('message', 'Transaction introuvable'), $response->status()); } catch (\Exception $e) { Log::error('Reloadly GiftCard GetOrderStatus Error: ' . $e->getMessage()); return $this->error($e->getMessage(), 500); }
    }

    public function redeemCode(int $transactionId): array
    {
        try { $token = $this->authService->getToken($this->product); if (empty($token)) return $this->error('Authentification Reloadly (giftcards) impossible', 401); $response = Http::withToken($token)->withHeaders(['Accept' => self::ACCEPT_HEADER])->timeout(self::TIMEOUT_SEARCH)->retry(2, 500)->get("{$this->baseUrl}/orders/transactions/{$transactionId}/cards"); if ($response->successful()) { $local = ReloadlyGiftcardTransaction::where('reloadly_transaction_id', $transactionId)->where('user_id', $this->user->id)->first(); if ($local) $local->update(['redeem_codes' => $response->json()]); return ['success' => true, 'data' => $response->json(), 'status' => $response->status()]; } return $this->error($response->json('message', 'Codes introuvables'), $response->status()); } catch (\Exception $e) { Log::error('Reloadly GiftCard RedeemCode Error: ' . $e->getMessage()); return $this->error($e->getMessage(), 500); }
    }

    public function getTransactionHistory(string $userId, int $perPage = 20): array
    { try { return ['success' => true, 'data' => ReloadlyGiftcardTransaction::where('user_id', $userId)->orderBy('created_at', 'desc')->paginate($perPage)]; } catch (\Exception $e) { Log::error('Get Reloadly GiftCard Transaction History Error: ' . $e->getMessage()); return $this->error($e->getMessage(), 500); } }

    private function refundTransaction(ReloadlyGiftcardTransaction $transaction, float $amount, string $reason = ''): void
    { try { $this->walletService->refundGiftCardPayment((int) $amount, $this->user); $transaction->update(['api_status' => 'FAILED', 'error_message' => $reason ?: 'Remboursement effectué']); } catch (\Exception $e) { Log::critical('Échec remboursement GiftCard', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]); } }

    private function error(string $message, int $status = 400): array
    { return ['success' => false, 'error' => $message, 'status' => $status]; }
}
