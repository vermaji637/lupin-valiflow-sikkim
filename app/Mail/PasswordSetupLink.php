<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordSetupLink extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $link)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Set Your Password',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-setup',
            with: [
                'link' => $this->link,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
