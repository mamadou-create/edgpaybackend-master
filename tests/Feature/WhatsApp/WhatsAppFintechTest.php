<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Role;
use App\Models\SupportRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WhatsAppChatSession;
use App\Jobs\SendWhatsAppTextMessageJob;
use App\Services\NimbaSmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsAppFintechTest extends TestCase
{
    use RefreshDatabase;

    private ?string $capturedOtp = null;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'whatsapp.verify_token' => 'verify-token-test',
            'whatsapp.otp_threshold' => 100000,
            'whatsapp.queue_outbound' => false,
            'whatsapp.validate_signature' => false,
        ]);

        $this->mock(NimbaSmsService::class, function ($mock): void {
            $mock->shouldReceive('sendSingleSms')
                ->andReturnUsing(function (string $senderName, string $to, string $message): array {
                    if (preg_match('/(\d{6})/', $message, $matches)) {
                        $this->capturedOtp = $matches[1];
                    }

                    return [
                        'success' => true,
                        'sender' => $senderName,
                        'to' => $to,
                    ];
                });
        });
    }

    #[Test]
    public function webhook_verify_returns_challenge_when_token_matches(): void
    {
        $response = $this->get('/api/v1/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=verify-token-test&hub.challenge=12345');

        $response->assertOk();
        $response->assertSeeText('12345');
    }

    #[Test]
    public function webhook_receive_rejects_invalid_signature_when_validation_is_enabled(): void
    {
        config([
            'whatsapp.validate_signature' => true,
            'whatsapp.app_secret' => 'meta-secret-test',
        ]);

        $response = $this->postJson('/api/v1/webhook/whatsapp', [
            'phone' => '622000001',
            'message' => 'Bonjour',
            'timestamp' => now()->toIso8601String(),
        ], [
            'X-Hub-Signature-256' => 'sha256=invalid-signature',
        ]);

        $response
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function webhook_receive_accepts_valid_signature_and_queues_outbound_message(): void
    {
        $this->createClientRole();
        Queue::fake();

        config([
            'whatsapp.validate_signature' => true,
            'whatsapp.app_secret' => 'meta-secret-test',
            'whatsapp.queue_outbound' => true,
            'whatsapp.outbound_queue' => 'whatsapp-tests',
        ]);

        $payload = [
            'phone' => '622000002',
            'message' => '1',
            'timestamp' => now()->toIso8601String(),
        ];

        $content = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = 'sha256=' . hash_hmac('sha256', $content, 'meta-secret-test');

        $response = $this->call(
            'POST',
            '/api/v1/webhook/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $content,
        );

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.intent', 'CREATE_ACCOUNT');

        Queue::assertPushed(SendWhatsAppTextMessageJob::class, function (SendWhatsAppTextMessageJob $job): bool {
            return $job->phone === '224622000002'
                && $job->message !== '';
        });
    }

    #[Test]
    public function whatsapp_create_user_endpoint_creates_user_wallet_and_whatsapp_fields(): void
    {
        $this->createClientRole();

        $response = $this->postJson('/api/v1/whatsapp/auth/create-user', [
            'phone' => '622111111',
            'name' => 'Client WhatsApp',
            'date_of_birth' => '1998-06-15',
            'pin' => '1234',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.phone', '622111111')
            ->assertJsonPath('data.display_name', 'Client WhatsApp');

        $user = User::query()->where('phone', '622111111')->firstOrFail();
        $wallet = Wallet::query()->where('user_id', $user->id)->first();

        $this->assertNotNull($wallet);
        $this->assertSame('622111111', $user->whatsapp_phone);
        $this->assertNotNull($user->whatsapp_verified_at);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertSame('1998-06-15', optional($user->date_of_birth)->format('Y-m-d'));
        $this->assertNotNull($user->pin_hash);
        $this->assertNotSame('1234', $user->pin_hash);
    }

    #[Test]
    public function whatsapp_link_account_and_verify_otp_links_existing_user(): void
    {
        $user = $this->createExistingClient('622222222', 'Compte Existant');

        $linkResponse = $this->postJson('/api/v1/whatsapp/auth/link-account', [
            'phone' => '620000000',
            'account_phone' => '622222222',
        ]);

        $linkResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.otp_sent', true);

        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $this->capturedOtp);

        $verifyResponse = $this->postJson('/api/v1/whatsapp/auth/verify-otp', [
            'phone' => '620000000',
            'otp' => $this->capturedOtp,
        ]);

        $verifyResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.linked', true)
            ->assertJsonPath('data.user_id', $user->id);

        $user->refresh();

        $this->assertSame('620000000', $user->whatsapp_phone);
        $this->assertNotNull($user->whatsapp_verified_at);
    }

    #[Test]
    public function whatsapp_send_endpoint_requires_otp_for_sensitive_transfer_and_executes_it(): void
    {
        $sender = $this->createExistingClient('622333333', 'Sender WhatsApp', pin: '1234', whatsappPhone: '622333333', balance: 200000);
        $receiver = $this->createExistingClient('622444444', 'Receiver WhatsApp', pin: '4321', whatsappPhone: '622444444', balance: 1000);

        $firstStep = $this->postJson('/api/v1/whatsapp/wallet/send', [
            'phone' => $sender->whatsapp_phone,
            'receiver_phone' => $receiver->phone,
            'amount' => 150000,
            'pin' => '1234',
        ]);

        $firstStep
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.requires_otp', true);

        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $this->capturedOtp);

        $secondStep = $this->postJson('/api/v1/whatsapp/wallet/send', [
            'phone' => $sender->whatsapp_phone,
            'receiver_phone' => $receiver->phone,
            'amount' => 150000,
            'pin' => '1234',
            'otp' => $this->capturedOtp,
        ]);

        $secondStep
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.amount', 150000)
            ->assertJsonPath('data.receiver_phone', '622444444');

        $senderWallet = Wallet::query()->where('user_id', $sender->id)->firstOrFail();
        $receiverWallet = Wallet::query()->where('user_id', $receiver->id)->firstOrFail();

        $this->assertSame(50000, (int) $senderWallet->cash_available);
        $this->assertSame(151000, (int) $receiverWallet->cash_available);
    }

    #[Test]
    public function whatsapp_webhook_guest_create_account_flow_persists_session_and_user(): void
    {
        $this->createClientRole();

        $this->postJson('/api/v1/webhook/whatsapp', [
            'phone' => '622555555',
            'message' => '1',
            'timestamp' => now()->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.intent', 'CREATE_ACCOUNT');

        $this->postJson('/api/v1/webhook/whatsapp', [
            'phone' => '622555555',
            'message' => 'Utilisateur Webhook',
            'timestamp' => now()->toIso8601String(),
        ])->assertOk();

        $this->postJson('/api/v1/webhook/whatsapp', [
            'phone' => '622555555',
            'message' => '2000-01-01',
            'timestamp' => now()->toIso8601String(),
        ])->assertOk();

        $this->postJson('/api/v1/webhook/whatsapp', [
            'phone' => '622555555',
            'message' => '9876',
            'timestamp' => now()->toIso8601String(),
        ])->assertOk();

        $final = $this->postJson('/api/v1/webhook/whatsapp', [
            'phone' => '622555555',
            'message' => '9876',
            'timestamp' => now()->toIso8601String(),
        ]);

        $final
            ->assertOk()
            ->assertJsonPath('data.intent', 'CREATE_ACCOUNT');

        $this->assertDatabaseHas('users', [
            'phone' => '622555555',
            'whatsapp_phone' => '622555555',
            'display_name' => 'Utilisateur Webhook',
        ]);

        $this->assertDatabaseHas('whatsapp_chat_sessions', [
            'user_phone' => '622555555',
            'state' => 'idle',
        ]);
    }

    #[Test]
    public function whatsapp_support_endpoint_creates_support_request(): void
    {
        $user = $this->createExistingClient('622666666', 'Support Test', whatsappPhone: '622666666');

        $response = $this->postJson('/api/v1/whatsapp/support/create', [
            'phone' => $user->whatsapp_phone,
            'message' => 'Je veux parler au support',
            'reason' => 'manual_test',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(1, SupportRequest::count());
        $this->assertDatabaseHas('support_requests', [
            'user_id' => $user->id,
            'source' => 'whatsapp',
            'reason' => 'manual_test',
            'status' => 'open',
        ]);
    }

    private function createClientRole(): Role
    {
        return Role::query()->updateOrCreate(
            ['slug' => 'client'],
            [
                'name' => 'Client',
                'description' => 'Client test',
                'is_super_admin' => false,
            ]
        );
    }

    private function createExistingClient(
        string $phone,
        string $displayName,
        string $pin = '1234',
        ?string $whatsappPhone = null,
        int $balance = 10000,
    ): User {
        $role = $this->createClientRole();

        $user = User::factory()->create([
            'role_id' => $role->id,
            'phone' => $phone,
            'whatsapp_phone' => $whatsappPhone,
            'whatsapp_verified_at' => $whatsappPhone ? now() : null,
            'phone_verified_at' => now(),
            'display_name' => $displayName,
            'pin_hash' => bcrypt($pin),
            'status' => true,
            'is_pro' => false,
        ]);

        Wallet::query()->create([
            'user_id' => $user->id,
            'currency' => 'GNF',
            'cash_available' => $balance,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);

        return $user;
    }
}