<?php

namespace App\Mail;

use App\Models\Creance;
use App\Models\CreanceTransaction;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CreanceReimbursementValidatedReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $client,
        public readonly Creance $creance,
        public readonly CreanceTransaction $transaction,
        private readonly ?string $pdfBytes = null,
    ) {}

    public function envelope(): Envelope
    {
        $receiptNumber = $this->transaction->receipt_number ?: $this->transaction->id;

        return new Envelope(
            from: new Address(config('mail.from.address'), 'MDING'),
            subject: "MDING - Reçu de paiement n° {$receiptNumber}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.creance_reimbursement_validated_receipt',
            with: [
                'client' => $this->client,
                'creance' => $this->creance,
                'transaction' => $this->transaction,
            ],
        );
    }

    public function attachments(): array
    {
        if (empty($this->pdfBytes)) {
            return [];
        }

        $receiptNumber = $this->transaction->receipt_number ?: $this->transaction->id;
        $filename = 'recu-mding-' . $receiptNumber . '.pdf';

        return [
            Attachment::fromData(fn () => $this->pdfBytes, $filename)
                ->withMime('application/pdf'),
        ];
    }
}
