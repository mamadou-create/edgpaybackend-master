<?php

namespace App\Jobs;

use App\Mail\CreanceReimbursementSubmittedMail;
use App\Models\CreanceTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendCreanceReimbursementSubmittedMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @param array<int,string> $recipients */
    public function __construct(
        public readonly string $transactionId,
        public readonly array $recipients,
    ) {}

    public function handle(): void
    {
        if (empty($this->recipients)) {
            return;
        }

        $tx = CreanceTransaction::with(['creance', 'client'])->find($this->transactionId);
        if (! $tx) {
            Log::warning('[Creance] Soumission mail job: transaction introuvable', [
                'tx_id' => $this->transactionId,
            ]);
            return;
        }

        $client = $tx->client;
        $creance = $tx->creance;
        if (! $client || ! $creance) {
            Log::warning('[Creance] Soumission mail job: relations manquantes', [
                'tx_id' => $tx->id,
                'client_id' => $tx->user_id,
                'creance_id' => $tx->creance_id,
            ]);
            return;
        }

        try {
            @set_time_limit(60);
            Mail::to($this->recipients)->send(
                new CreanceReimbursementSubmittedMail($client, $creance, $tx)
            );

            Log::info('[Creance] Email soumission envoyé', [
                'tx_id' => $tx->id,
                'client_id' => $client->id,
                'recipients_count' => count($this->recipients),
            ]);
        } catch (\Throwable $e) {
            Log::error('[Creance] Erreur envoi email (remboursement soumis): ' . $e->getMessage(), [
                'tx_id' => $tx->id,
            ]);
        }
    }
}
