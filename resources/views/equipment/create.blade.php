@extends('layouts.app')

@section('title', 'Add Equipment')

@section('content')
<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-cog"></i>
                    Master Control
                </span>
                <h2 class="page-title">Add Equipment</h2>
                <p class="page-subtitle">
                    Register a new equipment record with identity, facility, and approver mapping.
                </p>
            </div>

            <div class="toolbar-row mt-3 mt-lg-0">
                <a href="{{ route('equipment.index') }}" class="btn btn-light btn-compact toolbar-chip">
                    <i class="fas fa-arrow-left"></i>
                    Back to Equipment
                </a>
            </div>
        </div>
    </section>

    @include('common.alert')

    <div class="form-shell">
        <div class="card form-main-card">
            <div class="card-header">
                <div class="section-title">Equipment Details</div>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('equipment.store') }}" id="equipmentForm" class="esign-required">
                    @csrf

                    <div class="form-section-grid">
                        <div class="form-field">
                            <label class="form-label" for="equipment_name"><span class="form-required">*</span>Equipment Name</label>
                            <input type="text" id="equipment_name" name="equipment_name" class="form-control @error('equipment_name') is-invalid @enderror" placeholder="Enter equipment name" value="{{ old('equipment_name') }}" required>
                            @error('equipment_name')<span class="form-error">{{ $message }}</span>@enderror
                        </div>

                        <div class="form-field">
                            <label class="form-label" for="equipment_id"><span class="form-required">*</span>Equipment ID</label>
                            <input type="text" id="equipment_id" name="equipment_id" class="form-control @error('equipment_id') is-invalid @enderror" placeholder="Enter equipment ID" value="{{ old('equipment_id') }}" required>
                            @error('equipment_id')<span class="form-error">{{ $message }}</span>@enderror
                        </div>

                        <div class="form-field">
                            <label class="form-label" for="facility"><span class="form-required">*</span>Facility</label>
                            <input type="text" id="facility" name="facility" class="form-control @error('facility') is-invalid @enderror" placeholder="Enter facility name" value="{{ old('facility') }}" required>
                            @error('facility')<span class="form-error">{{ $message }}</span>@enderror
                        </div>

                        <div class="form-field">
                            <label class="form-label" for="approver_username"><span class="form-required">*</span>Approver</label>
                            <select id="approver_username" name="approver_username" class="form-control @error('approver_username') is-invalid @enderror" required>
                                <option value="">Select approver</option>
                                @foreach($users as $user)
                                    <option value="{{ $user->id }}" @selected(old('approver_username') == $user->id)>
                                        {{ $user->first_name }} {{ $user->last_name }} ({{ $user->email }})
                                    </option>
                                @endforeach
                            </select>
                            @error('approver_username')<span class="form-error">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <input type="hidden" name="password_verified" id="password_verified" value="0">

                    <div class="form-actions">
                        <a href="{{ route('equipment.index') }}" class="btn btn-light btn-compact">Cancel</a>
                        <button type="submit" class="btn btn-primary btn-compact">
                            <i class="fas fa-save"></i>
                            Save Equipment
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
                        <span class="form-note-title">Unique Code</span>
                        <span class="form-note-copy">Equipment ID should be unique because documents and workflows depend on this identifier.</span>
                    </div>
                    <div class="form-note-item">
                        <span class="form-note-title">Facility Mapping</span>
                        <span class="form-note-copy">Use the final facility name that should appear across document and report modules.</span>
                    </div>
                    <div class="form-note-item">
                        <span class="form-note-title">Approver</span>
                        <span class="form-note-copy">The selected approver becomes the responsible owner for this equipment record.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('equipmentForm');
    const passwordVerifiedInput = document.getElementById('password_verified');

    if (!form || !passwordVerifiedInput) return;
    form.addEventListener('submit', function () {
        passwordVerifiedInput.value = 1;
    });
});
</script>
@endsection
