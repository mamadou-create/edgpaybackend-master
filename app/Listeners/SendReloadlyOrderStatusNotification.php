<?php

namespace App\Listeners;

use App\Events\ReloadlyOrderProcessed;
use App\Models\PaymentTransaction;
use App\Notifications\ReloadlyOrderStatusNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendReloadlyOrderStatusNotification implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(ReloadlyOrderProcessed $event): void
    {
        if (!in_array($event->status, ['SUCCESS', 'FAILED'], true)) {
            Log::warning('Reloadly notification skipped: non-final status', [
                'payment_transaction_id' => $event->paymentTransactionId,
                'status' => $event->status,
                'order_type' => $event->orderType,
                'order_id' => $event->orderId,
            ]);

            return;
        }

        $paymentTransaction = PaymentTransaction::query()
            ->with('user')
            ->find($event->paymentTransactionId);

        if ($paymentTransaction === null) {
            Log::warning('Reloadly notification skipped: payment transaction not found', [
                'payment_transaction_id' => $event->paymentTransactionId,
                'status' => $event->status,
                'order_type' => $event->orderType,
                'order_id' => $event->orderId,
            ]);

            return;
        }

        $user = $paymentTransaction?->user;
        if ($user === null) {
            Log::warning('Reloadly notification skipped: user missing on payment transaction', [
                'payment_transaction_id' => $event->paymentTransactionId,
                'status' => $event->status,
                'order_type' => $event->orderType,
                'order_id' => $event->orderId,
            ]);

            return;
        }

        $user->notify(new ReloadlyOrderStatusNotification($event));
    }
}
