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

class WithdrawalRequestRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public WithdrawalRequest $withdrawalRequest;
    public User $requester;
    public ?string $reason;

    public function __construct(WithdrawalRequest $withdrawalRequest, User $requester, ?string $reason = null)
    {
        $this->withdrawalRequest = $withdrawalRequest;
        $this->requester = $requester;
        $this->reason = $reason;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), 'MDING'),
            subject: 'MDING - Votre demande de retrait a été rejetée',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.withdrawal_rejected',
            with: [
                'withdrawalRequest' => $this->withdrawalRequest,
                'requester' => $this->requester,
                'reason' => $this->reason,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
