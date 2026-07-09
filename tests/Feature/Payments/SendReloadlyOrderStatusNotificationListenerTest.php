<?php

namespace Tests\Feature\Payments;

use App\Events\ReloadlyOrderProcessed;
use App\Listeners\SendReloadlyOrderStatusNotification;
use App\Models\PaymentTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\ReloadlyOrderStatusNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SendReloadlyOrderStatusNotificationListenerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_sends_notification_for_final_status_with_existing_user(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $transaction = Transaction::create([
            'user_id' => $user->id,
            'reference' => 'TRX-LISTENER-1',
            'external_reference' => 'PAY-LISTENER-1',
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
            'payment_reference' => 'PAYREF-LISTENER-1',
            'merchant_reference' => 'TRX-LISTENER-1',
            'msisdn' => '622777777',
            'amount' => 10000,
            'currency' => 'GNF',
            'status' => 'CONFIRMED',
            'confirmation_status' => 'CONFIRMED',
        ]);

        $event = new ReloadlyOrderProcessed(
            paymentTransactionId: $paymentTx->id,
            orderType: 'AIRTIME',
            orderId: 'ORDER-LISTENER-1',
            status: 'SUCCESS',
            reloadlyTransactionId: 'REL-123'
        );

        (new SendReloadlyOrderStatusNotification())->handle($event);

        Notification::assertSentTo($user, ReloadlyOrderStatusNotification::class);
    }

    #[Test]
    public function it_skips_notification_for_non_final_status(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $transaction = Transaction::create([
            'user_id' => $user->id,
            'reference' => 'TRX-LISTENER-2',
            'external_reference' => 'PAY-LISTENER-2',
            'type' => 'DATA_PURCHASE',
            'direction' => 'DEBIT',
            'status' => 'PENDING',
            'amount' => 12000,
            'currency' => 'GNF',
            'provider' => 'MTN',
            'provider_status' => 'PENDING',
        ]);

        $paymentTx = PaymentTransaction::create([
            'transaction_id' => $transaction->id,
            'user_id' => $user->id,
            'provider' => 'MTN',
            'channel' => 'MOBILE_MONEY',
            'payment_reference' => 'PAYREF-LISTENER-2',
            'merchant_reference' => 'TRX-LISTENER-2',
            'msisdn' => '622888888',
            'amount' => 12000,
            'currency' => 'GNF',
            'status' => 'PENDING',
            'confirmation_status' => 'UNCONFIRMED',
        ]);

        $event = new ReloadlyOrderProcessed(
            paymentTransactionId: $paymentTx->id,
            orderType: 'DATA',
            orderId: 'ORDER-LISTENER-2',
            status: 'PROCESSING'
        );

        (new SendReloadlyOrderStatusNotification())->handle($event);

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_skips_notification_when_payment_transaction_is_missing(): void
    {
        Notification::fake();

        $event = new ReloadlyOrderProcessed(
            paymentTransactionId: '11111111-1111-1111-1111-111111111111',
            orderType: 'AIRTIME',
            orderId: 'ORDER-LISTENER-3',
            status: 'FAILED',
            errorCode: 'ERR_NOT_FOUND',
            errorMessage: 'Payment tx missing'
        );

        (new SendReloadlyOrderStatusNotification())->handle($event);

        Notification::assertNothingSent();
    }
}
