@extends('layouts.app')

@push('styles')
<style>
/*
 * DOCX fill form — overrides premium-ui.css global input rules:
 *   body.premium-app input[type="text"]  → white bg, min-height 46px, border-radius 14px, inset shadow
 *   body.premium-app input:focus         → blue focus ring box-shadow
 * Selectors below use #docxFillForm + input[type="text"] for higher specificity.
 */

/* E-Sign stays in the top-right corner while the form scrolls. */
#docxEsignDock {
    position: fixed !important;
    top: 112px;
    right: 22px;
    z-index: 2000;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
    margin: 0;
    pointer-events: none;
}
#docxEsignDock #btnEsignStamp,
#docxEsignDock #docxEsignStatus {
    pointer-events: auto;
}
#docxEsignDock #btnEsignStamp {
    background: #ffffff;
    color: #1a252f;
    border: 1px solid #c5d0e6;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.22);
    font-weight: 700;
}
#docxEsignDock #docxEsignStatus {
    max-width: 240px;
    white-space: normal;
    text-align: left;
    line-height: 1.35;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.16);
}

/* ── Preview container ── */
body.premium-app #docxFillForm .docx-preview-wrapper {
    overflow-x: auto;
    overflow-y: visible;
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 24px;
    min-height: 200px;
    position: relative;
    z-index: 1;
}

/* ── Table layout (must stay display:table for inputs to render in cells) ── */
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table {
    display: table !important;
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 16px;
    font-size: 13px;
    table-layout: auto;
}
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table tr {
    display: table-row !important;
}
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table td,
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table th {
    display: table-cell !important;
}
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table.header-table {
    margin-bottom: 0;
}
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table.header-table td {
    font-size: 12px;
    vertical-align: middle;
}
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table:not(.header-table) tr:first-child td,
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table:not(.header-table) tr:first-child th {
    font-weight: bold;
}

/* ── All DOCX cells ── */
body.premium-app #docxFillForm .docx-preview-wrapper td.docx-cell,
body.premium-app #docxFillForm .docx-preview-wrapper th.docx-cell {
    border: 1px solid #000;
    padding: 6px 8px;
    vertical-align: middle;
    min-width: 80px;
}

/* ── Editable cells: yellow background + blue bottom border ── */
body.premium-app #docxFillForm .docx-preview-wrapper td.docx-cell.editable {
    background-color: #fffde7 !important;
    background-image: none !important;
    padding: 2px 4px !important;
    border-bottom: 2px solid #4e73df !important;
    cursor: text;
    position: relative;
}

/* ── Locked cells: grey read-only ── */
body.premium-app #docxFillForm .docx-preview-wrapper td.docx-cell.locked {
    background-color: #f5f5f5 !important;
    background-image: none !important;
    color: #444 !important;
}

/*
 * DOCX table inputs — beat premium-ui input[type="text"] + input:focus
 * (white rounded 46px boxes that hide inside table cells)
 */
body.premium-app #docxFillForm .docx-preview-wrapper input[type="text"].docx-input,
body.premium-app #docxFillForm .docx-preview-wrapper input[type="text"].docx-input-inline,
body.premium-app #docxFillForm .docx-preview-wrapper textarea.docx-input {
    display: block !important;
    width: 100% !important;
    min-width: 60px !important;
    min-height: 28px !important;
    height: auto !important;
    margin: 0 !important;
    padding: 4px 6px !important;
    font-size: 13px !important;
    line-height: 1.4 !important;
    font-family: inherit !important;
    color: #212529 !important;
    background-color: #fffde7 !important;
    background-image: none !important;
    border: none !important;
    border-bottom: 2px solid #4e73df !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    outline: none !important;
    box-sizing: border-box !important;
    -webkit-appearance: none !important;
    appearance: none !important;
    opacity: 1 !important;
    visibility: visible !important;
    pointer-events: auto !important;
    cursor: text !important;
    position: relative !important;
    z-index: 2 !important;
}

/* Header row inputs: inline width inside label cells */
body.premium-app #docxFillForm .docx-preview-wrapper table.header-table input[type="text"].docx-input {
    display: inline-block !important;
    width: auto !important;
    min-width: 120px !important;
    min-height: 24px !important;
    vertical-align: baseline;
}

body.premium-app #docxFillForm .docx-preview-wrapper input[type="text"].docx-input:focus,
body.premium-app #docxFillForm .docx-preview-wrapper input[type="text"].docx-input-inline:focus,
body.premium-app #docxFillForm .docx-preview-wrapper textarea.docx-input:focus {
    background-color: #e8f0fe !important;
    border: none !important;
    border-bottom: 2px solid #224abe !important;
    box-shadow: none !important;
    outline: none !important;
}

body.premium-app #docxFillForm .docx-preview-wrapper input[type="text"].docx-input::placeholder,
body.premium-app #docxFillForm .docx-preview-wrapper input[type="text"].docx-input-inline::placeholder {
    color: #888 !important;
    opacity: 1 !important;
}

body.premium-app #docxFillForm .docx-preview-wrapper .docx-textbox {
    border: 1px solid #000;
    padding: 10px 12px;
    margin: 8px 0 12px 0;
    background: #fff;
}
body.premium-app #docxFillForm .docx-preview-wrapper .docx-textbox-line {
    margin: 4px 0;
}
body.premium-app #docxFillForm .docx-preview-wrapper .docx-textbox-blank .docx-input,
body.premium-app #docxFillForm .docx-preview-wrapper .docx-textbox-input {
    width: 100%;
    text-align: left;
}
body.premium-app #docxFillForm .docx-preview-wrapper .approval-stamp-cell {
    background: #f0f0f0 !important;
    color: #555;
}
body.premium-app #docxFillForm .docx-preview-wrapper .docx-approval-pending {
    color: #999;
    font-style: italic;
}
body.premium-app #docxFillForm .docx-preview-wrapper .docx-signature-font {
    font-family: "Segoe Script", "Brush Script MT", cursive;
    font-style: italic;
    font-size: 1.05em;
}
body.premium-app #docxFillForm .docx-preview-wrapper .docx-para {
    margin: 6px 0;
    font-size: 13px;
    line-height: 1.5;
}

body.premium-app #docxFillForm .docx-preview-wrapper textarea.docx-input {
    min-height: 72px !important;
    resize: vertical;
}

/* Date inputs: keep native calendar picker (do NOT use appearance:none) */
body.premium-app #docxFillForm .docx-preview-wrapper input[type="date"].docx-input,
body.premium-app #docxFillForm .docx-preview-wrapper input[type="date"].docx-input-inline {
    display: inline-block !important;
    width: auto !important;
    min-width: 140px !important;
    min-height: 28px !important;
    margin: 0 !important;
    padding: 4px 6px !important;
    font-size: 13px !important;
    background-color: #fffde7 !important;
    border: none !important;
    border-bottom: 2px solid #4e73df !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    -webkit-appearance: auto !important;
    appearance: auto !important;
    opacity: 1 !important;
    visibility: visible !important;
    pointer-events: auto !important;
    cursor: pointer !important;
    position: relative !important;
    z-index: 2 !important;
}

body.premium-app #docxFillForm .docx-preview-wrapper input[type="date"].docx-input:focus,
body.premium-app #docxFillForm .docx-preview-wrapper input[type="date"].docx-input-inline:focus {
    background-color: #e8f0fe !important;
    border-bottom: 2px solid #224abe !important;
    outline: none !important;
}

body.premium-app #docxFillForm .docx-preview-wrapper .docx-para-checkbox {
    margin: 4px 0 4px 12px;
}

body.premium-app #docxFillForm .docx-preview-wrapper .docx-checkbox-cell {
    white-space: nowrap;
}

body.premium-app #docxFillForm .docx-preview-wrapper .docx-check-label {
    margin-right: 12px;
    font-weight: normal;
    cursor: pointer;
}

body.premium-app #docxFillForm .docx-preview-wrapper .docx-check-label input {
    margin-right: 4px;
    pointer-events: auto !important;
    cursor: pointer !important;
}

