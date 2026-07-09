<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReloadlyOrderProcessed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $paymentTransactionId,
        public string $orderType,
        public string $orderId,
        public string $status,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?string $reloadlyTransactionId = null
    ) {
    }
}
