@extends('layouts.app')

@section('title', 'Notifications')

@section('content')
<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-bell"></i>
                    Inbox
                </span>
                <h2 class="page-title">Notifications</h2>
                <p class="page-subtitle">
                    Protocol title, notes, and seen time for each approval message.
                </p>
            </div>
            @if($notifications->count() > 0)
                <form method="POST" action="{{ route('notifications.destroyAll') }}" class="mt-3 mt-lg-0"
                      onsubmit="return confirm('Remove all notifications?');">
                    @csrf
                    <button type="submit" class="btn btn-light btn-compact">
                        <i class="fas fa-trash-alt"></i>
                        Remove All
                    </button>
                </form>
            @endif
        </div>
    </section>

    @include('common.alert')

    @forelse($notifications as $notification)
        @php
            $data = is_array($notification->data ?? null) ? $notification->data : [];
            if (! is_array($data) && is_string($notification->data ?? null)) {
                $data = json_decode($notification->data, true) ?: [];
            }
            $protocolTitle = trim((string) ($data['report_title'] ?? $data['reportTitle'] ?? ''));
            $reportId = $data['report_id'] ?? $data['reportId'] ?? null;
            $displayCode = trim((string) ($data['display_code'] ?? ''));
            $reportModel = null;
            if (is_numeric($reportId)) {
                $reportModel = \App\Models\Report::query()->find((int) $reportId);
                if ($displayCode === '' && $reportModel) {
                    $displayCode = $reportModel->displayCode();
                }
            }
            $note = trim((string) ($data['remark'] ?? $data['note'] ?? $data['Remark'] ?? $data['Note'] ?? ''));
            $message = trim((string) ($data['message'] ?? 'Notification'));
            if ($displayCode !== '') {
                $message = \App\Models\Report::rewriteIdsInText(
                    $message,
                    $reportModel,
                    $displayCode,
                    is_numeric($reportId) ? (int) $reportId : null
                );
            }
            if ($note === '' && preg_match('/(?:Remark|Note)\s*:\s*(.+)$/is', $message, $matches)) {
                $note = trim($matches[1]);
            }
            // Display friendly message (strip technical Remark suffix; rewrite old NEQ wording)
            $displayMessage = $message;
            if (preg_match('/^(.*?)\s*(?:Remark|Note)\s*:/is', $message, $parts)) {
                $displayMessage = trim($parts[1]);
            }
            $actorName = trim((string) ($data['actor_name'] ?? ''));
            if (preg_match('/^NEQ\s*#\d+\s+was\s+(rejected|sent back[^.]*)\s+by\s+(.+?)(?:\.|$)/i', $displayMessage, $m)) {
                $who = $actorName !== '' ? $actorName : trim($m[2], ' .');
                if (stripos($m[1], 'sent back') !== false) {
                    $displayMessage = "Your document was sent back for correction by {$who}. Please review the note below, update the document, and submit again.";
                } else {
                    $displayMessage = "Your document was rejected by {$who}. Please review the note below for the reason.";
                }
            }
            $url = $data['url'] ?? null;
            $decision = strtolower((string) ($data['decision'] ?? ''));
            $isRead = ! is_null($notification->read_at);
            $receivedAt = optional($notification->created_at)->timezone('Asia/Kolkata');
            $seenAt = optional($notification->read_at)->timezone('Asia/Kolkata');

            if ($protocolTitle === '' && ($displayCode !== '' || $reportId)) {
                $protocolTitle = $displayCode !== '' ? $displayCode : ('Report #' . $reportId);
            }

            $badge = 'Approval';
            $badgeClass = 'badge-primary';
            if ($decision === 'sent_back') {
                $badge = 'Sent Back';
                $badgeClass = 'badge-warning';
            } elseif ($decision === 'rejected') {
                $badge = 'Rejected';
                $badgeClass = 'badge-danger';
            } elseif (str_contains(strtolower($message), 'fully approved')) {
                $badge = 'Approved';
                $badgeClass = 'badge-success';
            }
        @endphp

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="badge {{ $badgeClass }} mb-2">{{ $badge }}</span>
                        <h5 class="mb-1">
                            {{ $protocolTitle !== '' ? $protocolTitle : 'Protocol' }}
                            @if($displayCode !== '')
                                <span class="text-muted font-weight-normal">{{ $displayCode }}</span>
                            @elseif($reportId)
                                <span class="text-muted font-weight-normal">#{{ $reportId }}</span>
                            @endif
                        </h5>
                        <p class="mb-2">{{ $displayMessage }}</p>
                    </div>
                    <div class="text-right ml-3">
                        @if($isRead)
                            <div class="text-primary font-weight-bold">
                                <i class="fas fa-check-double"></i>
                                Seen
                            </div>
                        @else
                            <div class="text-muted">
                                <i class="fas fa-circle" style="font-size:0.65rem;"></i>
                                Unread
                            </div>
                        @endif
                    </div>
                </div>

                @if($note !== '')
                    <div class="alert alert-light border mb-3">
                        <div class="small text-muted mb-1">Note</div>
                        <div>{{ $note }}</div>
                    </div>
                @endif

                @php
                    $mailStatus = strtolower((string) ($data['mail_status'] ?? ''));
                @endphp
                @if($mailStatus !== '')
                    <div class="mb-3 small">
                        @if($mailStatus === 'sent')
                            <span class="text-success"><i class="fas fa-envelope"></i> A copy was also sent to your email inbox</span>
                        @elseif($mailStatus === 'failed')
                            <span class="text-warning"><i class="fas fa-envelope-open"></i> Email could not be delivered — please contact your administrator</span>
                        @else
                            <span class="text-muted"><i class="fas fa-envelope"></i> Email delivery is in progress</span>
                        @endif
                    </div>
                @endif

                <div class="d-flex flex-wrap justify-content-between align-items-center">
                    <div class="small text-muted">
                        <div>Received: {{ $receivedAt ? $receivedAt->format('d-m-Y h:i A') : '-' }}</div>
                        <div>Seen: {{ $isRead && $seenAt ? $seenAt->format('d-m-Y h:i A') : '-' }}</div>
                    </div>
                    <div class="d-flex flex-wrap mt-2 mt-md-0">
                        @if($url)
                            <a href="{{ $url }}"
                               class="btn btn-primary btn-sm mr-2"
                               onclick="event.preventDefault(); markNotificationRead('{{ $notification->id }}', {{ json_encode($url) }});">
                                Open Protocol
                            </a>
                        @endif
                        <form method="POST" action="{{ route('notifications.destroy', $notification->id) }}"
                              onsubmit="return confirm('Remove this notification?');">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <i class="fas fa-trash-alt"></i>
                                Remove
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="card">
            <div class="card-body text-center text-muted py-5">
                No notifications found.
            </div>
        </div>
    @endforelse

    <div class="mt-3">
        {{ $notifications->links() }}
    </div>
</div>
@endsection
