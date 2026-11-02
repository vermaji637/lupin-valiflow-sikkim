@php
    $user = auth()->user();
   $unreadCount = $user ? $user->unreadNotifications()->count() : 0;
    // Relationship already orders by created_at DESC; do not call latest() again
    // (SQL Server rejects duplicate ORDER BY columns).
    $notifs = $user ? $user->notifications()->take(40)->get() : collect();
    $pageTitle = trim($__env->yieldContent('title')) ?: 'Workflow Workspace';
    $displayName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
    if ($displayName === '') {
        $displayName = $user->full_name ?? $user->fullname ?? $user->name ?? 'User';
    }
@endphp

<nav class="navbar navbar-expand topbar">

    <div class="d-flex align-items-center">
        <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
            <i class="fa fa-bars"></i>
        </button>

        <div class="topbar-heading">
            <span class="eyebrow">Qualification Operations</span>
            <h1 class="topbar-title">{{ $pageTitle }}</h1>
            <span class="topbar-subtitle">Controlled workflows, approvals, documents, and reporting in one workspace.</span>
        </div>
    </div>

    <ul class="navbar-nav ml-auto align-items-center">

        <li class="nav-item d-none d-xl-flex mr-3">
            <div class="chip">
                <span class="status-dot" style="background:#157a5c;"></span>
                Secure session active
            </div>
        </li>

        <li class="nav-item dropdown no-arrow mx-1">
            <a class="nav-link dropdown-toggle"
               href="#"
               id="alertsDropdown"
               role="button"
               data-toggle="dropdown"
               aria-haspopup="true"
               aria-expanded="false">

                <i class="fas fa-bell fa-fw"></i>

                @if($unreadCount > 0)
                    <span class="badge badge-danger badge-counter">
                        {{ $unreadCount }}
                    </span>
                @endif
            </a>

            <div class="dropdown-list dropdown-menu dropdown-menu-right neq-notif-dropdown"
                 aria-labelledby="alertsDropdown">

                <h6 class="dropdown-header d-flex justify-content-between align-items-center">
                    Notifications

                    @if($unreadCount > 0)
                        <form method="POST" action="{{ route('notifications.readAll') }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-light">
                                Mark all read
                            </button>
                        </form>
                    @endif
                </h6>

                <div class="neq-notif-scroll" style="height:360px;max-height:360px;overflow-y:scroll;overflow-x:hidden;">
                @forelse($notifs as $n)
                    @php
                        $nData = $n->data ?? [];
                        if (! is_array($nData)) {
                            $nData = json_decode((string) $nData, true) ?: [];
                        }
                        $url = $nData['url'] ?? '#';
                        $message = (string) ($nData['message'] ?? 'Notification');
                        $protocolTitle = trim((string) ($nData['report_title'] ?? ''));
                        $reportId = (int) ($nData['report_id'] ?? 0);
                        $displayCode = trim((string) ($nData['display_code'] ?? ''));
                        if ($displayCode === '' && $reportId > 0) {
                            $reportModel = \App\Models\Report::query()->find($reportId);
                            $displayCode = $reportModel ? $reportModel->displayCode() : '';
                        }
                        if ($displayCode !== '') {
                            $message = \App\Models\Report::rewriteIdsInText($message, null, $displayCode, $reportId > 0 ? $reportId : null);
                        }
                        $note = trim((string) ($nData['remark'] ?? $nData['note'] ?? ''));
                        if ($note === '' && preg_match('/(?:Remark|Note)\s*:\s*(.+)$/is', $message, $matches)) {
                            $note = trim($matches[1]);
                        }
                        $decision = strtolower((string) ($nData['decision'] ?? ''));
                        $mailStatus = strtolower((string) ($nData['mail_status'] ?? ''));
                        $actorName = trim((string) ($nData['actor_name'] ?? ''));
                        $isUnread = is_null($n->read_at);
                        $seenAt = optional($n->read_at)->timezone('Asia/Kolkata');

                        // Soften old technical messages for display
                        $messagePreview = $message;
                        if ($note !== '' && preg_match('/^(.*?)\s*(?:Remark|Note)\s*:/is', $message, $parts)) {
                            $messagePreview = trim($parts[1]);
                        }
                        if (preg_match('/^NEQ\s*#\d+\s+was\s+(rejected|sent back[^.]*)\s+by\s+(.+?)(?:\.|$)/i', $messagePreview, $m)) {
                            $who = $actorName !== '' ? $actorName : trim($m[2], " .");
                            if (stripos($m[1], 'sent back') !== false) {
                                $messagePreview = "Your document was sent back for correction by {$who}. Please review the note and update it.";
                            } else {
                                $messagePreview = "Your document was rejected by {$who}. Please review the note for the reason.";
                            }
                        }
                    @endphp

                    <div class="dropdown-item neq-notif-item {{ $isUnread ? 'bg-light' : '' }}"
                         data-notif-id="{{ $n->id }}">
                        <div class="d-flex justify-content-between align-items-start">
                            <button type="button"
                                    class="btn btn-link text-left p-0 text-decoration-none text-dark neq-notif-open"
                                    style="min-width:0; flex:1;"
                                    data-notif-id="{{ $n->id }}"
                                    data-notif-url="{{ $url }}">
                                <div class="{{ $isUnread ? 'font-weight-bold' : '' }}">
                                    {{ $protocolTitle !== '' ? \Illuminate\Support\Str::limit($protocolTitle, 70) : \Illuminate\Support\Str::limit($messagePreview, 70) }}
                                </div>
                                @if($protocolTitle !== '')
                                    <div class="small text-body">{{ \Illuminate\Support\Str::limit($messagePreview, 110) }}</div>
                                @endif
                                @if($note !== '')
                                    <div class="small mt-1 p-1 rounded neq-notif-note"
                                         style="background: {{ $decision === 'rejected' ? '#f8d7da' : '#fff3cd' }}; border: 1px solid {{ $decision === 'rejected' ? '#f5c6cb' : '#ffeeba' }};">
                                        <strong>Note:</strong> {{ \Illuminate\Support\Str::limit($note, 140) }}
                                    </div>
                                @elseif(in_array($decision, ['sent_back', 'rejected'], true))
                                    <div class="small mt-1 text-muted">No note was added.</div>
                                @endif
                                @if($mailStatus !== '')
                                    <div class="small mt-1">
                                        @if($mailStatus === 'sent')
                                            <span class="text-success"><i class="fas fa-envelope"></i> Email also sent to your inbox</span>
                                        @elseif($mailStatus === 'failed')
                                            <span class="text-warning"><i class="fas fa-envelope-open"></i> Email could not be delivered — contact your admin</span>
                                        @else
                                            <span class="text-muted"><i class="fas fa-envelope"></i> Email delivery in progress</span>
                                        @endif
                                    </div>
                                @endif
                                <div class="small text-gray-500">
                                    {{ optional($n->created_at)->diffForHumans() }}
                                    @if(! $isUnread && $seenAt)
                                        · Seen {{ $seenAt->format('d-m-Y h:i A') }}
                                    @endif
                                </div>
                            </button>
                            <button type="button"
                                    class="btn btn-sm btn-link text-danger neq-notif-delete ml-1"
                                    title="Delete notification"
                                    data-notif-id="{{ $n->id }}"
                                    onclick="event.preventDefault(); event.stopPropagation(); deleteNotification('{{ $n->id }}', this);">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="dropdown-item text-muted">
                        No notifications
                    </div>
                @endforelse
                </div>

                <a class="dropdown-item text-center small text-gray-500"
                   href="{{ route('notifications.index') }}">
                    View all
                </a>
            </div>
        </li>

        <li class="nav-item dropdown no-arrow">
            <a class="nav-link dropdown-toggle d-flex align-items-center"
               href="#"
               id="userDropdown"
               role="button"
               data-toggle="dropdown"
               aria-haspopup="true"
               aria-expanded="false">

                <span class="mr-2 d-none d-lg-inline text-right">
                    <span class="d-block font-weight-bold text-dark">{{ $displayName }}</span>
                    <span class="small text-muted">{{ $user?->email ?? '' }}</span>
                </span>

                <img class="img-profile rounded-circle"
                     src="{{ asset('admin/img/undraw_profile.svg') }}"
                     alt="Profile">
            </a>

            <div class="dropdown-menu dropdown-menu-right animated--grow-in"
                 aria-labelledby="userDropdown">

                <a class="dropdown-item" href="{{ route('profile.detail') }}">
                    <i class="fas fa-user fa-sm fa-fw mr-2 text-gray-400"></i>
                    Profile
                </a>

                <div class="dropdown-divider"></div>

                <a class="dropdown-item" href="#"
                   data-toggle="modal"
                   data-target="#logoutModal">
                    <i class="fas fa-sign-out-alt fa-sm fa-fw mr-2 text-gray-400"></i>
                    Logout
                </a>
            </div>
        </li>

    </ul>

