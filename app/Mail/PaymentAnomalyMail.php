<?php

namespace App\Mail;

use App\Models\DmlTransaction;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentAnomalyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $anomalyType,
        public ?User $affectedUser,
        public int $amount,
        public ?DmlTransaction $transaction,
        public string $details,
        public string $paymentType = 'DML'
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), 'MDING'),
            subject: '⚠️ MDING - Anomalie paiement détectée',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment_anomaly',
            with: [
                'anomalyType'  => $this->anomalyType,
                'affectedUser' => $this->affectedUser,
                'amount'       => $this->amount,
                'transaction'  => $this->transaction,
                'details'      => $this->details,
                'paymentType'  => $this->paymentType,
                'detectedAt'   => now()->format('d/m/Y H:i:s'),
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
