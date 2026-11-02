<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountLockedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userName,
        public int $lockoutMinutes,
        public ?string $unlockAtLabel = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your ValiFlow account has been temporarily locked',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account-locked',
            with: [
                'userName' => $this->userName,
                'lockoutMinutes' => $this->lockoutMinutes,
                'unlockAtLabel' => $this->unlockAtLabel,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
