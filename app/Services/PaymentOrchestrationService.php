<?php

namespace App\Services;

use App\Jobs\ExecuteReloadlyOrderJob;
use App\Models\AirtimeOrder;
use App\Models\DataOrder;
use App\Models\PaymentTransaction;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WebhookLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentOrchestrationService
{
    public function __construct(
        private WebhookSignatureService $webhookSignatureService
    ) {
    }

    public function createAirtimePurchaseIntent(array $input, ?User $user): array
    {
        $shouldDispatchReloadly = false;
        $walletBalanceBefore = null;
        $walletBalanceAfter = null;
        $walletDebitedAmount = null;

        $result = DB::transaction(function () use (
            $input,
            $user,
            &$shouldDispatchReloadly,
            &$walletBalanceBefore,
            &$walletBalanceAfter,
            &$walletDebitedAmount
        ) {
            $paymentReference = $this->generateReference('PAY');
            $transactionRef = $this->generateReference('TRX');

            $transaction = Transaction::create([
                'user_id' => $user?->id,
                'wallet_id' => null,
                'reference' => $transactionRef,
                'external_reference' => $paymentReference,
                'idempotency_key' => request()->attributes->get('idempotency_key'),
                'correlation_id' => request()->attributes->get('correlation_id'),
                'type' => 'AIRTIME_PURCHASE',
                'direction' => 'DEBIT',
                'status' => 'INITIATED',
                'amount' => (float) $input['amount'],
                'currency' => (string) ($input['currency'] ?? 'GNF'),
                'provider' => (string) $input['payment_provider'],
                'provider_status' => 'PENDING',
                'description' => 'Airtime purchase intent',
                'metadata' => [
                    'operator_id' => $input['operator_id'] ?? null,
                    'recipient_phone' => $input['recipient_phone'],
                ],
            ]);

            $paymentTransaction = PaymentTransaction::create([
                'transaction_id' => $transaction->id,
                'user_id' => $user?->id,
                'provider' => (string) $input['payment_provider'],
                'channel' => (string) ($input['payment_channel'] ?? 'MOBILE_MONEY'),
                'payment_reference' => $paymentReference,
                'merchant_reference' => $transactionRef,
                'provider_payment_id' => null,
                'msisdn' => (string) ($input['payer_msisdn'] ?? $input['recipient_phone']),
                'amount' => (float) $input['amount'],
                'currency' => (string) ($input['currency'] ?? 'GNF'),
                'status' => 'PENDING',
                'confirmation_status' => 'UNCONFIRMED',
                'idempotency_key' => request()->attributes->get('idempotency_key'),
                'correlation_id' => request()->attributes->get('correlation_id'),
                'webhook_verified' => false,
                'expires_at' => now()->addMinutes((int) ($input['expires_in_minutes'] ?? 15)),
                'raw_request' => $input,
                'metadata' => ['intent_type' => 'airtime'],
            ]);

            $airtimeOrder = AirtimeOrder::create([
                'user_id' => $user?->id,
                'transaction_id' => $transaction->id,
                'payment_transaction_id' => $paymentTransaction->id,
                'operator_id' => (string) ($input['operator_id'] ?? '0'),
                'operator_name' => (string) ($input['operator_name'] ?? ''),
                'recipient_msisdn' => (string) $input['recipient_phone'],
                'country_code' => (string) ($input['recipient_country_code'] ?? 'GN'),
                'amount' => (float) $input['amount'],
                'local_amount' => (float) $input['amount'],
                'local_currency' => (string) ($input['currency'] ?? 'GNF'),
                'status' => 'PENDING',
                'correlation_id' => request()->attributes->get('correlation_id'),
                'metadata' => [
                    'use_local_amount' => true,
                    'custom_identifier' => $paymentReference,
                ],
            ]);

            if ($this->isWalletPayment($input)) {
                $walletFlow = $this->confirmPaymentByWallet(
                    user: $user,
                    paymentTx: $paymentTransaction,
                    transaction: $transaction,
                    amount: (float) $input['amount'],
                    currency: (string) ($input['currency'] ?? 'GNF')
                );
                $walletBalanceBefore = $walletFlow['wallet_balance_before'];
                $walletBalanceAfter = $walletFlow['wallet_balance_after'];
                $walletDebitedAmount = $walletFlow['wallet_debited_amount'];
                $shouldDispatchReloadly = true;
            }

            return [
                'transaction' => $transaction,
                'payment_transaction' => $paymentTransaction,
                'airtime_order' => $airtimeOrder,
                'wallet_balance_before' => $walletBalanceBefore,
                'wallet_balance_after' => $walletBalanceAfter,
                'wallet_debited_amount' => $walletDebitedAmount,
            ];
        });

        if ($shouldDispatchReloadly) {
            ExecuteReloadlyOrderJob::dispatch($result['payment_transaction']->id)->onQueue('reloadly');
        }

        return $result;
    }

    public function createDataPurchaseIntent(array $input, ?User $user): array
    {
        $shouldDispatchReloadly = false;
        $walletBalanceBefore = null;
        $walletBalanceAfter = null;
        $walletDebitedAmount = null;

        $result = DB::transaction(function () use (
            $input,
            $user,
            &$shouldDispatchReloadly,
            &$walletBalanceBefore,
            &$walletBalanceAfter,
            &$walletDebitedAmount
        ) {
            $paymentReference = $this->generateReference('PAY');
            $transactionRef = $this->generateReference('TRX');

            $transaction = Transaction::create([
                'user_id' => $user?->id,
                'wallet_id' => null,
                'reference' => $transactionRef,
                'external_reference' => $paymentReference,
                'idempotency_key' => request()->attributes->get('idempotency_key'),
                'correlation_id' => request()->attributes->get('correlation_id'),
                'type' => 'DATA_PURCHASE',
                'direction' => 'DEBIT',
                'status' => 'INITIATED',
                'amount' => (float) $input['amount'],
                'currency' => (string) ($input['currency'] ?? 'GNF'),
                'provider' => (string) $input['payment_provider'],
                'provider_status' => 'PENDING',
                'description' => 'Data purchase intent',
                'metadata' => [
                    'operator_id' => $input['operator_id'] ?? null,
                    'data_plan_id' => $input['data_plan_id'],
                    'recipient_phone' => $input['recipient_phone'],
                ],
            ]);

            $paymentTransaction = PaymentTransaction::create([
                'transaction_id' => $transaction->id,
                'user_id' => $user?->id,
                'provider' => (string) $input['payment_provider'],
                'channel' => (string) ($input['payment_channel'] ?? 'MOBILE_MONEY'),
                'payment_reference' => $paymentReference,
                'merchant_reference' => $transactionRef,
                'provider_payment_id' => null,
                'msisdn' => (string) ($input['payer_msisdn'] ?? $input['recipient_phone']),
                'amount' => (float) $input['amount'],
                'currency' => (string) ($input['currency'] ?? 'GNF'),
                'status' => 'PENDING',
                'confirmation_status' => 'UNCONFIRMED',
                'idempotency_key' => request()->attributes->get('idempotency_key'),
                'correlation_id' => request()->attributes->get('correlation_id'),
                'webhook_verified' => false,
                'expires_at' => now()->addMinutes((int) ($input['expires_in_minutes'] ?? 15)),
                'raw_request' => $input,
                'metadata' => ['intent_type' => 'data'],
            ]);

            $dataOrder = DataOrder::create([
                'user_id' => $user?->id,
                'transaction_id' => $transaction->id,
                'payment_transaction_id' => $paymentTransaction->id,
                'operator_id' => (string) ($input['operator_id'] ?? '0'),
                'operator_name' => (string) ($input['operator_name'] ?? ''),
                'recipient_msisdn' => (string) $input['recipient_phone'],
                'country_code' => (string) ($input['recipient_country_code'] ?? 'GN'),
                'data_plan_id' => (string) $input['data_plan_id'],
                'data_plan_name' => (string) ($input['data_plan_name'] ?? ''),
                'validity' => (string) ($input['validity'] ?? ''),
                'allowance' => (string) ($input['allowance'] ?? ''),
                'amount' => (float) $input['amount'],
                'local_amount' => (float) $input['amount'],
                'local_currency' => (string) ($input['currency'] ?? 'GNF'),
                'status' => 'PENDING',
                'correlation_id' => request()->attributes->get('correlation_id'),
                'metadata' => [
                    'bundle_id' => (string) $input['data_plan_id'],
                    'custom_identifier' => $paymentReference,
                ],
            ]);

            if ($this->isWalletPayment($input)) {
                $walletFlow = $this->confirmPaymentByWallet(
                    user: $user,
                    paymentTx: $paymentTransaction,
                    transaction: $transaction,
                    amount: (float) $input['amount'],
                    currency: (string) ($input['currency'] ?? 'GNF')
                );
                $walletBalanceBefore = $walletFlow['wallet_balance_before'];
                $walletBalanceAfter = $walletFlow['wallet_balance_after'];
                $walletDebitedAmount = $walletFlow['wallet_debited_amount'];
                $shouldDispatchReloadly = true;
            }

            return [
                'transaction' => $transaction,
                'payment_transaction' => $paymentTransaction,
                'data_order' => $dataOrder,
                'wallet_balance_before' => $walletBalanceBefore,
                'wallet_balance_after' => $walletBalanceAfter,
                'wallet_debited_amount' => $walletDebitedAmount,
            ];
        });

        if ($shouldDispatchReloadly) {
            ExecuteReloadlyOrderJob::dispatch($result['payment_transaction']->id)->onQueue('reloadly');
        }

        return $result;
    }

    public function handlePaymentWebhook(
        string $provider,
        array $payload,
        array $headers,
        string $rawPayload,
        ?string $signature
    ): array {
        $providerSlug = strtoupper(trim($provider));

        $eventId = $this->extractEventId($payload) ?? hash('sha256', $providerSlug . '|' . $rawPayload);
        $paymentReference = $this->extractPaymentReference($payload);
        $normalizedStatus = $this->normalizePaymentStatus($payload);

        $existing = WebhookLog::where('provider', $providerSlug)
            ->where('event_id', $eventId)
            ->first();

        if ($existing && $existing->status === 'PROCESSED') {
            return [
                'success' => true,
                'status' => 200,
                'message' => 'Webhook déjà traité',
                'business_code' => 'WEBHOOK_ALREADY_PROCESSED',
                'data' => ['webhook_log_id' => $existing->id],
            ];
        }

        $signatureValid = $this->webhookSignatureService->isValid($providerSlug, $signature, $rawPayload);

        $webhookLog = $existing ?? new WebhookLog();
        $webhookLog->fill([
            'provider' => $providerSlug,
            'event_type' => (string) ($payload['event'] ?? $payload['type'] ?? 'payment.updated'),
            'event_id' => $eventId,
            'signature_header' => $signature,
            'signature_valid' => $signatureValid,
            'correlation_id' => request()->attributes->get('correlation_id'),
            'request_headers' => $headers,
            'payload' => $payload,
            'received_at' => now(),
            'status' => 'RECEIVED',
        ]);
        $webhookLog->save();

        if (!$signatureValid) {
            $webhookLog->status = 'FAILED';
            $webhookLog->processing_error = 'Invalid webhook signature';
            $webhookLog->processed_at = now();
            $webhookLog->save();

            return [
                'success' => false,
                'status' => 403,
                'message' => 'Signature webhook invalide',
                'business_code' => 'INVALID_WEBHOOK_SIGNATURE',
                'data' => ['webhook_log_id' => $webhookLog->id],
            ];
        }

        if ($paymentReference === null) {
            $webhookLog->status = 'IGNORED';
            $webhookLog->processing_error = 'Payment reference missing';
            $webhookLog->processed_at = now();
            $webhookLog->save();

            return [
                'success' => false,
                'status' => 422,
                'message' => 'Référence paiement absente du webhook',
                'business_code' => 'PAYMENT_REFERENCE_MISSING',
                'data' => ['webhook_log_id' => $webhookLog->id],
            ];
        }

        $dispatched = false;
        DB::transaction(function () use ($providerSlug, $paymentReference, $normalizedStatus, $payload, $webhookLog, &$dispatched) {
            /** @var PaymentTransaction|null $paymentTx */
            $paymentTx = PaymentTransaction::where('provider', $providerSlug)
                ->where(function ($q) use ($paymentReference) {
                    $q->where('payment_reference', $paymentReference)
                        ->orWhere('merchant_reference', $paymentReference)
                        ->orWhere('provider_payment_id', $paymentReference);
                })
                ->lockForUpdate()
                ->first();

            if (!$paymentTx) {
                $webhookLog->status = 'IGNORED';
                $webhookLog->processing_error = 'Payment transaction not found';
                $webhookLog->processed_at = now();
                $webhookLog->save();
                return;
            }

            $webhookLog->payment_transaction_id = $paymentTx->id;

            $currentStatus = (string) $paymentTx->status;
            $alreadyConfirmed = $currentStatus === 'CONFIRMED';

            if ($normalizedStatus === 'CONFIRMED') {
                $paymentTx->status = 'CONFIRMED';
                $paymentTx->confirmation_status = 'CONFIRMED';
                $paymentTx->provider_payment_id = $this->extractProviderPaymentId($payload) ?? $paymentTx->provider_payment_id;
                $paymentTx->webhook_verified = true;
                $paymentTx->webhook_verified_at = now();
                $paymentTx->paid_at = now();
                $paymentTx->raw_response = $payload;
                $paymentTx->save();

                if ($paymentTx->transaction) {
                    $paymentTx->transaction->status = 'SUCCESS';
                    $paymentTx->transaction->provider_status = 'CONFIRMED';
                    $paymentTx->transaction->processed_at = now();
                    $paymentTx->transaction->save();
                }

                if (!$alreadyConfirmed) {
                    ExecuteReloadlyOrderJob::dispatch($paymentTx->id)->onQueue('reloadly');
                    $dispatched = true;
                }
            } elseif ($normalizedStatus === 'FAILED') {
                $paymentTx->status = 'FAILED';
                $paymentTx->confirmation_status = 'UNCONFIRMED';
                $paymentTx->provider_payment_id = $this->extractProviderPaymentId($payload) ?? $paymentTx->provider_payment_id;
                $paymentTx->raw_response = $payload;
                $paymentTx->save();

                if ($paymentTx->transaction) {
                    $paymentTx->transaction->status = 'FAILED';
                    $paymentTx->transaction->provider_status = 'FAILED';
                    $paymentTx->transaction->processed_at = now();
                    $paymentTx->transaction->save();
                }

                AirtimeOrder::where('payment_transaction_id', $paymentTx->id)
                    ->where('status', 'PENDING')
                    ->update([
                        'status' => 'FAILED',
                        'error_code' => 'PAYMENT_FAILED',
                        'error_message' => 'Paiement rejeté/échoué',
                        'updated_at' => now(),
                    ]);

                DataOrder::where('payment_transaction_id', $paymentTx->id)
                    ->where('status', 'PENDING')
                    ->update([
                        'status' => 'FAILED',
                        'error_code' => 'PAYMENT_FAILED',
                        'error_message' => 'Paiement rejeté/échoué',
                        'updated_at' => now(),
                    ]);
            }

            $webhookLog->status = 'PROCESSED';
            $webhookLog->processed_at = now();
            $webhookLog->save();
        }, 3);

        return [
            'success' => true,
            'status' => 200,
            'message' => $dispatched ? 'Webhook traité, recharge planifiée' : 'Webhook traité',
            'business_code' => 'WEBHOOK_PROCESSED',
            'data' => [
                'webhook_log_id' => $webhookLog->id,
                'payment_reference' => $paymentReference,
                'normalized_status' => $normalizedStatus,
                'reload_job_dispatched' => $dispatched,
            ],
        ];
    }

    private function generateReference(string $prefix): string
    {
        return strtoupper($prefix . '-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(8)));
    }

    private function extractEventId(array $payload): ?string
    {
        $candidates = [
            $payload['event_id'] ?? null,
            $payload['id'] ?? null,
            $payload['data']['id'] ?? null,
            $payload['eventId'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    private function extractPaymentReference(array $payload): ?string
    {
        $candidates = [
            $payload['payment_reference'] ?? null,
            $payload['paymentReference'] ?? null,
            $payload['merchant_reference'] ?? null,
            $payload['merchantReference'] ?? null,
            $payload['transaction_id'] ?? null,
            $payload['transactionId'] ?? null,
            $payload['reference'] ?? null,
            $payload['tx_ref'] ?? null,
            $payload['data']['payment_reference'] ?? null,
            $payload['data']['paymentReference'] ?? null,
            $payload['data']['merchantReference'] ?? null,
            $payload['data']['reference'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    private function extractProviderPaymentId(array $payload): ?string
    {
        $candidates = [
            $payload['provider_payment_id'] ?? null,
            $payload['providerPaymentId'] ?? null,
            $payload['payment_id'] ?? null,
            $payload['paymentId'] ?? null,
            $payload['id'] ?? null,
            $payload['data']['id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    private function normalizePaymentStatus(array $payload): string
    {
        $statusRaw = (string) (
            $payload['status']
            ?? $payload['payment_status']
            ?? $payload['paymentStatus']
            ?? $payload['event']
            ?? $payload['type']
            ?? $payload['data']['status']
            ?? ''
        );

        $status = strtoupper(trim($statusRaw));

        $confirmedStatuses = ['SUCCESS', 'SUCCEEDED', 'CONFIRMED', 'COMPLETED', 'PAID', 'PAYMENT_CONFIRMED'];
        $failedStatuses = ['FAILED', 'CANCELLED', 'EXPIRED', 'DECLINED', 'REJECTED'];

        if (in_array($status, $confirmedStatuses, true)) {
            return 'CONFIRMED';
        }

        if (in_array($status, $failedStatuses, true)) {
            return 'FAILED';
        }

        return 'PENDING';
    }

    private function isWalletPayment(array $input): bool
    {
        $provider = strtoupper(trim((string) ($input['payment_provider'] ?? '')));
        $channel = strtoupper(trim((string) ($input['payment_channel'] ?? '')));

        return $provider === 'WALLET' || $channel === 'WALLET';
    }

    private function confirmPaymentByWallet(
        ?User $user,
        PaymentTransaction $paymentTx,
        Transaction $transaction,
        float $amount,
        string $currency
    ): array {
        if (!$user) {
            throw new \RuntimeException('Utilisateur requis pour un paiement wallet.');
        }

        $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();
        if (!$wallet) {
            throw new \RuntimeException('Wallet client introuvable.');
        }

        $amountInt = (int) round($amount);
        $balanceBefore = (int) $wallet->cash_available;
        $available = (int) $wallet->cash_available - (int) $wallet->blocked_amount;
        if ($available < $amountInt) {
            throw new \RuntimeException('Solde wallet insuffisant pour cette opération.');
        }

        $wallet->cash_available = (int) $wallet->cash_available - $amountInt;
        $wallet->save();

        $user->solde_portefeuille = max(0, (int) $wallet->cash_available);
        $user->save();

        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'amount' => -$amountInt,
            'type' => 'debit',
            'reference' => 'txn_wallet_pay_' . Str::lower(Str::random(12)),
            'description' => 'Paiement achat mobile via wallet',
            'metadata' => [
                'payment_transaction_id' => $paymentTx->id,
                'payment_reference' => $paymentTx->payment_reference,
                'currency' => strtoupper($currency),
            ],
        ]);

        $paymentTx->status = 'CONFIRMED';
        $paymentTx->confirmation_status = 'CONFIRMED';
        $paymentTx->provider_payment_id = 'WALLET-' . $paymentTx->payment_reference;
        $paymentTx->webhook_verified = true;
        $paymentTx->webhook_verified_at = now();
        $paymentTx->paid_at = now();
        $paymentTx->raw_response = [
            'source' => 'wallet',
            'status' => 'CONFIRMED',
            'wallet_id' => $wallet->id,
        ];
        $paymentTx->save();

        $transaction->status = 'SUCCESS';
        $transaction->provider_status = 'CONFIRMED';
        $transaction->processed_at = now();
        $transaction->save();

        return [
            'wallet_balance_before' => $balanceBefore,
            'wallet_balance_after' => (int) $wallet->cash_available,
            'wallet_debited_amount' => $amountInt,
        ];
    }
}
