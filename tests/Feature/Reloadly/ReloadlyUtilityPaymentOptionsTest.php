<?php

namespace Tests\Feature\Reloadly;

use App\Models\CurrencyConversionRate;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ReloadlyAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReloadlyUtilityPaymentOptionsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_rejects_a_gnf_wallet_when_no_active_xof_rate_exists(): void
    {
        $user = $this->gnfWalletUser();
        $this->fakeReloadlyBiller();

        $response = $this->actingAs($user, 'api')->getJson($this->endpoint());

        $response
            ->assertUnprocessable()
            ->assertJsonPath('business_code', 'PAYMENT_CURRENCY_NOT_SUPPORTED');
        $this->assertDatabaseCount('payment_intents', 0);
        expect(Wallet::query()->firstOrFail()->blocked_amount)->toBe(0);
    }

    #[Test]
    public function it_returns_only_the_public_gnf_option_when_an_active_xof_rate_exists(): void
    {
        $user = $this->gnfWalletUser();
        CurrencyConversionRate::create([
            'wallet_currency' => 'GNF',
            'payment_currency' => 'XOF',
            'rate' => 12.3,
            'enabled' => true,
            'status' => CurrencyConversionRate::STATUS_ACTIVE,
            'effective_from' => now()->subMinute(),
        ]);
        $this->fakeReloadlyBiller();

        $response = $this->actingAs($user, 'api')->getJson($this->endpoint());

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.serviceCurrency', 'XOF')
            ->assertJsonCount(1, 'data.options')
            ->assertJsonPath('data.options.0.walletCurrency', 'GNF')
            ->assertJsonPath('data.options.0.conversionAvailable', true)
            ->assertJsonPath('data.options.0.rate', 12.3)
            ->assertJsonPath('data.options.0.payableAmount', 12300)
            ->assertJsonMissingPath('data.options.0.wallet_id');
        $this->assertDatabaseCount('payment_intents', 0);
    }

    private function gnfWalletUser(): User
    {
        $user = User::factory()->create();
        Wallet::create([
            'user_id' => $user->id,
            'currency' => 'GNF',
            'cash_available' => 200000,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);

        return $user;
    }

    private function fakeReloadlyBiller(): void
    {
        $this->mock(ReloadlyAuthService::class, function ($mock): void {
            $mock->shouldReceive('getToken')->andReturn('test-token');
        });
        Http::fake([
            '*' => Http::response([
                'content' => [[
                    'id' => 9001,
                    'denominationType' => 'RANGE',
                    'localAmountSupported' => true,
                    'localTransactionCurrencyCode' => 'XOF',
                    'minLocalTransactionAmount' => 100,
                    'maxLocalTransactionAmount' => 100000,
                ]],
            ]),
        ]);
    }

    private function endpoint(): string
    {
        return '/api/v1/reloadly/utilities/payment-options?billerId=9001&amount=1000&useLocalAmount=1';
    }
}