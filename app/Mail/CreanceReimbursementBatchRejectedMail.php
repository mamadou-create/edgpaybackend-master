<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CreanceReimbursementBatchRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<int,array<string,mixed>> $transactions */
    public function __construct(
        public readonly User $client,
        public readonly string $batchKey,
        public readonly array $transactions,
        public readonly string $motif,
        public readonly string $totalAmount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), 'MDING'),
            subject: 'MDING - Soumission rejetée',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.creance_reimbursement_batch_rejected',
            with: [
                'client' => $this->client,
                'batchKey' => $this->batchKey,
                'transactions' => $this->transactions,
                'motif' => $this->motif,
                'totalAmount' => $this->totalAmount,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
