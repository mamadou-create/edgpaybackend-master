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

        // Load and encode the MDING logo as base64 for embedding in PDF
        $logoBase64 = '';
        $logoPath = public_path('images/mding_logo.png');
        if (!file_exists($logoPath)) {
            $logoPath = storage_path('app/mding_logo.png');
        }
        if (file_exists($logoPath)) {
            $logoBase64 = base64_encode((string) file_get_contents($logoPath));
        }

        $html = View::make('pdfs.mding_receipt', [
            'tx' => $transaction,
            'creance' => $transaction->creance,
            'client' => $transaction->client,
            'validateur' => $transaction->validateur,
            'logo_base64' => $logoBase64,
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
}
