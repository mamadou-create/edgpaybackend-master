<?php

namespace Tests\Feature\Payments;

use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use App\Jobs\ExecuteReloadlyOrderJob;
use App\Models\AirtimeOrder;
use App\Models\ApiLog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentOpsApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function health_endpoint_returns_overview_for_authorized_admin(): void
    {
        $admin = $this->createOpsAdmin();

        $this->actingAs($admin, 'api');
        $response = $this->getJson('/api/v1/ops/payments/health');

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'period',
                    'webhooks',
                    'payments',
                    'orders',
                    'sla',
                ],
            ]);
    }

    #[Test]
    public function replay_endpoint_rejects_non_confirmed_payment_transactions(): void
    {
        $admin = $this->createOpsAdmin();

        $transaction = Transaction::create([
            'reference' => 'TRX-OPS-1',
            'external_reference' => 'PAY-OPS-1',
            'type' => 'AIRTIME_PURCHASE',
            'direction' => 'DEBIT',
            'status' => 'PENDING',
            'amount' => 10000,
            'currency' => 'GNF',
            'provider' => 'ORANGE',
            'provider_status' => 'PENDING',
        ]);

        $paymentTx = PaymentTransaction::create([
            'transaction_id' => $transaction->id,
            'provider' => 'ORANGE',
            'channel' => 'MOBILE_MONEY',
            'payment_reference' => 'PAYREF-OPS-1',
            'merchant_reference' => 'TRX-OPS-1',
            'msisdn' => '622444444',
            'amount' => 10000,
            'currency' => 'GNF',
            'status' => 'PENDING',
            'confirmation_status' => 'UNCONFIRMED',
        ]);

        $this->actingAs($admin, 'api');
        $response = $this->withHeaders([
            'X-Idempotency-Key' => 'ops-replay-1',
        ])->postJson('/api/v1/ops/payments/replay/' . $paymentTx->id);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('business_code', 'PAYMENT_NOT_CONFIRMED');
    }

    #[Test]
    public function failures_endpoint_supports_pagination_and_date_filters(): void
    {
        $admin = $this->createOpsAdmin();

        $user = User::factory()->create();
        $transactionA = Transaction::create([
            'user_id' => $user->id,
            'reference' => 'TRX-OPS-F-1',
            'external_reference' => 'PAY-OPS-F-1',
            'type' => 'AIRTIME_PURCHASE',
            'direction' => 'DEBIT',
            'status' => 'SUCCESS',
            'amount' => 10000,
            'currency' => 'GNF',
            'provider' => 'ORANGE',
            'provider_status' => 'CONFIRMED',
        ]);

        $paymentA = PaymentTransaction::create([
            'transaction_id' => $transactionA->id,
            'user_id' => $user->id,
            'provider' => 'ORANGE',
            'channel' => 'MOBILE_MONEY',
            'payment_reference' => 'PAYREF-OPS-F-1',
            'merchant_reference' => 'TRX-OPS-F-1',
            'msisdn' => '622400001',
            'amount' => 10000,
            'currency' => 'GNF',
            'status' => 'CONFIRMED',
            'confirmation_status' => 'CONFIRMED',
        ]);

        $orderA = AirtimeOrder::create([
            'user_id' => $user->id,
            'transaction_id' => $transactionA->id,
            'payment_transaction_id' => $paymentA->id,
            'operator_id' => '201',
            'operator_name' => 'Orange Guinea',
            'recipient_msisdn' => '622400001',
            'country_code' => 'GN',
            'amount' => 10000,
            'local_amount' => 10000,
            'local_currency' => 'GNF',
            'status' => 'FAILED',
            'error_code' => 'ERR_1',
        ]);
        $orderA->updated_at = now()->subHours(3);
        $orderA->save();

        $transactionB = Transaction::create([
            'user_id' => $user->id,
            'reference' => 'TRX-OPS-F-2',
            'external_reference' => 'PAY-OPS-F-2',
            'type' => 'AIRTIME_PURCHASE',
            'direction' => 'DEBIT',
            'status' => 'SUCCESS',
            'amount' => 11000,
            'currency' => 'GNF',
            'provider' => 'MTN',
            'provider_status' => 'CONFIRMED',
        ]);

        $paymentB = PaymentTransaction::create([
            'transaction_id' => $transactionB->id,
            'user_id' => $user->id,
            'provider' => 'MTN',
            'channel' => 'MOBILE_MONEY',
            'payment_reference' => 'PAYREF-OPS-F-2',
            'merchant_reference' => 'TRX-OPS-F-2',
            'msisdn' => '622400002',
            'amount' => 11000,
            'currency' => 'GNF',
            'status' => 'CONFIRMED',
            'confirmation_status' => 'CONFIRMED',
        ]);

        $orderB = AirtimeOrder::create([
            'user_id' => $user->id,
            'transaction_id' => $transactionB->id,
            'payment_transaction_id' => $paymentB->id,
            'operator_id' => '202',
            'operator_name' => 'MTN Guinea',
            'recipient_msisdn' => '622400002',
            'country_code' => 'GN',
            'amount' => 11000,
            'local_amount' => 11000,
            'local_currency' => 'GNF',
            'status' => 'FAILED',
            'error_code' => 'ERR_2',
        ]);
        $orderB->updated_at = now()->subMinutes(30);
        $orderB->save();

        $this->actingAs($admin, 'api');
        $response = $this->getJson('/api/v1/ops/payments/failures?order_type=AIRTIME&per_page=1&page=1&from=' . urlencode(now()->subHour()->toIso8601String()));

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.meta.per_page', 1)
            ->assertJsonPath('data.meta.page', 1)
            ->assertJsonPath('data.meta.order_type', 'AIRTIME')
            ->assertJsonCount(1, 'data.items');
    }

    #[Test]
    public function replay_batch_endpoint_applies_dry_run_and_safety_cap(): void
    {
        Queue::fake();

        $admin = $this->createOpsAdmin();
        $user = User::factory()->create();

        $transaction = Transaction::create([
            'user_id' => $user->id,
            'reference' => 'TRX-OPS-B-1',
            'external_reference' => 'PAY-OPS-B-1',
            'type' => 'AIRTIME_PURCHASE',
            'direction' => 'DEBIT',
            'status' => 'SUCCESS',
            'amount' => 12000,
            'currency' => 'GNF',
            'provider' => 'ORANGE',
            'provider_status' => 'CONFIRMED',
        ]);

        $paymentTx = PaymentTransaction::create([
            'transaction_id' => $transaction->id,
            'user_id' => $user->id,
            'provider' => 'ORANGE',
            'channel' => 'MOBILE_MONEY',
            'payment_reference' => 'PAYREF-OPS-B-1',
            'merchant_reference' => 'TRX-OPS-B-1',
            'msisdn' => '622499991',
            'amount' => 12000,
            'currency' => 'GNF',
            'status' => 'CONFIRMED',
            'confirmation_status' => 'CONFIRMED',
        ]);

        AirtimeOrder::create([
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'payment_transaction_id' => $paymentTx->id,
            'operator_id' => '201',
            'operator_name' => 'Orange Guinea',
            'recipient_msisdn' => '622499991',
            'country_code' => 'GN',
            'amount' => 12000,
            'local_amount' => 12000,
            'local_currency' => 'GNF',
            'status' => 'FAILED',
            'error_code' => 'ERR_BATCH',
        ]);

        $this->actingAs($admin, 'api');
        $response = $this->withHeaders([
            'X-Idempotency-Key' => 'ops-replay-batch-1',
        ])->postJson('/api/v1/ops/payments/replay-batch', [
            'type' => 'AIRTIME',
            'limit' => 500,
            'dry_run' => true,
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.max_allowed', 100)
            ->assertJsonPath('data.effective_limit', 100)
            ->assertJsonPath('data.dispatched_count', 0);

        $log = ApiLog::query()->where('service', 'ops-payments-replay-batch')->latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('replay_batch', $log->request_body['action'] ?? null);
        $this->assertSame(true, $log->request_body['dry_run'] ?? null);
        $this->assertSame(0, $log->response_body['volume']['dispatched_count'] ?? null);

        Queue::assertNotPushed(ExecuteReloadlyOrderJob::class);
    }

    #[Test]
    public function replay_batch_endpoint_requires_explicit_confirmation_when_not_dry_run(): void
    {
        Queue::fake();

        $admin = $this->createOpsAdmin();

        $this->actingAs($admin, 'api');
        $response = $this->withHeaders([
            'X-Idempotency-Key' => 'ops-replay-batch-no-confirm',
        ])->postJson('/api/v1/ops/payments/replay-batch', [
            'type' => 'AIRTIME',
            'limit' => 10,
            'dry_run' => false,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('business_code', 'BATCH_CONFIRMATION_REQUIRED');

        Queue::assertNotPushed(ExecuteReloadlyOrderJob::class);
    }

    #[Test]
    public function failures_export_endpoint_returns_csv_content(): void
    {
        $admin = $this->createOpsAdmin();
        $user = User::factory()->create();

        $transaction = Transaction::create([
            'user_id' => $user->id,
            'reference' => 'TRX-OPS-E-1',
            'external_reference' => 'PAY-OPS-E-1',
            'type' => 'AIRTIME_PURCHASE',
            'direction' => 'DEBIT',
            'status' => 'SUCCESS',
            'amount' => 13000,
            'currency' => 'GNF',
            'provider' => 'ORANGE',
            'provider_status' => 'CONFIRMED',
        ]);

        $paymentTx = PaymentTransaction::create([
            'transaction_id' => $transaction->id,
            'user_id' => $user->id,
            'provider' => 'ORANGE',
            'channel' => 'MOBILE_MONEY',
            'payment_reference' => 'PAYREF-OPS-E-1',
            'merchant_reference' => 'TRX-OPS-E-1',
            'msisdn' => '622488881',
            'amount' => 13000,
            'currency' => 'GNF',
            'status' => 'CONFIRMED',
            'confirmation_status' => 'CONFIRMED',
        ]);

        AirtimeOrder::create([
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'payment_transaction_id' => $paymentTx->id,
            'operator_id' => '201',
            'operator_name' => 'Orange Guinea',
            'recipient_msisdn' => '622488881',
            'country_code' => 'GN',
            'amount' => 13000,
            'local_amount' => 13000,
            'local_currency' => 'GNF',
            'status' => 'FAILED',
            'error_code' => 'ERR_EXPORT',
            'error_message' => 'Provider timeout',
        ]);

        $this->actingAs($admin, 'api');
        $response = $this->get('/api/v1/ops/payments/failures/export?order_type=AIRTIME&limit=100');

        $response->assertStatus(200);
        $content = $response->streamedContent();

        $this->assertStringContainsString('order_type,order_id,payment_transaction_id,error_code,error_message,updated_at', $content);
        $this->assertStringContainsString('AIRTIME', $content);
        $this->assertStringContainsString('ERR_EXPORT', $content);

        $log = ApiLog::query()->where('service', 'ops-payments-failures-export')->latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('export_failures_csv', $log->request_body['action'] ?? null);
        $this->assertSame('AIRTIME', $log->request_body['order_type'] ?? null);
        $this->assertGreaterThan(0, (int) ($log->response_body['volume']['rows'] ?? 0));
    }

    #[Test]
    public function logs_endpoint_returns_paginated_ops_logs(): void
    {
        $admin = $this->createOpsAdmin();

        ApiLog::create([
            'service' => 'ops-payments-replay-batch',
            'endpoint' => 'api/v1/ops/payments/replay-batch',
            'method' => 'POST',
            'status_code' => 202,
            'correlation_id' => 'corr-ops-1',
            'idempotency_key' => 'idem-ops-1',
            'request_ip' => '127.0.0.1',
            'request_headers' => ['x-test' => ['1']],
            'request_body' => ['action' => 'replay_batch'],
            'response_body' => ['volume' => ['selected_count' => 1, 'dispatched_count' => 0]],
            'user_id' => $admin->id,
        ]);

        ApiLog::create([
            'service' => 'ops-payments-failures-export',
            'endpoint' => 'api/v1/ops/payments/failures/export',
            'method' => 'GET',
            'status_code' => 200,
            'correlation_id' => 'corr-ops-2',
            'idempotency_key' => null,
            'request_ip' => '127.0.0.1',
            'request_headers' => ['x-test' => ['2']],
            'request_body' => ['action' => 'export_failures_csv'],
            'response_body' => ['volume' => ['rows' => 2]],
            'user_id' => $admin->id,
        ]);

        ApiLog::create([
            'service' => 'reloadly-auth',
            'endpoint' => '/oauth/token',
            'method' => 'POST',
            'status_code' => 200,
            'correlation_id' => 'corr-non-ops',
            'request_ip' => '127.0.0.1',
            'request_headers' => [],
            'request_body' => [],
            'response_body' => [],
            'user_id' => $admin->id,
        ]);

        $this->actingAs($admin, 'api');
        $response = $this->getJson('/api/v1/ops/payments/logs?per_page=1&page=1');

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.meta.per_page', 1)
            ->assertJsonPath('data.meta.page', 1)
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonCount(1, 'data.items');
    }

    #[Test]
    public function logs_endpoint_supports_filters_by_service_and_correlation_id(): void
    {
        $admin = $this->createOpsAdmin();

        ApiLog::create([
            'service' => 'ops-payments-replay-batch',
            'endpoint' => 'api/v1/ops/payments/replay-batch',
            'method' => 'POST',
            'status_code' => 422,
            'correlation_id' => 'corr-filtered',
            'idempotency_key' => 'idem-filtered',
            'request_ip' => '127.0.0.1',
            'request_headers' => [],
            'request_body' => ['action' => 'replay_batch'],
            'response_body' => ['business_code' => 'BATCH_CONFIRMATION_REQUIRED'],
            'user_id' => $admin->id,
        ]);

        ApiLog::create([
            'service' => 'ops-payments-failures-export',
            'endpoint' => 'api/v1/ops/payments/failures/export',
            'method' => 'GET',
            'status_code' => 200,
            'correlation_id' => 'corr-other',
            'request_ip' => '127.0.0.1',
            'request_headers' => [],
            'request_body' => ['action' => 'export_failures_csv'],
            'response_body' => ['volume' => ['rows' => 1]],
            'user_id' => $admin->id,
        ]);

        $this->actingAs($admin, 'api');
        $response = $this->getJson('/api/v1/ops/payments/logs?service=ops-payments-replay-batch&correlation_id=corr-filtered');

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.service', 'ops-payments-replay-batch')
            ->assertJsonPath('data.items.0.correlation_id', 'corr-filtered')
            ->assertJsonPath('data.items.0.idempotency_key', 'idem-fil****');
    }

    #[Test]
    public function logs_endpoint_masks_sensitive_fields_in_list_items(): void
    {
        $admin = $this->createOpsAdmin();

        ApiLog::create([
            'service' => 'ops-payments-replay-batch',
            'endpoint' => 'api/v1/ops/payments/replay-batch',
            'method' => 'POST',
            'status_code' => 202,
            'correlation_id' => 'corr-mask-list',
            'idempotency_key' => 'idem-mask-list',
            'request_ip' => '127.0.0.1',
            'request_headers' => [
                'authorization' => ['Bearer list-secret-token'],
            ],
            'request_body' => [
                'action' => 'replay_batch',
                'client_secret' => 'list-ultra-secret',
            ],
            'response_body' => ['ok' => true],
            'user_id' => $admin->id,
        ]);

        $this->actingAs($admin, 'api');
        $response = $this->getJson('/api/v1/ops/payments/logs?service=ops-payments-replay-batch&correlation_id=corr-mask-list');

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.request_headers.authorization', '***REDACTED***')
            ->assertJsonPath('data.items.0.request_body.client_secret', '***REDACTED***')
            ->assertJsonPath('data.items.0.idempotency_key', 'idem-mas****');
    }

    #[Test]
    public function log_details_endpoint_masks_sensitive_fields(): void
    {
        $admin = $this->createOpsAdmin();

        $log = ApiLog::create([
            'service' => 'ops-payments-replay-batch',
            'endpoint' => 'api/v1/ops/payments/replay-batch',
            'method' => 'POST',
            'status_code' => 202,
            'correlation_id' => 'corr-sensitive',
            'idempotency_key' => 'idem-sensitive',
            'request_ip' => '127.0.0.1',
            'request_headers' => [
                'authorization' => ['Bearer super-secret-token'],
                'x-correlation-id' => ['corr-sensitive'],
            ],
            'request_body' => [
                'action' => 'replay_batch',
                'client_secret' => 'ultra-secret',
                'nested' => [
                    'access_token' => 'very-sensitive',
                    'safe_value' => 'ok',
                ],
            ],
            'response_body' => ['volume' => ['selected_count' => 2]],
            'user_id' => $admin->id,
        ]);

        $this->actingAs($admin, 'api');
        $response = $this->getJson('/api/v1/ops/payments/logs/' . $log->id);

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.request_headers.authorization', '***REDACTED***')
            ->assertJsonPath('data.request_body.client_secret', '***REDACTED***')
            ->assertJsonPath('data.request_body.nested.access_token', '***REDACTED***')
            ->assertJsonPath('data.request_body.nested.safe_value', 'ok')
            ->assertJsonPath('data.idempotency_key', 'idem-sen****');
    }

    #[Test]
    public function log_details_endpoint_returns_not_found_for_missing_id(): void
    {
        $admin = $this->createOpsAdmin();

        $this->actingAs($admin, 'api');
        $response = $this->getJson('/api/v1/ops/payments/logs/11111111-1111-1111-1111-111111111111');

        $response
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('business_code', 'OPS_LOG_NOT_FOUND');
    }

    private function createOpsAdmin(): User
    {
        $permission = Permission::query()->updateOrCreate(
            ['slug' => 'credits.manage'],
            [
                'name' => 'Manage credits',
                'module' => 'Credit',
                'description' => 'Ops permission for tests',
            ]
        );

        $role = Role::query()->create([
            'name' => 'Ops Admin',
            'slug' => 'ops_admin_test',
            'description' => 'Ops admin role for tests',
            'is_super_admin' => false,
        ]);

        $role->permissions()->attach($permission->id, ['access_level' => 'oui']);

        return User::factory()->create([
            'role_id' => $role->id,
            'status' => true,
            'email_verified_at' => now(),
        ]);
    }
}
