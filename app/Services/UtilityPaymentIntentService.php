<?php

namespace App\Services;

use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class UtilityPaymentIntentService
{
    public function reserve(User $user, float $amount, string $currency, string $subscriberAccountNumber, ?array $currencyResolution = null): PaymentIntent
    {
        $currencyResolution ??= [
            'payment_amount' => $amount,
            'payment_currency' => strtoupper($currency),
            'wallet_amount' => $amount,
            'wallet_currency' => strtoupper($currency),
            'conversion_rate' => null,
            'conversion_effective_at' => null,
            'conversion_applied' => false,
        ];
        $walletAmount = $this->walletAmount((float) $currencyResolution['wallet_amount']);

        return DB::transaction(function () use ($user, $walletAmount, $currency, $subscriberAccountNumber, $currencyResolution): PaymentIntent {
            $walletQuery = Wallet::query()->lockForUpdate()->where('user_id', $user->id);
            $wallet = isset($currencyResolution['wallet_id'])
                ? $walletQuery->whereKey($currencyResolution['wallet_id'])->first()
                : $walletQuery->first();
            if ($wallet === null) {
                throw new RuntimeException('Wallet client introuvable.');
            }
            if (strtoupper((string) $wallet->currency) !== strtoupper((string) $currencyResolution['wallet_currency'])) {
                throw new RuntimeException(isset($currencyResolution['wallet_id'])
                    ? 'Le wallet sélectionné ne correspond pas à la devise de paiement.'
                    : 'La devise du wallet client ne correspond pas à la devise Reloadly.');
            }
            if (($wallet->cash_available - $wallet->blocked_amount) < $walletAmount) {
                throw new RuntimeException('Solde wallet insuffisant.');
            }

            $intent = PaymentIntent::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'provider' => 'reloadly_utilities',
                'provider_reference' => Str::ulid()->toBase32(),
                'amount' => $walletAmount,
                'currency' => strtoupper((string) $currencyResolution['wallet_currency']),
                'payment_currency' => strtoupper((string) $currencyResolution['payment_currency']),
                'wallet_currency' => strtoupper((string) $currencyResolution['wallet_currency']),
                'conversion_rate' => $currencyResolution['conversion_rate'],
                'conversion_effective_at' => $currencyResolution['conversion_effective_at'],
                'converted_amount' => $walletAmount,
                'conversion_applied' => (bool) $currencyResolution['conversion_applied'],
                'subscriber_account_number' => $subscriberAccountNumber,
                'status' => 'RESERVED',
            ]);

            $wallet->increment('blocked_amount', $walletAmount);
            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'amount' => 0,
                'type' => 'utility_payment_reserved',
                'reference' => $intent->provider_reference,
                'description' => 'Réservation paiement Reloadly Utilities',
                'metadata' => ['payment_intent_id' => $intent->id, 'amount' => $walletAmount, 'currency' => strtoupper((string) $currencyResolution['wallet_currency']), 'payment_currency' => strtoupper((string) $currencyResolution['payment_currency']), 'conversion_rate' => $currencyResolution['conversion_rate'], 'conversion_effective_at' => $currencyResolution['conversion_effective_at']],
            ]);

            return $intent;
        });
    }

    public function markProcessing(PaymentIntent $intent, array $providerResponse): PaymentIntent
    {
        return $this->updateStatus($intent, 'PROCESSING', $providerResponse);
    }

    public function markTimeout(PaymentIntent $intent): PaymentIntent
    {
        return $this->updateStatus($intent, 'TIMEOUT');
    }

    public function confirm(PaymentIntent $intent, array $providerResponse): PaymentIntent
    {
        return DB::transaction(function () use ($intent, $providerResponse): PaymentIntent {
            $intent = PaymentIntent::query()->lockForUpdate()->findOrFail($intent->id);
            if ($intent->status === 'SUCCESS') {
                return $intent;
            }
            if (!in_array($intent->status, ['RESERVED', 'PROCESSING', 'TIMEOUT'], true)) {
                throw new RuntimeException('Transition de paiement invalide.');
            }

            $wallet = Wallet::query()->lockForUpdate()->findOrFail($intent->wallet_id);
            $amount = $this->walletAmount((float) $intent->amount);
            $wallet->decrement('cash_available', $amount);
            $wallet->decrement('blocked_amount', $amount);
            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $intent->user_id,
                'amount' => -$amount,
                'type' => 'utility_payment_debit',
                'reference' => $intent->provider_reference,
                'description' => 'Paiement Reloadly Utilities confirmé',
                'metadata' => ['payment_intent_id' => $intent->id, 'currency' => $intent->currency],
            ]);

            return $this->updateStatus($intent, 'SUCCESS', $providerResponse);
        });
    }

    public function release(PaymentIntent $intent, string $status, array $providerResponse = []): PaymentIntent
    {
        return DB::transaction(function () use ($intent, $status, $providerResponse): PaymentIntent {
            $intent = PaymentIntent::query()->lockForUpdate()->findOrFail($intent->id);
            if (!in_array($intent->status, ['RESERVED', 'PROCESSING', 'TIMEOUT'], true)) {
                return $intent;
            }

            $wallet = Wallet::query()->lockForUpdate()->findOrFail($intent->wallet_id);
            $amount = $this->walletAmount((float) $intent->amount);
            $wallet->decrement('blocked_amount', $amount);
            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $intent->user_id,
                'amount' => 0,
                'type' => 'utility_payment_released',
                'reference' => $intent->provider_reference,
                'description' => 'Réservation paiement Reloadly Utilities libérée',
                'metadata' => ['payment_intent_id' => $intent->id, 'currency' => $intent->currency],
            ]);

            return $this->updateStatus($intent, $status, $providerResponse);
        });
    }

    private function updateStatus(PaymentIntent $intent, string $status, array $providerResponse = []): PaymentIntent
    {
        $intent->update([
            'status' => $status,
            'provider_response' => $providerResponse === [] ? $intent->provider_response : $this->sanitizeProviderResponse($providerResponse),
        ]);

        return $intent->fresh();
    }

    public function sanitizeProviderResponse(array $providerResponse): array
    {
        foreach (['subscriberDetails', 'pinDetails', 'subscriberAccountNumber', 'accountNumber'] as $sensitiveKey) {
            unset($providerResponse[$sensitiveKey]);
        }

        foreach ($providerResponse as $key => $value) {
            if (is_array($value)) {
                $providerResponse[$key] = $this->sanitizeProviderResponse($value);
            }
        }

        return $providerResponse;
    }

    private function walletAmount(float $amount): int
    {
        if ($amount <= 0 || floor($amount) !== $amount) {
            throw new RuntimeException('Le wallet EDGPAY ne prend en charge que les montants entiers dans cette devise.');
        }

        return (int) $amount;
    }
}