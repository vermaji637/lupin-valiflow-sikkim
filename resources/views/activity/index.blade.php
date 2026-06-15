@extends('layouts.app') {{-- or your dashboard layout --}}

@section('content')
<div class="container">
    <h2>User Activity Logs</h2>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>User</th>
                <th>Event</th>
                <th>Description</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
                <tr>
                    <td>{{ $log->user->first_name ?? 'System' }}</td>
                    <td>{{ $log->event }}</td>
                    <td>{{ $log->description }}</td>
                    <td>{{ $log->created_at->format('d M Y h:i A') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">No activity found</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $logs->links() }}
</div>
@endsection
