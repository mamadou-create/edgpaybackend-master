<?php

namespace Tests\Feature\Payments;

use App\Jobs\ExecuteReloadlyOrderJob;
use App\Models\AirtimeOrder;
use App\Models\PaymentTransaction;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentOrchestrationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function airtime_intent_requires_idempotency_and_blocks_duplicates(): void
    {
        $user = $this->createAuthenticatedUser();

        $payload = [
            'payment_provider' => 'ORANGE',
            'payment_channel' => 'MOBILE_MONEY',
            'payer_msisdn' => '622123456',
            'recipient_phone' => '622123456',
            'recipient_country_code' => 'GN',
            'operator_id' => 201,
            'operator_name' => 'Orange Guinea',
            'amount' => 15000,
            'currency' => 'GNF',
            'expires_in_minutes' => 15,
        ];

        $headers = [
            'X-Idempotency-Key' => 'intent-key-000001',
            'X-Correlation-ID' => 'corr-intent-1',
        ];

        $this->actingAs($user, 'api');

        $first = $this->withHeaders($headers)->postJson('/api/v1/purchase/airtime/intent', $payload);
        $first
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'PENDING_PAYMENT');

        $second = $this->withHeaders($headers)->postJson('/api/v1/purchase/airtime/intent', $payload);
        $second
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('business_code', 'IDEMPOTENT_REPLAY_DETECTED');
    }

    #[Test]
    public function payment_webhook_with_invalid_signature_is_rejected_and_no_job_dispatched(): void
    {
        Queue::fake();

        $intent = $this->createAirtimeIntent();
        $paymentReference = (string) $intent['payment_reference'];

        config([
            'services.mobile_money.providers.ORANGE.webhook_secret' => 'orange-secret-test',
            'services.mobile_money.allow_unsigned_local' => false,
        ]);

        $payload = [
            'event_id' => 'evt-invalid-signature-1',
            'status' => 'SUCCESS',
            'payment_reference' => $paymentReference,
            'provider_payment_id' => 'prov-001',
        ];

        $response = $this->withHeaders([
            'X-Signature' => 'sha256=deadbeef',
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/webhook/payments/orange', $payload);

        $response
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('business_code', 'INVALID_WEBHOOK_SIGNATURE');

        $paymentTx = PaymentTransaction::query()->where('payment_reference', $paymentReference)->firstOrFail();
        $this->assertSame('PENDING', (string) $paymentTx->status);
        $this->assertFalse((bool) $paymentTx->webhook_verified);

        Queue::assertNotPushed(ExecuteReloadlyOrderJob::class);
    }

    #[Test]
    public function payment_webhook_confirmed_dispatches_reloadly_job_and_updates_statuses(): void
    {
        Queue::fake();

        $intent = $this->createAirtimeIntent();
        $paymentReference = (string) $intent['payment_reference'];

        config([
            'services.mobile_money.providers.ORANGE.webhook_secret' => 'orange-secret-test',
            'services.mobile_money.allow_unsigned_local' => false,
        ]);

        $payload = [
            'event_id' => 'evt-confirmed-1',
            'status' => 'SUCCESS',
            'payment_reference' => $paymentReference,
            'provider_payment_id' => 'prov-002',
        ];

        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = 'sha256=' . hash_hmac('sha256', (string) $raw, 'orange-secret-test');

        $response = $this->call(
            'POST',
            '/api/v1/webhook/payments/ORANGE',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $signature,
            ],
            $raw
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.reload_job_dispatched', true);

        $paymentTx = PaymentTransaction::query()->where('payment_reference', $paymentReference)->firstOrFail();
        $this->assertSame('CONFIRMED', (string) $paymentTx->status);
        $this->assertTrue((bool) $paymentTx->webhook_verified);

        $transaction = Transaction::query()->findOrFail($paymentTx->transaction_id);
        $this->assertSame('SUCCESS', (string) $transaction->status);

        Queue::assertPushed(ExecuteReloadlyOrderJob::class);
    }

    private function createAuthenticatedUser(): User
    {
        $role = Role::query()->updateOrCreate(
            ['slug' => 'client'],
            [
                'name' => 'Client',
                'description' => 'Role client test',
                'is_super_admin' => false,
            ]
        );

        return User::factory()->create([
            'role_id' => $role->id,
            'is_pro' => false,
            'status' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function createAirtimeIntent(): array
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user, 'api');

        $payload = [
            'payment_provider' => 'ORANGE',
            'payment_channel' => 'MOBILE_MONEY',
            'payer_msisdn' => '622999999',
            'recipient_phone' => '622999999',
            'recipient_country_code' => 'GN',
            'operator_id' => 201,
            'operator_name' => 'Orange Guinea',
            'amount' => 10000,
            'currency' => 'GNF',
            'expires_in_minutes' => 15,
        ];

        $response = $this->withHeaders([
            'X-Idempotency-Key' => 'intent-key-' . uniqid('', true),
            'X-Correlation-ID' => 'corr-intent-' . uniqid(),
        ])->postJson('/api/v1/purchase/airtime/intent', $payload);

        $response->assertStatus(201);

        $paymentReference = (string) $response->json('data.payment_reference');
        $paymentTx = PaymentTransaction::query()->where('payment_reference', $paymentReference)->firstOrFail();
        AirtimeOrder::query()->where('payment_transaction_id', $paymentTx->id)->firstOrFail();

        return [
            'payment_reference' => $paymentReference,
            'payment_transaction_id' => $paymentTx->id,
        ];
    }
}