/* Approver dropdowns inside signature tables */
body.premium-app #docxFillForm .docx-preview-wrapper select.docx-input.approver-field {
    display: block !important;
    width: 100% !important;
    min-height: 28px !important;
    padding: 4px 6px !important;
    font-size: 13px !important;
    background-color: #fffde7 !important;
    border: none !important;
    border-bottom: 2px solid #4e73df !important;
    border-radius: 0 !important;
    -webkit-appearance: menulist !important;
    appearance: menulist !important;
    pointer-events: auto !important;
    cursor: pointer !important;
}

body.premium-app #docxFillForm .docx-preview-wrapper input.docx-attachment-field {
    display: block !important;
    width: 100% !important;
    font-size: 12px !important;
    pointer-events: auto !important;
    cursor: pointer !important;
}

body.premium-app #docxFillForm .docx-preview-wrapper input.attachment-name-field {
    display: none !important;
}

body.premium-app #docxFillForm .docx-preview-wrapper a.docx-attachment-link {
    color: #0d6efd !important;
    text-decoration: underline !important;
    display: inline-block;
    margin-bottom: 4px;
    word-break: break-word;
}

.text-xs { font-size: 0.75rem; }

#docxFillForm.requal-fill-locked .docx-preview-wrapper,
#docxFillForm.requal-fill-locked .requal-locked-block {
    pointer-events: none;
}
</style>
@endpush

@section('content')
@if(($importSource ?? '') === 'requalification' && empty($requalFillLocked))
<div id="docxEsignDock" aria-label="E-Sign">
    <button type="button" class="btn btn-light" id="btnEsignStamp"
            title="Click a cell, then stamp your name and today's date">
        <i class="fas fa-signature mr-1"></i> E-Sign
    </button>
    <span id="docxEsignStatus" class="badge badge-light text-secondary px-3 py-2" style="display:none;"></span>
