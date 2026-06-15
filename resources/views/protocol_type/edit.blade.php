@extends('layouts.app')

@section('title', 'Edit Protocol Type')

@section('content')
<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-pen-square"></i>
                    Master Control
                </span>
                <h2 class="page-title">Edit Protocol Type</h2>
                <p class="page-subtitle">
                    Update the protocol classification while keeping downstream naming consistent.
                </p>
            </div>

            <div class="toolbar-row mt-3 mt-lg-0">
                <a href="{{ route('protocol-type.index') }}" class="btn btn-light btn-compact toolbar-chip">
                    <i class="fas fa-arrow-left"></i>
                    Back to Protocol Types
                </a>
            </div>
        </div>
    </section>

    @include('common.alert')

    <div class="form-shell">
        <div class="card form-main-card">
            <div class="card-header">
                <div class="section-title">Protocol Type Details</div>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('protocol-type.update', $protocol->id) }}">
                    @csrf
                    @method('PUT')

                    <div class="form-section-grid">
                        <div class="form-field form-field-full">
                            <label class="form-label" for="protocol_type_name"><span class="form-required">*</span>Protocol Type Name</label>
                            <input type="text" id="protocol_type_name" name="protocol_type_name" class="form-control @error('protocol_type_name') is-invalid @enderror" placeholder="Enter protocol type name" value="{{ old('protocol_type_name', $protocol->protocol_type_name) }}" required>
                            @error('protocol_type_name')<span class="form-error">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="{{ route('protocol-type.index') }}" class="btn btn-light btn-compact">Cancel</a>
                        <button class="btn btn-primary btn-compact" type="submit">
                            <i class="fas fa-save"></i>
                            Update Protocol Type
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card form-side-card">
            <div class="card-header">
                <div class="section-title">Current Record</div>
            </div>
            <div class="card-body">
                <div class="form-note-list">
                    <div class="form-note-item">
                        <span class="form-note-title">Record ID</span>
                        <span class="form-note-copy">#{{ $protocol->id }}</span>
                    </div>
                    <div class="form-note-item">
                        <span class="form-note-title">Current Name</span>
                        <span class="form-note-copy">{{ $protocol->protocol_type_name }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
