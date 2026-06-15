<x-app-layout>
    <x-slot name="header"><h2>My Pending Protocol Tasks</h2></x-slot>

    @if(session('success'))
        <div class="bg-green-100 border border-green-300 p-2 rounded mb-3">
            {{ session('success') }}
        </div>
    @endif

    @forelse($tasks as $task)
        <div class="border rounded p-3 mb-3">
            <div class="font-semibold">{{ $task->protocol->title }}</div>
            <div>Step: {{ ucfirst($task->step) }} ({{ $task->sequence }}/3)</div>
            <div>Status: {{ $task->status }}</div>

            <form method="POST" action="{{ route('workflow.complete', $task->id) }}" class="mt-2">
                @csrf
                <button class="bg-emerald-600 text-white px-3 py-1 rounded"
                        onclick="return confirm('Mark this step as completed?')">
                    Complete Step
                </button>
            </form>
        </div>
    @empty
        <p>No actionable tasks right now.</p>
    @endforelse
</x-app-layout>
