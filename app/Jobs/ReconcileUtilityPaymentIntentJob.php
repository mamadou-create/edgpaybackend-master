<?php

namespace App\Jobs;

use App\Services\UtilityPaymentReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReconcileUtilityPaymentIntentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 90;
    public int $uniqueFor = 120;
    public array $backoff = [30, 120, 300];

    public function __construct(private readonly string $intentId)
    {
        $this->onQueue('reloadly');
    }

    public function uniqueId(): string
    {
        return $this->intentId;
    }

    public function handle(UtilityPaymentReconciliationService $reconciliation): void
    {
        $outcome = $reconciliation->reconcile($this->intentId);

        Log::info('Utility payment reconciliation job completed', [
            'payment_intent_id' => $this->intentId,
            'outcome' => $outcome,
        ]);
    }
}