<?php

namespace App\Services;

use App\Models\Report;
use App\Models\User;
use App\Notifications\ReportApprovalAssigned;
use App\Notifications\ReportApprovalCompleted;
use App\Notifications\ReportApprovalRejected;
use Illuminate\Support\Collection;

class ReportApprovalNotifier
{
    public function notifyApprover(User $user, Report $report, string $message): void
    {
        $this->safeNotify($user, new ReportApprovalAssigned(
            report: $report,
            message: $message,
            url: route('reports.show', $report),
        ));
    }

    public function notifyFullyApproved(Report $report): void
    {
        $creator = User::find($report->created_by);
        if (! $creator) {
            return;
        }

        $this->safeNotify($creator, new ReportApprovalCompleted(
            report: $report,
            url: route('reports.show', $report),
        ));
    }

    /**
     * @param  iterable<int, User>  $priorApprovers
     */
    public function notifyRejected(
        Report $report,
        string $remark,
        User $rejecter,
        iterable $priorApprovers,
        string $decision = 'rejected',
    ): void {
        $url = route('reports.show', $report);
        $notifiedIds = [];
        $decision = $decision === 'sent_back' ? 'sent_back' : 'rejected';
        $remark = trim($remark);

        foreach ($priorApprovers as $user) {
            if (! $user instanceof User || in_array($user->id, $notifiedIds, true)) {
                continue;
            }

            $this->safeNotify($user, new ReportApprovalRejected(
                report: $report,
                remark: $remark,
                rejecter: $rejecter,
                url: $url,
                recipientRole: 'prior_approver',
                decision: $decision,
            ));

            $notifiedIds[] = $user->id;
        }

        $creator = User::find($report->created_by);
        if ($creator && ! in_array($creator->id, $notifiedIds, true)) {
            $this->safeNotify($creator, new ReportApprovalRejected(
                report: $report,
                remark: $remark,
                rejecter: $rejecter,
                url: $url,
                recipientRole: 'creator',
                decision: $decision,
            ));
        }
    }

    /**
     * Users who already approved in the current stage (before step reset on reject).
     */
    public function priorApproversForStage(Report $report, string $stage): Collection
    {
        $userIds = $report->approvalSteps()
            ->where('stage', $stage)
            ->whereNotNull('approved_at')
            ->pluck('user_id')
            ->unique()
            ->filter()
            ->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        return User::whereIn('id', $userIds)->get();
    }

    public static function reportDisplayTitle(Report $report): string
    {
        $title = trim($report->protocol_title);

        return $title !== '' ? $title : $report->displayCode();
    }

    private function safeNotify(User $user, $notification): void
    {
        try {
            $user->notifyNow($notification, ['database']);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            $user->notifyNow($notification, ['mail']);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
