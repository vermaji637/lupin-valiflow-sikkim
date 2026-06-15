@extends('layouts.app')

@section('title', 'Confirm Equipment Import')

@section('content')
<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-file-csv"></i>
                    Bulk Import
                </span>
                <h2 class="page-title">Confirm Equipment Import</h2>
                <p class="page-subtitle">
                    Review the uploaded file, assign an approver for all new records, then confirm the import.
                </p>
            </div>

            <div class="toolbar-row mt-3 mt-lg-0">
                <a href="{{ route('equipment.import.cancel') }}" class="btn btn-light btn-compact toolbar-chip">
                    <i class="fas fa-arrow-left"></i>
                    Cancel
                </a>
            </div>
        </div>
    </section>

    @include('common.alert')

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible" role="alert">
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
            <strong>Please fix the following:</strong>
            <ul class="mb-0 mt-2">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($importableRows === 0)
        <div class="alert alert-warning" role="alert">
            <strong>Nothing to import.</strong>
            All {{ $totalRows }} row(s) in this file already exist in the system (duplicate equipment_id).
            Upload a different file or remove existing records first.
        </div>
    @endif

    <div class="form-shell">
        <div class="card form-main-card">
            <div class="card-header">
                <div class="section-title">Import Summary</div>
            </div>
            <div class="card-body">
                <div class="metric-grid mb-4">
                    <div class="metric-card">
                        <span class="metric-label">File</span>
                        <span class="metric-value" style="font-size:1.1rem;">{{ $originalName }}</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label">Rows in file</span>
                        <span class="metric-value">{{ $totalRows }}</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label">Will import</span>
                        <span class="metric-value">{{ $importableRows }}</span>
                        <span class="metric-note">Pending approval workflow</span>
                    </div>
                    @if($duplicateRows > 0)
                        <div class="metric-card">
                            <span class="metric-label">Skipped duplicates</span>
                            <span class="metric-value">{{ $duplicateRows }}</span>
                            <span class="metric-note">Existing equipment_id values</span>
                        </div>
                    @endif
                </div>

                @if($previewRows->isNotEmpty())
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered mb-0">
                            <thead>
                                <tr>
                                    <th>Equipment Name</th>
                                    <th>Equipment ID</th>
                                    <th>Facility</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($previewRows as $row)
                                    <tr>
                                        <td>{{ $row['equipment_name'] ?? '—' }}</td>
                                        <td>{{ $row['equipment_id'] ?? '—' }}</td>
                                        <td>{{ $row['facility'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($totalRows > $previewRows->count())
                        <p class="small text-muted mb-4">
                            Showing first {{ $previewRows->count() }} of {{ $totalRows }} rows.
                        </p>
                    @endif
                @endif

                <form method="POST" action="{{ route('equipment.import') }}" id="equipmentImportConfirmForm">
                    @csrf

                    <div class="form-section-grid">
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
                            <p class="small text-muted mt-2 mb-0">
                                The selected approver will be assigned to every imported equipment record, same as manual entry.
                            </p>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="{{ route('equipment.import.cancel') }}" class="btn btn-light btn-compact">Cancel</a>
                        <button type="submit" class="btn btn-primary btn-compact" id="confirmImportBtn" @if($importableRows === 0) disabled aria-disabled="true" @endif>
                            <i class="fas fa-check" id="confirmImportIcon"></i>
                            <span id="confirmImportLabel">Confirm Import</span>
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
                        <span class="form-note-title">Approver required</span>
                        <span class="form-note-copy">Every imported record is created with approval status Pending and the approver you select here.</span>
                    </div>
                    <div class="form-note-item">
                        <span class="form-note-title">Duplicate IDs</span>
                        <span class="form-note-copy">Rows with an equipment_id that already exists are skipped automatically.</span>
                    </div>
                    <div class="form-note-item">
                        <span class="form-note-title">Same as manual entry</span>
                        <span class="form-note-copy">Imported equipment uses the same approval workflow as equipment added one at a time.</span>
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
    const form = document.getElementById('equipmentImportConfirmForm');
    const btn = document.getElementById('confirmImportBtn');
    const label = document.getElementById('confirmImportLabel');
    const icon = document.getElementById('confirmImportIcon');

    if (!form || !btn) return;

    form.addEventListener('submit', function () {
        if (btn.disabled) {
            return;
        }
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        if (label) {
            label.textContent = 'Importing...';
        }
        if (icon) {
            icon.className = 'fas fa-spinner fa-spin';
        }
    });
});
</script>
@endsection
