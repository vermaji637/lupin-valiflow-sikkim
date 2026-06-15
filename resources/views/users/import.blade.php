@extends('layouts.app')

@section('title', 'Import Users')

@section('content')
<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-file-import"></i>
                    User Administration
                </span>
                <h2 class="page-title">Import Users</h2>
                <p class="page-subtitle">
                    Upload users in bulk from the sample CSV format using the same premium admin flow.
                </p>
            </div>

            <div class="toolbar-row mt-3 mt-lg-0">
                <a href="{{ route('users.index') }}" class="btn btn-light btn-compact toolbar-chip">
                    <i class="fas fa-arrow-left"></i>
                    Back to Users
                </a>
            </div>
        </div>
    </section>

    @include('common.alert')

    <div class="form-shell">
        <div class="card form-main-card">
            <div class="card-header">
                <div class="section-title">Upload File</div>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('users.upload') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="upload-panel mb-4">
                        Use the required CSV structure before uploading.
                        <a href="{{ asset('files/sample-data-sheet.csv') }}" target="_blank" class="upload-link">Download sample CSV format</a>
                    </div>

                    <div class="form-section-grid">
                        <div class="form-field form-field-full">
                            <label class="form-label" for="file"><span class="form-required">*</span>CSV File</label>
                            <input type="file" id="file" name="file" class="form-control @error('file') is-invalid @enderror">
                            <span class="form-help">Upload a valid CSV datasheet only.</span>
                            @error('file')<span class="form-error">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <div class="form-actions">
                        <a class="btn btn-light btn-compact" href="{{ route('users.index') }}">Cancel</a>
                        <button type="submit" class="btn btn-primary btn-compact">
                            <i class="fas fa-upload"></i>
                            Upload Users
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card form-side-card">
            <div class="card-header">
                <div class="section-title">Import Notes</div>
            </div>
            <div class="card-body">
                <div class="form-note-list">
                    <div class="form-note-item">
                        <span class="form-note-title">Format First</span>
                        <span class="form-note-copy">Use the provided sample sheet to keep column order and headers correct.</span>
                    </div>
                    <div class="form-note-item">
                        <span class="form-note-title">Data Quality</span>
                        <span class="form-note-copy">Check emails, role values, and mobile numbers before uploading to avoid import errors.</span>
                    </div>
                    <div class="form-note-item">
                        <span class="form-note-title">Bulk Action</span>
                        <span class="form-note-copy">This process creates multiple user records in one operation, so review the file carefully.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
