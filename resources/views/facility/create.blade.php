@extends('layouts.app')

@section('title', 'Add Facility')

@section('content')
<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-building"></i>
                    Master Control
                </span>
                <h2 class="page-title">Add Facility</h2>
                <p class="page-subtitle">
                    Create a facility master record used by equipment, documents, and reports.
                </p>
            </div>

            <div class="toolbar-row mt-3 mt-lg-0">
                <a href="{{ route('facility.index') }}" class="btn btn-light btn-compact toolbar-chip">
                    <i class="fas fa-arrow-left"></i>
                    Back to Facilities
                </a>
            </div>
        </div>
    </section>

    @include('common.alert')

    <div class="form-shell">
        <div class="card form-main-card">
            <div class="card-header">
                <div class="section-title">Facility Details</div>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('facility.store') }}">
                    @csrf
                    <div class="form-section-grid">
                        <div class="form-field form-field-full">
                            <label class="form-label" for="facility_name"><span class="form-required">*</span>Facility Name</label>
                            <input type="text" id="facility_name" name="facility_name" class="form-control @error('facility_name') is-invalid @enderror" placeholder="Enter facility name" value="{{ old('facility_name') }}" required>
                            @error('facility_name')<span class="form-error">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="{{ route('facility.index') }}" class="btn btn-light btn-compact">Cancel</a>
                        <button class="btn btn-primary btn-compact" type="submit">
                            <i class="fas fa-save"></i>
                            Save Facility
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
                        <span class="form-note-title">Standard Naming</span>
                        <span class="form-note-copy">Use the exact facility naming standard required across equipment and document modules.</span>
                    </div>
                    <div class="form-note-item">
                        <span class="form-note-title">Reuse</span>
                        <span class="form-note-copy">This value appears in multiple workflows, so avoid shorthand that may confuse approvers or QA.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
