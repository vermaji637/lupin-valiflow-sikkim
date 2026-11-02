@extends('layouts.app')

@php
    $sectionTitle = ($importSourceSectionTitle ?? '') !== ''
        ? $importSourceSectionTitle
        : (($importSourceLabel ?? '') !== '' ? $importSourceLabel : 'Pre-Approved DOCX (VIVA)');
    $isNeqFill = ($importSource ?? '') === 'new_equipment_qualification';
@endphp

@section('title', $isNeqFill ? $sectionTitle : ('Import ' . $sectionTitle))

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
                        <i class="fas fa-file-import mr-2"></i>
                        {{ $isNeqFill ? $sectionTitle : ('Import ' . $sectionTitle) }}
                    </h5>
                    <p class="mb-0 text-white-50 small mt-1">
                        @if($isNeqFill)
                            Upload a pre-approved New Equipment Qualification document. Blank fields will open for fill; filled VIVA content stays locked. Then submit for POST approval.
                        @elseif(($importSource ?? '') === 'requalification')
                            Upload a Requalification document. Select the pre-approval users first. The document stays locked until pre-approval is complete, then fill and Fill with DOCX open.
                        @elseif(($importSourceLabel ?? '') !== '')
                            Upload a {{ $importSourceLabel }} document. PRE approval is skipped; you will fill blank fields and submit directly for POST approval.
                        @else
                            Upload a VIVA pre-approved document. PRE approval is skipped; you will fill blank fields and submit directly for POST approval.
                        @endif
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
                        <input type="hidden" name="import_source" value="{{ $importSource ?? '' }}">

                        <div class="card mb-4 border">
                            <div class="card-header">Document Mapping</div>
                            <div class="card-body">
                                <div class="row g-3">
                                    {{-- <div class="col-md-6">
                                        <label class="form-label font-weight-bold">Approved Document</label>
                                        <select class="form-control" name="document_id" id="import_document_id"
                                                data-details-url-template="{{ url('/admin/documents/__ID__/details') }}">
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
                                    </div> --}}
                                    <div class="col-md-6">
                                        <label class="form-label font-weight-bold">Equipment Name <span class="text-danger">*</span></label>
                                        <input type="text" name="equipment_name" id="import_equipment_name" class="form-control"
                                               value="{{ old('equipment_name') }}" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label font-weight-bold">Equipment ID <span class="text-danger">*</span></label>
                                        <input type="text" name="equipment_id" id="import_equipment_id" class="form-control"
                                               value="{{ old('equipment_id') }}" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label font-weight-bold">Document Number</label>
                                        <input type="text" name="protocol_document_no" id="import_protocol_document_no" class="form-control"
                                               value="{{ old('protocol_document_no') }}"
                                               placeholder="Optional — also auto-read from DOCX if present">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label font-weight-bold">Reference Protocol No</label>
                                        <input type="text" name="reference_protocol_no" id="import_reference_protocol_no" class="form-control"
                                               value="{{ old('reference_protocol_no') }}"
                                               placeholder="Optional — also auto-read from DOCX if present">
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
@endsection

@push('scripts')
<script>
(function () {
    var form = document.getElementById('protocolImportForm');
    var btn = document.getElementById('btnUpload');
    if (form && btn) {
        form.addEventListener('submit', function () {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Uploading…';
        });
    }

    var select = document.getElementById('import_document_id');
    if (!select) return;

    select.addEventListener('change', function () {
        var opt = select.options[select.selectedIndex];
        if (!opt || !opt.value) return;
        var facility = document.getElementById('import_facility_section');
        var eqName = document.getElementById('import_equipment_name');
        var eqId = document.getElementById('import_equipment_id');
        if (facility) facility.value = opt.getAttribute('data-facility') || '';
        if (eqName) eqName.value = opt.getAttribute('data-equipment-name') || '';
        if (eqId) eqId.value = opt.getAttribute('data-equipment-id') || '';
    });
})();
</script>
@endpush