</div>
@endif
<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-11 col-xl-10">

            <div class="mb-4">
                <a href="{{ route('reports.show', $report) }}" class="text-decoration-none text-secondary">
                    <i class="fas fa-arrow-left mr-1"></i> Back to {{ $report->displayCode() }}
                </a>
            </div>

            <div class="card shadow border-0">
                <div class="card-header py-3" style="background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h5 class="mb-0 font-weight-bold text-white">
                                <i class="fas fa-file-signature mr-2"></i>
                                {{ ($importSourceSectionTitle ?? '') !== '' ? $importSourceSectionTitle : 'Fill DOCX Fields' }}
                            </h5>
                            <p class="mb-0 text-white-50 small mt-1">
                                @if(!empty($requalFillLocked))
                                    Pre-approval first. The document and Fill with DOCX stay locked until every pre-approver has approved.
                                @elseif(($importSource ?? '') === 'new_equipment_qualification')
                                    Fill New Equipment Qualification — empty cells are editable; pre-approved content stays locked.
                                @elseif(!empty($useTablePreview))
                                    Document table layout — empty cells are editable, filled cells are read-only.
                                @else
                                    Flat field list — empty cells are editable, filled cells are read-only.
                                @endif
                            </p>
                        </div>
                        <div class="d-flex align-items-center">
                            <span id="docxDraftStatus" class="badge badge-light text-secondary shadow-sm mr-2 px-3 py-2"
                                  @if(empty($draftSavedAt)) style="display:none;" @endif>
                                @if(!empty($draftSavedAt))
                                    <i class="fas fa-cloud-upload-alt mr-1"></i> Draft saved {{ $draftSavedAt }}
                                @endif
                            </span>
                            {{-- Save is automatic, so the button stays hidden.
                            <button type="button" class="btn btn-light shadow-sm mr-2" id="btnSaveDraft">
                                <i class="fas fa-save mr-1"></i> Save
                            </button>
                            --}}
                            <span class="badge badge-light px-3 py-2 text-primary shadow-sm font-weight-bold">
                                {{ count($fields) }} Total Fields
                            </span>
                        </div>
                    </div>
                </div>

                <form action="{{ route('reports.docx-import.fill', $report) }}" method="POST" id="docxFillForm" class="esign-required {{ !empty($requalFillLocked) ? 'requal-fill-locked' : '' }}" enctype="multipart/form-data">
                    @csrf
                    <input type="hidden" name="password">
                    {{-- Hidden header cell values synced from  and table inputs --}}
                    <input type="hidden" name="fields[header_0_row_2_col_2]" id="header_facility_hidden"
                           value="{{ old('fields.header_0_row_2_col_2', $facilitySection) }}">
                    <input type="hidden" name="fields[header_0_row_4_col_4]" id="header_version_hidden"
                           value="{{ old('fields.header_0_row_4_col_4', $report->version ?? '') }}">
                    <div class="card-body px-4 py-4">

                        @if (session('error'))
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle mr-2"></i> {{ session('error') }}
                            </div>
                        @endif

                        @if ($errors->any())
                            <div class="alert alert-danger">
                                <ul class="mb-0 pl-3">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if(($importSource ?? '') === 'requalification' && ($report->status ?? '') === 'pre_approved')
                            <div class="alert alert-success">
                                Pre-approval is complete. Fill the document. Fill with DOCX is now available.
                            </div>
                        @endif

                        @if(!empty($requalFillLocked))
                            <div class="card mb-4 border" id="requalPreApprovalCard">
                                <div class="card-header font-weight-bold">Pre Approval</div>
                                <div class="card-body">
                                    @if(!empty($requalPreEditable))
                                        <p class="mb-3">
                                            Select all seven pre-approval users in the order shown. Approval goes to each person one by one. The document and Fill with DOCX stay locked until every one has approved.
                                        </p>
                                        <input type="hidden" name="requal_pre_submit" value="1">
                                    @else
                                        <div class="alert alert-warning mb-3">
                                            Pre-approval is in progress. The document stays locked until every selected user has approved.
                                        </div>
                                    @endif
                                    <div class="row">
                                        @foreach(\App\Http\Controllers\DocxImportController::preApproverFieldTitles() as $roleKey => $roleTitle)
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label font-weight-bold" for="pre_{{ $roleKey }}">{{ $roleTitle }}</label>
                                                <select class="form-control" id="pre_{{ $roleKey }}" name="{{ $roleKey }}"
                                                        @if(empty($requalPreEditable)) disabled @else required @endif>
                                                    <option value="">-- Select User --</option>
                                                    @foreach($users as $user)
                                                        <option value="{{ $user->id }}"
                                                            {{ (string) old($roleKey, $draftPreApprovers[$roleKey] ?? '') === (string) $user->id ? 'selected' : '' }}>
                                                            {{ $user->display_name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if(empty($requalFillLocked))
                        <div class="card mb-4 border" id="fillWithDocxCard">
                            {{-- Copy field values from an already-approved DOCX protocol --}}
                            <div class="card-header d-flex align-items-center justify-content-between py-2">
                                <span class="font-weight-bold">Fill with DOCX</span>
                                <button type="button" class="btn btn-primary shadow-sm btn-sm" id="btnFillWithDocx">
                                    <i class="fas fa-file-import mr-1"></i> Fill with DOCX
                                </button>
                            </div>
                            <div class="card-body py-3" id="fillWithDocxPanel" style="display: none;">
                                <div class="row g-3">
                                    <div class="col-md-12">
                                        <label class="form-label font-weight-bold" for="fill_from_protocol_id">Approved Protocol</label>
                                        <select class="form-control" id="fill_from_protocol_id" data-fill-from-protocol-select="1">
                                            <option value="">-- Select Approved Protocol --</option>
                                            @forelse(($approvedDocxProtocols ?? []) as $protocol)
                                                <option value="{{ $protocol->id }}">
                                                    {{ $protocol->fill_option_label }}
                                                </option>
                                            @empty
                                                <option value="" disabled>No approved {{ $importSourceLabel ?? 'DOCX' }} protocols available</option>
                                            @endforelse
                                        </select>
                                        <small class="text-muted d-block mt-1">
                                            @if(trim((string) ($importSourceLabel ?? '')) !== '')
                                                Select an approved {{ $importSourceLabel }} protocol to copy matching field values into this form.
                                            @else
                                                Select an approved protocol to copy matching field values into this form.
                                            @endif
                                        </small>
                                    </div>
                                </div>
                                <div id="fillWithDocxStatus" class="alert mt-3 mb-0 py-2 small" style="display: none;" role="alert"></div>
                            </div>
                        </div>
                        @endif

                        <div class="card mb-4 border {{ !empty($requalFillLocked) ? 'requal-locked-block' : '' }}">
                            {{-- Link report to approved document master and equipment metadata --}}
                            <div class="card-header">Document Mapping</div>
                            <div class="card-body">
                                <div class="row g-3">
                                    {{-- <div class="col-md-6">
                                        <label class="form-label font-weight-bold">Approved Document</label>
                                        <input type="hidden" name="document_id" id="document_id_hidden"
                                               value="{{ old('document_id', $report->document_no) }}">
                                        <select class="form-control" id="fill_document_id" data-docx-fill-select="1">
                                            <option value="">-- Select Approved Document --</option>
                                            @foreach(($documents ?? []) as $document)
                                                <option value="{{ $document->id }}"
                                                        data-facility="{{ $document->facility->facility_name ?? '' }}"
                                                        data-equipment-name="{{ $document->equipment->equipment_name ?? '' }}"
                                                        data-equipment-id="{{ $document->equipment->equipment_id ?? '' }}"
                                                        {{ (string) old('document_id', $report->document_no) === (string) $document->id ? 'selected' : '' }}>
                                                    {{ $document->id }} - {{ $document->title }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label font-weight-bold">Version</label>
                                        <input type="text" name="version" class="form-control"
                                               value="{{ old('version', $report->version) }}">
                                    </div> --}}
                                    <div class="col-md-12">
                                        <label class="form-label font-weight-bold">Protocol / Document Title </label>
                                        <input type="text" name="document_title" id="fill_document_title" class="form-control"
                                               value="{{ old('document_title', $documentTitle ?? '') }}"
                                               placeholder="Enter protocol or document title"
                                               >
                                        <small class="text-muted">This title appears in the protocol list after you submit.</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label font-weight-bold">Document Number</label>
                                        <input type="text" name="protocol_document_no" id="fill_protocol_document_no" class="form-control"
                                               value="{{ old('protocol_document_no', $protocolDocumentNo ?? '') }}"
                                               placeholder="From original protocol, or enter if blank">
                                        <small class="text-muted">Shown on the report header. Auto-filled when present in the uploaded DOCX.</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label font-weight-bold">Reference Protocol No</label>
                                        <input type="text" name="reference_protocol_no" id="fill_reference_protocol_no" class="form-control"
                                               value="{{ old('reference_protocol_no', $referenceProtocolNo ?? '') }}"
                                               placeholder="From original protocol, or enter if blank">
                                        <small class="text-muted">Shown on the report header. Auto-filled when present in the uploaded DOCX.</small>
                                    </div>
                                    {{-- <div class="col-md-4">
                                        <label class="form-label font-weight-bold">Facility / Section</label>
                                        <input type="text" name="facility_section" id="fill_facility_section" class="form-control"
                                               value="{{ old('facility_section', $facilitySection) }}">
                                    </div> --}}
                                    <div class="col-md-6">
                                        <label class="form-label font-weight-bold">Equipment Name <span class="text-danger">*</span></label>
                                        <input type="text" name="equipment_name" id="fill_equipment_name" class="form-control"
                                               value="{{ old('equipment_name', $equipmentName) }}"
                                               required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label font-weight-bold">Equipment ID <span class="text-danger">*</span></label>
                                        <input type="text" name="equipment_id" id="fill_equipment_id" class="form-control"
                                               value="{{ old('equipment_id', $equipmentId) }}"
                                               required>
                                    </div>
                                    @if(!empty($hasEffectiveDateField))
                                        <div class="col-md-4">
                                            <label class="form-label font-weight-bold">Effective Date</label>
                                            <input type="date" name="effective_date" id="fill_effective_date" class="form-control"
                                                   value="{{ old('effective_date', $effectiveDate ?? '') }}">
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>

                        @php
                            $presentApproverRoles = $presentApproverRoles ?? [];
                            $hasApproverFields = count($presentApproverRoles) > 0;
                        @endphp

                        {{-- POST approver IDs: only for roles that exist in this report's parsed DOCX fields. --}}
                        @if($hasApproverFields)
                            @php $draftApprovers = $draftApprovers ?? []; @endphp
                            @if(in_array('post_prepared_by_name', $presentApproverRoles, true))
                                <input type="hidden" name="post_prepared_by_name" id="post_prepared_by_name_value"
                                       value="{{ old('post_prepared_by_name', $draftApprovers['post_prepared_by_name'] ?? auth()->id()) }}">
                            @endif
                            @foreach ([
                                'post_checked_by_engineering_name' => 'post_checked_by_engineering_name_value',
                                'post_checked_by_production_name'  => 'post_checked_by_production_name_value',
                                'post_checked_by_quality_name'     => 'post_checked_by_quality_name_value',
                                'post_checked_by_validation_name'  => 'post_checked_by_validation_name_value',
                                'post_approved_by_quality_name'    => 'post_approved_by_quality_name_value',
                            ] as $roleKey => $inputId)
                                @if(in_array($roleKey, $presentApproverRoles, true))
                                    <input type="hidden" name="{{ $roleKey }}" id="{{ $inputId }}"
                                           value="{{ old($roleKey, $draftApprovers[$roleKey] ?? '') }}">
                                @endif
                            @endforeach
                        @endif

                        @if(!empty($usesApprovalPage) && $hasApproverFields)
                            <div class="alert alert-info mb-4">
                                <i class="fas fa-user-check mr-1"></i>
                                <strong>Approval Page:</strong> Select <strong>Performed By</strong>,
                                <strong>Checked By</strong>, and <strong>Approved By</strong> users in the signature table below.
                                Signature and Date columns are filled automatically when each approval step completes.
                                <strong>Performed By</strong> defaults to you
                                ({{ trim((auth()->user()->first_name ?? '') . ' ' . (auth()->user()->last_name ?? '')) ?: 'Current user' }}).
                            </div>
                        @elseif($hasApproverFields)
                            <div class="alert alert-info mb-4">
                                <i class="fas fa-user-check mr-1"></i>
                                Select approvers in the <strong>Prepared By</strong>, <strong>Checked By</strong>, and
                                <strong>Approved By</strong> tables below. Those users will receive POST approval workflow steps.
                            </div>
                        @endif

                        {{-- Primary UI: Python-rendered DOCX table with inline editable cells --}}
                        @if(!empty($useTablePreview))
                            @php
                                $templateName = trim((string) ($documentTitle ?? '')) !== ''
                                    ? (string) $documentTitle
                                    : (\App\Models\Report::importSourceLabel($importSource ?? '')
                                        ?: ($report->template->name ?? 'PERIODIC HVAC VALIDATION REPORT'));
                            @endphp
                            <div class="docx-preview-wrapper mb-4">
                                @if(!empty($showLegacyBladeHeader))
                                {{-- IOQ protocol only: extra blade header (not in Python preview) --}}
                                <table class="docx-table header-table" style="margin-bottom:0;">
                                    <tr>
                                        <td rowspan="3" style="width:100px; text-align:center; vertical-align:middle; border:1px solid #000; padding:8px;">
                                            <img src="{{ asset('storage/lupin.png') }}" alt="Lupin" style="height:50px; width:auto;" onerror="this.style.display='none'">
                                            <div style="font-size:11px; font-weight:bold; margin-top:4px;">LUPIN</div>
                                        </td>
                                        <td colspan="4" style="text-align:center; font-weight:bold; font-size:15px; border:1px solid #000; padding:8px; background:#f8f9fa;">
                                            {{ strtoupper($templateName) }}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="border:1px solid #000; padding:6px;">
                                            <strong>Title:</strong> {{ $templateName }}
                                        </td>
                                        <td style="border:1px solid #000; padding:6px;">
                                            <strong>Document Number:</strong>
                                            <input type="text" name="fields[header_0_row_4_col_2]" id="header_doc_number" class="docx-input"
                                                   value="{{ old('fields.header_0_row_4_col_2', $protocolDocumentNo ?? $documentNo) }}">
                                        </td>
                                        <td style="border:1px solid #000; padding:6px;">
                                            <strong>Equipment Name:</strong>
                                            <input type="text" name="fields[header_0_row_2_col_4]" id="header_equipment_name" class="docx-input"
                                                   value="{{ old('fields.header_0_row_2_col_4', $equipmentName) }}">
                                        </td>
                                        <td style="border:1px solid #000; padding:6px;">
                                            <strong>Equipment ID:</strong>
                                            <input type="text" name="fields[header_0_row_3_col_4]" id="header_equipment_id" class="docx-input"
                                                   value="{{ old('fields.header_0_row_3_col_4', $equipmentId) }}">
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan="4" style="border:1px solid #000; padding:6px;">
                                            <strong>Reference Protocol No:</strong>
                                            <input type="text" name="fields[header_ref_protocol]" id="header_ref_protocol" class="docx-input"
                                                   value="{{ old('fields.header_ref_protocol', $referenceProtocolNo ?? '') }}">
                                        </td>
                                    </tr>
                                </table>
                                @endif

                                {!! $previewHtml !!}
                            </div>

                            {{-- Hidden inputs for locked fields (not editable in table preview) --}}
                            @foreach($fields as $field)
                                @php
                                    $lockedRaw = $field['locked'] ?? false;
                                    if (is_bool($lockedRaw)) {
                                        $isLocked = $lockedRaw;
                                    } elseif (is_numeric($lockedRaw)) {
                                        $isLocked = (int) $lockedRaw === 1;
                                    } elseif (is_string($lockedRaw)) {
                                        $isLocked = in_array(strtolower(trim($lockedRaw)), ['1', 'true', 'yes'], true);
                                    } else {
                                        $isLocked = false;
                                    }
                                    $location = $field['location'] ?? '';
                                    $value    = $field['value'] ?? '';
                                @endphp
                                @if($isLocked && $location !== '')
                                    <input type="hidden" name="fields[{{ $location }}]" value="{{ $value }}">
                                @endif
                            @endforeach

                        @else
                            {{-- Fallback flat field list when Python table preview is unavailable --}}
                            <div class="alert alert-warning mb-3">
                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                @if(empty($docxTemplateAvailable))
                                    The original DOCX template file is missing on the server.
                                    Start the Python service on port 8001 and ensure a generated DOCX attachment exists,
                                    or re-import the document to restore the table layout.
                                @else
                                    Table preview unavailable — ensure the Python service is running on port 8001.
                                    Showing field list instead.
                                @endif
                            </div>
                            <div class="row">
                                @forelse(($flatListFields ?? $fields) as $field)
                                    @php
                                        $lockedRaw = $field['locked'] ?? false;
                                        if (is_bool($lockedRaw)) {
                                            $isLocked = $lockedRaw;
                                        } elseif (is_numeric($lockedRaw)) {
                                            $isLocked = (int) $lockedRaw === 1;
                                        } elseif (is_string($lockedRaw)) {
                                            $isLocked = in_array(strtolower(trim($lockedRaw)), ['1', 'true', 'yes'], true);
                                        } else {
                                            $isLocked = false;
                                        }
                                        $label    = $field['label']    ?? '';
                                        $value    = $field['value']    ?? '';
                                        $location = $field['location'] ?? '';
                                        $type     = $field['type']     ?? '';
                                    @endphp
                                    <div class="col-12 mb-3">
                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                            <label class="form-label font-weight-bold mb-0 {{ $isLocked ? 'text-muted' : 'text-dark' }}">
                                                {{ $label }}
                                            </label>
                                            @if($isLocked)
                                                <span class="badge badge-secondary text-xs">
                                                    <i class="fas fa-lock mr-1"></i> Locked
                                                </span>
                                            @else
                                                <span class="badge badge-light text-primary text-xs">
                                                    <i class="fas fa-edit mr-1"></i> Editable
                                                </span>
                                            @endif
                                        </div>

                                        @if($isLocked)
                                            <div class="form-control-plaintext bg-light px-3 py-2 rounded border text-muted" style="min-height:38px;">
                                                {{ $value !== '' ? $value : '—' }}
                                            </div>
                                            <input type="hidden" name="fields[{{ $location }}]" value="{{ $value }}">
                                        @else
                                            @if(strlen($value) > 100 || str_contains(strtolower($label), 'description') || str_contains(strtolower($label), 'remark'))
                                                <textarea name="fields[{{ $location }}]"
                                                          class="form-control"
                                                          rows="3"
                                                          placeholder="Enter {{ strtolower($label) }}...">{{ old('fields.'.$location, $value) }}</textarea>
                                            @else
                                                <input type="text"
                                                       name="fields[{{ $location }}]"
                                                       class="form-control"
                                                       value="{{ old('fields.'.$location, $value) }}"
                                                       placeholder="Enter {{ strtolower($label) }}...">
                                            @endif
                                        @endif

                                        <div class="d-flex justify-content-between mt-1 text-xs text-muted">
                                            <span>Type: <code>{{ $type }}</code></span>
                                            <span>Location: <code>{{ $location }}</code></span>
                                        </div>
                                    </div>
                                @empty
                                    <div class="col-12 text-center py-5">
                                        <h6 class="text-muted">No Fields Detected</h6>
                                    </div>
                                @endforelse
                            </div>
                        @endif

                    </div>

                    <div class="card-footer bg-light px-4 py-3 d-flex justify-content-end align-items-center">
                        {{-- Cancel does not leave this form; left out.
                        <a href="{{ route('reports.show', $report) }}" class="btn btn-light shadow-sm">
                            <i class="fas fa-times mr-1"></i> Cancel
                        </a>
                        --}}
                        @if(!empty($requalFillLocked) && !empty($requalPreEditable))
                            <button type="submit" class="btn btn-primary shadow-sm" id="btnSubmit">
                                <i class="fas fa-user-check mr-1"></i>
                                <span id="btnText">Send for Pre Approval</span>
                            </button>
                        @elseif(empty($requalFillLocked) && count($fields) > 0)
                            <button type="submit" class="btn btn-primary shadow-sm" id="btnSubmit">
                                <i class="fas fa-file-export mr-1"></i>
                                <span id="btnText">Generate & Submit for Post Approval</span>
                            </button>
                        @endif
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form      = document.getElementById('docxFillForm');
    const btnSubmit = document.getElementById('btnSubmit');
    const btnText   = document.getElementById('btnText');
    const requalFillLocked = @json(!empty($requalFillLocked));

    if (requalFillLocked && form) {
        form.querySelectorAll('.docx-preview-wrapper input, .docx-preview-wrapper select, .docx-preview-wrapper textarea, .requal-locked-block input, .requal-locked-block select, .requal-locked-block textarea').forEach(function (el) {
            el.disabled = true;
        });
        form.querySelectorAll('.docx-preview-wrapper [contenteditable="true"]').forEach(function (el) {
            el.setAttribute('contenteditable', 'false');
        });
    }

    /** Active users for signature-table approver dropdowns (same labels as fill_docx.blade.php). */
    const docxUsers = @json(
        $users->map(fn ($u) => ['id' => $u->id, 'label' => $u->display_name])->values()
    );

    const approverHiddenByRole = {
        post_prepared_by_name: document.getElementById('post_prepared_by_name_value'),
        post_checked_by_engineering_name: document.getElementById('post_checked_by_engineering_name_value'),
        post_checked_by_production_name: document.getElementById('post_checked_by_production_name_value'),
        post_checked_by_quality_name: document.getElementById('post_checked_by_quality_name_value'),
        post_checked_by_validation_name: document.getElementById('post_checked_by_validation_name_value'),
        post_approved_by_quality_name: document.getElementById('post_approved_by_quality_name_value'),
    };

    /**
     * Infer POST approver role from Veeva approval-page tables (Role | Name | Signature | Date).
     */
    const inferApproverRoleFromApprovalPageTable = function (sel) {
        const row = sel.closest('tr');
        if (!row) return '';
        const roleCell = row.querySelector('td:first-child');
        const roleText = (roleCell ? roleCell.textContent : '').toLowerCase();
        if (roleText.includes('performed') || roleText.includes('recorded') || roleText.includes('complied')) {
            return 'post_prepared_by_name';
        }
        if (roleText.includes('checked') || roleText.includes('reviewed')) {
            return 'post_checked_by_engineering_name';
        }
        if (roleText.includes('approved')) {
            return 'post_approved_by_quality_name';
        }
        return '';
    };

    /**
     * Infer POST approver role from 8.0 Report Approval tables (Prepared/Checked/Approved By blocks).
     */
    const inferApproverRoleFromReportApprovalTable = function (sel) {
        const table = sel.closest('table');
        if (!table) return '';
        const titleCell = table.querySelector('tr:first-child td');
        const title = (titleCell ? titleCell.textContent : '').toLowerCase();
        const approverSelects = Array.from(table.querySelectorAll('select.approver-field'));
        const idx = approverSelects.indexOf(sel);
        if (idx < 0) return '';
        if (title.includes('checked by')) {
            const roles = [
                'post_checked_by_engineering_name',
                'post_checked_by_production_name',
                'post_checked_by_quality_name',
                'post_checked_by_validation_name',
            ];
            return roles[idx] || '';
        }
        if (title.includes('prepared by')) {
            return 'post_prepared_by_name';
        }
        if (title.includes('approved by')) {
            return 'post_approved_by_quality_name';
        }
        return '';
    };

    /**
     * Infer POST approver role from signature row text when parser did not set data-approver-role.
     * Covers Quality Assurance rows embedded in Checked By tables.
     */
    const inferApproverRoleFromRow = function (sel) {
        const row = sel.closest('tr');
        if (!row) return '';
        const text = (row.textContent || '').toLowerCase();
        if (text.includes('quality') || text.includes('assurance') || /\bqa\b/.test(text)) {
            const tableTitle = ((sel.closest('table') || {}).innerText || '').slice(0, 80).toLowerCase();
            if (tableTitle.includes('checked by') && !tableTitle.includes('approved by')) {
                return 'post_checked_by_quality_name';
            }
            return 'post_approved_by_quality_name';
        }
        if (text.includes('reviewed')) {
            return 'post_checked_by_engineering_name';
        }
        if (text.includes('engineering') || text.includes('checked')) {
            return 'post_checked_by_engineering_name';
        }
        if (text.includes('production')) {
            return 'post_checked_by_production_name';
        }
        if (text.includes('validation') || text.includes('user/validation')) {
            return 'post_checked_by_validation_name';
        }
        if (text.includes('performed') || text.includes('recorded') || text.includes('complied')) {
            return 'post_prepared_by_name';
        }
        if (text.includes('prepared')) {
            return 'post_prepared_by_name';
        }
        return '';
    };

    /** Hydrate signature-table approver placeholders with user options from DB. */
    const hydrateApproverFields = function () {
        document.querySelectorAll('select.approver-field').forEach(function (sel) {
            if (sel.dataset.hydrated === '1') return;
            sel.dataset.hydrated = '1';

            let role = sel.dataset.approverRole || '';
            if (!role) {
                role = inferApproverRoleFromApprovalPageTable(sel);
            }
            if (!role) {
                role = inferApproverRoleFromReportApprovalTable(sel);
            }
            if (!role) {
                role = inferApproverRoleFromRow(sel);
                if (role) {
                    sel.dataset.approverRole = role;
                }
            }
            if (!role) return;

            const hiddenId = approverHiddenByRole[role] ? approverHiddenByRole[role].value : '';
            docxUsers.forEach(function (user) {
                const opt = document.createElement('option');
                opt.value = user.id;
                opt.textContent = user.label;
                if (String(hiddenId) === String(user.id)) {
                    opt.selected = true;
                }
                sel.appendChild(opt);
            });

            sel.addEventListener('change', function () {
                const userId = sel.value;
                const fieldLoc = sel.dataset.fieldLocation || '';

                if (role && approverHiddenByRole[role]) {
                    approverHiddenByRole[role].value = userId;
                }

                const nameHidden = fieldLoc
                    ? document.querySelector(
                        'input.approver-name-field[data-field-location="' + fieldLoc + '"]'
                    )
                    : null;
                const selected = docxUsers.find(function (u) { return String(u.id) === String(userId); });
                if (nameHidden && selected) {
                    nameHidden.value = selected.label;
                }

                // Date fields stay empty until the user picks a date or an approver stamps on approval.
            });

            if (sel.value) {
                sel.dispatchEvent(new Event('change'));
            }
        });
    };

    /**
     * Attachment rows whose title was treated as a heading (Dust Extractor, Area Recovery)
     * can arrive without a file input. Put Choose File on every Attachment No. data cell.
     */
    const ensureAttachmentFileInputs = function () {
        document.querySelectorAll('table.docx-table').forEach(function (table) {
            const headerRow = table.querySelector('tr');
            if (!headerRow) return;
            const headers = Array.from(headerRow.children);
            let attachCol = -1;
            headers.forEach(function (cell, idx) {
                const text = (cell.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
                if (text === 'attachment no.' || text === 'attachment no') attachCol = idx;
            });
            if (attachCol < 0) return;

            Array.from(table.querySelectorAll('tr')).forEach(function (row, rowIndex) {
                if (rowIndex === 0) return;
                const cells = Array.from(row.children);
                const cell = cells[attachCol];
                if (!cell || cell.querySelector('input[type="file"]')) return;

                let location = '';
                const marked = row.querySelector('[data-field], [name^="fields["], [name^="attachments["]');
                const raw = marked
                    ? (marked.getAttribute('data-field') || marked.getAttribute('name') || '')
                    : '';
                const match = raw.match(/table_\d+_row_\d+/);
                if (match) {
                    location = match[0] + '_col_' + attachCol;
                }
                if (!location) return;

                cell.classList.add('editable', 'docx-attachment-cell');
                cell.setAttribute('data-attachment-location', location);
                cell.innerHTML = ''
                    + '<input type="file" class="docx-attachment-field" name="attachments[' + location + ']" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">'
                    + '<small class="text-muted d-block mt-1">Upload PDF/DOCX</small>'
                    + '<input type="hidden" name="fields[' + location + ']" class="attachment-name-field" data-attachment-location="' + location + '">';
            });
        });
    };

    const isDateTimeEntryInput = function (input) {
        if (!input || input.type === 'hidden' || input.type === 'file' || input.type === 'radio' || input.type === 'checkbox') {
            return false;
        }
        const hint = ((input.getAttribute('placeholder') || '') + ' ' + (input.getAttribute('name') || '')).toLowerCase();
        if (hint.indexOf('signature') !== -1 || hint.indexOf('effective') !== -1) return false;
        // "Checked by (Engineering) (Sign. /Date)" is a written sign-off, not a calendar.
        if (/\bsign\b/.test(hint) && /\bdate\b/.test(hint)) return false;
        return /\bdate\b/.test(hint) || /\btime\b/.test(hint);
    };

    /** Date and time are picked on this protocol, including a time, not copied from the old file. */
    const prepareDateTimeInputs = function (clearValues) {
        document.querySelectorAll('input.docx-input, input.form-control').forEach(function (input) {
            if (!isDateTimeEntryInput(input)) return;
            if (input.type === 'date' || input.type === 'text' || input.type === 'datetime-local') {
                const current = input.value || input.getAttribute('value') || '';
                input.type = 'datetime-local';
                if (clearValues) {
                    input.value = '';
                } else if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(current)) {
                    input.value = current.slice(0, 16);
                } else if (/^\d{4}-\d{2}-\d{2}$/.test(current)) {
                    input.value = current + 'T00:00';
                }
            }
        });
    };

    /** Copy selected filename into visible + hidden fields[] when user picks a file. */
    const bindAttachmentFields = function () {
        document.querySelectorAll('input.docx-attachment-field').forEach(function (fileInput) {
            fileInput.addEventListener('change', function () {
                const loc = (fileInput.name.match(/attachments\[(.+)\]/) || [])[1];
                if (!loc || !fileInput.files || !fileInput.files[0]) return;
                const name = fileInput.files[0].name;
                const hidden = document.querySelector(
                    'input.attachment-name-field[data-attachment-location="' + loc + '"]'
                );
                const labelEdit = fileInput.closest('td')?.querySelector('input.docx-attachment-label-edit');
                if (hidden) hidden.value = name;
                if (labelEdit) labelEdit.value = name;
            });
        });
    };

    hydrateApproverFields();
    ensureAttachmentFileInputs();
    prepareDateTimeInputs(false);
    bindAttachmentFields();

    if (form && btnSubmit) {
        form.addEventListener('submit', function () {
            if (form.querySelector('input[name="requal_pre_submit"]')) {
                btnSubmit.disabled = true;
                if (btnText) btnText.innerText = 'Sending for pre-approval...';
                return;
            }
            document.querySelectorAll('select.approver-field').forEach(function (sel) {
                sel.dispatchEvent(new Event('change'));
            });
            btnSubmit.disabled = true;
            if (btnText) btnText.innerText = 'Filling Document, please wait...';
            const icon = btnSubmit.querySelector('i');
            if (icon) icon.className = 'spinner-border spinner-border-sm mr-1';
        });
    }

    // Sync blade header inputs with Word header table field locations
    const syncHeaderPair = function (headerId, fieldName) {
        const header = document.getElementById(headerId);
        if (!header) return;
        const match = document.querySelector('[name="fields[' + fieldName + ']"]');
        if (!match) return;
        header.addEventListener('input', function () { match.value = header.value; });
        match.addEventListener('input', function () { header.value = match.value; });
        if (header.value && !match.value) match.value = header.value;
        if (match.value && !header.value) header.value = match.value;
    };

    const coverLocations = @json($coverFieldLocations ?? []);
    const fillFromProtocolUrlTemplate = @json(
        route('reports.docx-import.fill-from-protocol', ['report' => $report->id, 'sourceReport' => '__SOURCE__'])
    );

    const btnFillWithDocx = document.getElementById('btnFillWithDocx');
    const fillWithDocxPanel = document.getElementById('fillWithDocxPanel');
    const fillFromProtocolSelect = document.getElementById('fill_from_protocol_id');
    const fillWithDocxStatus = document.getElementById('fillWithDocxStatus');

    const showFillWithDocxStatus = function (message, type) {
        if (!fillWithDocxStatus) return;
        fillWithDocxStatus.style.display = 'block';
        fillWithDocxStatus.className = 'alert mt-3 mb-0 py-2 small alert-' + (type || 'info');
        fillWithDocxStatus.textContent = message;
    };

    const applyDocxAutofillPayload = function (payload) {
        const fields = payload.fields || {};
        let fieldCount = 0;

        Object.keys(fields).forEach(function (location) {
            const value = fields[location];
            const inputs = document.querySelectorAll('[name="fields[' + location + ']"]');
            inputs.forEach(function (input) {
                if (!input || input.type === 'file') return;
                if (input.readOnly || input.disabled) return;
                if (input.classList.contains('approver-field') || input.classList.contains('approver-name-field')) return;
                if (input.classList.contains('attachment-name-field') || input.classList.contains('docx-attachment-field')) return;
                if (isDateTimeEntryInput(input)) return;
                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                fieldCount++;
            });
        });

        // Approval selects stay on "-- Select User --". Date and time stay empty
        // so this protocol can pick its own.
        prepareDateTimeInputs(true);

        {{-- if (coverLocations.facility_section && fields[coverLocations.facility_section]) {
            const el = document.getElementById('fill_facility_section');
            if (el) {
                el.value = fields[coverLocations.facility_section];
                el.dispatchEvent(new Event('input', { bubbles: true }));
            }
        } --}}
        if (coverLocations.equipment_name && fields[coverLocations.equipment_name]) {
            const el = document.getElementById('fill_equipment_name');
            if (el) {
                el.value = fields[coverLocations.equipment_name];
                el.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
        if (coverLocations.equipment_id && fields[coverLocations.equipment_id]) {
            const el = document.getElementById('fill_equipment_id');
            if (el) {
                el.value = fields[coverLocations.equipment_id];
                el.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }

        return fieldCount;
    };

    if (btnFillWithDocx && fillWithDocxPanel) {
        btnFillWithDocx.addEventListener('click', function () {
            const isHidden = fillWithDocxPanel.style.display === 'none';
            fillWithDocxPanel.style.display = isHidden ? 'block' : 'none';
            if (isHidden && fillFromProtocolSelect) {
                fillFromProtocolSelect.focus();
            }
        });
    }

    if (fillFromProtocolSelect) {
        fillFromProtocolSelect.addEventListener('change', function () {
            const sourceId = fillFromProtocolSelect.value;
            if (!sourceId) {
                if (fillWithDocxStatus) fillWithDocxStatus.style.display = 'none';
                return;
            }

            const url = fillFromProtocolUrlTemplate.replace('__SOURCE__', encodeURIComponent(sourceId));
            showFillWithDocxStatus('Loading field data from selected protocol...', 'info');

            fetch(url, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, status: response.status, data: data };
                    });
                })
                .then(function (result) {
                    if (!result.ok) {
                        const message = (result.data && result.data.message)
                            ? result.data.message
                            : 'Failed to load DOCX field data.';
                        console.error('Fill with DOCX failed', {
                            status: result.status,
                            sourceId: sourceId,
                            response: result.data,
                        });
                        showFillWithDocxStatus(message, 'danger');
                        return;
                    }

                    const filled = applyDocxAutofillPayload(result.data);
                    const count = result.data.filled_count != null ? result.data.filled_count : filled;
                    const sourceCode = (result.data.source_code || '').trim() || sourceId;
                    showFillWithDocxStatus(
                        'Filled ' + count + ' matching field(s) from ' + sourceCode + '.',
                        'success'
                    );
                    if (typeof window.docxScheduleDraftSave === 'function') {
                        window.docxScheduleDraftSave();
                    }
                })
                .catch(function (error) {
                    console.error('Fill with DOCX request error', {
                        sourceId: sourceId,
                        url: url,
                        error: error,
                    });
                    showFillWithDocxStatus('Failed to load DOCX field data. Check the console for details.', 'danger');
                });
        });
    }

    const syncMappingToHeader = function (mappingId, headerId, fieldName) {
        if (!fieldName) return;
        const mapping = document.getElementById(mappingId);
        const header  = document.getElementById(headerId);
        const hidden  = document.querySelector('[name="fields[' + fieldName + ']"]');
        if (!mapping) return;
        const apply = function () {
            if (header) header.value = mapping.value;
            if (hidden) hidden.value = mapping.value;
        };
        mapping.addEventListener('input', apply);
        apply();
    };
    const syncMappingToHidden = function (mappingId, hiddenId) {
        const mapping = document.getElementById(mappingId);
        const hidden  = document.getElementById(hiddenId);
        if (!mapping || !hidden) return;
        const apply = function () { hidden.value = mapping.value; };
        mapping.addEventListener('input', apply);
        apply();
    };
    const syncMappingToField = function (mappingId, fieldName) {
        const mapping = document.getElementById(mappingId);
        const hidden  = document.querySelector('[name="fields[' + fieldName + ']"]');
        if (!mapping || !fieldName) return;
        const apply = function () {
            if (hidden) hidden.value = mapping.value;
        };
        mapping.addEventListener('input', apply);
        apply();
    };

    {{-- if (coverLocations.facility_section) {
        syncMappingToField('fill_facility_section', coverLocations.facility_section);
    } --}}
    if (coverLocations.equipment_name) {
        syncMappingToField('fill_equipment_name', coverLocations.equipment_name);
        syncMappingToHeader('fill_equipment_name', 'header_equipment_name', coverLocations.equipment_name);
    }
    if (coverLocations.equipment_id) {
        syncMappingToField('fill_equipment_id', coverLocations.equipment_id);
        syncMappingToHeader('fill_equipment_id', 'header_equipment_id', coverLocations.equipment_id);
    }
    syncMappingToHeader('fill_protocol_document_no', 'header_doc_number', 'header_0_row_4_col_2');
    syncMappingToField('fill_protocol_document_no', coverLocations.document_no || 'header_0_row_4_col_2');
    syncMappingToHeader('fill_reference_protocol_no', 'header_ref_protocol', 'header_ref_protocol');
    syncMappingToField('fill_reference_protocol_no', coverLocations.reference_protocol_no || 'header_ref_protocol');
    const documentTitleLocation = @json($documentTitleLocation ?? null);
    const fillDocumentTitle = document.getElementById('fill_document_title');
    if (documentTitleLocation && fillDocumentTitle) {
        const previewTitle = document.querySelector('[name="fields[' + documentTitleLocation + ']"]');
        const applyDocumentTitle = function () {
            if (previewTitle) previewTitle.value = fillDocumentTitle.value;
        };
        fillDocumentTitle.addEventListener('input', applyDocumentTitle);
        if (!fillDocumentTitle.value && previewTitle && previewTitle.value) {
            fillDocumentTitle.value = previewTitle.value;
        }
        applyDocumentTitle();
    }
    {{-- syncMappingToHidden('version', 'header_version_hidden'); --}}

    const effectiveDateLocation = @json($effectiveDateLocation ?? null);
    const fillEffectiveDate = document.getElementById('fill_effective_date');
    const toIsoDate = function (value) {
        if (!value) return '';
        const v = String(value).trim();
        const dmy = v.match(/^(\d{2})-(\d{2})-(\d{4})$/);
        if (dmy) return dmy[3] + '-' + dmy[2] + '-' + dmy[1];
        return v;
    };
    const injectEffectiveDateParagraphInputs = function () {
        const defaultDate = fillEffectiveDate ? toIsoDate(fillEffectiveDate.value) : '';
        document.querySelectorAll('.docx-preview p.docx-para').forEach(function (p) {
            const raw = (p.textContent || '').trim();
            if (!/effective\s*date/i.test(raw)) return;
            if (p.querySelector('input[type="date"]')) return;

            const loc = effectiveDateLocation || ('para_effective_' + Array.from(document.querySelectorAll('input[name^="fields[para_"]')).length);
            const label = 'Effective Date: ';
            const input = document.createElement('input');
            input.type = 'date';
            input.name = 'fields[' + loc + ']';
            input.className = 'docx-input-inline';
            input.value = defaultDate;

            p.textContent = '';
            p.classList.add('docx-para-editable');
            p.appendChild(document.createTextNode(label));
            p.appendChild(input);

            if (fillEffectiveDate) {
                input.addEventListener('input', function () { fillEffectiveDate.value = input.value; });
                input.addEventListener('change', function () { fillEffectiveDate.value = input.value; });
            }
        });
    };
    if (effectiveDateLocation && fillEffectiveDate) {
        const previewEffective = document.querySelector('[name="fields[' + effectiveDateLocation + ']"]');
        const applyEffectiveDate = function () {
            if (previewEffective) {
                previewEffective.value = toIsoDate(fillEffectiveDate.value);
            }
        };
        fillEffectiveDate.addEventListener('input', applyEffectiveDate);
        fillEffectiveDate.addEventListener('change', applyEffectiveDate);
        if (!fillEffectiveDate.value && previewEffective && previewEffective.value) {
            fillEffectiveDate.value = toIsoDate(previewEffective.value);
        }
        applyEffectiveDate();
    }
    injectEffectiveDateParagraphInputs();

    {{-- Autofill / draft resume for approver dropdowns from hidden inputs --}}
    Object.keys(approverHiddenByRole).forEach(function (role) {
        const hidden = approverHiddenByRole[role];
        if (!hidden || !hidden.value) return;
        document.querySelectorAll('select.approver-field').forEach(function (sel) {
            const selRole = sel.dataset.approverRole
                || inferApproverRoleFromApprovalPageTable(sel)
                || inferApproverRoleFromReportApprovalTable(sel)
                || inferApproverRoleFromRow(sel);
            if (selRole === role) {
                sel.value = String(hidden.value);
            }
        });
        const named = document.querySelector('select[name="' + role + '"]');
        if (named) {
            named.value = String(hidden.value);
        }
    });

    // ── Autosave / resume draft (no e-sign, no approval start) ──
    const draftSaveUrl = @json(route('reports.docx-import.draft', $report));
    const draftStatusEl = document.getElementById('docxDraftStatus');
    const btnSaveDraft = document.getElementById('btnSaveDraft');
    let draftTimer = null;
    let draftSaving = false;
    let draftPending = false;
    let draftDirty = false;
    let finalSubmitting = false;

    const setDraftStatus = function (message, tone) {
        if (!draftStatusEl) return;
        draftStatusEl.style.display = 'inline-block';
        draftStatusEl.className = 'badge shadow-sm mr-2 px-3 py-2 '
            + (tone === 'danger' ? 'badge-danger'
                : (tone === 'saving' ? 'badge-warning text-dark' : 'badge-light text-secondary'));
        draftStatusEl.innerHTML = message;
    };

    const collectDraftFormData = function () {
        const fd = new FormData(form);
        fd.delete('password');
        // Empty file inputs must not be posted. A chosen file is part of the draft.
        form.querySelectorAll('input[type="file"]').forEach(function (input) {
            if (!input.name) return;
            if (!input.files || !input.files.length) {
                fd.delete(input.name);
            }
        });
        return fd;
    };

    const paintSavedAttachment = function (loc, info) {
        if (!loc || !info || !info.url) return;
        const cell = document.querySelector('td[data-attachment-location="' + loc + '"]');
        if (!cell) return;
        let link = cell.querySelector('a.docx-attachment-link');
        if (!link) {
            link = document.createElement('a');
            link.className = 'docx-attachment-link';
            link.target = '_blank';
            cell.insertBefore(link, cell.firstChild);
        }
        link.href = info.url;
        link.textContent = info.name || 'Open attachment';
        const hidden = cell.querySelector('input.attachment-name-field');
        if (hidden) hidden.value = info.name || hidden.value;
        const fileInput = cell.querySelector('input[type="file"]');
        if (fileInput) fileInput.value = '';
    };

    const saveDraftNow = function (opts) {
        opts = opts || {};
        if (requalFillLocked || !form || finalSubmitting) {
            return Promise.resolve(false);
        }
        if (draftSaving) {
            draftPending = true;
            draftDirty = true;
            return Promise.resolve(false);
        }
        draftSaving = true;
        draftDirty = false;
        if (!opts.silent) {
            setDraftStatus('<i class="fas fa-spinner fa-spin mr-1"></i> Saving draft…', 'saving');
        }
        if (btnSaveDraft) {
            btnSaveDraft.disabled = true;
        }

        return fetch(draftSaveUrl, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': (form.querySelector('input[name="_token"]') || {}).value || '',
            },
            credentials: 'same-origin',
            body: collectDraftFormData(),
            keepalive: !!opts.keepalive,
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                }).catch(function () {
                    return { ok: response.ok, data: {} };
                });
            })
            .then(function (result) {
                if (!result.ok || !result.data.success) {
                    const msg = (result.data && result.data.message)
                        ? result.data.message
                        : 'Draft could not be saved.';
                    setDraftStatus('<i class="fas fa-exclamation-triangle mr-1"></i> ' + msg, 'danger');
                    draftDirty = true;
                    return false;
                }
                const when = result.data.saved_at || '';
                const savedFiles = result.data.attachments || {};
                Object.keys(savedFiles).forEach(function (loc) {
                    paintSavedAttachment(loc, savedFiles[loc]);
                });
                setDraftStatus(
                    '<i class="fas fa-cloud-upload-alt mr-1"></i> Draft saved'
                        + (when ? (' ' + when) : ''),
                    'ok'
                );
                return true;
            })
            .catch(function () {
                setDraftStatus('<i class="fas fa-exclamation-triangle mr-1"></i> Draft save failed (network).', 'danger');
                draftDirty = true;
                return false;
            })
            .finally(function () {
                draftSaving = false;
                if (btnSaveDraft) {
                    btnSaveDraft.disabled = false;
                }
                if (draftPending && !finalSubmitting) {
                    draftPending = false;
                    saveDraftNow();
                }
            });
    };

    const scheduleDraftSave = function () {
        draftDirty = true;
        if (draftTimer) {
            clearTimeout(draftTimer);
        }
        draftTimer = setTimeout(function () {
            saveDraftNow();
        }, 1500);
    };
    window.docxScheduleDraftSave = scheduleDraftSave;
    window.docxSaveDraftNow = saveDraftNow;

    if (form) {
        form.addEventListener('input', function (e) {
            const t = e.target;
            if (!t || t.name === 'password' || (t.type === 'file')) return;
            scheduleDraftSave();
        });
        form.addEventListener('change', function (e) {
            const t = e.target;
            if (!t || t.name === 'password') return;
            if (t.type === 'file') {
                if (!t.files || !t.files.length) return;
                if (draftTimer) {
                    clearTimeout(draftTimer);
                }
                saveDraftNow();
                return;
            }
            scheduleDraftSave();
        });
        form.addEventListener('submit', function () {
            finalSubmitting = true;
            if (draftTimer) {
                clearTimeout(draftTimer);
            }
        });
    }

    if (btnSaveDraft) {
        btnSaveDraft.addEventListener('click', function () {
            if (draftTimer) {
                clearTimeout(draftTimer);
            }
            saveDraftNow();
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden' && draftDirty && !finalSubmitting) {
            if (draftTimer) {
                clearTimeout(draftTimer);
            }
            saveDraftNow({ keepalive: true, silent: true });
        }
    });
    window.addEventListener('pagehide', function () {
        if (draftDirty && !finalSubmitting) {
            saveDraftNow({ keepalive: true, silent: true });
        }
    });

    @if(($importSource ?? '') === 'requalification' && empty($requalFillLocked))
    (function () {
        const btnEsignStamp = document.getElementById('btnEsignStamp');
        const esignStatusEl = document.getElementById('docxEsignStatus');
        const preview = document.querySelector('.docx-preview-wrapper');
        const signerName = @json(trim((auth()->user()->first_name ?? '').' '.(auth()->user()->last_name ?? '')) ?: (auth()->user()->email ?? 'User'));
        let lastSignField = null;
        const esignUndo = new WeakMap();

        const pushEsignUndo = function (field, previous, stamped) {
            if (!field || previous === stamped) return;
            const stack = esignUndo.get(field) || [];
            stack.push({ previous: previous, stamped: stamped });
            esignUndo.set(field, stack);
        };

        const looksLikeEsign = function (value) {
            const text = String(value || '').replace(/\s+/g, ' ').trim();
            if (!/\d{2}-\d{2}-\d{4}\s+\d{1,2}:\d{2}\s+(AM|PM)$/i.test(text)) return false;
            return text.indexOf(signerName) === 0
                || text.indexOf('Verified by sign and date : ' + signerName) === 0;
        };

        const undoEsignValue = function (field) {
            const stack = esignUndo.get(field);
            if (stack && stack.length) {
                const top = stack[stack.length - 1];
                if (field.value === top.stamped) {
                    stack.pop();
                    field.value = top.previous;
                    return true;
                }
            }
            if (!looksLikeEsign(field.value)) return false;
            field.value = '';
            return true;
        };

        const showEsignStatus = function (message, ok) {
            if (!esignStatusEl) return;
            esignStatusEl.style.display = 'inline-block';
            esignStatusEl.className = 'badge shadow-sm mr-2 px-3 py-2 '
                + (ok ? 'badge-success' : 'badge-warning text-dark');
            esignStatusEl.textContent = message;
        };

        const isStampableField = function (el) {
            if (!el || !preview || !preview.contains(el)) return false;
            if (el.disabled || el.readOnly) return false;
            const tag = (el.tagName || '').toLowerCase();
            if (tag !== 'textarea' && tag !== 'input') return false;
            const type = (el.getAttribute('type') || 'text').toLowerCase();
            if (tag === 'input' && type !== 'text' && type !== 'search') return false;
            const name = el.getAttribute('name') || '';
            return name.indexOf('fields[') === 0;
        };

        const rememberSignField = function (fromNode) {
            if (!fromNode || !fromNode.closest) return;
            const direct = fromNode.closest('input, textarea');
            if (isStampableField(direct)) {
                lastSignField = direct;
                return;
            }
            const cell = fromNode.closest('td, th, p, .docx-textbox');
            if (!cell) return;
            const field = cell.querySelector('input, textarea');
            if (isStampableField(field)) {
                lastSignField = field;
            }
        };

        if (preview) {
            preview.addEventListener('focusin', function (event) {
                rememberSignField(event.target);
            });
            preview.addEventListener('click', function (event) {
                rememberSignField(event.target);
            });
            preview.addEventListener('keydown', function (event) {
                if ((event.key !== 'z' && event.key !== 'Z') || event.altKey || event.shiftKey) return;
                if (!event.ctrlKey && !event.metaKey) return;
                const field = event.target;
                if (!isStampableField(field) || !undoEsignValue(field)) return;
                event.preventDefault();
                event.stopPropagation();
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.dispatchEvent(new Event('change', { bubbles: true }));
            }, true);
        }

        const stampWhen = function () {
            const now = new Date();
            const pad = function (n) { return String(n).padStart(2, '0'); };
            let hours = now.getHours();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            return pad(now.getDate()) + '-' + pad(now.getMonth() + 1) + '-' + now.getFullYear()
                + ' ' + pad(hours) + ':' + pad(now.getMinutes()) + ' ' + ampm;
        };

        if (btnEsignStamp) {
            btnEsignStamp.addEventListener('mousedown', function (event) {
                event.preventDefault();
            });
            btnEsignStamp.addEventListener('click', function () {
                const field = isStampableField(lastSignField) ? lastSignField : null;
                if (!field) {
                    showEsignStatus('Click a sign cell first, then click E-Sign.', false);
                    return;
                }
                const when = stampWhen();
                const previous = field.value;
                const hint = ((field.getAttribute('placeholder') || '') + ' ' + (field.value || ''))
                    .replace(/\s+/g, ' ')
                    .trim();
                const verifiedLabel = /^verified\s*by\s*sign\s*and\s*date\b/i.test(hint);
                if (verifiedLabel) {
                    field.value = 'Verified by sign and date : ' + signerName + ' ' + when;
                } else if ((field.tagName || '').toLowerCase() === 'textarea') {
                    field.value = signerName + '\n' + when;
                } else {
                    field.value = signerName + '  ' + when;
                }
                pushEsignUndo(field, previous, field.value);
                field.focus();
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.dispatchEvent(new Event('change', { bubbles: true }));
                if (esignStatusEl) {
                    esignStatusEl.style.display = 'none';
                    esignStatusEl.textContent = '';
                }
            });
        }
    })();
    @endif
});
</script>
@endsection
