<?php

namespace App\Repositories;

use App\Enums\ReloadlyProduct;
use App\Interfaces\ReloadlyUtilityRepositoryInterface;
use App\Models\PaymentIntent;
use App\Models\ReloadlyUtilityTransaction;
use App\Models\User;
use App\Services\ReloadlyAuthService;
use App\Services\ReloadlyUtilityPaymentRules;
use App\Services\PaymentCurrencyResolver;
use App\Services\UtilityPaymentIntentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReloadlyUtilityRepository implements ReloadlyUtilityRepositoryInterface
{
    private string $baseUrl;
    private ReloadlyProduct $product;
    private ?User $user;
    private ReloadlyAuthService $authService;
    private ReloadlyUtilityPaymentRules $paymentRules;
    private PaymentCurrencyResolver $currencyResolver;
    private UtilityPaymentIntentService $paymentIntents;
    private const ACCEPT_HEADER = 'application/com.reloadly.utilities-v1+json';
    private const TIMEOUT_SEARCH = 30;
    private const TIMEOUT_TRANSACTION = 60;
    private const TIMEOUT_BALANCE = 15;

    public function __construct(
        ReloadlyAuthService $authService,
        ReloadlyUtilityPaymentRules $paymentRules,
        UtilityPaymentIntentService $paymentIntents,
        PaymentCurrencyResolver $currencyResolver,
    )
    {
        $this->product = ReloadlyProduct::UTILITIES;
        $this->baseUrl = $this->product->baseUrl();
        $this->user = Auth::guard()->user();
        $this->authService = $authService;
        $this->paymentRules = $paymentRules;
        $this->paymentIntents = $paymentIntents;
        $this->currencyResolver = $currencyResolver;
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

    public function payBill(array $data): array
    {
        if (!isset($data['billerId'], $data['amount'], $data['subscriberAccountNumber'])) return $this->error('billerId, amount et subscriberAccountNumber sont requis', 400);
        if (!is_numeric($data['amount'])) return $this->error('Montant invalide', 400);
        $billerResult = $this->findBiller((int) $data['billerId']);
        if (!$billerResult['success']) return $this->error($billerResult['error'], $billerResult['status']);
        $paymentRules = $this->paymentRules->resolve($billerResult['data'], $data);
        if (!$paymentRules['success']) return $this->error($paymentRules['error'], 422, ['code' => $paymentRules['code']]);
        $payment = $paymentRules['data'];
        $amount = $payment['amount'];
        $currencyResolution = $this->currencyResolver->resolve($this->user, $amount, $payment['currency'], (string) ($data['paymentCurrency'] ?? ''));
        if (!$currencyResolution['success']) return $this->error($currencyResolution['error'], 422, ['code' => $currencyResolution['code']]);
        try {
            $intent = $this->paymentIntents->reserve(
                $this->user,
                $amount,
                $payment['currency'],
                $data['subscriberAccountNumber'],
                $currencyResolution['data'],
            );
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422, ['code' => 'PAYMENT_RESERVATION_REJECTED']);
        }
        $idempotencyKey = hash('sha256', $this->user->id . '|' . $intent->provider_reference);
        $referenceId = $intent->provider_reference;
        $transaction = ReloadlyUtilityTransaction::create(['idempotency_key' => $idempotencyKey, 'user_id' => $this->user->id, 'biller_id' => $data['billerId'], 'subscriber_account_number' => hash_hmac('sha256', $data['subscriberAccountNumber'], (string) config('app.key')), 'amount' => $amount, 'use_local_amount' => $payment['useLocalAmount'], 'reference_id' => $referenceId, 'api_status' => 'PENDING']);
        $token = $this->authService->getToken($this->product);
        if (empty($token)) { $this->paymentIntents->release($intent, 'FAILED'); $transaction->update(['api_status' => 'FAILED', 'error_message' => 'Authentification Reloadly impossible']); return $this->error('Authentification Reloadly impossible', 401); }
        try {
            $this->logRequestTarget('payment');
            $payload = [
                'subscriberAccountNumber' => $data['subscriberAccountNumber'],
                'amount' => $amount,
                'billerId' => $data['billerId'],
                'referenceId' => $referenceId,
                'useLocalAmount' => $payment['useLocalAmount'],
            ];
            if ($payment['amountId'] !== null) {
                $payload['amountId'] = $payment['amountId'];
            }
            $response = Http::withToken($token)
                ->withHeaders(['Accept' => self::ACCEPT_HEADER])
                ->timeout(self::TIMEOUT_TRANSACTION)
                ->post("{$this->baseUrl}/pay", $payload);
        }
        catch (\Illuminate\Http\Client\ConnectionException $e) { $this->paymentIntents->markTimeout($intent); $transaction->update(['api_status' => 'TIMEOUT', 'error_message' => 'Timeout API : ' . $e->getMessage()]); return $this->error('Le paiement est en cours de traitement. Vérifiez son statut avant de réessayer.', 504); }
        catch (\Exception $e) { $this->paymentIntents->release($intent, 'FAILED'); $transaction->update(['api_status' => 'FAILED', 'error_message' => 'Exception HTTP: ' . $e->getMessage()]); return $this->error($e->getMessage(), 500); }
        return $this->handlePayResponse($response, $transaction, $payment, $intent);
    }

    public function paymentOptions(array $data): array
    {
        if (!isset($data['billerId'], $data['amount'])) return $this->error('billerId et amount sont requis', 400);
        $billerResult = $this->findBiller((int) $data['billerId']);
        if (!$billerResult['success']) return $this->error($billerResult['error'], $billerResult['status']);
        $paymentRules = $this->paymentRules->resolve($billerResult['data'], $data);
        if (!$paymentRules['success']) return $this->error($paymentRules['error'], 422, ['code' => $paymentRules['code']]);

        $options = $this->currencyResolver->options($this->user, $paymentRules['data']['amount'], $paymentRules['data']['currency']);
        $compatibleOptions = collect($options['options'])->filter(
            fn (array $option): bool => $option['currency'] === $options['service_currency'] || $option['conversion_available'],
        );
        if ($compatibleOptions->isEmpty()) {
            return $this->error(
                'Aucune devise wallet n’est autorisée pour cette facture.',
                422,
                ['code' => 'PAYMENT_CURRENCY_NOT_SUPPORTED'],
            );
        }
        $payableOptions = $compatibleOptions->filter(fn (array $option): bool => $option['can_pay']);
        if ($payableOptions->isEmpty()) {
            return $this->error(
                'Solde insuffisant dans les devises wallet autorisées.',
                422,
                ['code' => 'PAYMENT_CURRENCY_INSUFFICIENT_FUNDS'],
            );
        }

        return ['success' => true, 'data' => [
            'serviceCurrency' => $options['service_currency'],
            'options' => $payableOptions
                ->map(fn (array $option): array => [
                    'walletCurrency' => $option['currency'],
                    'balance' => $option['wallet_balance'],
                    'conversionAvailable' => $option['conversion_available'],
                    'rate' => $option['conversion_rate'],
                    'payableAmount' => $option['estimated_amount'],
                ])
                ->values()
                ->all(),
        ], 'status' => 200];
    }

    private function handlePayResponse($response, ReloadlyUtilityTransaction $transaction, array $payment, \App\Models\PaymentIntent $intent): array
    {
        $apiData = $response->json(); $statusCode = $response->status(); $apiStatus = $apiData['status'] ?? null; $isSuccess = $response->successful() && $apiStatus === 'SUCCESSFUL'; $isProcessing = $response->successful() && $apiStatus === 'PROCESSING';
        $safeApiData = $this->paymentIntents->sanitizeProviderResponse($apiData);
        $transaction->update(['api_response' => $safeApiData, 'reloadly_transaction_id' => $apiData['id'] ?? null, 'biller_name' => $apiData['billerName'] ?? null, 'country_code' => $apiData['countryCode'] ?? null, 'transaction_date' => $apiData['date'] ?? null, 'api_status' => $isSuccess ? 'SUCCESS' : ($isProcessing ? 'PROCESSING' : 'FAILED'), 'error_message' => (!$isSuccess && !$isProcessing) ? ($apiData['message'] ?? 'Erreur inconnue') : null]);
        $apiData['amount'] = $payment['amount'];
        $apiData['currency'] = $payment['currency'];
        $apiData['displayCurrency'] = $payment['displayCurrency'];
        if ($isSuccess) { $this->paymentIntents->confirm($intent, $safeApiData); return ['success' => true, 'data' => $apiData, 'status' => $statusCode, 'message' => 'Paiement de facture effectué avec succès']; }
        if ($isProcessing) { $this->paymentIntents->markProcessing($intent, $safeApiData); return ['success' => true, 'data' => $apiData, 'status' => $statusCode, 'message' => 'Paiement en cours de traitement chez le fournisseur']; }
        if ($statusCode === 401) $this->authService->forgetToken($this->product);
        $this->paymentIntents->release($intent, 'FAILED', $safeApiData);
        $transaction->update(['api_status' => 'FAILED', 'error_message' => $apiData['message'] ?? 'Échec paiement facture']);
        return ['success' => false, 'error' => $apiData['message'] ?? 'Erreur API Reloadly Utilities', 'status' => $statusCode, 'data' => $apiData];
    }

    public function getTransactionStatus(int $transactionId): array
    {
        try {
            $token = $this->authService->getToken($this->product);
            if (empty($token)) return $this->error('Authentification Reloadly (utilities) impossible', 401);

            $response = Http::withToken($token)
                ->withHeaders(['Accept' => self::ACCEPT_HEADER])
                ->timeout(self::TIMEOUT_SEARCH)
                ->retry(2, 500)
                ->get("{$this->baseUrl}/transactions/{$transactionId}");
            if (!$response->successful()) {
                return $this->error($response->json('message', 'Transaction introuvable'), $response->status());
            }

            $responseBody = $response->json();
            $transactionData = $responseBody['transaction'] ?? $responseBody;
            $local = ReloadlyUtilityTransaction::query()
                ->where('reloadly_transaction_id', $transactionId)
                ->where('user_id', $this->user->id)
                ->first();
            if ($local !== null && in_array($local->api_status, ['TIMEOUT', 'PROCESSING'], true)) {
                $intent = PaymentIntent::query()->where('provider_reference', $local->reference_id)->first();
                $providerStatus = $transactionData['status'] ?? null;
                if ($providerStatus === 'SUCCESSFUL') {
                    $safeTransactionData = $this->paymentIntents->sanitizeProviderResponse($transactionData);
                    $local->update(['api_status' => 'SUCCESS', 'api_response' => $safeTransactionData]);
                    $intent?->status !== 'SUCCESS' && $intent !== null ? $this->paymentIntents->confirm($intent, $safeTransactionData) : null;
                } elseif ($providerStatus === 'FAILED') {
                    $safeTransactionData = $this->paymentIntents->sanitizeProviderResponse($transactionData);
                    $local->update(['api_status' => 'FAILED', 'api_response' => $safeTransactionData]);
                    $intent !== null ? $this->paymentIntents->release($intent, 'FAILED', $safeTransactionData) : null;
                }
            }

            return ['success' => true, 'data' => $transactionData, 'status' => $response->status()];
        } catch (\Exception $e) {
            Log::error('Reloadly Utility GetTransactionStatus Error: ' . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    public function getBalance(): array
    { try { $token = $this->authService->getToken($this->product); if (empty($token)) return $this->error('Authentification Reloadly (utilities) impossible', 401); $response = Http::withToken($token)->withHeaders(['Accept' => self::ACCEPT_HEADER])->timeout(self::TIMEOUT_BALANCE)->retry(2, 500)->get("{$this->baseUrl}/accounts/balance"); if ($response->successful()) return ['success' => true, 'data' => $response->json(), 'status' => $response->status()]; return $this->error('Erreur lors de la récupération du solde Utilities', $response->status()); } catch (\Exception $e) { Log::error('Reloadly Utility GetBalance Error: ' . $e->getMessage()); return $this->error($e->getMessage(), 500); } }

    private function findBiller(int $billerId): array
    {
        try {
            $token = $this->authService->getToken($this->product);
            if (empty($token)) return $this->error('Authentification Reloadly (utilities) impossible', 401);
            $response = Http::withToken($token)->withHeaders(['Accept' => self::ACCEPT_HEADER])->timeout(self::TIMEOUT_SEARCH)->retry(2, 500)->get("{$this->baseUrl}/billers", ['id' => $billerId, 'page' => 1, 'size' => 20]);
            if (!$response->successful()) return $this->error($response->json('message', 'Biller introuvable'), $response->status());
            $biller = collect($response->json('content', []))->first(fn ($item) => (int) ($item['id'] ?? 0) === $billerId);
            return is_array($biller) ? ['success' => true, 'data' => $biller, 'status' => $response->status()] : $this->error('Biller introuvable', 404);
        } catch (\Exception $e) { Log::error('Reloadly Utility FindBiller Error: ' . $e->getMessage()); return $this->error('Catalogue Reloadly indisponible', 502); }
    }

    public function getTransactionHistory(string $userId, int $perPage = 20): array
    { try { return ['success' => true, 'data' => ReloadlyUtilityTransaction::query()->with('paymentIntent:id,provider_reference,currency,payment_currency,wallet_currency,conversion_rate,conversion_effective_at,converted_amount,conversion_applied')->where('user_id', $userId)->latest()->paginate($perPage)]; } catch (\Exception $e) { Log::error('Get Reloadly Utility Transaction History Error: ' . $e->getMessage()); return $this->error($e->getMessage(), 500); } }

    private function logRequestTarget(string $operation): void
    {
        $isLive = config('services.reloadly.mode') === 'live';
        $environment = $isLive ? 'PRODUCTION' : 'SANDBOX';
        Log::info("Reloadly Utilities Request Target: {$environment} [{$this->baseUrl}]", [
            'operation' => $operation,
            'mode' => config('services.reloadly.mode'),
        ]);
    }

    private function error(string $message, int $status = 400, ?array $data = null): array
    {
        return ['success' => false, 'error' => $message, 'status' => $status, 'data' => $data];
    }
}
