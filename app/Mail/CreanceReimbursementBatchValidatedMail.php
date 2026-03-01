<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CreanceReimbursementBatchValidatedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<int,array<string,mixed>> $transactions
     */
    public function __construct(
        public readonly User $client,
        public readonly string $batchKey,
        public readonly array $transactions,
        public readonly float $totalValidated,
        public readonly ?string $validatedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), 'MDING'),
            subject: 'MDING - Paiement validé (reçu)',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.creance_reimbursement_batch_validated',
            with: [
                'client' => $this->client,
                'batchKey' => $this->batchKey,
                'transactions' => $this->transactions,
                'totalValidated' => $this->totalValidated,
                'validatedAt' => $this->validatedAt,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
