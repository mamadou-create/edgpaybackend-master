<?php

namespace App\Jobs;

use App\Mail\CreanceReimbursementBatchValidatedMail;
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

class SendCreanceBatchValidatedReceiptMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Keep retrying for a while (do not fail fast) to avoid missing PDF sends
     * when the environment (GD/assets) is temporarily not ready.
     */
    public int $tries = 50;

    public function __construct(
        public readonly string $clientId,
        public readonly string $batchKey,
    ) {}

    public function backoff(): int
    {
        return 300;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDays(2);
    }

    public function handle(): void
    {
        $client = User::find($this->clientId);
        if (! ($client instanceof User) || empty($client->email)) {
            Log::warning('[Creance] Batch reçu non envoyé (client introuvable ou sans email)', [
                'client_id' => $this->clientId,
                'batch_key' => $this->batchKey,
            ]);
            return;
        }

        $txs = CreanceTransaction::query()
            ->with(['creance'])
            ->where('batch_key', $this->batchKey)
            ->where('user_id', $client->id)
            ->where('statut', 'valide')
            ->orderBy('created_at')
            ->get();

        if ($txs->isEmpty()) {
            Log::warning('[Creance] Batch reçu: aucune transaction validée trouvée', [
                'client_id' => $client->id,
                'batch_key' => $this->batchKey,
            ]);
            return;
        }

        $transactions = [];
        $totalValidated = 0.0;
        $validatedAt = null;

        foreach ($txs as $tx) {
            $totalValidated += (float) $tx->montant;
            $validatedAt = $validatedAt ?: optional($tx->valide_at)->toDateTimeString();

            $transactions[] = [
                'transaction_id' => $tx->id,
                'type' => $tx->type,
                'montant' => $tx->montant,
                'created_at' => optional($tx->created_at)->toDateTimeString(),
                'creance_id' => $tx->creance_id,
                'creance_reference' => $tx->creance?->reference,
                'receipt_number' => $tx->receipt_number,
            ];
        }

        $pdfBytes = null;
        try {
            if (!class_exists(\Dompdf\Dompdf::class)) {
                throw new \RuntimeException('dompdf/dompdf non installé');
            }
            /** @var MdingReceiptPdfService $pdfService */
            $pdfService = app(MdingReceiptPdfService::class);
            $pdfBytes = $pdfService->generateForBatchValidated(
                client: $client,
                batchKey: $this->batchKey,
                transactions: $txs->all(),
                validatedAt: $validatedAt,
            );
        } catch (\Throwable $e) {
            Log::error('[Creance] PDF batch reçu non généré, re-queue dans 5 min: ' . $e->getMessage(), [
                'client_id' => $client->id,
                'batch_key' => $this->batchKey,
            ]);

            // Do NOT send email without PDF. Re-queue so it can succeed once GD/assets are fixed.
            $this->release(300);
            return;
        }

        try {
            @set_time_limit(60);
            Mail::to($client->email)->send(
                new CreanceReimbursementBatchValidatedMail(
                    client: $client,
                    batchKey: $this->batchKey,
                    transactions: $transactions,
                    totalValidated: $totalValidated,
                    validatedAt: $validatedAt,
                    pdfBytes: $pdfBytes,
                )
            );

            Log::info('[Creance] Email batch reçu envoyé', [
                'client_id' => $client->id,
                'batch_key' => $this->batchKey,
                'transactions_count' => count($transactions),
            ]);
        } catch (\Throwable $e) {
            Log::error('[Creance] Erreur envoi email (batch reçu): ' . $e->getMessage(), [
                'client_id' => $client->id,
                'batch_key' => $this->batchKey,
            ]);
        }
    }
}
