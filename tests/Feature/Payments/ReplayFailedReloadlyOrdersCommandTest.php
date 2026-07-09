<?php

namespace Tests\Feature\Payments;

use App\Jobs\ExecuteReloadlyOrderJob;
use App\Models\AirtimeOrder;
use App\Models\PaymentTransaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReplayFailedReloadlyOrdersCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function command_dispatches_replay_for_failed_airtime_with_confirmed_payment(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $transaction = Transaction::create([
            'user_id' => $user->id,
            'reference' => 'TRX-CMD-1',
            'external_reference' => 'PAY-CMD-1',
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
            'payment_reference' => 'PAYREF-CMD-1',
            'merchant_reference' => 'TRX-CMD-1',
            'msisdn' => '622333333',
            'amount' => 10000,
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
            'recipient_msisdn' => '622333333',
            'country_code' => 'GN',
            'amount' => 10000,
            'local_amount' => 10000,
            'local_currency' => 'GNF',
            'status' => 'FAILED',
            'error_code' => 'RELOADLY_PROVIDER_ERROR',
        ]);

        $this->artisan('payments:replay-failed-reloadly --type=airtime --limit=10')
            ->assertSuccessful();

        Queue::assertPushed(ExecuteReloadlyOrderJob::class, 1);
    }

    #[Test]
    public function command_dry_run_does_not_dispatch_jobs(): void
    {
        Queue::fake();

        $this->artisan('payments:replay-failed-reloadly --dry-run')
            ->assertSuccessful();

        Queue::assertNotPushed(ExecuteReloadlyOrderJob::class);
    }
}
