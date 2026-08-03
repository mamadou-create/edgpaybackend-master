<?php

namespace App\Services;

use App\Enums\ReloadlyProduct;
use App\Models\PaymentIntent;
use App\Models\ReloadlyUtilityTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class UtilityPaymentReconciliationService
{
    private const ACCEPT_HEADER = 'application/com.reloadly.utilities-v1+json';

    public function __construct(
        private readonly ReloadlyAuthService $authService,
        private readonly UtilityPaymentIntentService $paymentIntents,
    ) {}

    public function reconcile(string $intentId): string
    {
        $lock = Cache::lock("utilities:reconcile:intent:{$intentId}", 120);
        if (!$lock->get()) {
            return 'LOCKED';
        }

        try {
            [$intent, $transaction] = DB::transaction(function () use ($intentId): array {
                $intent = PaymentIntent::query()->lockForUpdate()->find($intentId);
                if ($intent === null || !in_array($intent->status, ['PROCESSING', 'TIMEOUT'], true)) {
                    return [null, null];
                }

                return [
                    $intent,
                    ReloadlyUtilityTransaction::query()
                        ->where('reference_id', $intent->provider_reference)
                        ->lockForUpdate()
                        ->first(),
                ];
            });

            if ($intent === null) {
                return 'SKIPPED';
            }

            $token = $this->authService->getToken(ReloadlyProduct::UTILITIES);
            if ($token === '') {
                Log::warning('Utility payment reconciliation skipped: Reloadly authentication unavailable', [
                    'payment_intent_id' => $intent->id,
                    'provider_reference' => $intent->provider_reference,
                ]);

                return 'AUTHENTICATION_ERROR';
            }

            $response = $this->providerResponse($token, $intent, $transaction);
            if (!$response->successful()) {
                if ($response->status() === 401) {
                    $this->authService->forgetToken(ReloadlyProduct::UTILITIES);
                }
                Log::warning('Utility payment reconciliation provider error', [
                    'payment_intent_id' => $intent->id,
                    'provider_reference' => $intent->provider_reference,
                    'http_status' => $response->status(),
                    'provider_error_code' => $response->json('errorCode'),
                ]);

                return 'PROVIDER_ERROR';
            }

            $providerTransaction = $this->findProviderTransaction($response->json(), $intent->provider_reference);
            if ($providerTransaction === null) {
                Log::info('Utility payment reconciliation transaction not found yet', [
                    'payment_intent_id' => $intent->id,
                    'provider_reference' => $intent->provider_reference,
                ]);

                return 'NOT_FOUND';
            }

            $providerStatus = (string) ($providerTransaction['status'] ?? '');
            $safeResponse = $this->paymentIntents->sanitizeProviderResponse($providerTransaction);
            $this->updateLocalTransaction($transaction, $safeResponse, $providerStatus);

            return match ($providerStatus) {
                'SUCCESSFUL' => $this->confirm($intent, $safeResponse),
                'FAILED', 'REFUNDED' => $this->release($intent, $providerStatus, $safeResponse),
                'PROCESSING' => 'PROCESSING',
                default => 'UNKNOWN_STATUS',
            };
        } catch (\Throwable $exception) {
            Log::error('Utility payment reconciliation failed', [
                'payment_intent_id' => $intentId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return 'ERROR';
        } finally {
            $lock->release();
        }
    }

    private function providerResponse(string $token, PaymentIntent $intent, ?ReloadlyUtilityTransaction $transaction)
    {
        $client = Http::withToken($token)
            ->withHeaders(['Accept' => self::ACCEPT_HEADER])
            ->timeout(30)
            ->retry(2, 500, throw: false);

        if ($transaction?->reloadly_transaction_id !== null) {
            return $client->get($this->baseUrl() . '/transactions/' . $transaction->reloadly_transaction_id);
        }

        return $client->get($this->baseUrl() . '/transactions', [
            'referenceId' => $intent->provider_reference,
            'page' => 1,
            'size' => 1,
        ]);
    }

    private function findProviderTransaction(array $response, string $reference): ?array
    {
        if (isset($response['transaction']) && is_array($response['transaction'])) {
            return $response['transaction'];
        }

        foreach (($response['content'] ?? []) as $entry) {
            $transaction = is_array($entry) ? ($entry['transaction'] ?? null) : null;
            if (is_array($transaction) && ($transaction['referenceId'] ?? null) === $reference) {
                return $transaction;
            }
        }

        return null;
    }

    private function confirm(PaymentIntent $intent, array $providerResponse): string
    {
        $this->paymentIntents->confirm($intent, $providerResponse);

        return 'SUCCESS';
    }

    private function release(PaymentIntent $intent, string $providerStatus, array $providerResponse): string
    {
        $this->paymentIntents->release($intent, $providerStatus === 'REFUNDED' ? 'REFUNDED' : 'FAILED', $providerResponse);

        return $providerStatus === 'REFUNDED' ? 'REFUNDED' : 'FAILED';
    }

    private function updateLocalTransaction(?ReloadlyUtilityTransaction $transaction, array $providerResponse, string $providerStatus): void
    {
        if ($transaction === null) {
            return;
        }

        $transaction->update([
            'reloadly_transaction_id' => $providerResponse['id'] ?? $transaction->reloadly_transaction_id,
            'api_status' => match ($providerStatus) {
                'SUCCESSFUL' => 'SUCCESS',
                'FAILED', 'REFUNDED' => 'FAILED',
                default => 'PROCESSING',
            },
            'api_response' => $providerResponse,
            'error_message' => in_array($providerStatus, ['FAILED', 'REFUNDED'], true)
                ? ($providerResponse['message'] ?? 'Paiement Utilities échoué')
                : null,
        ]);
    }

    private function baseUrl(): string
    {
        return ReloadlyProduct::UTILITIES->baseUrl();
    }
}