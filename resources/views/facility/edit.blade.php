@extends('layouts.app')

@section('title', 'Edit Facility')

@section('content')
<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-pen-square"></i>
                    Master Control
                </span>
                <h2 class="page-title">Edit Facility</h2>
                <p class="page-subtitle">
                    Update the facility master record while keeping naming aligned across linked modules.
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
                <form method="POST" action="{{ route('facility.update', $facility->id) }}">
                    @csrf
                    @method('PUT')
                    <div class="form-section-grid">
                        <div class="form-field form-field-full">
                            <label class="form-label" for="facility_name"><span class="form-required">*</span>Facility Name</label>
                            <input type="text" id="facility_name" name="facility_name" value="{{ old('facility_name', $facility->facility_name) }}" class="form-control @error('facility_name') is-invalid @enderror" required>
                            @error('facility_name')<span class="form-error">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="{{ route('facility.index') }}" class="btn btn-light btn-compact">Cancel</a>
                        <button class="btn btn-primary btn-compact" type="submit">
                            <i class="fas fa-save"></i>
                            Update Facility
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
                        <span class="form-note-copy">#{{ $facility->id }}</span>
                    </div>
                    <div class="form-note-item">
                        <span class="form-note-title">Current Name</span>
                        <span class="form-note-copy">{{ $facility->facility_name }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
