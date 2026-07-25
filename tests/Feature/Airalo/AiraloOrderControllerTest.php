<?php

namespace Tests\Feature\Airalo;

use App\Models\AiraloOrder;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiraloOrderControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function authenticated_user_can_create_airalo_order_successfully(): void
    {
        config([
            'services.airalo.base_url' => 'https://partners.airalo.test',
            'services.airalo.client_id' => 'airalo-client-id',
            'services.airalo.client_secret' => 'airalo-client-secret',
            'cache.default' => 'array',
        ]);

        Cache::forget('airalo:oauth:access_token');

        Http::fake([
            'https://partners.airalo.test/v2/token' => Http::response([
                'access_token' => 'airalo-token-xyz',
                'token_type' => 'Bearer',
                'expires_in' => 86400,
            ], 200),
            'https://partners.airalo.test/v2/packages*' => Http::response([
                'data' => [
                    ['id' => 'pkg-123', 'title' => 'France 3GB', 'price' => 1000, 'currency' => 'GNF'],
                ],
            ], 200),
            'https://partners.airalo.test/v2/orders' => Http::response([
                'data' => [
                    'id' => 'ord-789',
                    'sim' => [
                        'iccid' => '8988211000000000001',
                    ],
                    'installation' => [
                        'qrcode_url' => 'https://airalo.test/qr/ord-789',
                        'smdp_address' => 'LPA:1$sm-dp.example$ACTIVATION',
                        'ac_code' => 'ACTIVATION-CODE-123',
                    ],
                ],
            ], 200),
        ]);

        $user = User::factory()->create(['solde_portefeuille' => 5000]);
        $wallet = Wallet::query()->create([
            'user_id' => $user->id,
            'currency' => 'GNF',
            'cash_available' => 5000,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);
        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/v1/airalo/orders', [
            'package_id' => 'pkg-123',
            'quantity' => 1,
            'description' => 'Achat test eSIM',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order_id', 'ord-789')
            ->assertJsonPath('data.iccid', '8988211000000000001')
            ->assertJsonPath('data.qrcode_url', 'https://airalo.test/qr/ord-789')
            ->assertJsonPath('data.smdp_address', 'LPA:1$sm-dp.example$ACTIVATION')
            ->assertJsonPath('data.ac_code', 'ACTIVATION-CODE-123');

        $this->assertDatabaseHas('airalo_orders', [
            'user_id' => $user->id,
            'package_id' => 'pkg-123',
            'airalo_order_id' => 'ord-789',
            'iccid' => '8988211000000000001',
            'quantity' => 1,
            'status' => 'completed',
            'currency' => 'GNF',
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'amount' => -1000,
            'type' => 'debit',
        ]);

        $wallet->refresh();
        $user->refresh();
        $this->assertSame(4000, (int) $wallet->cash_available);
        $this->assertSame(4000, (int) $user->solde_portefeuille);

        Http::assertSentCount(3);
    }

    #[Test]
    public function it_returns_402_when_wallet_balance_is_insufficient(): void
    {
        config([
            'services.airalo.base_url' => 'https://partners.airalo.test',
            'services.airalo.client_id' => 'airalo-client-id',
            'services.airalo.client_secret' => 'airalo-client-secret',
            'cache.default' => 'array',
        ]);

        Cache::forget('airalo:oauth:access_token');

        Http::fake([
            'https://partners.airalo.test/v2/token' => Http::response([
                'access_token' => 'airalo-token-xyz',
                'token_type' => 'Bearer',
                'expires_in' => 86400,
            ], 200),
            'https://partners.airalo.test/v2/packages*' => Http::response([
                'data' => [
                    ['id' => 'pkg-123', 'title' => 'France 3GB', 'price' => 2500, 'currency' => 'GNF'],
                ],
            ], 200),
        ]);

        $user = User::factory()->create(['solde_portefeuille' => 1000]);
        $wallet = Wallet::query()->create([
            'user_id' => $user->id,
            'currency' => 'GNF',
            'cash_available' => 1000,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);
        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/v1/airalo/orders', [
            'package_id' => 'pkg-123',
            'quantity' => 1,
        ]);

        $response
            ->assertStatus(402)
            ->assertJsonPath('success', false)
            ->assertJsonPath('business_code', 'INSUFFICIENT_BALANCE');

        $wallet->refresh();
        $user->refresh();
        $this->assertSame(1000, (int) $wallet->cash_available);
        $this->assertSame(1000, (int) $user->solde_portefeuille);
        $this->assertDatabaseCount('airalo_orders', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);

        Http::assertSentCount(2);
    }

    #[Test]
    public function it_rolls_back_wallet_balance_when_airalo_api_returns_500(): void
    {
        $this->assertWalletRollbackWhenAiraloFails(500);
    }

    #[Test]
    public function it_rolls_back_wallet_balance_when_airalo_api_returns_400(): void
    {
        $this->assertWalletRollbackWhenAiraloFails(400);
    }

    #[Test]
    public function authenticated_user_can_view_airalo_orders_history(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'api');

        $this->assertDatabaseCount('airalo_orders', 0);

        AiraloOrder::query()->create([
            'user_id' => $user->id,
            'package_id' => 'pkg-history-1',
            'airalo_order_id' => 'ord-history-1',
            'iccid' => '8988211000000000099',
            'qrcode_url' => 'https://airalo.test/qr/history',
            'smdp_address' => 'LPA:1$sm-dp.history$ACTIVATION',
            'ac_code' => 'HISTORY-AC-001',
            'quantity' => 1,
            'price' => 9.99,
            'currency' => 'USD',
            'status' => 'completed',
        ]);

        $response = $this->getJson('/api/v1/airalo/orders');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.package_id', 'pkg-history-1')
            ->assertJsonPath('data.0.airalo_order_id', 'ord-history-1')
            ->assertJsonPath('data.0.status', 'completed');
    }

    #[Test]
    public function authenticated_user_can_view_own_airalo_order_detail(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'api');

        $order = AiraloOrder::query()->create([
            'user_id' => $user->id,
            'package_id' => 'pkg-detail-1',
            'airalo_order_id' => 'ord-detail-1',
            'iccid' => '8988211000000000011',
            'qrcode_url' => 'https://airalo.test/qr/detail',
            'smdp_address' => 'LPA:1$sm-dp.detail$ACTIVATION',
            'ac_code' => 'DETAIL-AC-001',
            'quantity' => 1,
            'price' => 12.50,
            'currency' => 'USD',
            'status' => 'completed',
        ]);

        $response = $this->getJson('/api/v1/airalo/orders/' . $order->id);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.package_id', 'pkg-detail-1')
            ->assertJsonPath('data.airalo_order_id', 'ord-detail-1');
    }

    #[Test]
    public function it_returns_404_when_user_requests_another_users_airalo_order_detail(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $order = AiraloOrder::query()->create([
            'user_id' => $owner->id,
            'package_id' => 'pkg-private-1',
            'airalo_order_id' => 'ord-private-1',
            'quantity' => 1,
            'currency' => 'USD',
            'status' => 'completed',
        ]);

        $this->actingAs($intruder, 'api');

        $response = $this->getJson('/api/v1/airalo/orders/' . $order->id);

        $response
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function it_returns_validation_error_for_invalid_order_payload(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/v1/airalo/orders', [
            'package_id' => '',
            'quantity' => 0,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    private function assertWalletRollbackWhenAiraloFails(int $airaloOrderStatus): void
    {
        config([
            'services.airalo.base_url' => 'https://partners.airalo.test',
            'services.airalo.client_id' => 'airalo-client-id',
            'services.airalo.client_secret' => 'airalo-client-secret',
            'cache.default' => 'array',
        ]);

        Cache::forget('airalo:oauth:access_token');

        Http::fake([
            'https://partners.airalo.test/v2/token' => Http::response([
                'access_token' => 'airalo-token-xyz',
                'token_type' => 'Bearer',
                'expires_in' => 86400,
            ], 200),
            'https://partners.airalo.test/v2/packages*' => Http::response([
                'data' => [
                    ['id' => 'pkg-123', 'title' => 'France 3GB', 'price' => 1500, 'currency' => 'GNF'],
                ],
            ], 200),
            'https://partners.airalo.test/v2/orders' => Http::response([
                'message' => 'Airalo failure for test',
            ], $airaloOrderStatus),
        ]);

        $user = User::factory()->create(['solde_portefeuille' => 5000]);
        $wallet = Wallet::query()->create([
            'user_id' => $user->id,
            'currency' => 'GNF',
            'cash_available' => 5000,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);

        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/v1/airalo/orders', [
            'package_id' => 'pkg-123',
            'quantity' => 1,
            'description' => 'Achat test rollback',
        ]);

        $response->assertStatus($airaloOrderStatus)->assertJsonPath('success', false);

        $wallet->refresh();
        $user->refresh();
        $this->assertSame(5000, (int) $wallet->cash_available);
        $this->assertSame(5000, (int) $user->solde_portefeuille);

        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('airalo_orders', 0);
    }
}
