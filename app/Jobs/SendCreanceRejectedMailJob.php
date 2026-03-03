<?php

namespace App\Jobs;

use App\Mail\CreanceReimbursementRejectedMail;
use App\Models\CreanceTransaction;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendCreanceRejectedMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $transactionId) {}

    public function handle(): void
    {
        $tx = CreanceTransaction::with(['creance', 'client'])->find($this->transactionId);
        if (! $tx) {
            Log::warning('[Creance] Rejet mail job: transaction introuvable', [
                'tx_id' => $this->transactionId,
            ]);
            return;
        }

        $client = $tx->client;
        if (! ($client instanceof User) || empty($client->email)) {
            Log::warning('[Creance] Rejet mail non envoyé (client sans email)', [
                'tx_id' => $tx->id,
                'client_id' => $tx->user_id,
            ]);
            return;
        }

        if (! $tx->creance) {
            Log::warning('[Creance] Rejet mail job: créance manquante', [
                'tx_id' => $tx->id,
                'creance_id' => $tx->creance_id,
            ]);
            return;
        }

        try {
            @set_time_limit(60);
            Mail::to($client->email)->send(new CreanceReimbursementRejectedMail($client, $tx->creance, $tx));

            Log::info('[Creance] Email rejet envoyé', [
                'tx_id' => $tx->id,
                'client_id' => $client->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Creance] Erreur envoi email (paiement rejeté): ' . $e->getMessage(), [
                'tx_id' => $tx->id,
                'client_id' => $client->id,
            ]);
        }
    }
}
