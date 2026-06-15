<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class ReportApprovalAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $report,
        public string $message,
        public string $url
    ) {}

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toArray($notifiable)
    {
        return [
            'message' => $this->message,
            'url' => $this->url,
            'report_id' => $this->report->id,
            'report_title' => "Report #{$this->report->id}",
        ];
    }
}