</nav>

{{-- Notification detail modal --}}
<div class="modal fade" id="neqNotifDetailModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="neqNotifDetailTitle">Notification</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="neqNotifDetailBody">
                <p class="text-muted mb-0">Loading…</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>
                <a href="#" class="btn btn-primary btn-sm d-none" id="neqNotifDetailOpen">Open</a>
            </div>
        </div>
    </div>
</div>

<style>
/* Notification panel: fixed scroll area (beats SB Admin / premium-ui overrides) */
body.premium-app .topbar,
.topbar {
    overflow: visible !important;
}
body.premium-app .topbar .dropdown-menu.neq-notif-dropdown,
.topbar .dropdown-menu.neq-notif-dropdown,
.neq-notif-dropdown.dropdown-list,
.neq-notif-dropdown {
    min-width: 380px !important;
    width: min(440px, 92vw) !important;
    max-width: 440px !important;
    padding: 0 !important;
    max-height: 480px !important;
    overflow: hidden !important;
    position: absolute !important;
}
body.premium-app .topbar .dropdown-menu.neq-notif-dropdown.show,
.topbar .dropdown-menu.neq-notif-dropdown.show,
.neq-notif-dropdown.show {
    display: flex !important;
    flex-direction: column !important;
}
body.premium-app .topbar .neq-notif-dropdown > .dropdown-header,
.neq-notif-dropdown > .dropdown-header,
body.premium-app .topbar .neq-notif-dropdown > a.dropdown-item,
.neq-notif-dropdown > a.dropdown-item {
    flex: 0 0 auto;
}
body.premium-app .topbar .neq-notif-scroll,
.topbar .neq-notif-scroll,
.neq-notif-scroll {
    display: block !important;
    flex: 1 1 auto !important;
    min-height: 0 !important;
    height: 360px !important;
    max-height: 360px !important;
    overflow-x: hidden !important;
    overflow-y: scroll !important;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior: contain;
}
.neq-notif-item {
    white-space: normal !important;
    border-bottom: 1px solid #edf2f7;
}
.neq-notif-item:hover {
    background: #f8fafc;
}
.neq-notif-delete {
    line-height: 1;
    padding: 0.15rem 0.35rem !important;
    font-size: 1.1rem;
    opacity: 0.7;
}
.neq-notif-delete:hover {
    opacity: 1;
}
</style>

