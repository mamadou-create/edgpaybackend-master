<?php

namespace Tests\Feature\Airalo;

use App\Models\AiraloOrder;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
                    [
                        'id' => 'pkg-123',
                        'title' => 'France 3GB',
                        'country_name' => 'France',
                        'data' => '3 GB',
                        'validity_days' => 7,
                        'operator_name' => 'Orange',
                        'price' => 1000,
                        'currency' => 'GNF',
                    ],
                ],
            ], 200),
            'https://partners.airalo.test/v2/orders' => Http::response([
                'data' => [
                    'id' => 1234567,
                    'code' => '20260725-1234567',
                    'sims' => [[
                        'id' => 2508142,
                        'iccid' => '8988211000000000001',
                        'qrcode' => 'LPA:1$sm-dp.example$ACTIVATION',
                        'qrcode_url' => 'https://airalo.test/qr/ord-789',
                        'lpa' => 'sm-dp.example',
                        'matching_id' => 'ACTIVATION-CODE-123',
                        'direct_apple_installation_url' => 'https://airalo.test/apple/ord-789',
                    ]],
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

        $response = $this->postJson('/api/v1/esim/orders', [
            'package_id' => 'pkg-123',
            'quantity' => 1,
            'description' => 'Achat test eSIM',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order_id', '1234567')
            ->assertJsonPath('data.iccid', '8988211000000000001')
            ->assertJsonPath('data.qrcode', 'LPA:1$sm-dp.example$ACTIVATION')
            ->assertJsonPath('data.qrcode_url', 'https://airalo.test/qr/ord-789')
            ->assertJsonPath('data.smdp_address', 'sm-dp.example')
            ->assertJsonPath('data.ac_code', 'ACTIVATION-CODE-123')
            ->assertJsonPath('data.direct_apple_installation_url', 'https://airalo.test/apple/ord-789');

        $this->assertDatabaseHas('airalo_orders', [
            'user_id' => $user->id,
            'package_id' => 'pkg-123',
            'package_title' => 'France 3GB',
            'destination' => 'France',
            'data_volume' => '3 GB',
            'validity_days' => 7,
            'operator_name' => 'Orange',
            'airalo_order_id' => '1234567',
            'airalo_order_code' => '20260725-1234567',
            'quantity' => 1,
            'status' => 'completed',
            'currency' => 'GNF',
        ]);
        $this->assertTrue(Schema::hasColumn('airalo_orders', 'iccid'));
        $storedOrder = AiraloOrder::query()->sole();
        $this->assertSame('8988211000000000001', $storedOrder->iccid);
        $this->assertSame(2508142, $storedOrder->airalo_sim_id);
        $this->assertSame('ACTIVATION-CODE-123', $storedOrder->airalo_matching_id);
        $this->assertFalse(Schema::hasColumn('airalo_orders', 'qrcode_url'));
        $this->assertFalse(Schema::hasColumn('airalo_orders', 'smdp_address'));
        $this->assertFalse(Schema::hasColumn('airalo_orders', 'ac_code'));

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'amount' => -1000,
            'type' => 'debit',
        ]);
        $transaction = WalletTransaction::query()->firstOrFail();
        $this->assertSame('completed', $transaction->metadata['payment_status']);

        $wallet->refresh();
        $user->refresh();
        $this->assertSame(4000, (int) $wallet->cash_available);
        $this->assertSame(4000, (int) $user->solde_portefeuille);

        Http::assertSentCount(3);
    }

    #[Test]
    public function it_returns_400_when_wallet_balance_is_insufficient(): void
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
            ->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Solde insuffisant dans votre Wallet Mding Pay.')
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
            ->assertJsonPath('data.0.status', 'completed')
            ->assertJsonMissingPath('data.0.iccid')
            ->assertJsonMissingPath('data.0.qrcode_url')
            ->assertJsonMissingPath('data.0.smdp_address')
            ->assertJsonMissingPath('data.0.ac_code');
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
    public function authenticated_user_can_retrieve_installation_instructions_on_demand(): void
    {
        config([
            'services.airalo.base_url' => 'https://partners.airalo.test',
            'services.airalo.client_id' => 'airalo-client-id',
            'services.airalo.client_secret' => 'airalo-client-secret',
            'cache.default' => 'array',
        ]);
        Cache::forget('airalo:oauth:access_token');
        Log::spy();

        Http::fake([
            'https://partners.airalo.test/v2/token' => Http::response([
                'access_token' => 'airalo-token-xyz',
                'expires_in' => 86400,
            ]),
            'https://partners.airalo.test/v2/orders/ord-install-1*' => Http::response([
                'data' => [
                    'id' => 'ord-install-1',
                    'sims' => [[
                        'id' => 2508142,
                        'iccid' => '8988211000000000011',
                        'qrcode' => 'LPA:1$sm-dp.example$ACTIVATION',
                        'qrcode_url' => 'https://airalo.test/qr/install-1',
                        'lpa' => 'sm-dp.example',
                        'matching_id' => 'MATCHING-ID-123',
                        'direct_apple_installation_url' => 'https://airalo.test/apple/install-1',
                    ]],
                ],
            ]),
            'https://partners.airalo.test/v2/sims/8988211000000000011' => Http::response([
                'data' => [
                    'id' => 2508142,
                    'iccid' => '8988211000000000011',
                    'lpa' => 'sm-dp.example',
                    'matching_id' => 'MATCHING-ID-123',
                    'qrcode' => 'LPA:1$sm-dp.example$ACTIVATION',
                    'qrcode_url' => 'https://airalo.test/qr/install-1',
                    'direct_apple_installation_url' => 'https://airalo.test/apple/install-1',
                ],
            ]),
            'https://partners.airalo.test/v2/sims/8988211000000000011/instructions' => Http::response([
                'data' => [
                    'instructions' => [
                        'ios' => [[
                            'model' => 'iPhone',
                            'version' => 'iOS 18',
                            'direct_apple_installation_url' => 'https://airalo.test/apple/install-1',
                            'installation_via_qr_code' => [
                                'steps' => ['Open Settings.', 'Scan the QR code.'],
                            ],
                            'installation_manual' => [
                                'steps' => ['Enter the SM-DP+ address.'],
                            ],
                            'network_setup' => [
                                'steps' => ['Enable data roaming.'],
                            ],
                        ]],
                        'android' => [[
                            'model' => 'Pixel',
                            'version' => 'Android 15',
                            'installation_via_qr_code' => [
                                'steps' => ['Open Network settings.'],
                            ],
                            'installation_manual' => [
                                'steps' => ['Enter the activation code.'],
                            ],
                            'network_setup' => [
                                'steps' => ['Enable mobile data.'],
                            ],
                        ]],
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $order = AiraloOrder::query()->create([
            'user_id' => $user->id,
            'package_id' => 'pkg-install-1',
            'airalo_order_id' => 'ord-install-1',
            'quantity' => 1,
            'currency' => 'USD',
            'status' => 'completed',
        ]);
        $this->actingAs($user, 'api');

        $this->getJson('/api/v1/esim/orders/' . $order->id . '/instructions')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.qrcode', 'LPA:1$sm-dp.example$ACTIVATION')
            ->assertJsonPath('data.qrcode_url', 'https://airalo.test/qr/install-1')
            ->assertJsonPath('data.lpa', 'sm-dp.example')
            ->assertJsonPath('data.smdp_address', 'sm-dp.example')
            ->assertJsonPath('data.matching_id', 'MATCHING-ID-123')
            ->assertJsonPath('data.ac_code', 'MATCHING-ID-123')
            ->assertJsonPath('data.direct_apple_installation_url', 'https://airalo.test/apple/install-1')
            ->assertJsonPath('data.ios_instructions.0.qr_steps.0', 'Open Settings.')
            ->assertJsonPath('data.android_instructions.0.network_steps.0', 'Enable mobile data.');

        Http::assertSent(static fn ($request): bool => $request->url() === 'https://partners.airalo.test/v2/orders/ord-install-1?include=sims');
        Http::assertSent(static fn ($request): bool => $request->url() === 'https://partners.airalo.test/v2/sims/8988211000000000011');
        Http::assertSent(static fn ($request): bool => $request->url() === 'https://partners.airalo.test/v2/sims/8988211000000000011/instructions');

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            return $message === 'Airalo eSIM installation instructions retrieved.'
                && $context['airalo_status'] === 200
                && $context['airalo_response']['order']['data']['sims'][0]['qrcode'] === '[REDACTED]'
                && $context['airalo_response']['order']['data']['sims'][0]['iccid'] === '[REDACTED]'
                && $context['airalo_response']['order']['data']['sims'][0]['matching_id'] === '[REDACTED]';
        })->once();
    }

    #[Test]
    public function it_maps_installation_fields_from_documented_order_sims(): void
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
                'expires_in' => 86400,
            ]),
            'https://partners.airalo.test/v2/orders/2192552*' => Http::response([
                'data' => [
                    'id' => 2192552,
                    'sims' => [[
                        'id' => 2508142,
                        'iccid' => '8988211000000002192',
                        'qrcode' => 'LPA:1$sm-dp.example$ACTIVATION',
                        'qrcode_url' => 'https://airalo.test/qr/2192552',
                        'lpa' => 'sm-dp.example',
                        'matching_id' => 'MATCHING-ID-2192552',
                    ]],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $order = AiraloOrder::query()->create([
            'user_id' => $user->id,
            'package_id' => 'discover-in-3days-300mb',
            'airalo_order_id' => '2192552',
            'quantity' => 1,
            'currency' => 'USD',
            'status' => 'completed',
        ]);
        $this->actingAs($user, 'api');

        $this->getJson('/api/v1/esim/orders/' . $order->id . '/instructions')
            ->assertOk()
            ->assertJsonPath('data.qrcode_url', 'https://airalo.test/qr/2192552')
            ->assertJsonPath('data.smdp_address', 'sm-dp.example')
            ->assertJsonPath('data.ac_code', 'MATCHING-ID-2192552');
    }

    #[Test]
    public function it_retrieves_a_sim_profile_when_an_order_only_contains_an_iccid(): void
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
                'expires_in' => 86400,
            ]),
            'https://partners.airalo.test/v2/orders/2192552*' => Http::response([
                'data' => ['id' => 2192552, 'sims' => [['iccid' => '8988211000000002192']]],
            ]),
            'https://partners.airalo.test/v2/sims/8988211000000002192/instructions' => Http::response([
                'data' => [
                    'instructions' => [
                        'ios' => [[
                            'installation_via_qr_code' => [
                                'qrcode_url' => 'https://airalo.test/qr/2192552',
                                'lpa' => 'sm-dp.example',
                                'matching_id' => 'MATCHING-ID-2192552',
                            ],
                        ]],
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $order = AiraloOrder::query()->create([
            'user_id' => $user->id,
            'package_id' => 'discover-in-3days-300mb',
            'airalo_order_id' => '2192552',
            'quantity' => 1,
            'currency' => 'USD',
            'status' => 'completed',
        ]);
        $this->actingAs($user, 'api');

        $this->getJson('/api/v1/esim/orders/' . $order->id . '/instructions')
            ->assertOk()
            ->assertJsonPath('data.qrcode_url', 'https://airalo.test/qr/2192552')
            ->assertJsonPath('data.smdp_address', 'sm-dp.example')
            ->assertJsonPath('data.ac_code', 'MATCHING-ID-2192552');

        Http::assertSent(static fn ($request): bool => $request->url() === 'https://partners.airalo.test/v2/sims/8988211000000002192/instructions');
    }

    #[Test]
    public function it_uses_the_numeric_airalo_id_with_documented_sim_inclusion(): void
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
                'expires_in' => 86400,
            ]),
            'https://partners.airalo.test/v2/orders/2192552*' => Http::response([
                'data' => [
                    'id' => 2192552,
                    'sims' => [[
                        'iccid' => '89852350326101080144',
                        'qrcode_url' => 'https://airalo.test/qr/2192552',
                    ]],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $order = AiraloOrder::query()->create([
            'user_id' => $user->id,
            'package_id' => 'discover-in-3days-300mb',
            'airalo_order_id' => '2192552',
            'airalo_order_code' => '20260725-2192552',
            'quantity' => 1,
            'currency' => 'USD',
            'status' => 'completed',
        ]);
        $this->actingAs($user, 'api');

        $this->getJson('/api/v1/esim/orders/' . $order->id . '/instructions')
            ->assertOk()
            ->assertJsonPath('data.iccid', '89852350326101080144')
            ->assertJsonPath('data.qrcode_url', 'https://airalo.test/qr/2192552');

        Http::assertSent(static fn ($request): bool => $request->url() === 'https://partners.airalo.test/v2/orders/2192552?include=sims');
    }

    #[Test]
    public function it_does_not_request_installation_data_before_airalo_marks_the_order_completed(): void
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
                'expires_in' => 86400,
            ]),
            'https://partners.airalo.test/v2/orders/2192607*' => Http::response([
                'data' => ['id' => 2192607, 'status' => 'processing'],
            ]),
        ]);

        $user = User::factory()->create();
        $order = AiraloOrder::query()->create([
            'user_id' => $user->id,
            'package_id' => 'discover-in-1gb-5days-px',
            'airalo_order_id' => '2192607',
            'quantity' => 1,
            'currency' => 'USD',
            'status' => 'completed',
        ]);
        $this->actingAs($user, 'api');

        $this->getJson('/api/v1/esim/orders/' . $order->id . '/instructions')
            ->assertOk()
            ->assertJsonPath('data.instructions_status', 'pending')
            ->assertJsonPath('data.qrcode_url', null);

        Http::assertSentCount(2);
    }

    #[Test]
    public function it_persists_identifiers_from_documented_order_sims(): void
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
                'expires_in' => 86400,
            ]),
            'https://partners.airalo.test/v2/orders/2192552*' => Http::response([
                'data' => ['id' => 2192552, 'sims' => [[
                    'id' => 2508142,
                    'iccid' => '89852350326101340605',
                    'qrcode_url' => 'https://airalo.test/qr/2192552',
                    'lpa' => 'sm-dp.example',
                    'matching_id' => 'ACTIVATION-CODE-2192552',
                ]]],
            ]),
        ]);

        $user = User::factory()->create();
        $order = AiraloOrder::query()->create([
            'user_id' => $user->id,
            'package_id' => 'discover-in-3days-300mb',
            'airalo_order_id' => '2192552',
            'quantity' => 1,
            'currency' => 'USD',
            'status' => 'completed',
        ]);
        $this->actingAs($user, 'api');

        $this->getJson('/api/v1/esim/orders/' . $order->id . '/instructions')
            ->assertOk()
            ->assertJsonPath('data.iccid', '89852350326101340605')
            ->assertJsonPath('data.qrcode_url', 'https://airalo.test/qr/2192552')
            ->assertJsonPath('data.smdp_address', 'sm-dp.example')
            ->assertJsonPath('data.ac_code', 'ACTIVATION-CODE-2192552');
    }

    #[Test]
    public function it_uses_only_documented_sim_endpoints_after_order_inclusion(): void
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
                'expires_in' => 86400,
            ]),
            'https://partners.airalo.test/v2/orders/2192552*' => Http::response([
                'data' => ['id' => 2192552, 'sims' => [[
                    'id' => 2508142,
                    'iccid' => '89852350326101340605',
                    'qrcode_url' => 'https://airalo.test/qr/2192552',
                    'lpa' => 'sm-dp.example',
                    'matching_id' => 'MATCHING-ID-2192552',
                ]]],
            ]),
            'https://partners.airalo.test/v2/sims/89852350326101340605' => Http::response([
                'data' => [
                    'id' => 2508142,
                    'iccid' => '89852350326101340605',
                    'qrcode_url' => 'https://airalo.test/qr/2192552',
                    'lpa' => 'sm-dp.example',
                    'matching_id' => 'MATCHING-ID-2192552',
                ],
            ]),
            'https://partners.airalo.test/v2/sims/89852350326101340605/instructions' => Http::response([
                'data' => ['instructions' => []],
            ]),
        ]);

        $user = User::factory()->create();
        $order = AiraloOrder::query()->create([
            'user_id' => $user->id,
            'package_id' => 'discover-in-3days-300mb',
            'airalo_order_id' => '2192552',
            'quantity' => 1,
            'currency' => 'USD',
            'status' => 'completed',
        ]);
        $this->actingAs($user, 'api');

        $this->getJson('/api/v1/esim/orders/' . $order->id . '/instructions')
            ->assertOk()
            ->assertJsonPath('data.iccid', '89852350326101340605')
            ->assertJsonPath('data.qrcode_url', 'https://airalo.test/qr/2192552')
            ->assertJsonPath('data.smdp_address', 'sm-dp.example')
            ->assertJsonPath('data.ac_code', 'MATCHING-ID-2192552');

        Http::assertSentCount(4);
        Http::assertSent(static fn ($request): bool => $request->url() === 'https://partners.airalo.test/v2/orders/2192552?include=sims');
        Http::assertSent(static fn ($request): bool => $request->url() === 'https://partners.airalo.test/v2/sims/89852350326101340605');
        Http::assertSent(static fn ($request): bool => $request->url() === 'https://partners.airalo.test/v2/sims/89852350326101340605/instructions');
    }

    #[Test]
    public function it_retrieves_an_iccid_from_documented_order_sim_inclusion(): void
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
                'expires_in' => 86400,
            ]),
            'https://partners.airalo.test/v2/orders/2192607*' => Http::response([
                'data' => ['id' => 2192607, 'sims' => [[
                    'id' => 2508142,
                    'iccid' => '89852350326101340605',
                    'qrcode_url' => 'https://airalo.test/qr/2192607',
                    'lpa' => 'sm-dp.example',
                    'matching_id' => 'ACTIVATION-CODE-2192607',
                ]]],
            ]),
        ]);

        $user = User::factory()->create();
        $order = AiraloOrder::query()->create([
            'user_id' => $user->id,
            'package_id' => 'discover-in-1gb-5days-px',
            'airalo_order_id' => '2192607',
            'quantity' => 1,
            'currency' => 'USD',
            'status' => 'completed',
        ]);
        $this->actingAs($user, 'api');

        $this->getJson('/api/v1/esim/orders/' . $order->id . '/instructions')
            ->assertOk()
            ->assertJsonPath('data.iccid', '89852350326101340605')
            ->assertJsonPath('data.qrcode_url', 'https://airalo.test/qr/2192607')
            ->assertJsonPath('data.smdp_address', 'sm-dp.example')
            ->assertJsonPath('data.ac_code', 'ACTIVATION-CODE-2192607');
    }

    #[Test]
    public function it_logs_available_order_keys_when_documented_order_sims_are_missing(): void
    {
        config([
            'services.airalo.base_url' => 'https://partners.airalo.test',
            'services.airalo.client_id' => 'airalo-client-id',
            'services.airalo.client_secret' => 'airalo-client-secret',
            'cache.default' => 'array',
        ]);
        Cache::forget('airalo:oauth:access_token');
        Log::spy();

        Http::fake([
            'https://partners.airalo.test/v2/token' => Http::response([
                'access_token' => 'airalo-token-xyz',
                'expires_in' => 86400,
            ]),
            'https://partners.airalo.test/v2/orders/2192607*' => Http::response([
                'data' => ['id' => 2192607],
            ]),
        ]);

        $user = User::factory()->create();
        $order = AiraloOrder::query()->create([
            'user_id' => $user->id,
            'package_id' => 'discover-in-1gb-5days-px',
            'airalo_order_id' => '2192607',
            'quantity' => 1,
            'currency' => 'USD',
            'status' => 'completed',
        ]);
        $this->actingAs($user, 'api');

        $this->getJson('/api/v1/esim/orders/' . $order->id . '/instructions')->assertOk();

        Log::shouldHaveReceived('warning')->withArgs(static function (string $message, array $context): bool {
            return $message === 'Airalo keys available'
                && $context['airalo_order_id'] === '2192607'
                && $context['keys'] === ['id'];
        })->once();
    }

    #[Test]
    public function it_returns_partial_airalo_guides_when_no_sim_profile_is_available(): void
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
                'expires_in' => 86400,
            ]),
            'https://partners.airalo.test/v2/orders/2192552*' => Http::response([
                'data' => [
                    'id' => 2192552,
                    'sims' => [[
                        'iccid' => '8988211000000002192',
                    ]],
                    'qrcode_installation' => '<p>Scan the QR code on another device.</p>',
                    'manual_installation' => '<p>Enable data roaming.</p>',
                    'installation_guides' => [
                        'en' => 'https://www.airalo.com/help/getting-started-with-airalo',
                    ],
                ],
            ]),
            'https://partners.airalo.test/v2/sims/8988211000000002192' => Http::response([], 404),
            'https://partners.airalo.test/v2/sims/8988211000000002192/instructions' => Http::response([], 404),
        ]);

        $user = User::factory()->create();
        $order = AiraloOrder::query()->create([
            'user_id' => $user->id,
            'package_id' => 'discover-in-3days-300mb',
            'airalo_order_id' => '2192552',
            'quantity' => 1,
            'currency' => 'USD',
            'status' => 'completed',
        ]);
        $this->actingAs($user, 'api');

        $this->getJson('/api/v1/esim/orders/' . $order->id . '/instructions')
            ->assertOk()
            ->assertJsonPath('data.instructions_status', 'partial')
            ->assertJsonPath('data.guide_url', 'https://www.airalo.com/help/getting-started-with-airalo')
            ->assertJsonPath('data.instructions_html', '<p>Scan the QR code on another device.</p>')
            ->assertJsonPath('data.qrcode_url', null)
            ->assertJsonPath('data.smdp_address', null);
    }

    #[Test]
    public function it_caches_sim_package_history_and_hides_unlimited_zero_balances(): void
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
                'expires_in' => 86400,
            ]),
            'https://partners.airalo.test/v2/sims/89852350326101080144/packages' => Http::response([
                'data' => [[
                    'id' => 'package-history-1',
                    'is_unlimited' => true,
                    'remaining' => 0,
                    'total' => 0,
                    'amount' => 0,
                ]],
            ]),
        ]);

        $user = User::factory()->create();
        $order = AiraloOrder::query()->create([
            'user_id' => $user->id,
            'package_id' => 'discover-in-3days-300mb',
            'airalo_order_id' => '2192552',
            'airalo_order_code' => '20260725-2192552',
            'iccid' => '89852350326101080144',
            'quantity' => 1,
            'currency' => 'USD',
            'status' => 'completed',
        ]);
        $this->actingAs($user, 'api');

        foreach (range(1, 2) as $_) {
            $this->getJson('/api/v1/esim/orders/' . $order->id . '/packages')
                ->assertOk()
                ->assertJsonPath('data.0.id', 'package-history-1')
                ->assertJsonPath('data.0.is_unlimited', true)
                ->assertJsonMissingPath('data.0.remaining')
                ->assertJsonMissingPath('data.0.total')
                ->assertJsonMissingPath('data.0.amount')
                ->assertJsonMissingPath('data.0.iccid');
        }

        Http::assertSentCount(2);
    }

    #[Test]
    public function it_normalizes_remaining_sim_data_for_the_mobile_client(): void
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
                'expires_in' => 86400,
            ]),
            'https://partners.airalo.test/v2/sims/89852350326101080145/packages' => Http::response([
                'data' => [[
                    'id' => 'package-balance-1',
                    'remaining' => '1.5 GB',
                    'amount' => '2 GB',
                ]],
            ]),
        ]);

        $user = User::factory()->create();
        $order = AiraloOrder::query()->create([
            'user_id' => $user->id,
            'package_id' => 'discover-in-3days-300mb',
            'airalo_order_id' => '2192553',
            'iccid' => '89852350326101080145',
            'quantity' => 1,
            'currency' => 'USD',
            'status' => 'completed',
        ]);
        $this->actingAs($user, 'api');

        $this->getJson('/api/v1/esim/orders/' . $order->id . '/packages')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'package-balance-1')
            ->assertJsonPath('data.0.is_unlimited', false)
            ->assertJsonPath('data.0.remaining_mb', 1536)
            ->assertJsonPath('data.0.total_mb', 2048);
    }

    #[Test]
    public function it_logs_available_order_keys_when_documented_installation_data_is_empty(): void
    {
        config([
            'services.airalo.base_url' => 'https://partners.airalo.test',
            'services.airalo.client_id' => 'airalo-client-id',
            'services.airalo.client_secret' => 'airalo-client-secret',
            'cache.default' => 'array',
        ]);
        Cache::forget('airalo:oauth:access_token');
        Log::spy();

        Http::fake([
            'https://partners.airalo.test/v2/token' => Http::response([
                'access_token' => 'airalo-token-xyz',
                'expires_in' => 86400,
            ]),
            'https://partners.airalo.test/v2/orders/2192552*' => Http::response([
                'data' => ['id' => 2192552, 'sims' => [['iccid' => '8988211000000002192']], 'status' => 'completed'],
            ]),
            'https://partners.airalo.test/v2/sims/8988211000000002192/instructions' => Http::response([], 404),
            'https://partners.airalo.test/v2/sims/8988211000000002192' => Http::response([], 404),
        ]);

        $user = User::factory()->create();
        $order = AiraloOrder::query()->create([
            'user_id' => $user->id,
            'package_id' => 'discover-in-3days-300mb',
            'airalo_order_id' => '2192552',
            'quantity' => 1,
            'currency' => 'USD',
            'status' => 'completed',
        ]);
        $this->actingAs($user, 'api');

        $this->getJson('/api/v1/esim/orders/' . $order->id . '/instructions')
            ->assertOk()
            ->assertJsonPath('data.qrcode_url', null);

        Log::shouldHaveReceived('warning')->withArgs(static function (string $message, array $context): bool {
            return $message === 'Airalo keys available'
                && $context['airalo_order_id'] === '2192552'
                && $context['keys'] === ['id', 'sims', 'status'];
        })->once();
    }

    #[Test]
    public function it_returns_a_safe_generic_error_when_instruction_retrieval_fails(): void
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
                'expires_in' => 86400,
            ]),
            'https://partners.airalo.test/v2/orders/ord-missing-1*' => Http::response([
                'message' => 'Order not found',
                'qrcode_url' => 'https://airalo.test/private-qr',
            ], 404),
        ]);

        $user = User::factory()->create();
        $order = AiraloOrder::query()->create([
            'user_id' => $user->id,
            'package_id' => 'pkg-missing-1',
            'airalo_order_id' => 'ord-missing-1',
            'quantity' => 1,
            'currency' => 'USD',
            'status' => 'completed',
        ]);
        $this->actingAs($user, 'api');

        $this->getJson('/api/v1/esim/orders/' . $order->id . '/instructions')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('debug_message')
            ->assertJsonPath('airalo_message', 'Order not found')
            ->assertJsonMissingPath('qrcode_url');
    }

    #[Test]
    public function it_hides_airalo_diagnostics_in_production_when_instruction_retrieval_fails(): void
    {
        $this->app->instance('env', 'production');
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
                'expires_in' => 86400,
            ]),
            'https://partners.airalo.test/v2/orders/ord-production-1*' => Http::response([
                'message' => 'Private upstream detail',
            ], 404),
        ]);

        $user = User::factory()->create();
        $order = AiraloOrder::query()->create([
            'user_id' => $user->id,
            'package_id' => 'pkg-production-1',
            'airalo_order_id' => 'ord-production-1',
            'quantity' => 1,
            'currency' => 'USD',
            'status' => 'completed',
        ]);
        $this->actingAs($user, 'api');

        $this->getJson('/api/v1/esim/orders/' . $order->id . '/instructions')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('debug_message')
            ->assertJsonMissingPath('airalo_message')
            ->assertJsonMissingPath('qrcode_url');
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
