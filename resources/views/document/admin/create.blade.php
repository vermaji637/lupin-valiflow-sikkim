@extends('layouts.app')
@section('title', 'Create Document')

@section('content')
<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-file-medical"></i>
                    Document Control
                </span>
                <h2 class="page-title">Create Document</h2>
                <p class="page-subtitle">
                    Create a new document mapping by selecting protocol, equipment, and facility in a cleaner controlled form.
                </p>
            </div>

            <div class="toolbar-row mt-3 mt-lg-0">
                <a href="{{ route('admin.documents') }}" class="btn btn-light btn-compact toolbar-chip">
                    <i class="fas fa-arrow-left"></i>
                    Back to Documents
                </a>
            </div>
        </div>
    </section>

    @include('common.alert')

    @if ($errors->any())
        <div class="alert alert-danger">
            <strong>Error.</strong>
            {{ implode(' ', $errors->all()) }}
        </div>
    @endif

    <div class="form-shell">
        <div class="card form-main-card">
            <div class="card-header">
                <div class="section-title">Document Mapping</div>
            </div>
            <div class="card-body">
                <form method="POST" class="esign-required" action="{{ route('admin.documents.store') }}">
                    @csrf

                    <div class="form-section-grid">
                        <div class="form-field">
                            <label class="form-label" for="protocol_type_id"><span class="form-required">*</span>Protocol Type</label>
                            <select id="protocol_type_id" name="protocol_type_id" class="form-control @error('protocol_type_id') is-invalid @enderror" required>
                                <option value="">Select protocol type</option>
                                @foreach($protocolTypes as $type)
                                    <option value="{{ $type->id }}" @selected(old('protocol_type_id') == $type->id)>{{ $type->protocol_type_name }}</option>
                                @endforeach
                            </select>
                            @error('protocol_type_id')<span class="form-error">{{ $message }}</span>@enderror
                        </div>

                        <div class="form-field">
                            <label class="form-label" for="equipment_picker"><span class="form-required">*</span>Equipment</label>
                            <select id="equipment_picker" name="equipment_id" class="form-control @error('equipment_id') is-invalid @enderror" required>
                                <option value="">Select equipment</option>
                                @foreach($equipments as $equipment)
                                    <option
                                        value="{{ $equipment->id }}"
                                        data-equipment-name="{{ $equipment->equipment_name }}"
                                        data-equipment-code="{{ $equipment->equipment_id }}"
                                        @selected(old('equipment_id') == $equipment->id)
                                    >
                                        {{ $equipment->equipment_name }} ({{ $equipment->equipment_id }})
                                    </option>
                                @endforeach
                            </select>
                            <span class="form-help">One selection fills both equipment name and code references.</span>
                            @error('equipment_id')<span class="form-error">{{ $message }}</span>@enderror
                        </div>

                        <div class="form-field">
                            <label class="form-label" for="equipment_name_preview">Equipment Name</label>
                            <input type="text" id="equipment_name_preview" class="form-control" value="{{ optional($equipments->firstWhere('id', old('equipment_id')))->equipment_name }}" placeholder="Auto-filled from equipment selection" readonly>
                        </div>

                        <div class="form-field">
                            <label class="form-label" for="equipment_code_preview">Equipment ID</label>
                            <input type="text" id="equipment_code_preview" class="form-control" value="{{ optional($equipments->firstWhere('id', old('equipment_id')))->equipment_id }}" placeholder="Auto-filled from equipment selection" readonly>
                        </div>

                        <div class="form-field form-field-full">
                            <label class="form-label" for="facility_id"><span class="form-required">*</span>Facility</label>
                            <select id="facility_id" name="facility_id" class="form-control @error('facility_id') is-invalid @enderror" required>
                                <option value="">Select facility</option>
                                @foreach($facilities as $facility)
                                    <option value="{{ $facility->id }}" @selected(old('facility_id') == $facility->id)>{{ $facility->facility_name }}</option>
                                @endforeach
                            </select>
                            @error('facility_id')<span class="form-error">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <input type="hidden" name="equipment_name_id" id="equipment_name_id" value="{{ old('equipment_name_id', old('equipment_id')) }}">
                    <input type="hidden" name="equipment_code_id" id="equipment_code_id" value="{{ old('equipment_code_id', old('equipment_id')) }}">

                    <div class="form-actions">
                        <a href="{{ route('admin.documents') }}" class="btn btn-light btn-compact">Cancel</a>
                        <button class="btn btn-primary btn-compact" type="submit">
                            <i class="fas fa-save"></i>
                            Create Document
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
                        <span class="form-note-title">Duplicate Check</span>
                        <span class="form-note-copy">The system blocks duplicate combinations of protocol type, equipment, and facility.</span>
                    </div>
                    <div class="form-note-item">
                        <span class="form-note-title">Generated Title</span>
                        <span class="form-note-copy">The final document title is automatically built from the selected protocol, equipment code, equipment name, and facility.</span>
                    </div>
                    <div class="form-note-item">
                        <span class="form-note-title">QA Flow</span>
                        <span class="form-note-copy">New records enter the pending state and are then completed by QA.</span>
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
    var equipmentSelect = document.getElementById('equipment_picker');
    var equipmentNamePreview = document.getElementById('equipment_name_preview');
    var equipmentCodePreview = document.getElementById('equipment_code_preview');
    var equipmentNameId = document.getElementById('equipment_name_id');
    var equipmentCodeId = document.getElementById('equipment_code_id');

    function syncEquipmentFields() {
        var selectedOption = equipmentSelect.options[equipmentSelect.selectedIndex];
        var selectedValue = equipmentSelect.value || '';

        equipmentNamePreview.value = selectedOption ? (selectedOption.getAttribute('data-equipment-name') || '') : '';
        equipmentCodePreview.value = selectedOption ? (selectedOption.getAttribute('data-equipment-code') || '') : '';
        equipmentNameId.value = selectedValue;
        equipmentCodeId.value = selectedValue;
    }

    if (equipmentSelect) {
        equipmentSelect.addEventListener('change', syncEquipmentFields);
        syncEquipmentFields();
    }
});
</script>
@endsection