<script>
function markNotificationRead(id, url) {
    fetch("{{ url('/notifications') }}/" + id + "/read", {
        method: "POST",
        headers: {
            "X-CSRF-TOKEN": "{{ csrf_token() }}",
            "Accept": "application/json",
            "X-Requested-With": "XMLHttpRequest"
        }
    }).finally(function () {
        if (url && url !== '#') {
            window.location = url;
        }
    });
}

function deleteNotification(id, btn) {
    if (!id) return;
    fetch("{{ url('/notifications') }}/" + id + "/delete", {
        method: "POST",
        headers: {
            "X-CSRF-TOKEN": "{{ csrf_token() }}",
            "Accept": "application/json",
            "X-Requested-With": "XMLHttpRequest"
        }
    }).then(function (res) {
        if (!res.ok) throw new Error('Delete failed');
        var row = btn && btn.closest ? btn.closest('.neq-notif-item') : null;
        var wasUnread = !!(row && row.classList.contains('bg-light'));
        if (row) row.remove();
        if (wasUnread) {
            var badge = document.querySelector('#alertsDropdown .badge-counter');
            if (badge) {
                var n = parseInt(badge.textContent, 10) || 0;
                if (n > 1) badge.textContent = String(n - 1);
                else badge.remove();
            }
        }
    }).catch(function () {
        alert('Could not delete notification.');
    });
}

document.addEventListener('wheel', function (event) {
    var scrollBox = event.target.closest && event.target.closest('.neq-notif-scroll');
    if (!scrollBox) return;
    // Keep wheel scrolling inside the notification list (do not scroll the page).
    event.stopPropagation();
}, { passive: true });

