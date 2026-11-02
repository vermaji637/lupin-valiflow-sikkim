<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NeqApprovalDecision extends Notification
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $remark,
        public string $actorName,
        public string $url,
        public int $recordId,
        public string $decision = 'rejected',
        public string $recipientRole = 'uploader',
        public string $mailStatus = 'pending',
        public string $displayCode = '',
        public string $documentNumber = '',
    ) {}

    public function via($notifiable): array
    {
        // Database only here — controller sends mail separately and patches mail_status.
        return ['database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $isSendBack = $this->decision === 'sent_back';
        $note = trim($this->remark);
        $subjectDoc = trim($this->documentNumber) !== ''
            ? $this->documentNumber
            : (trim($this->displayCode) !== '' ? $this->displayCode : $this->title);

        return (new MailMessage)
            ->subject(($isSendBack ? 'Sent back: ' : 'Rejected: ') . $subjectDoc)
            ->view('emails.neq-approval-decision', [
                'recipientName'  => $notifiable->full_name ?? $notifiable->email,
                'documentTitle'  => $this->title,
                'displayCode'    => $this->displayCode,
                'documentNumber' => $this->documentNumber,
                'remark'         => $note !== '' ? $note : '—',
                'actorName'      => $this->actorName,
                'url'            => $this->url,
                'isCreator'      => $this->recipientRole === 'uploader',
                'isSendBack'     => $isSendBack,
            ]);
    }

    public function toDatabase($notifiable): array
    {
        return $this->payload();
    }

    public function toArray($notifiable): array
    {
        return $this->payload();
    }

    private function payload(): array
    {
        $isSendBack = $this->decision === 'sent_back';
        $note = trim($this->remark);
        $headline = $isSendBack ? 'Sent back for correction' : 'Rejected';
        $identity = trim($this->title) !== ''
            ? $this->title
            : (trim($this->displayCode) !== ''
                ? $this->displayCode
                : 'New Equipment Qualification #' . $this->recordId);

        if ($isSendBack) {
            $message = $this->recipientRole === 'uploader'
                ? "Document {$identity} was sent back for correction by {$this->actorName}."
                    . ' Please review the note, update the document, and submit again.'
                : "Document {$identity}, which you previously approved, was sent back for correction by {$this->actorName}.";
        } else {
            $message = $this->recipientRole === 'uploader'
                ? "Document {$identity} was rejected by {$this->actorName}."
                    . ' Please review the note below for the reason.'
                : "Document {$identity}, which you previously approved, was rejected by {$this->actorName}.";
        }

        return [
            'message'               => $message,
            'url'                   => $this->url,
            'report_id'             => $this->recordId,
            'report_title'          => $headline . ': ' . $identity,
            'display_code'          => $this->displayCode,
            'reference_document_no' => $this->documentNumber,
            'remark'                => $note,
            'note'                  => $note,
            'decision'              => $this->decision,
            'type'                  => $isSendBack ? 'neq_sent_back' : 'neq_rejected',
            'mail_status'           => $this->mailStatus,
            'mail_direction'        => 'inbound',
            'actor_name'            => $this->actorName,
        ];
    }
}
