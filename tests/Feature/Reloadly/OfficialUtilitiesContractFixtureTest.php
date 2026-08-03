<?php

namespace Tests\Feature\Reloadly;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OfficialUtilitiesContractFixtureTest extends TestCase
{
    #[Test]
    public function it_records_only_the_documented_utility_payment_paths(): void
    {
        $contract = require base_path('tests/Fixtures/Reloadly/utility_payments_contract.php');

        expect(array_column($contract['paths'], 'path'))->toEqual([
            'https://auth.reloadly.com/oauth/token',
            '/accounts/balance',
            '/billers',
            '/pay',
            '/transactions',
            '/transactions/{id}',
        ]);
    }

    #[Test]
    public function it_records_fixed_denomination_as_an_amount_id_payment(): void
    {
        $contract = require base_path('tests/Fixtures/Reloadly/utility_payments_contract.php');

        expect($contract['pay']['required'])->toEqual([
            'subscriberAccountNumber',
            'amount',
            'billerId',
        ]);
        expect($contract['pay']['optional'])->toContain('amountId');
        expect($contract['pay']['fixed_denomination_amount_id'])->toBeTrue();
        expect($contract['biller']['fixed_amount_fields'])->toEqual([
            'id',
            'amount',
            'description',
        ]);
    }
}