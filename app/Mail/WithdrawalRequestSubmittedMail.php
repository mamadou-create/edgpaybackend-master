<?php

namespace App\Mail;

use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WithdrawalRequestSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public WithdrawalRequest $withdrawalRequest;
    public User $requester;

    public function __construct(WithdrawalRequest $withdrawalRequest, User $requester)
    {
        $this->withdrawalRequest = $withdrawalRequest;
        $this->requester = $requester;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), 'MDING'),
            subject: 'MDING - Nouvelle demande de retrait',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.withdrawal_submitted',
            with: [
                'withdrawalRequest' => $this->withdrawalRequest,
                'requester' => $this->requester,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
