<?php

namespace App\Jobs;

use App\Mail\CreanceReimbursementValidatedReceiptMail;
use App\Models\CreanceTransaction;
use App\Models\User;
use App\Services\MdingReceiptPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendCreanceValidatedReceiptMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $transactionId) {}

    public function handle(): void
    {
        $tx = CreanceTransaction::with(['creance', 'client', 'validateur'])->find($this->transactionId);
        if (! $tx) {
            Log::warning('[Creance] Reçu mail job: transaction introuvable', [
                'tx_id' => $this->transactionId,
            ]);
            return;
        }

        $client = $tx->client;
        if (! ($client instanceof User) || empty($client->email)) {
            Log::warning('[Creance] Reçu mail non envoyé (client sans email)', [
                'tx_id' => $tx->id,
                'client_id' => $tx->user_id,
            ]);
            return;
        }

        $pdfBytes = null;
        if (class_exists(\Dompdf\Dompdf::class)) {
            try {
                $pdfBytes = app(MdingReceiptPdfService::class)->generateForTransaction($tx);
            } catch (\Throwable $e) {
                Log::error('[Creance] Erreur génération PDF reçu: ' . $e->getMessage(), [
                    'tx_id' => $tx->id,
                ]);
            }
        } else {
            Log::warning('[Creance] dompdf/dompdf non installé: reçu PDF non généré.', [
                'tx_id' => $tx->id,
            ]);
        }

        try {
            @set_time_limit(120);
            Mail::to($client->email)->send(
                new CreanceReimbursementValidatedReceiptMail($client, $tx->creance, $tx, $pdfBytes)
            );

            Log::info('[Creance] Email reçu envoyé', [
                'tx_id' => $tx->id,
                'client_id' => $client->id,
                'with_pdf' => !empty($pdfBytes),
            ]);
        } catch (\Throwable $e) {
            Log::error('[Creance] Erreur envoi email (reçu paiement validé): ' . $e->getMessage(), [
                'tx_id' => $tx->id,
                'client_id' => $client->id,
            ]);
        }
    }
}
