<?php

namespace App\Mail;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordExpiryReminder extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userName,
        public Carbon $expiresAt,
        public string $remainingLabel,
        public string $resetUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your password will expire in ' . $this->remainingLabel,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-expiry-reminder',
            with: [
                'userName' => $this->userName,
                'expiresAt' => $this->expiresAt,
                'remainingLabel' => $this->remainingLabel,
                'resetUrl' => $this->resetUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
