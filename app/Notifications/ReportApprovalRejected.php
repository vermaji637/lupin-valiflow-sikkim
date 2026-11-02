<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\ReportApprovalNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReportApprovalRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $report,
        public string $remark,
        public User $rejecter,
        public string $url,
        public string $recipientRole = 'prior_approver',
        public string $decision = 'rejected',
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $title = ReportApprovalNotifier::reportDisplayTitle($this->report);
        $rejecterName = trim($this->rejecter->full_name ?? '') ?: $this->rejecter->email;
        $isSendBack = $this->decision === 'sent_back';

        return (new MailMessage)
            ->subject(($isSendBack ? 'Protocol sent back: ' : 'Protocol rejected: ') . $title)
            ->view('emails.report-approval-rejected', [
                'recipientName' => $notifiable->full_name ?? $notifiable->email,
                'reportTitle'   => $title,
                'reportId'      => $this->report->displayCode(),
                'stage'         => strtoupper((string) $this->report->stage),
                'remark'        => $this->remark,
                'rejecterName'  => $rejecterName,
                'url'           => $this->url,
                'isCreator'     => $this->recipientRole === 'creator',
                'isSendBack'    => $isSendBack,
            ]);
    }

    public function toArray($notifiable): array
    {
        $title = ReportApprovalNotifier::reportDisplayTitle($this->report);
        $rejecterName = trim($this->rejecter->full_name ?? '') ?: $this->rejecter->email;
        $isSendBack = $this->decision === 'sent_back';
        $verb = $isSendBack ? 'sent back for correction' : 'rejected';
        $note = trim($this->remark);

        return [
            'message'      => "{$this->report->displayCode()} ({$title}) was {$verb} by {$rejecterName}.",
            'url'          => $this->url,
            'report_id'    => $this->report->id,
            'display_code' => $this->report->displayCode(),
            'report_title' => $title,
            'remark'       => $note,
            'note'         => $note,
            'rejecter'     => $rejecterName,
            'decision'     => $this->decision,
        ];
    }
}
