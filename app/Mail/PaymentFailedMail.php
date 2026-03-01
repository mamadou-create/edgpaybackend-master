<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $userName;
    public int    $amount;
    public string $compteur;
    public string $errorMessage;
    public string $paymentType;

    /**
     * @param string $userName     Nom de l'utilisateur
     * @param int    $amount       Montant du paiement
     * @param string $compteur     Numéro du compteur / référence
     * @param string $errorMessage Message d'erreur technique
     * @param string $paymentType  Type (Prépayé / Postpayé / Paiement électronique)
     */
    public function __construct(
        string $userName,
        int $amount,
        string $compteur,
        string $errorMessage,
        string $paymentType = 'Paiement'
    ) {
        $this->userName     = $userName;
        $this->amount       = $amount;
        $this->compteur     = $compteur;
        $this->errorMessage = $errorMessage;
        $this->paymentType  = $paymentType;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), 'MDING'),
            subject: 'MDING - Échec de paiement',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment_failed',
            with: [
                'userName'     => $this->userName,
                'amount'       => $this->amount,
                'compteur'     => $this->compteur,
                'errorMessage' => $this->errorMessage,
                'paymentType'  => $this->paymentType,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
