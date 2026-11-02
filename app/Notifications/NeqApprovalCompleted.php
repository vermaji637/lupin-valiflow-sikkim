<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NeqApprovalCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $url,
        public int $recordId,
        public string $displayCode = '',
        public string $documentNumber = '',
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $subjectDoc = trim($this->documentNumber) !== ''
            ? $this->documentNumber
            : (trim($this->displayCode) !== '' ? $this->displayCode : $this->title);

        return (new MailMessage)
            ->subject('Fully approved: ' . $subjectDoc)
            ->view('emails.neq-approval-completed', [
                'recipientName'  => $notifiable->full_name ?? $notifiable->email,
                'documentTitle'  => $this->title,
                'displayCode'    => $this->displayCode,
                'documentNumber' => $this->documentNumber,
                'url'            => $this->url,
            ]);
    }

    public function toArray($notifiable): array
    {
        $identity = trim($this->title) !== ''
            ? $this->title
            : (trim($this->displayCode) !== '' ? $this->displayCode : 'NEQ #' . $this->recordId);

        return [
            'message'               => "Document {$identity} has been fully approved.",
            'url'                   => $this->url,
            'report_id'             => $this->recordId,
            'report_title'          => $this->title,
            'display_code'          => $this->displayCode,
            'reference_document_no' => $this->documentNumber,
            'type'                  => 'neq_completed',
        ];
    }
}
