<?php

namespace App\Services;

final class ReloadlyUtilityPaymentRules
{
    public function resolve(array $biller, array $payment): array
    {
        $useLocalAmount = (bool) ($payment['useLocalAmount'] ?? false);
        $denominationType = strtoupper((string) ($biller['denominationType'] ?? ''));

        if ($denominationType === 'FIXED') {
            return $this->resolveFixedLocalAmount($biller, $payment, $useLocalAmount);
        }

        if ($denominationType === 'RANGE') {
            return $this->resolveRangeAmount($biller, $payment, $useLocalAmount);
        }

        return $this->failure('Type de montant Reloadly non pris en charge.', 'UNSUPPORTED_DENOMINATION_TYPE');
    }

    private function resolveFixedLocalAmount(array $biller, array $payment, bool $useLocalAmount): array
    {
        if (!$useLocalAmount) {
            return $this->failure(
                'Les formules fixes locales exigent useLocalAmount=true.',
                'LOCAL_AMOUNT_REQUIRED',
            );
        }

        $amountId = filter_var($payment['amountId'] ?? null, FILTER_VALIDATE_INT);
        if ($amountId === false || $amountId === null) {
            return $this->failure('amountId est requis pour une formule fixe.', 'FIXED_AMOUNT_ID_REQUIRED');
        }

        $currency = $this->stringValue($biller['localTransactionCurrencyCode'] ?? null);
        if ($currency === null) {
            return $this->failure('Devise locale Reloadly indisponible.', 'LOCAL_CURRENCY_UNAVAILABLE');
        }

        foreach ($biller['localFixedAmounts'] ?? [] as $amount) {
            if (!is_array($amount) || (int) ($amount['id'] ?? 0) !== $amountId) {
                continue;
            }

            return $this->success([
                'amount' => (float) $amount['amount'],
                'amountId' => $amountId,
                'useLocalAmount' => true,
                'currency' => $currency,
                'displayCurrency' => $currency,
            ]);
        }

        return $this->failure('amountId ne correspond pas à une formule Reloadly disponible.', 'INVALID_FIXED_AMOUNT_ID');
    }

    private function resolveRangeAmount(array $biller, array $payment, bool $useLocalAmount): array
    {
        $amount = $payment['amount'] ?? null;
        if (!is_numeric($amount) || (float) $amount <= 0) {
            return $this->failure('Montant invalide.', 'INVALID_AMOUNT');
        }

        $prefix = $useLocalAmount ? 'local' : 'international';
        $supported = (bool) ($biller["{$prefix}AmountSupported"] ?? false);
        $currency = $this->stringValue($biller["{$prefix}TransactionCurrencyCode"] ?? null);
        $minimum = $biller["min" . ucfirst($prefix) . 'TransactionAmount'] ?? null;
        $maximum = $biller["max" . ucfirst($prefix) . 'TransactionAmount'] ?? null;

        if (!$supported || $currency === null) {
            return $this->failure('Devise de paiement Reloadly non disponible pour ce biller.', 'UNSUPPORTED_PAYMENT_CURRENCY');
        }

        $amount = (float) $amount;
        if (is_numeric($minimum) && $amount < (float) $minimum) {
            return $this->failure('Montant inférieur au minimum Reloadly.', 'AMOUNT_BELOW_MINIMUM');
        }
        if (is_numeric($maximum) && $amount > (float) $maximum) {
            return $this->failure('Montant supérieur au maximum Reloadly.', 'AMOUNT_ABOVE_MAXIMUM');
        }

        return $this->success([
            'amount' => $amount,
            'amountId' => null,
            'useLocalAmount' => $useLocalAmount,
            'currency' => $currency,
            'displayCurrency' => $currency,
        ]);
    }

    private function success(array $data): array
    {
        return ['success' => true, 'data' => $data];
    }

    private function failure(string $error, string $code): array
    {
        return ['success' => false, 'error' => $error, 'code' => $code];
    }

    private function stringValue(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}