@extends('layouts.app')
@section('content')
<div class="container py-3">
    <h4 class="mb-3">Pending Documents</h4>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @forelse($documents as $index => $doc)
        <form method="POST" action="{{ route('qa.documents.update') }}" class="d-flex align-items-center mb-2 flex-wrap esign-required" >
            @csrf
            <input type="hidden" name="document_id" value="{{ $doc->id }}">

            <!-- Serial No -->
            <div class="me-2 fw-bold" style="width: 40px; text-align: center;">
                {{ $index + 1 }}
            </div>

            <!-- Document Title -->
            <div class="me-2 fw-bold flex-shrink-1" style="min-width: 200px;">
                {{ $doc->title }}
            </div>

            <!-- Input Box -->
            <div class="me-2 flex-grow-1">
                <input type="text" name="document_text" 
                       class="form-control"
                       maxlength="255"
                       placeholder="Enter document text"
                       oninput="document.getElementById('counter-{{ $doc->id }}').innerText = this.value.length"
                       required>
            </div>

          

            <!-- Submit Button -->
             <div class="me-2" style="width: 60px; text-align: center;">
                <button type="submit" class="btn btn-success btn-sm">Submit</button>
            </div>
        </form>
    @empty
        <p class="text-muted">No pending documents.</p>
    @endforelse
</div>
@endsection
