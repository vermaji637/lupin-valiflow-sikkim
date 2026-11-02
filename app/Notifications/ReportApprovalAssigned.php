<?php

namespace App\Notifications;

use App\Services\ReportApprovalNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReportApprovalAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $report,
        public string $message,
        public string $url
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $title = ReportApprovalNotifier::reportDisplayTitle($this->report);
        $message = \App\Models\Report::rewriteIdsInText(
            $this->message,
            $this->report,
            $this->report->displayCode(),
            (int) $this->report->id
        );

        return (new MailMessage)
            ->subject('Approval Required: ' . $title)
            ->view('emails.report-approval-assigned', [
                'recipientName' => $notifiable->full_name ?? $notifiable->email,
                'bodyText'      => $message,
                'reportTitle'   => $title,
                'reportId'      => $this->report->displayCode(),
                'url'           => $this->url,
            ]);
    }

    public function toArray($notifiable): array
    {
        $title = ReportApprovalNotifier::reportDisplayTitle($this->report);
        $message = \App\Models\Report::rewriteIdsInText(
            $this->message,
            $this->report,
            $this->report->displayCode(),
            (int) $this->report->id
        );

        return [
            'message'      => $message,
            'url'          => $this->url,
            'report_id'    => $this->report->id,
            'display_code' => $this->report->displayCode(),
            'report_title' => $title,
            'note'         => '',
            'type'         => 'approval_required',
        ];
    }
}
