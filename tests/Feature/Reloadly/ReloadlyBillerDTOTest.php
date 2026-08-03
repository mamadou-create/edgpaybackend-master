<?php

namespace Tests\Feature\Reloadly;

use App\Data\ReloadlyBillerDTO;
use App\Http\Resources\ReloadlyBillerResource;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReloadlyBillerDTOTest extends TestCase
{
    #[Test]
    public function it_normalizes_a_fixed_local_biller_without_unofficial_metadata(): void
    {
        $biller = ReloadlyBillerDTO::fromReloadly([
            'id' => 27,
            'name' => 'Canal+ Mali',
            'countryCode' => 'ML',
            'type' => 'TV_BILL_PAYMENT',
            'serviceType' => 'PREPAID',
            'localAmountSupported' => true,
            'localTransactionCurrencyCode' => 'XOF',
            'internationalTransactionCurrencyCode' => 'USD',
            'denominationType' => 'FIXED',
            'localFixedAmounts' => [
                ['id' => 3, 'amount' => 10000, 'description' => 'Acces Basic'],
            ],
            'requiresInvoice' => false,
        ]);

        $payload = (new ReloadlyBillerResource($biller))->resolve();

        expect($payload)->toMatchArray([
            'id' => 27,
            'country_iso_code' => 'ML',
            'denomination_type' => 'FIXED',
            'local_amount_supported' => true,
            'local_transaction_currency_code' => 'XOF',
            'requires_invoice' => false,
        ]);
        expect($payload['local_fixed_amounts'])->toEqual([
            [
                'id' => 3,
                'biller_id' => 27,
                'amount_id' => 3,
                'amount' => 10000.0,
                'description' => 'Acces Basic',
                'currency' => 'XOF',
            ],
        ]);
        expect($payload)->not->toHaveKey('lookup_supported');
        expect($payload)->not->toHaveKey('identifier_fields');
    }
}