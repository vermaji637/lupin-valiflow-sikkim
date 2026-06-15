@extends('layouts.app')

@section('content')
<div class="container">
    <h2>Notifications</h2>

    @if(session('status'))
        <div style="color: green;">
            {{ session('status') }}
        </div>
    @endif

    @forelse($notifications as $notification)
        <div style="border:1px solid #ccc; padding:10px; margin-bottom:10px;">
            <p>{{ $notification->data['message'] ?? 'No message' }}</p>

            @if(is_null($notification->read_at))
                <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                    @endif
        </div>
    @empty
        <p>No notifications found.</p>
    @endforelse

    {{ $notifications->links() }}
</div>
@endsection
