<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DailyReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $stats;
    public string $date;

    public function __construct(array $stats, string $date)
    {
        $this->stats = $stats;
        $this->date  = $date;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), 'MDING'),
            subject: 'MDING - Rapport journalier du ' . $this->date,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.daily_report',
            with: [
                'stats' => $this->stats,
                'date'  => $this->date,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
