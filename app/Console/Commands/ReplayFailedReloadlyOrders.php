<?php

namespace App\Console\Commands;

use App\Jobs\ExecuteReloadlyOrderJob;
use App\Models\AirtimeOrder;
use App\Models\DataOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ReplayFailedReloadlyOrders extends Command
{
    protected $signature = 'payments:replay-failed-reloadly
        {--type=all : airtime|data|all}
        {--limit=50 : Nombre maximum d\'ordres à rejouer}
        {--dry-run : Affiche uniquement les ordres ciblés}';

    protected $description = 'Rejoue les ordres Reloadly en échec (paiements confirmés uniquement).';

    public function handle(): int
    {
        $type = strtolower((string) $this->option('type'));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        if (!in_array($type, ['all', 'airtime', 'data'], true)) {
            $this->error('Option --type invalide. Valeurs autorisées: airtime|data|all');
            return self::INVALID;
        }

        $targets = collect();

        if (in_array($type, ['all', 'airtime'], true)) {
            $targets = $targets->concat($this->failedAirtime($limit));
        }

        if (in_array($type, ['all', 'data'], true)) {
            $targets = $targets->concat($this->failedData($limit));
        }

        $targets = $targets
            ->unique('payment_transaction_id')
            ->take($limit)
            ->values();

        if ($targets->isEmpty()) {
            $this->info('Aucun ordre éligible au replay.');
            return self::SUCCESS;
        }

        $this->table(
            ['type', 'order_id', 'payment_transaction_id', 'error_code', 'updated_at'],
            $targets->map(fn (array $row) => [
                $row['type'],
                $row['order_id'],
                $row['payment_transaction_id'],
                $row['error_code'],
                $row['updated_at'],
            ])->all()
        );

        if ($dryRun) {
            $this->info('Dry-run activé: aucun job dispatché.');
            return self::SUCCESS;
        }

        foreach ($targets as $target) {
            ExecuteReloadlyOrderJob::dispatch($target['payment_transaction_id'])->onQueue('reloadly');
        }

        $this->info('Replay planifié pour ' . $targets->count() . ' transaction(s) paiement.');

        return self::SUCCESS;
    }

    private function failedAirtime(int $limit): Collection
    {
        return AirtimeOrder::query()
            ->where('status', 'FAILED')
            ->whereNotNull('payment_transaction_id')
            ->whereHas('paymentTransaction', fn ($q) => $q->where('status', 'CONFIRMED'))
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (AirtimeOrder $o) => [
                'type' => 'AIRTIME',
                'order_id' => $o->id,
                'payment_transaction_id' => $o->payment_transaction_id,
                'error_code' => $o->error_code,
                'updated_at' => optional($o->updated_at)->toDateTimeString(),
            ]);
    }

    private function failedData(int $limit): Collection
    {
        return DataOrder::query()
            ->where('status', 'FAILED')
            ->whereNotNull('payment_transaction_id')
            ->whereHas('paymentTransaction', fn ($q) => $q->where('status', 'CONFIRMED'))
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (DataOrder $o) => [
                'type' => 'DATA',
                'order_id' => $o->id,
                'payment_transaction_id' => $o->payment_transaction_id,
                'error_code' => $o->error_code,
                'updated_at' => optional($o->updated_at)->toDateTimeString(),
            ]);
    }
}
