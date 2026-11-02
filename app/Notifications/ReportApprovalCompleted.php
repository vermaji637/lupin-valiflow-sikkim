<?php

namespace App\Notifications;

use App\Services\ReportApprovalNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReportApprovalCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $report,
        public string $url
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $title = ReportApprovalNotifier::reportDisplayTitle($this->report);

        return (new MailMessage)
            ->subject('Fully Approved: ' . $title)
            ->view('emails.report-approval-completed', [
                'recipientName' => $notifiable->full_name ?? $notifiable->email,
                'reportTitle'   => $title,
                'reportId'      => $this->report->displayCode(),
                'url'           => $this->url,
            ]);
    }

    public function toArray($notifiable): array
    {
        $title = ReportApprovalNotifier::reportDisplayTitle($this->report);

        return [
            'message'      => "{$this->report->displayCode()} ({$title}) has been fully approved.",
            'url'          => $this->url,
            'report_id'    => $this->report->id,
            'display_code' => $this->report->displayCode(),
            'report_title' => $title,
        ];
    }
}
