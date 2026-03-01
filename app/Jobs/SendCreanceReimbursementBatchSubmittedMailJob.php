<?php

namespace App\Jobs;

use App\Mail\CreanceReimbursementBatchSubmittedMail;
use App\Models\CreanceTransaction;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendCreanceReimbursementBatchSubmittedMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @param array<int,string> $recipients */
    public function __construct(
        public readonly string $clientId,
        public readonly string $batchKey,
        public readonly array $recipients,
    ) {}

    public function handle(): void
    {
        if (empty($this->recipients)) {
            return;
        }

        $client = User::find($this->clientId);
        if (! ($client instanceof User)) {
            Log::warning('[Creance] Batch mail job: client introuvable', [
                'client_id' => $this->clientId,
                'batch_key' => $this->batchKey,
            ]);
            return;
        }

        $txs = CreanceTransaction::query()
            ->with(['creance'])
            ->where('batch_key', $this->batchKey)
            ->where('user_id', $client->id)
            ->orderBy('created_at')
            ->get();

        if ($txs->isEmpty()) {
            Log::warning('[Creance] Batch mail job: aucune transaction trouvée', [
                'client_id' => $client->id,
                'batch_key' => $this->batchKey,
            ]);
            return;
        }

        $transactions = [];
        $totalSubmitted = 0.0;
        $hasProof = false;

        foreach ($txs as $tx) {
            $totalSubmitted += (float) $tx->montant;
            if (!empty($tx->preuve_fichier)) {
                $hasProof = true;
            }

            $transactions[] = [
                'transaction_id' => $tx->id,
                'type' => $tx->type,
                'montant' => $tx->montant,
                'statut' => $tx->statut,
                'created_at' => $tx->created_at,
                'creance_id' => $tx->creance_id,
                'creance_reference' => $tx->creance?->reference,
            ];
        }

        try {
            @set_time_limit(60);
            Mail::to($this->recipients)->send(
                new CreanceReimbursementBatchSubmittedMail(
                    client: $client,
                    batchKey: $this->batchKey,
                    transactions: $transactions,
                    totalSubmitted: $totalSubmitted,
                    hasProof: $hasProof,
                )
            );

            Log::info('[Creance] Email batch soumission envoyé', [
                'client_id' => $client->id,
                'batch_key' => $this->batchKey,
                'transactions_count' => count($transactions),
                'recipients_count' => count($this->recipients),
            ]);
        } catch (\Throwable $e) {
            Log::error('[Creance] Erreur envoi email (batch soumis): ' . $e->getMessage(), [
                'client_id' => $client->id,
                'batch_key' => $this->batchKey,
            ]);
        }
    }
}
