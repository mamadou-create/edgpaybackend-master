<?php

namespace App\Services;

use App\Models\CurrencyConversionRate;
use App\Models\User;
use App\Models\Wallet;

final class PaymentCurrencyResolver
{
    public function options(User $user, float $paymentAmount, string $paymentCurrency): array
    {
        $paymentCurrency = strtoupper($paymentCurrency);
        $options = Wallet::query()
            ->where('user_id', $user->id)
            ->orderBy('currency')
            ->get()
            ->map(function (Wallet $wallet) use ($paymentAmount, $paymentCurrency): array {
                $walletCurrency = strtoupper((string) $wallet->currency);
                $availableBalance = $wallet->cash_available - $wallet->blocked_amount;

                if ($walletCurrency === $paymentCurrency) {
                    return [
                        'wallet_id' => $wallet->id,
                        'currency' => $walletCurrency,
                        'wallet_balance' => $availableBalance,
                        'conversion_available' => false,
                        'estimated_amount' => $paymentAmount,
                        'conversion_rate' => null,
                        'conversion_effective_at' => null,
                        'can_pay' => $availableBalance >= $paymentAmount,
                    ];
                }

                $rate = CurrencyConversionRate::query()
                    ->where('wallet_currency', $walletCurrency)
                    ->where('payment_currency', $paymentCurrency)
                    ->activeAt(now())
                    ->orderByDesc('effective_from')
                    ->first();

                $estimatedAmount = $rate === null ? null : $this->convert($paymentAmount, (float) $rate->rate);

                return [
                    'wallet_id' => $wallet->id,
                    'currency' => $walletCurrency,
                    'wallet_balance' => $availableBalance,
                    'conversion_available' => $rate !== null,
                    'estimated_amount' => $estimatedAmount,
                    'conversion_rate' => $rate === null ? null : (float) $rate->rate,
                    'conversion_effective_at' => $rate?->effective_from?->toIso8601String(),
                    'can_pay' => $estimatedAmount !== null && $availableBalance >= $estimatedAmount,
                ];
            })
            ->values()
            ->all();

        return [
            'service_currency' => $paymentCurrency,
            'options' => $options,
        ];
    }

    public function resolve(User $user, float $paymentAmount, string $paymentCurrency, string $walletCurrency): array
    {
        $paymentCurrency = strtoupper($paymentCurrency);
        $walletCurrency = strtoupper($walletCurrency);
        $option = collect($this->options($user, $paymentAmount, $paymentCurrency)['options'])
            ->firstWhere('currency', $walletCurrency);

        if ($option === null || !$option['conversion_available'] && $walletCurrency !== $paymentCurrency) {
            return $this->failure('La devise de paiement sélectionnée n’est pas prise en charge.', 'PAYMENT_CURRENCY_NOT_SUPPORTED');
        }
        if (!$option['can_pay']) {
            return $this->failure('Solde insuffisant dans la devise sélectionnée.', 'PAYMENT_CURRENCY_INSUFFICIENT_FUNDS');
        }

        return [
            'success' => true,
            'data' => [
                'wallet_id' => $option['wallet_id'],
                'payment_amount' => $paymentAmount,
                'payment_currency' => $paymentCurrency,
                'wallet_amount' => $option['estimated_amount'],
                'wallet_currency' => $walletCurrency,
                'conversion_rate' => $option['conversion_rate'],
                'conversion_effective_at' => $option['conversion_effective_at'],
                'conversion_applied' => $walletCurrency !== $paymentCurrency,
            ],
        ];
    }

    private function convert(float $paymentAmount, float $rate): int
    {
        return (int) ceil($paymentAmount * $rate);
    }

    private function failure(string $error, string $code): array
    {
        return ['success' => false, 'error' => $error, 'code' => $code];
    }
}