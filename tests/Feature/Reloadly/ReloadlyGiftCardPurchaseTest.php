<?php

namespace Tests\Feature\Reloadly;

use App\Interfaces\ReloadlyGiftCardRepositoryInterface;
use App\Models\ReloadlyGiftcardTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReloadlyGiftCardPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.reloadly.products.giftcards.margin_percent', 5);
        config()->set('services.reloadly.products.giftcards.margin_fixed', 0);
        config()->set('services.reloadly.products.giftcards.usd_to_gnf', 8700);
        config()->set('services.reloadly.products.giftcards.wallet_currency', 'GNF');
    }

    #[Test]
    public function quote_calculates_margin_and_wallet_amount(): void
    {
        $user = User::factory()->create();
        Wallet::create([
            'user_id' => $user->id,
            'currency' => 'GNF',
            'cash_available' => 300000,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);

        $response = $this->actingAs($user, 'api')->getJson(
            '/api/v1/reloadly/giftcards/orders/quote?base_amount=25&quantity=1'
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.base_amount', 25)
            ->assertJsonPath('data.commission_amount', 1.25)
            ->assertJsonPath('data.total_user_price', 26.25)
            ->assertJsonPath('data.wallet_amount', 228375)
            ->assertJsonPath('data.wallet_currency', 'GNF');
    }

    #[Test]
    public function purchase_sends_base_amount_and_total_wallet_debit_when_balance_is_sufficient(): void
    {
        $user = User::factory()->create();
        Wallet::create([
            'user_id' => $user->id,
            'currency' => 'GNF',
            'cash_available' => 300000,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);

        $repository = Mockery::mock(ReloadlyGiftCardRepositoryInterface::class);
        $repository->shouldReceive('orderGiftCard')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                return $payload['productId'] === 123
                    && $payload['unitPrice'] === 25.0
                    && $payload['baseAmount'] === 25.0
                    && $payload['commissionAmount'] === 1.25
                    && $payload['walletAmount'] === 228375;
            }))
            ->andReturn([
                'success' => true,
                'data' => ['transactionId' => 999],
                'message' => 'Commande simulée',
            ]);
        $this->app->instance(ReloadlyGiftCardRepositoryInterface::class, $repository);

        $response = $this->actingAs($user, 'api')->postJson(
            '/api/v1/reloadly/giftcards/orders',
            [
                'productId' => 123,
                'base_amount' => 25,
                'quantity' => 1,
            ],
            ['X-Idempotency-Key' => 'giftcard-test-sufficient-001']
        );

        $response->assertOk()->assertJsonPath('data.transactionId', 999);
    }

    #[Test]
    public function purchase_is_rejected_without_debit_when_balance_is_insufficient(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => 'GNF',
            'cash_available' => 100000,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);

        $response = $this->actingAs($user, 'api')->postJson(
            '/api/v1/reloadly/giftcards/orders',
            [
                'productId' => 123,
                'base_amount' => 25,
                'quantity' => 1,
            ],
            ['X-Idempotency-Key' => 'giftcard-test-insufficient-001']
        );

        $response->assertStatus(400)->assertJsonPath('success', false);
        $this->assertSame(100000, (int) $wallet->fresh()->cash_available);
        $this->assertDatabaseHas('reloadly_giftcard_transactions', [
            'user_id' => $user->id,
            'product_id' => 123,
            'api_status' => 'FAILED',
            'wallet_amount' => 228375,
        ]);
    }
}
