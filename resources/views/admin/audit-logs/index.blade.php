@extends('layouts.app')

@section('title', 'Audit History')

@section('content')
<div class="container-fluid px-0">
    <section class="page-hero">
        <span class="page-kicker">
            <i class="fas fa-shield-alt"></i>
            Audit Control
        </span>
        <h2 class="page-title">Audit & Activity History</h2>
        <p class="page-subtitle">
            Simple activity history for non-technical users with clear before/after values.
        </p>

        <div class="metric-grid">
            <div class="metric-card">
                <span class="metric-label">Total Events</span>
                <span class="metric-value">{{ $summary['total'] }}</span>
                <span class="metric-note">Current filtered result set</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Form Submissions</span>
                <span class="metric-value">{{ $summary['requests'] }}</span>
                <span class="metric-note">Disabled to avoid duplicate audit rows</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Changes / Auth</span>
                <span class="metric-value">{{ $summary['changes'] }} / {{ $summary['auth'] }}</span>
                <span class="metric-note">Create/update/delete and login/logout events</span>
            </div>
        </div>
    </section>

    <div class="card mb-4">
        <div class="card-header">
            <div class="section-title">Filter History</div>
        </div>
        <div class="card-body">
            <form method="GET">
                <div class="row">
                    <div class="col-lg-3 mb-3">
                        {{-- @if(\App\Services\SidebarPermissionService::userCan('audit-filter-users')) --}}
                        @if(\App\Services\SidebarPermissionService::userCan('audit-view'))
                            <label class="small text-muted">User</label>
                            <select name="user_id" class="form-control">
                                <option value="">All Users</option>
                                @foreach($users as $user)
                                    @php $fullName = trim(($user->first_name ?? '').' '.($user->last_name ?? '')); @endphp
                                    <option value="{{ $user->id }}" @selected(request('user_id')==$user->id)>
                                        {{ $fullName !== '' ? $fullName : $user->email }}
                                    </option>
                                @endforeach
                            </select>
                        @else
                            <label class="small text-muted">User</label>
                            <input class="form-control" value="{{ trim((auth()->user()->first_name ?? '').' '.(auth()->user()->last_name ?? '')) }}" disabled>
                        @endif
                    </div>

                    <div class="col-lg-2 mb-3">
                        <label class="small text-muted">Event</label>
                        <select name="event" class="form-control">
                            <option value="">All Events</option>
                            @foreach($events as $event)
                                <option value="{{ $event }}" @selected(request('event')==$event)>
                                    {{ strtoupper($event) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-lg-2 mb-3">
                        <label class="small text-muted">From Date</label>
                        <input type="date" name="from_date" value="{{ request('from_date') }}" class="form-control">
                    </div>

                    <div class="col-lg-2 mb-3">
                        <label class="small text-muted">To Date</label>
                        <input type="date" name="to_date" value="{{ request('to_date') }}" class="form-control">
                    </div>

                    {{-- <div class="col-lg-3 mb-3">
                        <label class="small text-muted">Search</label>
                        <input type="text" name="search" value="{{ request('search') }}"
                               class="form-control" placeholder="User / event / model / URL / IP">
                    </div> --}}
                </div>

                <div class="toolbar-row">
                    <button type="submit" class="btn btn-primary btn-compact">Apply Filters</button>
                    <a href="{{ route('admin.audit.logs') }}" class="btn btn-light btn-compact">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="section-title">History Log</div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0">
                    <thead>
                        <tr>
                            <th>Date &amp; Time</th>
                            <th>User</th>
                            <th>Activity Performed</th>
                            <th>Status</th>
                            <th>Field Changed</th>
                            <th>Previous Value</th>
                            <th>New Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($logs as $log)
                            <tr>
                                <td>{{ optional($log->created_at)->format('d-m-Y h:i A') }}</td>
                                <td>
                                    <div class="font-weight-bold">{{ $log->actor_name ?? 'System' }}</div>
                                    <div class="small text-muted">{{ $log->actor_email ?? '' }}</div>
                                </td>
                                <td>{{ $log->activity_label ?? '—' }}</td>
                                <td>
                                    <span class="badge {{ ($log->status_label ?? 'Success') === 'Success' ? 'badge-success' : 'badge-danger' }}">
                                        {{ $log->status_label ?? 'Success' }}
                                    </span>
                                </td>
                                <td style="white-space: pre-line;">{{ $log->changed_fields_text ?? '—' }}</td>
                                <td style="white-space: pre-line;">{{ $log->previous_value_text ?? '—' }}</td>
                                <td style="white-space: pre-line;">{{ $log->new_value_text ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">No audit history found for the current filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-4">
        {{ $logs->links() }}
    </div>
</div>
@endsection
