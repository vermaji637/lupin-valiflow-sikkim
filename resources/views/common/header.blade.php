@php
    $user = auth()->user();
   $unreadCount = $user ? $user->unreadNotifications()->count() : 0;
    $notifs = $user ? $user->notifications()->take(10)->get() : collect();
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

            <div class="dropdown-list dropdown-menu dropdown-menu-right animated--grow-in"
                 aria-labelledby="alertsDropdown"
                 style="min-width: 360px;">

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

                @forelse($notifs as $n)
                    @php
                        $url = $n->data['url'] ?? '#';
                        $message = $n->data['message'] ?? 'Notification';
                        $isUnread = is_null($n->read_at);
                    @endphp

                    <a class="dropdown-item d-flex align-items-center {{ $isUnread ? 'bg-light' : '' }}"
                       href="{{ $url }}"
                       onclick="event.preventDefault(); markNotificationRead('{{ $n->id }}','{{ $url }}');">

                        <div class="mr-3">
                            <div class="icon-circle {{ $isUnread ? 'bg-primary' : 'bg-secondary' }}">
                                <i class="fas fa-info text-white"></i>
                            </div>
                        </div>

                        <div>
                            <div class="{{ $isUnread ? 'font-weight-bold' : '' }}">
                                {{ $message }}
                            </div>
                            <div class="small text-gray-500">
                                {{ optional($n->created_at)->diffForHumans() }}
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="dropdown-item text-muted">
                        No notifications
                    </div>
                @endforelse

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

{{-- JS to mark notification read --}}
<script>
function markNotificationRead(id, url) {
    fetch("{{ url('/notifications/read') }}/" + id, {
        method: "POST",
        headers: {
            "X-CSRF-TOKEN": "{{ csrf_token() }}",
            "Accept": "application/json"
        }
    }).then(() => {
        window.location = url;
    });
}
</script>
