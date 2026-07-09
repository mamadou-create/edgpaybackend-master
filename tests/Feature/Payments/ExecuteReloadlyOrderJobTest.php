<?php

namespace Tests\Feature\Payments;

use App\Interfaces\ReloadlyServiceInterface;
use App\Jobs\ExecuteReloadlyOrderJob;
use App\Notifications\ReloadlyOrderStatusNotification;
use App\Models\AirtimeOrder;
use App\Models\DataOrder;
use App\Models\PaymentTransaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ExecuteReloadlyOrderJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_processes_airtime_order_successfully_when_payment_is_confirmed(): void
    {
        Notification::fake();

        [$paymentTx, $airtimeOrder] = $this->createConfirmedAirtimeOrder();
        $user = User::query()->findOrFail($paymentTx->user_id);

        $reloadly = new class implements ReloadlyServiceInterface {
            public function authenticate(): array
            {
                return ['success' => true, 'status' => 200, 'data' => ['access_token' => 'x']];
            }

            public function detectOperator(string $phone, string $countryCode = 'GN'): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }

            public function getDataPlans(int $operatorId, ?string $recipientPhone = null): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }

            public function topupAirtime(array $payload): array
            {
                return [
                    'success' => true,
                    'status' => 200,
                    'data' => ['transactionId' => 'rl-airtime-123'],
                ];
            }

            public function topupData(array $payload): array
            {
                return ['success' => true, 'status' => 200, 'data' => ['transactionId' => 'rl-data-123']];
            }

            public function getPromotions(int $operatorId): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }

            public function getCommissions(?int $operatorId = null): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }

            public function verifyTransaction(int|string $reloadlyTransactionId): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }
        };

        $job = new ExecuteReloadlyOrderJob($paymentTx->id);
        $job->handle($reloadly);

        $airtimeOrder->refresh();
        $paymentTx->refresh();
        $transaction = Transaction::query()->findOrFail($paymentTx->transaction_id);

        $this->assertSame('SUCCESS', (string) $airtimeOrder->status);
        $this->assertSame('rl-airtime-123', (string) $airtimeOrder->reloadly_transaction_id);
        $this->assertNotNull($airtimeOrder->delivered_at);
        $this->assertSame('CONFIRMED', (string) $paymentTx->status);
        $this->assertSame('SUCCESS', (string) $transaction->status);

        Notification::assertSentTo($user, ReloadlyOrderStatusNotification::class, function ($notification) {
            $data = $notification->toArray(new \stdClass());
            return ($data['status'] ?? null) === 'SUCCESS' && ($data['order_type'] ?? null) === 'AIRTIME';
        });
    }

    #[Test]
    public function it_retries_airtime_order_on_transient_reloadly_error(): void
    {
        [$paymentTx, $airtimeOrder] = $this->createConfirmedAirtimeOrder();

        $reloadly = new class implements ReloadlyServiceInterface {
            public function authenticate(): array
            {
                return ['success' => true, 'status' => 200, 'data' => ['access_token' => 'x']];
            }

            public function detectOperator(string $phone, string $countryCode = 'GN'): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }

            public function getDataPlans(int $operatorId, ?string $recipientPhone = null): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }

            public function topupAirtime(array $payload): array
            {
                return [
                    'success' => false,
                    'status' => 503,
                    'business_code' => 'RELOADLY_PROVIDER_ERROR',
                    'message' => 'Provider unavailable',
                    'data' => [],
                ];
            }

            public function topupData(array $payload): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }

            public function getPromotions(int $operatorId): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }

            public function getCommissions(?int $operatorId = null): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }

            public function verifyTransaction(int|string $reloadlyTransactionId): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }
        };

        $job = new ExecuteReloadlyOrderJob($paymentTx->id);

        $this->expectException(RuntimeException::class);
        $job->handle($reloadly);

        $airtimeOrder->refresh();
        $this->assertSame('PENDING', (string) $airtimeOrder->status);
        $this->assertSame('RELOADLY_PROVIDER_ERROR', (string) $airtimeOrder->error_code);
    }

    #[Test]
    public function it_marks_data_order_failed_without_retry_on_business_error(): void
    {
        Notification::fake();

        [$paymentTx, $dataOrder] = $this->createConfirmedDataOrder();
        $user = User::query()->findOrFail($paymentTx->user_id);

        $reloadly = new class implements ReloadlyServiceInterface {
            public function authenticate(): array
            {
                return ['success' => true, 'status' => 200, 'data' => ['access_token' => 'x']];
            }

            public function detectOperator(string $phone, string $countryCode = 'GN'): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }

            public function getDataPlans(int $operatorId, ?string $recipientPhone = null): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }

            public function topupAirtime(array $payload): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }

            public function topupData(array $payload): array
            {
                return [
                    'success' => false,
                    'status' => 422,
                    'business_code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid bundle',
                    'data' => [],
                ];
            }

            public function getPromotions(int $operatorId): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }

            public function getCommissions(?int $operatorId = null): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }

            public function verifyTransaction(int|string $reloadlyTransactionId): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }
        };

        $job = new ExecuteReloadlyOrderJob($paymentTx->id);
        $job->handle($reloadly);

        $dataOrder->refresh();
        $this->assertSame('FAILED', (string) $dataOrder->status);
        $this->assertSame('VALIDATION_ERROR', (string) $dataOrder->error_code);
        $this->assertNull($dataOrder->delivered_at);

        Notification::assertSentTo($user, ReloadlyOrderStatusNotification::class, function ($notification) {
            $data = $notification->toArray(new \stdClass());
            return ($data['status'] ?? null) === 'FAILED' && ($data['order_type'] ?? null) === 'DATA';
        });
    }

    #[Test]
    public function it_skips_when_payment_is_not_confirmed(): void
    {
        [$paymentTx, $airtimeOrder] = $this->createConfirmedAirtimeOrder();
        $paymentTx->status = 'PENDING';
        $paymentTx->save();

        $reloadly = new class implements ReloadlyServiceInterface {
            public function authenticate(): array
            {
                return ['success' => true, 'status' => 200, 'data' => ['access_token' => 'x']];
            }

            public function detectOperator(string $phone, string $countryCode = 'GN'): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }

            public function getDataPlans(int $operatorId, ?string $recipientPhone = null): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }

            public function topupAirtime(array $payload): array
            {
                return [
                    'success' => true,
                    'status' => 200,
                    'data' => ['transactionId' => 'should-not-run'],
                ];
            }

            public function topupData(array $payload): array
            {
                return ['success' => true, 'status' => 200, 'data' => ['transactionId' => 'should-not-run']];
            }

            public function getPromotions(int $operatorId): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }

            public function getCommissions(?int $operatorId = null): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }

            public function verifyTransaction(int|string $reloadlyTransactionId): array
            {
                return ['success' => true, 'status' => 200, 'data' => []];
            }
        };

        $job = new ExecuteReloadlyOrderJob($paymentTx->id);
        $job->handle($reloadly);

        $airtimeOrder->refresh();
        $this->assertSame('PENDING', (string) $airtimeOrder->status);
        $this->assertNull($airtimeOrder->reloadly_transaction_id);
    }

    private function createConfirmedAirtimeOrder(): array
    {
        $user = User::factory()->create([
            'status' => true,
            'email_verified_at' => now(),
        ]);

        $transaction = Transaction::create([
            'user_id' => $user->id,
            'reference' => 'TRX-' . strtoupper(bin2hex(random_bytes(4))),
            'external_reference' => 'PAY-' . strtoupper(bin2hex(random_bytes(4))),
            'type' => 'AIRTIME_PURCHASE',
            'direction' => 'DEBIT',
            'status' => 'SUCCESS',
            'amount' => 10000,
            'currency' => 'GNF',
            'provider' => 'ORANGE',
            'provider_status' => 'CONFIRMED',
        ]);

        $paymentTx = PaymentTransaction::create([
            'transaction_id' => $transaction->id,
            'user_id' => $user->id,
            'provider' => 'ORANGE',
            'channel' => 'MOBILE_MONEY',
            'payment_reference' => 'PAYREF-' . strtoupper(bin2hex(random_bytes(4))),
            'merchant_reference' => $transaction->reference,
            'msisdn' => '622111111',
            'amount' => 10000,
            'currency' => 'GNF',
            'status' => 'CONFIRMED',
            'confirmation_status' => 'CONFIRMED',
            'webhook_verified' => true,
            'webhook_verified_at' => now(),
            'paid_at' => now(),
        ]);

        $order = AirtimeOrder::create([
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'payment_transaction_id' => $paymentTx->id,
            'operator_id' => '201',
            'operator_name' => 'Orange Guinea',
            'recipient_msisdn' => '622111111',
            'country_code' => 'GN',
            'amount' => 10000,
            'local_amount' => 10000,
            'local_currency' => 'GNF',
            'status' => 'PENDING',
            'metadata' => [
                'custom_identifier' => $paymentTx->payment_reference,
            ],
        ]);

        return [$paymentTx, $order];
    }

    private function createConfirmedDataOrder(): array
    {
        $user = User::factory()->create([
            'status' => true,
            'email_verified_at' => now(),
        ]);

        $transaction = Transaction::create([
            'user_id' => $user->id,
            'reference' => 'TRX-' . strtoupper(bin2hex(random_bytes(4))),
            'external_reference' => 'PAY-' . strtoupper(bin2hex(random_bytes(4))),
            'type' => 'DATA_PURCHASE',
            'direction' => 'DEBIT',
            'status' => 'SUCCESS',
            'amount' => 20000,
            'currency' => 'GNF',
            'provider' => 'ORANGE',
            'provider_status' => 'CONFIRMED',
        ]);

        $paymentTx = PaymentTransaction::create([
            'transaction_id' => $transaction->id,
            'user_id' => $user->id,
            'provider' => 'ORANGE',
            'channel' => 'MOBILE_MONEY',
            'payment_reference' => 'PAYREF-' . strtoupper(bin2hex(random_bytes(4))),
            'merchant_reference' => $transaction->reference,
            'msisdn' => '622222222',
            'amount' => 20000,
            'currency' => 'GNF',
            'status' => 'CONFIRMED',
            'confirmation_status' => 'CONFIRMED',
            'webhook_verified' => true,
            'webhook_verified_at' => now(),
            'paid_at' => now(),
        ]);

        $order = DataOrder::create([
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'payment_transaction_id' => $paymentTx->id,
            'operator_id' => '201',
            'operator_name' => 'Orange Guinea',
            'recipient_msisdn' => '622222222',
            'country_code' => 'GN',
            'data_plan_id' => 'bundle-1',
            'data_plan_name' => 'Weekly 2GB',
            'amount' => 20000,
            'local_amount' => 20000,
            'local_currency' => 'GNF',
            'status' => 'PENDING',
            'metadata' => [
                'custom_identifier' => $paymentTx->payment_reference,
            ],
        ]);

        return [$paymentTx, $order];
    }
}
