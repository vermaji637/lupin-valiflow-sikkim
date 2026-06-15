@extends('layouts.app')

@section('title', 'Import VIVA DOCX')

@section('content')
<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-9 col-xl-8">

            <div class="mb-3">
                <a href="{{ route('protocol') }}" class="text-decoration-none text-secondary">
                    <i class="fas fa-arrow-left mr-1"></i> Back to All Protocol
                </a>
            </div>

            <div class="card shadow border-0">
                <div class="card-header py-3" style="background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);">
                    <h5 class="mb-0 font-weight-bold text-white">
                        <i class="fas fa-file-import mr-2"></i> Import Pre-Approved DOCX (VIVA)
                    </h5>
                    <p class="mb-0 text-white-50 small mt-1">
                        Upload a VIVA pre-approved document. PRE approval is skipped; you will fill blank fields and submit directly for POST approval.
                    </p>
                </div>

                <div class="card-body p-4">
                    @if(session('error'))
                        <div class="alert alert-danger">{{ session('error') }}</div>
                    @endif

                    @if($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0 pl-3">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST"
                          action="{{ route('protocol.docx-import.store') }}"
                          enctype="multipart/form-data"
                          id="protocolImportForm">
                        @csrf

                        <div class="card mb-4 border">
                            <div class="card-header">Document Mapping</div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label font-weight-bold">Approved Document</label>
                                        <select class="form-control" name="document_id" id="import_document_id">
                                            <option value="">-- Select Approved Document --</option>
                                            @foreach($documents as $document)
                                                <option value="{{ $document->id }}"
                                                        data-facility="{{ $document->facility->facility_name ?? '' }}"
                                                        data-equipment-name="{{ $document->equipment->equipment_name ?? '' }}"
                                                        data-equipment-id="{{ $document->equipment->equipment_id ?? '' }}"
                                                        {{ old('document_id') == $document->id ? 'selected' : '' }}>
                                                    {{ $document->id }} - {{ $document->title }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label font-weight-bold">Version</label>
                                        <input type="text" name="version" class="form-control" value="{{ old('version') }}">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label font-weight-bold">Facility / Section</label>
                                        <input type="text" name="facility_section" id="import_facility_section" class="form-control" value="{{ old('facility_section') }}">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label font-weight-bold">Equipment Name</label>
                                        <input type="text" name="equipment_name" id="import_equipment_name" class="form-control" value="{{ old('equipment_name') }}">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label font-weight-bold">Equipment ID</label>
                                        <input type="text" name="equipment_id" id="import_equipment_id" class="form-control" value="{{ old('equipment_id') }}">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="font-weight-bold">
                                Pre-Approved DOC/DOCX File <span class="text-danger">*</span>
                            </label>
                            <input type="file"
                                   class="form-control"
                                   name="docx_file"
                                   accept=".doc,.docx"
                                   required>
                            <small class="text-muted">Supported: .doc, .docx (Max: 10MB). Filled fields will be locked; blank fields will be editable.</small>
                        </div>

                        <div class="d-flex justify-content-between mt-4">
                            <a href="{{ route('protocol') }}" class="btn btn-light shadow-sm">
                                <i class="fas fa-times mr-1"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary shadow-sm" id="btnUpload">
                                <i class="fas fa-upload mr-1"></i> Upload &amp; Detect Fields
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const select = document.getElementById('import_document_id');
    if (!select) return;

    select.addEventListener('change', function () {
        const opt = select.options[select.selectedIndex];
        if (!opt || !opt.value) return;

        const facility = document.getElementById('import_facility_section');
        const eqName   = document.getElementById('import_equipment_name');
        const eqId     = document.getElementById('import_equipment_id');

        if (facility && opt.dataset.facility) facility.value = opt.dataset.facility;
        if (eqName && opt.dataset.equipmentName) eqName.value = opt.dataset.equipmentName;
        if (eqId && opt.dataset.equipmentId) eqId.value = opt.dataset.equipmentId;
    });

    const form = document.getElementById('protocolImportForm');
    const btn  = document.getElementById('btnUpload');
    if (form && btn) {
        form.addEventListener('submit', function () {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span> Uploading...';
        });
    }
});
</script>
@endsection
