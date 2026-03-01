<?php

namespace App\Mail;

use App\Models\TopupRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TopupRequestApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public TopupRequest $topupRequest;
    public User $requester;
    public ?User $approver;

    public function __construct(TopupRequest $topupRequest, User $requester, ?User $approver = null)
    {
        $this->topupRequest = $topupRequest;
        $this->requester = $requester;
        $this->approver = $approver;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), 'MDING'),
            subject: 'MDING - Votre recharge a été validée',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.topup_request_approved',
            with: [
                'topupRequest' => $this->topupRequest,
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
