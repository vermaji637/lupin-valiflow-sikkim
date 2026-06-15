@extends('layouts.app')

@section('title', 'Profile')

@section('content')
<div>
    <x-slot name="header"><h2>Create Protocol</h2></x-slot>

    <form method="POST" action="{{ route('protocols.store') }}" class="space-y-4">
        @csrf
        <input name="title" class="border p-2 w-full" placeholder="Title" required>
        <textarea name="description" class="border p-2 w-full" placeholder="Description"></textarea>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label>Reviewer</label>
                <select name="reviewer_id" class="border p-2 w-full" required>
                    <option value="">-- Select --</option>
                    @foreach($users as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Checker</label>
                <select name="checker_id" class="border p-2 w-full" required>
                    <option value="">-- Select --</option>
                    @foreach($users as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Validator</label>
                <select name="validator_id" class="border p-2 w-full" required>
                    <option value="">-- Select --</option>
                    @foreach($users as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <button class="bg-blue-600 text-white px-4 py-2 rounded">Create</button>
    </form>
</div>
@endsection