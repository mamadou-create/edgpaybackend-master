<?php

namespace App\Services;

use App\Models\CreanceTransaction;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;

class MdingReceiptPdfService
{
    public function generateForTransaction(CreanceTransaction $transaction): string
    {
        $transaction->loadMissing(['creance', 'client', 'validateur']);

        $html = View::make('pdfs.mding_receipt', [
            'tx' => $transaction,
            'creance' => $transaction->creance,
            'client' => $transaction->client,
            'validateur' => $transaction->validateur,
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isFontSubsettingEnabled', true);
        // Use a built-in core font for performance (and to avoid heavy TTF parsing).
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A5', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Génère un reçu PDF récapitulatif pour une validation batch.
     *
     * @param array<int,CreanceTransaction> $transactions
     */
    public function generateForBatchValidated(
        \App\Models\User $client,
        string $batchKey,
        array $transactions,
        ?string $validatedAt = null,
    ): string {
        $html = View::make('pdfs.mding_batch_receipt', [
            'client' => $client,
            'batch_key' => $batchKey,
            'transactions' => $transactions,
            'validated_at' => $validatedAt,
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
