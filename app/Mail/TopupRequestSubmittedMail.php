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

class TopupRequestSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public TopupRequest $topupRequest;
    public User $requester;

    public function __construct(TopupRequest $topupRequest, User $requester)
    {
        $this->topupRequest = $topupRequest;
        $this->requester = $requester;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), 'MDING'),
            subject: 'MDING - Nouvelle demande de recharge',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.topup_request_submitted',
            with: [
                'topupRequest' => $this->topupRequest,
                'requester' => $this->requester,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
