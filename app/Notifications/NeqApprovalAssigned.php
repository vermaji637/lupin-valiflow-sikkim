<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NeqApprovalAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $message,
        public string $url,
        public int $recordId,
        public string $displayCode = '',
        public string $documentNumber = '',
        public string $roleLabel = '',
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
            ->subject('Approval required: ' . $subjectDoc)
            ->view('emails.neq-approval-assigned', [
                'recipientName'   => $notifiable->full_name ?? $notifiable->email,
                'bodyText'        => $this->message,
                'documentTitle'   => $this->title,
                'displayCode'     => $this->displayCode,
                'documentNumber'  => $this->documentNumber,
                'roleLabel'       => $this->roleLabel,
                'url'             => $this->url,
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'message'               => $this->message,
            'url'                   => $this->url,
            'report_id'             => $this->recordId,
            'report_title'          => $this->title,
            'display_code'          => $this->displayCode,
            'reference_document_no' => $this->documentNumber,
            'type'                  => 'neq_assigned',
        ];
    }
}
