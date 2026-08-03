<?php

namespace Tests\Feature\Reloadly;

use App\Services\ReloadlyUtilityPaymentRules;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReloadlyUtilityPaymentRulesTest extends TestCase
{
    #[Test]
    public function fixed_local_amount_requires_a_catalog_amount_id(): void
    {
        $result = app(ReloadlyUtilityPaymentRules::class)->resolve($this->fixedBiller(), [
            'amount' => 10000,
            'useLocalAmount' => true,
        ]);

        expect($result)->toMatchArray([
            'success' => false,
            'code' => 'FIXED_AMOUNT_ID_REQUIRED',
        ]);
    }

    #[Test]
    public function fixed_local_amount_uses_the_catalog_amount_and_xof_currency(): void
    {
        $result = app(ReloadlyUtilityPaymentRules::class)->resolve($this->fixedBiller(), [
            'amount' => 1,
            'amountId' => 3,
            'useLocalAmount' => true,
        ]);

        expect($result)->toMatchArray([
            'success' => true,
            'data' => [
                'amount' => 10000.0,
                'amountId' => 3,
                'useLocalAmount' => true,
                'currency' => 'XOF',
                'displayCurrency' => 'XOF',
            ],
        ]);
    }

    #[Test]
    public function fixed_local_amount_rejects_an_international_amount_interpretation(): void
    {
        $result = app(ReloadlyUtilityPaymentRules::class)->resolve($this->fixedBiller(), [
            'amount' => 10000,
            'amountId' => 3,
            'useLocalAmount' => false,
        ]);

        expect($result)->toMatchArray([
            'success' => false,
            'code' => 'LOCAL_AMOUNT_REQUIRED',
        ]);
    }

    private function fixedBiller(): array
    {
        return [
            'denominationType' => 'FIXED',
            'localTransactionCurrencyCode' => 'XOF',
            'localFixedAmounts' => [
                ['id' => 3, 'amount' => 10000, 'description' => 'Acces Basic'],
            ],
        ];
    }
}