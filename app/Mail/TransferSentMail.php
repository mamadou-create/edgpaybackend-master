<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TransferSentMail extends Mailable
{
    use Queueable, SerializesModels;

    public User $sender;
    public User $receiver;
    public int $amount;

    public function __construct(User $sender, User $receiver, int $amount)
    {
        $this->sender   = $sender;
        $this->receiver = $receiver;
        $this->amount   = $amount;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), 'MDING'),
            subject: 'MDING - Transfert effectué avec succès',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.transfer_sent',
            with: [
                'sender'   => $this->sender,
                'receiver' => $this->receiver,
                'amount'   => $this->amount,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