document.addEventListener('shown.bs.dropdown', function (event) {
    var toggle = event.target;
    if (!toggle || toggle.id !== 'alertsDropdown') return;
    var menu = document.querySelector('.neq-notif-dropdown');
    var scrollBox = menu && menu.querySelector('.neq-notif-scroll');
    if (!scrollBox) return;
    scrollBox.style.height = '360px';
    scrollBox.style.maxHeight = '360px';
    scrollBox.style.overflowY = 'scroll';
    scrollBox.style.overflowX = 'hidden';
});

// Bootstrap 4 fallback (jQuery)
if (window.jQuery) {
    window.jQuery(document).on('shown.bs.dropdown', '#alertsDropdown', function () {
        var scrollBox = document.querySelector('.neq-notif-dropdown .neq-notif-scroll');
        if (!scrollBox) return;
        scrollBox.style.height = '360px';
        scrollBox.style.maxHeight = '360px';
        scrollBox.style.overflowY = 'scroll';
        scrollBox.style.overflowX = 'hidden';
    });
}

document.addEventListener('click', function (event) {
    var openBtn = event.target.closest && event.target.closest('.neq-notif-open');
    if (!openBtn) return;
    event.preventDefault();
    event.stopPropagation();
    var id = openBtn.getAttribute('data-notif-id');
    var fallbackUrl = openBtn.getAttribute('data-notif-url') || '#';
    if (!id) return;

    fetch("{{ url('/notifications') }}/" + id, {
        headers: {
            "Accept": "application/json",
            "X-Requested-With": "XMLHttpRequest"
        }
    }).then(function (res) { return res.json(); }).then(function (payload) {
        var data = payload.data || {};
        var note = (data.remark || data.note || '').trim();
        var rawMessage = String(data.message || '');
        if (!note) {
            var m = rawMessage.match(/(?:Remark|Note)\s*:\s*(.+)$/i);
            if (m) note = m[1].trim();
        }
        var displayMessage = rawMessage.replace(/\s*(?:Remark|Note)\s*:\s*.+$/i, '').trim();
        var actorName = String(data.actor_name || '').trim();
        var oldTech = displayMessage.match(/^NEQ\s*#\d+\s+was\s+(rejected|sent back[^.]*)\s+by\s+(.+?)(?:\.|$)/i);
        if (oldTech) {
            var who = actorName || String(oldTech[2] || '').replace(/\.\s*$/, '').trim();
            if (/sent back/i.test(oldTech[1])) {
                displayMessage = 'Your document was sent back for correction by ' + who + '. Please review the note below, update the document, and submit again.';
            } else {
                displayMessage = 'Your document was rejected by ' + who + '. Please review the note below for the reason.';
            }
        }

        var mailStatus = String(data.mail_status || '').toLowerCase();
        var mailLine = '';
        if (mailStatus === 'sent') {
            mailLine = 'A copy of this update was also sent to your email inbox.';
        } else if (mailStatus === 'failed') {
            mailLine = 'Email could not be delivered to your inbox. Please contact your administrator if you need a copy.';
        } else if (mailStatus === 'pending') {
            mailLine = 'Email delivery is in progress.';
        }

        var title = data.report_title || 'Notification';
        var body = '<p class="mb-2">' + escapeHtml(displayMessage) + '</p>';
        if (note) {
            body += '<div class="alert alert-warning py-2"><strong>Note:</strong><br>' + escapeHtml(note) + '</div>';
        } else if (data.decision === 'sent_back' || data.decision === 'rejected') {
            body += '<div class="alert alert-secondary py-2"><strong>Note:</strong> No note was added.</div>';
        }
        if (mailLine) {
            body += '<p class="mb-1"><i class="fas fa-envelope"></i> ' + escapeHtml(mailLine) + '</p>';
        }
        if (payload.created_at) {
            body += '<p class="small text-muted mb-0">Received: ' + escapeHtml(payload.created_at) + '</p>';
        }

        document.getElementById('neqNotifDetailTitle').textContent = title;
        document.getElementById('neqNotifDetailBody').innerHTML = body;
        var openLink = document.getElementById('neqNotifDetailOpen');
        var url = data.url || fallbackUrl;
        if (url && url !== '#') {
            openLink.href = url;
            openLink.classList.remove('d-none');
        } else {
            openLink.classList.add('d-none');
        }
        if (window.jQuery) {
            window.jQuery('#neqNotifDetailModal').modal('show');
        }
    }).catch(function () {
        if (fallbackUrl && fallbackUrl !== '#') window.location = fallbackUrl;
    });
});

function escapeHtml(str) {
    return String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
</script>
