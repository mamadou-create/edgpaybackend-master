<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileUtilityPaymentIntentJob;
use App\Models\PaymentIntent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ReconcileUtilityPayments extends Command
{
    protected $signature = 'utilities:reconcile {--limit=100 : Nombre maximum d’intentions à traiter} {--sync : Exécute immédiatement sans queue}';
    protected $description = 'Réconcilie les paiements Reloadly Utilities PROCESSING et TIMEOUT.';

    public function handle(): int
    {
        $lock = Cache::lock('utilities:reconcile:command', 300);
        if (!$lock->get()) {
            $this->warn('Une réconciliation Utilities est déjà en cours.');

            return self::SUCCESS;
        }

        try {
            $intents = PaymentIntent::query()
                ->where('provider', 'reloadly_utilities')
                ->whereIn('status', ['PROCESSING', 'TIMEOUT'])
                ->oldest('updated_at')
                ->limit(max(1, (int) $this->option('limit')))
                ->pluck('id');

            foreach ($intents as $intentId) {
                $job = new ReconcileUtilityPaymentIntentJob((string) $intentId);
                if ($this->option('sync')) {
                    $job->handle(app(\App\Services\UtilityPaymentReconciliationService::class));
                } else {
                    dispatch($job);
                }
            }

            Log::info('Utility payment reconciliation dispatched', [
                'count' => $intents->count(),
                'sync' => (bool) $this->option('sync'),
            ]);
            $this->info("{$intents->count()} intention(s) Utilities planifiée(s).");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}