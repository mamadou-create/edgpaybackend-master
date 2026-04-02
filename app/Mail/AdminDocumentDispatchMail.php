<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminDocumentDispatchMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $sender,
        public readonly string $documentTitle,
        public readonly string $documentNumber,
        public readonly string $messageText,
        public readonly ?string $recipientName,
        private readonly string $pdfBytes,
        private readonly string $pdfName,
        private readonly ?string $attachmentBytes = null,
        private readonly ?string $attachmentName = null,
        private readonly ?string $attachmentMime = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), 'MDING'),
            subject: sprintf('%s %s', $this->documentTitle, $this->documentNumber),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin_document_dispatch',
            with: [
                'senderName' => $this->sender->display_name ?: 'Admin EdgPay',
                'documentTitle' => $this->documentTitle,
                'documentNumber' => $this->documentNumber,
                'messageText' => $this->messageText,
                'recipientName' => $this->recipientName,
            ],
        );
    }

    public function attachments(): array
    {
        $attachments = [
            Attachment::fromData(fn () => $this->pdfBytes, $this->pdfName)
                ->withMime('application/pdf'),
        ];

        if ($this->attachmentBytes !== null && $this->attachmentName !== null) {
            $attachments[] = Attachment::fromData(fn () => $this->attachmentBytes, $this->attachmentName)
                ->withMime($this->attachmentMime ?: 'application/octet-stream');
        }

        return $attachments;
    }
}