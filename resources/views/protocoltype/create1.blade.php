@extends('layouts.app')

@section('title', 'Add Protocol Type')

@section('content')
<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-layer-group"></i>
                    Master Control
                </span>
                <h2 class="page-title">Add Protocol Type</h2>
                <p class="page-subtitle">
                    Create a protocol category used in templates, documents, and validation workflows.
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
                <form method="POST" action="{{ route('protocol-type.store') }}">
                    @csrf
                    <div class="form-section-grid">
                        <div class="form-field form-field-full">
                            <label class="form-label" for="protocol_type_name"><span class="form-required">*</span>Protocol Type Name</label>
                            <input type="text" id="protocol_type_name" name="protocol_type_name" class="form-control @error('protocol_type_name') is-invalid @enderror" placeholder="Enter protocol type name" value="{{ old('protocol_type_name') }}" required>
                            @error('protocol_type_name')<span class="form-error">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="{{ route('protocol-type.index') }}" class="btn btn-light btn-compact">Cancel</a>
                        <button class="btn btn-primary btn-compact" type="submit">
                            <i class="fas fa-save"></i>
                            Save Protocol Type
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card form-side-card">
            <div class="card-header">
                <div class="section-title">Guidance</div>
            </div>
            <div class="card-body">
                <div class="form-note-list">
                    <div class="form-note-item">
                        <span class="form-note-title">Naming</span>
                        <span class="form-note-copy">Keep the protocol type name short, clear, and consistent with your internal validation standards.</span>
                    </div>
                    <div class="form-note-item">
                        <span class="form-note-title">Reusability</span>
                        <span class="form-note-copy">This master value is reused across multiple modules, so avoid duplicate naming patterns.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
