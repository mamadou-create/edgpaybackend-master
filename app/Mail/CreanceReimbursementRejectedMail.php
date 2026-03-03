<?php

namespace App\Mail;

use App\Models\Creance;
use App\Models\CreanceTransaction;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CreanceReimbursementRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $client,
        public readonly Creance $creance,
        public readonly CreanceTransaction $transaction,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), 'MDING'),
            subject: 'MDING - Paiement rejeté',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.creance_reimbursement_rejected',
            with: [
                'client' => $this->client,
                'creance' => $this->creance,
                'transaction' => $this->transaction,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
