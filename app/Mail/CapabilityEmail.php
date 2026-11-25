<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class CapabilityEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly string $mailSubject,
        private readonly string $textBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.plain-text',
            with: ['body' => $this->textBody],
            htmlString: nl2br(e($this->textBody)),
        );
    }
}
