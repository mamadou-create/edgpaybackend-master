<?php

namespace Tests\Feature\Chatbot;

use App\Models\Role;
use App\Models\SupportRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use App\Services\NimbaSmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChatbotOtpFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function send_money_flow_advances_to_otp_and_executes_transfer(): void
    {
        $this->mockSmsFailure();
        config(['chatbot.allow_otp_fallback' => true]);

        [$sender, $recipient] = $this->createChatbotUsers();

        $this->actingAs($sender, 'api');

        $stepOne = $this->postJson('/api/v1/chat/message', [
            'message' => 'Je veux envoyer 1000 a ' . $recipient->phone,
        ]);

        $stepOne
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.intent', 'SEND_MONEY')
            ->assertJsonPath('data.awaiting', 'send_confirm');

        $stepTwo = $this->postJson('/api/v1/chat/message', [
            'message' => 'oui',
        ]);

        $stepTwo
            ->assertOk()
            ->assertJsonPath('data.intent', 'SEND_MONEY')
            ->assertJsonPath('data.awaiting', 'send_otp')
            ->assertJsonPath('data.requires_otp', true)
            ->assertJsonPath('data.metadata.otp_delivery', 'local_fallback');

        $sender->refresh();
        $otp = (string) $sender->two_factor_token;

        $this->assertMatchesRegularExpression('/^\d{6}$/', $otp);

        $stepThree = $this->postJson('/api/v1/chat/message', [
            'message' => $otp,
        ]);

        $stepThree
            ->assertOk()
            ->assertJsonPath('data.intent', 'SEND_MONEY')
            ->assertJsonPath('data.awaiting', null)
            ->assertJsonPath('data.requires_otp', false);

        $senderWallet = Wallet::where('user_id', $sender->id)->firstOrFail();
        $recipientWallet = Wallet::where('user_id', $recipient->id)->firstOrFail();

        $sender->refresh();

        $this->assertSame(9000, (int) $senderWallet->cash_available);
        $this->assertSame(1500, (int) $recipientWallet->cash_available);
        $this->assertNull($sender->two_factor_token);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $sender->id,
            'type' => 'transfer_out',
            'amount' => -1000,
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $recipient->id,
            'type' => 'transfer_in',
            'amount' => 1000,
        ]);
        $this->assertSame(0, SupportRequest::count());
    }

    #[Test]
    public function withdraw_flow_advances_to_otp_and_creates_pending_request(): void
    {
        $this->mockSmsFailure();
        config(['chatbot.allow_otp_fallback' => true]);

        [$sender] = $this->createChatbotUsers();

        $this->actingAs($sender, 'api');

        $stepOne = $this->postJson('/api/v1/chat/message', [
            'message' => 'Retrait 1000',
        ]);

        $stepOne
            ->assertOk()
            ->assertJsonPath('data.intent', 'WITHDRAW')
            ->assertJsonPath('data.awaiting', 'withdraw_confirm');

        $stepTwo = $this->postJson('/api/v1/chat/message', [
            'message' => 'oui',
        ]);

        $stepTwo
            ->assertOk()
            ->assertJsonPath('data.intent', 'WITHDRAW')
            ->assertJsonPath('data.awaiting', 'withdraw_otp')
            ->assertJsonPath('data.requires_otp', true)
            ->assertJsonPath('data.metadata.otp_delivery', 'local_fallback');

        $sender->refresh();
        $otp = (string) $sender->two_factor_token;

        $stepThree = $this->postJson('/api/v1/chat/message', [
            'message' => $otp,
        ]);

        $stepThree
            ->assertOk()
            ->assertJsonPath('data.intent', 'WITHDRAW')
            ->assertJsonPath('data.awaiting', null)
            ->assertJsonPath('data.requires_otp', false);

        $sender->refresh();
        $wallet = Wallet::where('user_id', $sender->id)->firstOrFail();
        $withdrawalRequest = WithdrawalRequest::where('user_id', $sender->id)->latest('created_at')->first();

        $this->assertNotNull($withdrawalRequest);
        $this->assertSame(1000, (int) $withdrawalRequest->amount);
        $this->assertSame($wallet->id, $withdrawalRequest->wallet_id);
        $this->assertNull($sender->two_factor_token);
        $this->assertGreaterThan(0, (int) $wallet->blocked_amount);
        $this->assertSame(0, SupportRequest::count());
    }

    #[Test]
    public function send_money_escalates_to_support_when_sms_fails_and_fallback_is_disabled(): void
    {
        $this->mockSmsFailure();
        config(['chatbot.allow_otp_fallback' => false]);

        [$sender, $recipient] = $this->createChatbotUsers();

        $this->actingAs($sender, 'api');

        $this->postJson('/api/v1/chat/message', [
            'message' => 'Je veux envoyer 1000 a ' . $recipient->phone,
        ])->assertOk()->assertJsonPath('data.awaiting', 'send_confirm');

        $confirmation = $this->postJson('/api/v1/chat/message', [
            'message' => 'oui',
        ]);

        $confirmation
            ->assertOk()
            ->assertJsonPath('data.intent', 'SUPPORT_HELP')
            ->assertJsonPath('data.support_transferred', true);

        $this->assertSame(1, SupportRequest::count());
        $this->assertSame(0, WalletTransaction::count());
    }

    #[Test]
    public function deposit_flow_returns_secure_metadata_action_without_mutating_wallet(): void
    {
        [$sender] = $this->createChatbotUsers();

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Depot 25000',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.intent', 'DEPOSIT')
            ->assertJsonPath('data.awaiting', null)
            ->assertJsonPath('data.requires_otp', false)
            ->assertJsonPath('data.metadata.amount', 25000)
            ->assertJsonPath('data.metadata.action', 'open_secure_deposit_flow');

        $wallet = Wallet::where('user_id', $sender->id)->firstOrFail();

        $this->assertSame(10000, (int) $wallet->cash_available);
        $this->assertSame(0, (int) $wallet->blocked_amount);
        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame(0, WithdrawalRequest::count());
        $this->assertSame(0, SupportRequest::count());
    }

    #[Test]
    public function prepaid_bill_intent_returns_dedicated_metadata_action(): void
    {
        [$sender] = $this->createChatbotUsers();

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Je veux payer une facture prepayee',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.intent', 'PREPAID_BILL')
            ->assertJsonPath('data.awaiting', null)
            ->assertJsonPath('data.metadata.action', 'open_prepaid_bill_flow');

        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame(0, WithdrawalRequest::count());
        $this->assertSame(0, SupportRequest::count());
    }

    #[Test]
    public function prepaid_bill_nlp_phrasing_recognizes_buying_energy_and_surfaces_bill_buttons(): void
    {
        [$sender] = $this->createChatbotUsers();

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Je veux acheter du courant',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'PREPAID_BILL')
            ->assertJsonPath('data.metadata.action', 'open_prepaid_bill_flow')
            ->assertJsonFragment(['label' => 'Facture prepayee'])
            ->assertJsonFragment(['label' => 'Facture postpayee']);
    }

    #[Test]
    public function prepaid_bill_keywords_can_be_overridden_from_configuration(): void
    {
        [$sender] = $this->createChatbotUsers();
        config(['chatbot.intent_keywords.prepaid_bill' => ['mot cle facture test']]);

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'mot cle facture test',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'PREPAID_BILL')
            ->assertJsonPath('data.metadata.action', 'open_prepaid_bill_flow');
    }

    #[Test]
    public function prepaid_bill_keywords_can_be_overridden_from_system_settings(): void
    {
        [$sender] = $this->createChatbotUsers();

        \App\Models\SystemSetting::updateOrCreate(
            ['key' => 'chatbot_intent_keywords_prepaid_bill'],
            [
                'value' => 'mon mot cle admin',
                'type' => 'string',
                'group' => 'chatbot',
                'description' => 'Override test',
                'is_active' => true,
                'is_editable' => true,
                'order' => 40,
            ]
        );

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'mon mot cle admin',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'PREPAID_BILL')
            ->assertJsonPath('data.metadata.action', 'open_prepaid_bill_flow');
    }

    #[Test]
    public function postpaid_bill_intent_returns_dedicated_metadata_action(): void
    {
        [$sender] = $this->createChatbotUsers();

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Je veux payer une facture postpayee',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.intent', 'POSTPAID_BILL')
            ->assertJsonPath('data.awaiting', null)
            ->assertJsonPath('data.metadata.action', 'open_postpaid_bill_flow');

        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame(0, WithdrawalRequest::count());
        $this->assertSame(0, SupportRequest::count());
    }

    #[Test]
    public function postpaid_bill_nlp_phrasing_recognizes_paying_edg_bill(): void
    {
        [$sender] = $this->createChatbotUsers();

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Je veux payer ma facture EDG',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'POSTPAID_BILL')
            ->assertJsonPath('data.metadata.action', 'open_postpaid_bill_flow');
    }

    #[Test]
    public function balance_response_surfaces_bill_payment_buttons(): void
    {
        [$sender] = $this->createChatbotUsers();

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Quel est mon solde ?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'CHECK_BALANCE')
            ->assertJsonFragment(['label' => 'Facture prepayee'])
            ->assertJsonFragment(['label' => 'Facture postpayee']);
    }

    #[Test]
    public function admin_balance_response_does_not_surface_bill_payment_buttons(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Quel est mon solde ?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'CHECK_BALANCE')
            ->assertJsonMissing(['label' => 'Facture prepayee'])
            ->assertJsonMissing(['label' => 'Facture postpayee'])
            ->assertJsonFragment(['label' => 'Historique des transactions']);
    }

    #[Test]
    public function admin_cannot_receive_open_bill_action_for_postpaid_request(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Je veux payer ma facture EDG',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'SUPPORT_HELP')
            ->assertJsonPath('data.metadata.bill_payment_supported', false)
            ->assertJsonMissing(['action' => 'open_postpaid_bill_flow']);
    }

    private function mockSmsFailure(): void
    {
        $this->mock(NimbaSmsService::class, function ($mock): void {
            $mock->shouldReceive('sendSingleSms')
                ->andReturn([
                    'success' => false,
                    'error' => 'simulated_sms_failure',
                ]);
        });
    }

    private function createChatbotUsers(): array
    {
        $clientRole = Role::query()->updateOrCreate(
            ['slug' => 'client'],
            [
                'name' => 'Client',
                'description' => 'Rôle client (tests)',
                'is_super_admin' => false,
            ]
        );

        $sender = User::factory()->create([
            'role_id' => $clientRole->id,
            'is_pro' => false,
            'phone' => '622000001',
            'display_name' => 'Sender Test',
            'solde_portefeuille' => 10000,
            'two_factor_enabled' => true,
        ]);

        $recipient = User::factory()->create([
            'role_id' => $clientRole->id,
            'is_pro' => false,
            'phone' => '622222221',
            'display_name' => 'Recipient Test',
            'solde_portefeuille' => 500,
            'two_factor_enabled' => true,
        ]);

        Wallet::create([
            'user_id' => $sender->id,
            'currency' => 'GNF',
            'cash_available' => 10000,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);

        Wallet::create([
            'user_id' => $recipient->id,
            'currency' => 'GNF',
            'cash_available' => 500,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);

        return [$sender, $recipient];
    }

    private function createAdminUser(): User
    {
        $adminRole = Role::query()->updateOrCreate(
            ['slug' => 'super_admin'],
            [
                'name' => 'Super Admin',
                'description' => 'Rôle super admin (tests)',
                'is_super_admin' => true,
            ]
        );

        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_pro' => false,
            'phone' => '622999999',
            'display_name' => 'Admin Test',
            'solde_portefeuille' => 50000,
            'two_factor_enabled' => true,
        ]);

        Wallet::create([
            'user_id' => $admin->id,
            'currency' => 'GNF',
            'cash_available' => 50000,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);

        return $admin;
    }
}