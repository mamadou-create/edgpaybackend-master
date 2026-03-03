<?php

namespace App\Jobs;

use App\Mail\CreanceReimbursementBatchRejectedMail;
use App\Models\CreanceTransaction;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendCreanceBatchRejectedMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $clientId,
        public readonly string $batchKey,
    ) {}

    public function handle(): void
    {
        $client = User::query()->find($this->clientId);
        if (! ($client instanceof User) || empty($client->email)) {
            Log::warning('[Creance] Rejet batch mail non envoyé (client introuvable/sans email)', [
                'client_id' => $this->clientId,
                'batch_key' => $this->batchKey,
            ]);
            return;
        }

        $txs = CreanceTransaction::query()
            ->where('user_id', $client->id)
            ->where('batch_key', $this->batchKey)
            ->where('statut', 'rejete')
            ->orderBy('created_at')
            ->get(['id', 'montant', 'motif_rejet', 'created_at', 'creance_id']);

        if ($txs->isEmpty()) {
            Log::warning('[Creance] Rejet batch mail job: aucune transaction rejetée', [
                'client_id' => $client->id,
                'batch_key' => $this->batchKey,
            ]);
            return;
        }

        $motif = (string) ($txs->first()->motif_rejet ?? '');
        $total = $txs->sum(fn ($t) => (float) $t->montant);

        try {
            @set_time_limit(60);
            Mail::to($client->email)->send(new CreanceReimbursementBatchRejectedMail(
                client: $client,
                batchKey: $this->batchKey,
                transactions: $txs->map(fn ($t) => [
                    'id' => (string) $t->id,
                    'montant' => (string) $t->montant,
                ])->values()->all(),
                motif: $motif,
                totalAmount: number_format($total, 2, '.', ''),
            ));

            Log::info('[Creance] Email rejet batch envoyé', [
                'client_id' => $client->id,
                'batch_key' => $this->batchKey,
                'count' => $txs->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[Creance] Erreur envoi email (soumission rejetée): ' . $e->getMessage(), [
                'client_id' => $client->id,
                'batch_key' => $this->batchKey,
            ]);
        }
    }
}
