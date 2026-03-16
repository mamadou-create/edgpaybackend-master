<?php

namespace Tests\Feature\Chatbot;

use App\Models\Role;
use App\Models\SupportRequest;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserAssistantMemory;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use App\Services\NimbaAiAssistantService;
use App\Services\NimbaSmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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

    #[Test]
    public function greeting_intent_returns_contextual_help_and_default_actions(): void
    {
        [$sender] = $this->createChatbotUsers();

        $wallet = Wallet::where('user_id', $sender->id)->firstOrFail();
        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $sender->id,
            'amount' => -25000,
            'type' => 'transfer_out',
            'reference' => 'txn_test_greeting_personalization',
            'description' => 'Transfert test récent',
            'metadata' => ['status' => 'completed'],
        ]);

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'bonjour',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'GREETING')
            ->assertJsonPath('data.metadata.personalized_suggestions.0', 'Envoyer de l\'argent')
            ->assertJsonFragment(['label' => 'Aide'])
            ->assertJsonFragment(['label' => 'Vérifier mon solde']);
    }

    #[Test]
    public function help_intent_returns_capabilities_without_escalation(): void
    {
        [$sender] = $this->createChatbotUsers();

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'aide',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'HELP')
            ->assertJsonPath('data.support_transferred', false)
            ->assertJsonFragment(['label' => 'Envoyer de l\'argent'])
            ->assertJsonFragment(['label' => 'Historique des transactions']);
    }

    #[Test]
    public function personalized_suggestions_distinguish_prepaid_edg_activity(): void
    {
        [$sender] = $this->createChatbotUsers();

        $wallet = Wallet::where('user_id', $sender->id)->firstOrFail();
        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $sender->id,
            'amount' => -18000,
            'type' => 'wallet_bill_payment',
            'reference' => 'txn_test_prepaid_personalization',
            'description' => 'Paiement facture prepayee EDG',
            'metadata' => [
                'provider' => 'EDG',
                'compteur_type' => 'prepaid',
            ],
        ]);

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'bonjour',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'GREETING')
            ->assertJsonPath('data.metadata.personalized_suggestions.0', 'Facture prepayee')
            ->assertJsonPath('data.metadata.recent_activity', 'Dernière opération: paiement EDG prépayé de 18 000 GNF le ' . now()->format('d/m H:i') . '.');
    }

    #[Test]
    public function personalized_suggestions_distinguish_postpaid_edg_activity(): void
    {
        [$sender] = $this->createChatbotUsers();

        $wallet = Wallet::where('user_id', $sender->id)->firstOrFail();
        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $sender->id,
            'amount' => -42000,
            'type' => 'debit_wallet_creance',
            'reference' => 'txn_test_postpaid_personalization',
            'description' => 'Paiement facture EDG postpayee',
            'metadata' => [
                'source' => 'creance_wallet_payment',
                'provider' => 'EDG',
                'compteur_type' => 'postpaid',
            ],
        ]);

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'aide',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'HELP')
            ->assertJsonPath('data.metadata.personalized_suggestions.0', 'Facture postpayee');
    }

    #[Test]
    public function service_info_question_returns_natural_explanation(): void
    {
        [$sender] = $this->createChatbotUsers();

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Comment fonctionne votre service ?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'SERVICE_INFO')
            ->assertJsonPath('data.metadata.knowledge_topic', 'service')
            ->assertJsonFragment(['label' => 'Quels sont les frais ?']);
    }

    #[Test]
    public function fees_question_returns_safe_answer_without_inventing_numbers(): void
    {
        [$sender] = $this->createChatbotUsers();

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Quels sont les frais de transfert ?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'FEES_INFO')
            ->assertJsonPath('data.metadata.knowledge_topic', 'fees')
            ->assertJsonFragment(['label' => 'Historique des transactions']);
    }

    #[Test]
    public function application_transfer_question_returns_direct_knowledge_answer(): void
    {
        [$sender] = $this->createChatbotUsers();

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Comment envoyer de l\'argent dans l\'application ?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'APP_KNOWLEDGE')
            ->assertJsonPath('data.metadata.knowledge_key', 'transfer_howto')
            ->assertJsonPath('data.metadata.knowledge_topic', 'transfer')
            ->assertJsonFragment(['label' => 'Envoyer de l\'argent']);
    }

    #[Test]
    public function postpaid_minimum_question_returns_grounded_creance_based_answer(): void
    {
        [$sender] = $this->createChatbotUsers();

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Il faut combien au minimum pour payer une facture postpayee ?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'APP_KNOWLEDGE')
            ->assertJsonPath('data.metadata.knowledge_key', 'postpaid_minimum')
            ->assertJsonPath('data.metadata.knowledge_topic', 'postpaid_bill')
            ->assertJsonPath('data.metadata.action', 'open_postpaid_bill_flow')
            ->assertJsonFragment(['label' => 'Facture postpayee']);

        $this->assertStringContainsString('il n\'y a pas un minimum fixe universel', $response->json('data.reply'));
        $this->assertStringContainsString('montant restant', $response->json('data.reply'));
    }

    #[Test]
    public function generic_application_question_returns_direct_capability_answer_instead_of_unknown(): void
    {
        [$sender] = $this->createChatbotUsers();

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Que peux-tu m\'expliquer sur l\'application EdgPay ?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'APP_KNOWLEDGE')
            ->assertJsonPath('data.metadata.knowledge_key', 'application_capabilities')
            ->assertJsonPath('data.metadata.knowledge_topic', 'application');
    }

    #[Test]
    public function app_knowledge_answer_can_be_overridden_from_system_settings(): void
    {
        [$sender] = $this->createChatbotUsers();

        SystemSetting::updateOrCreate(
            ['key' => 'chatbot_app_knowledge_transfer_howto'],
            [
                'value' => json_encode([
                    'patterns' => ['comment envoyer de l argent dans l application'],
                    'reply' => 'Réponse administrateur: allez dans le transfert, saisissez le montant, confirmez puis validez avec OTP.',
                    'buttons' => ['Envoyer de l\'argent', 'Aide'],
                    'knowledge_topic' => 'transfer',
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
                'group' => 'chatbot',
                'description' => 'Override app knowledge test',
                'is_active' => true,
                'is_editable' => true,
                'order' => 41,
            ]
        );

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Comment envoyer de l\'argent dans l\'application ?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'APP_KNOWLEDGE')
            ->assertJsonPath('data.metadata.knowledge_key', 'transfer_howto')
            ->assertJsonPath('data.metadata.knowledge_topic', 'transfer')
            ->assertJsonPath('data.reply', 'Réponse administrateur: allez dans le transfert, saisissez le montant, confirmez puis validez avec OTP.');
    }

    #[Test]
    public function greeting_surfaces_frequent_beneficiary_smart_suggestions(): void
    {
        [$sender, $recipient] = $this->createChatbotUsers();

        $wallet = Wallet::where('user_id', $sender->id)->firstOrFail();

        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $sender->id,
            'amount' => -15000,
            'type' => 'transfer_out',
            'reference' => 'txn_smart_1',
            'description' => 'Transfert chatbot vers Recipient Test (622222221)',
            'metadata' => ['to_user_id' => $recipient->id],
        ]);

        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $sender->id,
            'amount' => -12000,
            'type' => 'transfer_out',
            'reference' => 'txn_smart_2',
            'description' => 'Transfert chatbot vers Recipient Test (622222221)',
            'metadata' => ['to_user_id' => $recipient->id],
        ]);

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'bonjour',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'GREETING')
            ->assertJsonPath('data.metadata.smart_suggestions.0.label', 'Envoyer à Recipient Test')
            ->assertJsonPath('data.metadata.smart_suggestions.0.value', 'Envoyer de l\'argent à Recipient Test');
    }

    #[Test]
    public function successful_transfer_persists_long_term_beneficiary_memory(): void
    {
        $this->mockSmsFailure();
        config(['chatbot.allow_otp_fallback' => true]);

        [$sender, $recipient] = $this->createChatbotUsers();

        $this->actingAs($sender, 'api');

        $this->postJson('/api/v1/chat/message', [
            'message' => 'Je veux envoyer 2000 a ' . $recipient->phone,
        ])->assertOk();

        $this->postJson('/api/v1/chat/message', [
            'message' => 'oui',
        ])->assertOk();

        $sender->refresh();

        $this->postJson('/api/v1/chat/message', [
            'message' => (string) $sender->two_factor_token,
        ])->assertOk();

        $this->assertDatabaseHas('user_assistant_memories', [
            'user_id' => $sender->id,
            'category' => 'frequent_beneficiary',
            'memory_key' => $recipient->id,
        ]);

        $memory = UserAssistantMemory::query()
            ->where('user_id', $sender->id)
            ->where('category', 'frequent_beneficiary')
            ->where('memory_key', $recipient->id)
            ->firstOrFail();

        $this->assertSame('Recipient Test', $memory->payload['display_name']);
    }

    #[Test]
    public function help_response_surfaces_memory_summary_and_automation_suggestions(): void
    {
        [$sender, $recipient] = $this->createChatbotUsers();

        UserAssistantMemory::query()->create([
            'user_id' => $sender->id,
            'category' => 'frequent_beneficiary',
            'memory_key' => $recipient->id,
            'summary' => 'Destinataire fréquent : Recipient Test',
            'payload' => [
                'recipient_id' => $recipient->id,
                'display_name' => 'Recipient Test',
                'phone' => $recipient->phone,
            ],
            'usage_count' => 3,
            'last_used_at' => now(),
        ]);

        $wallet = Wallet::where('user_id', $sender->id)->firstOrFail();
        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $sender->id,
            'amount' => -18000,
            'type' => 'wallet_bill_payment',
            'reference' => 'txn_edg_help_memory_1',
            'description' => 'Paiement facture EDG',
            'metadata' => ['provider' => 'EDG'],
        ]);
        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $sender->id,
            'amount' => -16000,
            'type' => 'wallet_bill_payment',
            'reference' => 'txn_edg_help_memory_2',
            'description' => 'Paiement facture EDG',
            'metadata' => ['provider' => 'EDG'],
        ]);

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'aide',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'HELP')
            ->assertJsonPath('data.metadata.memory_summary.0', 'Vous envoyez souvent de l\'argent à Recipient Test.')
            ->assertJsonPath('data.metadata.automation_suggestions.0', 'Voulez-vous reprendre un transfert vers Recipient Test ?');
    }

    #[Test]
    public function send_confirmation_warns_about_unusual_high_value_recipient(): void
    {
        [$sender, $recipient] = $this->createChatbotUsers();

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Je veux envoyer 250000 a ' . $recipient->phone,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'SEND_MONEY')
            ->assertJsonPath('data.metadata.risk_alert', 'Ce bénéficiaire n\'apparaît pas encore parmi vos contacts fréquents. Vérifiez bien son numéro avant de confirmer.');
    }

    #[Test]
    public function unknown_intent_records_learning_signal_metadata(): void
    {
        [$sender] = $this->createChatbotUsers();

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'blabla opération spatiale',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'UNKNOWN')
            ->assertJsonPath('data.metadata.learning_signal', 'needs_training');
    }

    #[Test]
    public function unknown_general_question_uses_ai_fallback_when_configured(): void
    {
        [$sender] = $this->createChatbotUsers();

        config([
            'services.nimba_ai.enabled' => true,
            'services.nimba_ai.api_key' => 'test-key',
        ]);

        $this->mock(NimbaAiAssistantService::class, function ($mock): void {
            $mock->shouldReceive('answer')
                ->once()
                ->andReturn([
                    'reply' => 'Paris est la capitale de la France.',
                    'provider' => 'chatgpt',
                    'model' => 'fake-gpt',
                    'finish_reason' => 'stop',
                ]);
        });

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Quelle est la capitale de la France ?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'AI_FALLBACK')
            ->assertJsonPath('data.reply', 'Paris est la capitale de la France.')
            ->assertJsonPath('data.metadata.ai_generated', true)
            ->assertJsonPath('data.metadata.ai_provider', 'chatgpt')
            ->assertJsonPath('data.metadata.ai_model', 'fake-gpt')
            ->assertJsonPath('data.metadata.selected_agent.key', 'nimba');
    }

    #[Test]
    public function user_can_select_a_manual_conversational_agent(): void
    {
        [$sender] = $this->createChatbotUsers();

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'bonjour',
            'selected_agent' => 'coach',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'GREETING')
            ->assertJsonPath('data.metadata.selected_agent.key', 'coach')
            ->assertJsonPath('data.metadata.selected_agent.label', 'NIMBA Coach');
    }

    #[Test]
    public function user_default_conversational_agent_is_used_when_no_manual_agent_is_provided(): void
    {
        [$sender] = $this->createChatbotUsers();
        $sender->forceFill(['default_conversational_agent' => 'coach'])->save();

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'bonjour',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'GREETING')
            ->assertJsonPath('data.metadata.selected_agent.key', 'coach')
            ->assertJsonPath('data.metadata.selected_agent.label', 'NIMBA Coach');
    }

    #[Test]
    public function system_setting_can_override_available_agents_and_global_default_agent(): void
    {
        [$sender] = $this->createChatbotUsers();

        SystemSetting::updateOrCreate(
            ['key' => 'chatbot_conversational_agents'],
            [
                'value' => json_encode([
                    [
                        'key' => 'guardian',
                        'label' => 'NIMBA Guardian',
                        'description' => 'Surveille la sécurité et les opérations sensibles.',
                        'system_prompt' => 'Style d agent: très vigilant, focalisé sur sécurité et conformité.',
                    ],
                    [
                        'key' => 'seller',
                        'label' => 'NIMBA Commercial',
                        'description' => 'Met en avant les parcours de vente et les encaissements.',
                        'system_prompt' => 'Style d agent: orienté vente, encaissement et onboarding.',
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'type' => 'json',
                'group' => 'chatbot',
                'description' => 'Catalogue agent test',
                'is_active' => true,
                'is_editable' => true,
                'order' => 47,
            ]
        );

        SystemSetting::updateOrCreate(
            ['key' => 'chatbot_default_conversational_agent'],
            [
                'value' => 'guardian',
                'type' => 'string',
                'group' => 'chatbot',
                'description' => 'Agent par défaut test',
                'is_active' => true,
                'is_editable' => true,
                'order' => 48,
            ]
        );

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'bonjour',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'GREETING')
            ->assertJsonPath('data.metadata.selected_agent.key', 'guardian')
            ->assertJsonPath('data.metadata.available_agents.0.key', 'guardian')
            ->assertJsonPath('data.metadata.available_agents.1.key', 'seller');
    }

    #[Test]
    public function selected_manual_agent_is_forwarded_to_ai_context(): void
    {
        [$sender] = $this->createChatbotUsers();

        config([
            'services.nimba_ai.enabled' => true,
            'services.nimba_ai.api_key' => 'test-key',
        ]);

        $this->mock(NimbaAiAssistantService::class, function ($mock): void {
            $mock->shouldReceive('answer')
                ->once()
                ->withArgs(function ($user, $message, $transcript, $context): bool {
                    return $message === 'Explique moi cette fonction obscure'
                        && ($context['agent_profile']['key'] ?? null) === 'expert'
                        && ($context['agent_profile']['label'] ?? null) === 'NIMBA Expert Paiement';
                })
                ->andReturn([
                    'reply' => 'Je vais vous répondre avec un niveau de détail expert.',
                    'provider' => 'chatgpt',
                    'model' => 'fake-gpt',
                    'finish_reason' => 'stop',
                ]);
        });

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Explique moi cette fonction obscure',
            'selected_agent' => 'expert',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'AI_FALLBACK')
            ->assertJsonPath('data.metadata.selected_agent.key', 'expert');
    }

    #[Test]
    public function app_specific_freeform_question_uses_chatgpt_provider_before_generic_capabilities_reply(): void
    {
        [$sender] = $this->createChatbotUsers();

        config([
            'services.nimba_ai.enabled' => true,
            'services.nimba_ai.api_key' => 'test-key',
            'services.nimba_ai.provider' => 'chatgpt',
            'services.nimba_ai.enable_app_fallback' => true,
        ]);

        $this->mock(NimbaAiAssistantService::class, function ($mock): void {
            $mock->shouldReceive('answer')
                ->once()
                ->andReturn([
                    'reply' => 'Pour changer votre PIN, ouvrez les paramètres de sécurité du compte puis suivez le parcours prévu dans l application.',
                    'provider' => 'chatgpt',
                    'model' => 'fake-gpt',
                    'finish_reason' => 'stop',
                ]);
        });

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Comment activer les notifications dans EdgPay ?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'AI_FALLBACK')
            ->assertJsonPath('data.metadata.ai_provider', 'chatgpt');
    }

    #[Test]
    public function rag_knowledge_base_injects_admin_article_into_ai_prompt(): void
    {
        [$sender] = $this->createChatbotUsers();

        SystemSetting::query()->create([
            'key' => 'chatbot_knowledge_article_notifications',
            'value' => json_encode([
                'title' => 'Notifications EdgPay',
                'topic' => 'settings',
                'patterns' => ['notifications edgpay', 'activer les notifications'],
                'keywords' => ['notifications', 'alertes', 'profil'],
                'content' => 'Les notifications EdgPay se gèrent depuis les paramètres du compte, section Notifications, où l utilisateur peut activer les alertes importantes.',
                'channels' => ['app'],
                'priority' => 0.9,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'type' => 'json',
            'group' => 'chatbot',
            'description' => 'Article test RAG notifications',
            'is_active' => true,
            'is_editable' => true,
            'order' => 1,
        ]);

        config([
            'services.nimba_ai.enabled' => true,
            'services.nimba_ai.api_key' => 'test-key',
            'services.nimba_ai.provider' => 'chatgpt',
            'services.nimba_ai.enable_app_fallback' => true,
            'services.nimba_ai.rag_enabled' => true,
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'model' => 'fake-gpt',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'content' => 'Vous pouvez gérer vos notifications depuis les paramètres du compte.',
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Comment activer les notifications dans EdgPay ?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'AI_FALLBACK')
            ->assertJsonPath('data.metadata.knowledge_references.0.key', 'notifications');

        Http::assertSent(function ($request): bool {
            $messages = $request['messages'] ?? [];
            $serialized = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return is_string($serialized)
                && str_contains($serialized, 'Extraits RAG prioritaires pour cette question')
                && str_contains($serialized, 'Notifications EdgPay')
                && str_contains($serialized, 'section Notifications');
        });
    }

    #[Test]
    public function current_events_question_can_be_grounded_with_web_search_results(): void
    {
        [$sender] = $this->createChatbotUsers();

        config([
            'services.nimba_ai.enabled' => true,
            'services.nimba_ai.api_key' => 'test-key',
            'services.nimba_ai.provider' => 'chatgpt',
            'services.nimba_ai.enable_app_fallback' => true,
            'services.nimba_ai.web_search.enabled' => true,
            'services.nimba_ai.web_search.provider' => 'serper',
            'services.nimba_ai.web_search.base_url' => 'https://google.serper.dev/search',
            'services.nimba_ai.web_search.api_key' => 'serper-test-key',
            'services.nimba_ai.web_search.max_results' => 2,
        ]);

        Http::fake([
            'https://google.serper.dev/*' => Http::response([
                'organic' => [
                    [
                        'title' => 'Iran: latest developments',
                        'link' => 'https://news.example.com/iran-latest',
                        'snippet' => 'Les dernieres informations evoquent une forte tension diplomatique et des appels a la desescalade.',
                        'source' => 'News Example',
                        'date' => '2026-03-15',
                    ],
                    [
                        'title' => 'Regional reactions around Iran',
                        'link' => 'https://analysis.example.com/iran-region',
                        'snippet' => 'Plusieurs pays de la region ont publie des communiques et renforcent la surveillance.',
                        'source' => 'Analysis Example',
                        'date' => '2026-03-15',
                    ],
                ],
            ], 200),
            'https://api.openai.com/*' => Http::response([
                'model' => 'fake-gpt',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'content' => 'Selon des sources web recentes, la situation en Iran reste tendue et evolutive.',
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Quelle est la situation en Iran aujourd hui ?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'AI_FALLBACK')
            ->assertJsonPath('data.metadata.ai_provider', 'chatgpt')
            ->assertJsonPath('data.metadata.web_references.0.source', 'News Example')
            ->assertJsonPath('data.metadata.web_references.0.url', 'https://news.example.com/iran-latest');

        Http::assertSent(function ($request): bool {
            if (!str_contains($request->url(), 'api.openai.com')) {
                return false;
            }

            $messages = $request['messages'] ?? [];
            $serialized = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return is_string($serialized)
                && str_contains($serialized, 'Sources web recentes a utiliser seulement pour les questions d actualite ou de contexte externe')
                && str_contains($serialized, 'Iran: latest developments')
                && str_contains($serialized, 'News Example');
        });
    }

    #[Test]
    public function evergreen_general_question_does_not_trigger_web_search_when_not_current(): void
    {
        [$sender] = $this->createChatbotUsers();

        config([
            'services.nimba_ai.enabled' => true,
            'services.nimba_ai.api_key' => 'test-key',
            'services.nimba_ai.provider' => 'chatgpt',
            'services.nimba_ai.enable_app_fallback' => true,
            'services.nimba_ai.web_search.enabled' => true,
            'services.nimba_ai.web_search.provider' => 'serper',
            'services.nimba_ai.web_search.base_url' => 'https://google.serper.dev/search',
            'services.nimba_ai.web_search.api_key' => 'serper-test-key',
        ]);

        Http::fake([
            'https://google.serper.dev/*' => Http::response([
                'organic' => [],
            ], 200),
            'https://api.openai.com/*' => Http::response([
                'model' => 'fake-gpt',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'content' => 'Conakry est la capitale de la Guinee.',
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Quelle est la capitale de la Guinee ?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'AI_FALLBACK')
            ->assertJsonPath('data.metadata.web_references', []);

        Http::assertNotSent(function ($request): bool {
            return str_contains($request->url(), 'google.serper.dev');
        });
    }

    #[Test]
    public function system_setting_can_disable_web_search_even_when_env_flag_is_enabled(): void
    {
        [$sender] = $this->createChatbotUsers();

        SystemSetting::query()->create([
            'key' => 'chatbot_web_search_enabled',
            'value' => 'false',
            'type' => 'boolean',
            'group' => 'chatbot',
            'description' => 'Toggle recherche web NIMBA',
            'is_active' => true,
            'is_editable' => true,
            'order' => 49,
        ]);

        config([
            'services.nimba_ai.enabled' => true,
            'services.nimba_ai.api_key' => 'test-key',
            'services.nimba_ai.provider' => 'chatgpt',
            'services.nimba_ai.enable_app_fallback' => true,
            'services.nimba_ai.web_search.enabled' => true,
            'services.nimba_ai.web_search.provider' => 'serper',
            'services.nimba_ai.web_search.base_url' => 'https://google.serper.dev/search',
            'services.nimba_ai.web_search.api_key' => 'serper-test-key',
        ]);

        Http::fake([
            'https://google.serper.dev/*' => Http::response([
                'organic' => [[
                    'title' => 'Should not be called',
                    'link' => 'https://news.example.com/unused',
                    'snippet' => 'unused',
                ]],
            ], 200),
            'https://api.openai.com/*' => Http::response([
                'model' => 'fake-gpt',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'content' => 'Je reponds sans recherche web recente.',
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Quelle est la situation en Iran aujourd hui ?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'AI_FALLBACK')
            ->assertJsonPath('data.metadata.web_references', []);

        Http::assertNotSent(function ($request): bool {
            return str_contains($request->url(), 'google.serper.dev');
        });
    }

    #[Test]
    public function system_setting_can_switch_web_search_provider_to_tavily(): void
    {
        [$sender] = $this->createChatbotUsers();

        SystemSetting::query()->create([
            'key' => 'chatbot_web_search_provider',
            'value' => 'tavily',
            'type' => 'string',
            'group' => 'chatbot',
            'description' => 'Provider recherche web NIMBA',
            'is_active' => true,
            'is_editable' => true,
            'order' => 50,
        ]);

        config([
            'services.nimba_ai.enabled' => true,
            'services.nimba_ai.api_key' => 'test-key',
            'services.nimba_ai.provider' => 'chatgpt',
            'services.nimba_ai.enable_app_fallback' => true,
            'services.nimba_ai.web_search.enabled' => true,
            'services.nimba_ai.web_search.provider' => 'serper',
            'services.nimba_ai.web_search.api_key' => 'shared-fallback-key',
            'services.nimba_ai.web_search.providers.serper.api_key' => 'serper-key',
            'services.nimba_ai.web_search.providers.serper.base_url' => 'https://google.serper.dev/search',
            'services.nimba_ai.web_search.providers.tavily.api_key' => 'tavily-key',
            'services.nimba_ai.web_search.providers.tavily.base_url' => 'https://api.tavily.com/search',
        ]);

        Http::fake([
            'https://api.tavily.com/*' => Http::response([
                'results' => [[
                    'title' => 'Iran Tavily Update',
                    'url' => 'https://news.example.com/iran-tavily',
                    'content' => 'Tavily signale une situation toujours evolutive.',
                    'published_date' => '2026-03-16',
                ]],
            ], 200),
            'https://google.serper.dev/*' => Http::response([
                'organic' => [[
                    'title' => 'Should not be used',
                    'link' => 'https://news.example.com/unused-serper',
                    'snippet' => 'unused',
                ]],
            ], 200),
            'https://api.openai.com/*' => Http::response([
                'model' => 'fake-gpt',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'content' => 'Selon des sources web recentes, la situation reste evolutive.',
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Quelle est la situation en Iran aujourd hui ?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'AI_FALLBACK')
            ->assertJsonPath('data.metadata.web_search_provider', 'tavily')
            ->assertJsonPath('data.metadata.web_references.0.title', 'Iran Tavily Update')
            ->assertJsonPath('data.metadata.web_references.0.url', 'https://news.example.com/iran-tavily');

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'api.tavily.com/search');
        });

        Http::assertNotSent(function ($request): bool {
            return str_contains($request->url(), 'google.serper.dev');
        });
    }

    #[Test]
    public function gemini_provider_can_answer_freeform_question_through_real_http_adapter(): void
    {
        [$sender] = $this->createChatbotUsers();

        config([
            'services.nimba_ai.enabled' => true,
            'services.nimba_ai.api_key' => 'gemini-test-key',
            'services.nimba_ai.provider' => 'gemini',
            'services.nimba_ai.base_url' => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent',
            'services.nimba_ai.model' => 'gemini-2.0-flash',
            'services.nimba_ai.enable_app_fallback' => true,
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'modelVersion' => 'gemini-2.0-flash',
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => [
                        'parts' => [[
                            'text' => 'Conakry est la capitale de la Guinée.',
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Quelle est la capitale de la Guinée ?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'AI_FALLBACK')
            ->assertJsonPath('data.reply', 'Conakry est la capitale de la Guinée.')
            ->assertJsonPath('data.metadata.ai_provider', 'gemini')
            ->assertJsonPath('data.metadata.ai_model', 'gemini-2.0-flash')
            ->assertJsonPath('data.metadata.finish_reason', 'stop');

        Http::assertSent(function ($request): bool {
            $body = $request->data();
            $contents = $body['contents'] ?? [];
            $serialized = json_encode($contents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return str_contains($request->url(), 'generativelanguage.googleapis.com')
                && str_contains($request->url(), 'key=gemini-test-key')
                && is_array($contents)
                && is_string($serialized)
                && str_contains($serialized, 'Quelle est la capitale de la Guinée ?');
        });
    }

    #[Test]
    public function selected_gemini_agent_routes_to_gemini_even_if_global_provider_is_chatgpt(): void
    {
        [$sender] = $this->createChatbotUsers();

        config([
            'services.nimba_ai.enabled' => true,
            'services.nimba_ai.provider' => 'chatgpt',
            'services.nimba_ai.api_key' => 'openai-fallback-key',
            'services.nimba_ai.base_url' => 'https://api.openai.com/v1/chat/completions',
            'services.nimba_ai.gemini_base_url' => null,
            'services.nimba_ai.providers.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent',
            'services.nimba_ai.providers.gemini.api_key' => 'gemini-agent-key',
            'services.nimba_ai.providers.gemini.model' => 'gemini-2.0-flash',
            'services.nimba_ai.enable_app_fallback' => true,
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'modelVersion' => 'gemini-2.0-flash',
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => [
                        'parts' => [[
                            'text' => 'Réponse Gemini sélectionnée via agent.',
                        ]],
                    ],
                ]],
            ], 200),
            'https://api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'Réponse OpenAI non attendue.'],
                ]],
            ], 200),
        ]);

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Peux-tu résumer rapidement ce sujet ?',
            'selected_agent' => 'gemini',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'AI_FALLBACK')
            ->assertJsonPath('data.reply', 'Réponse Gemini sélectionnée via agent.')
            ->assertJsonPath('data.metadata.ai_provider', 'gemini')
            ->assertJsonPath('data.metadata.selected_agent.provider', 'gemini')
            ->assertJsonPath('data.metadata.selected_agent.model', 'gemini-2.0-flash');

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'generativelanguage.googleapis.com')
                && str_contains($request->url(), 'key=gemini-agent-key');
        });
    }

    #[Test]
    public function claude_provider_can_answer_freeform_question_through_real_http_adapter(): void
    {
        [$sender] = $this->createChatbotUsers();

        config([
            'services.nimba_ai.enabled' => true,
            'services.nimba_ai.provider' => 'claude',
            'services.nimba_ai.providers.claude.base_url' => 'https://api.anthropic.com/v1/messages',
            'services.nimba_ai.providers.claude.api_key' => 'claude-test-key',
            'services.nimba_ai.providers.claude.model' => 'claude-3-5-sonnet-20241022',
            'services.nimba_ai.providers.claude.version' => '2023-06-01',
            'services.nimba_ai.enable_app_fallback' => true,
        ]);

        Http::fake([
            'https://api.anthropic.com/*' => Http::response([
                'id' => 'msg_test',
                'model' => 'claude-3-5-sonnet-20241022',
                'stop_reason' => 'end_turn',
                'content' => [[
                    'type' => 'text',
                    'text' => 'Claude répond avec une synthèse structurée.',
                ]],
            ], 200),
        ]);

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Donne-moi une réponse structurée.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'AI_FALLBACK')
            ->assertJsonPath('data.reply', 'Claude répond avec une synthèse structurée.')
            ->assertJsonPath('data.metadata.ai_provider', 'claude')
            ->assertJsonPath('data.metadata.ai_model', 'claude-3-5-sonnet-20241022')
            ->assertJsonPath('data.metadata.finish_reason', 'end_turn');

        Http::assertSent(function ($request): bool {
            $body = $request->data();
            $messages = $body['messages'] ?? [];
            $serialized = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return str_contains($request->url(), 'api.anthropic.com/v1/messages')
                && $request->header('x-api-key')[0] === 'claude-test-key'
                && $request->header('anthropic-version')[0] === '2023-06-01'
                && is_string($serialized)
                && str_contains($serialized, 'Donne-moi une réponse structurée.');
        });
    }

    #[Test]
    public function chatbot_still_responds_when_assistant_memory_table_is_missing(): void
    {
        [$sender] = $this->createChatbotUsers();

        Schema::dropIfExists('user_assistant_memories');

        $this->actingAs($sender, 'api');

        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'bonjour',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.intent', 'GREETING');
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