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

class WithdrawalRequestApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public WithdrawalRequest $withdrawalRequest;
    public User $requester;
    public ?User $approver;

    public function __construct(WithdrawalRequest $withdrawalRequest, User $requester, ?User $approver = null)
    {
        $this->withdrawalRequest = $withdrawalRequest;
        $this->requester = $requester;
        $this->approver = $approver;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), 'MDING'),
            subject: 'MDING - Votre retrait a été approuvé',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.withdrawal_approved',
            with: [
                'withdrawalRequest' => $this->withdrawalRequest,
                'requester' => $this->requester,
                'approver' => $this->approver,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
