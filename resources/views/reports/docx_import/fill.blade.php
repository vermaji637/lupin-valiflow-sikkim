@extends('layouts.app')

@push('styles')
<style>
/*
 * DOCX fill form — overrides premium-ui.css global input rules:
 *   body.premium-app input[type="text"]  → white bg, min-height 46px, border-radius 14px, inset shadow
 *   body.premium-app input:focus         → blue focus ring box-shadow
 * Selectors below use #docxFillForm + input[type="text"] for higher specificity.
 */

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
</style>
@endpush

@section('content')
<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-11 col-xl-10">

            <div class="mb-4">
                <a href="{{ route('reports.show', $report) }}" class="text-decoration-none text-secondary">
                    <i class="fas fa-arrow-left mr-1"></i> Back to Report #{{ $report->id }}
                </a>
            </div>

            <div class="card shadow border-0">
                <div class="card-header py-3" style="background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h5 class="mb-0 font-weight-bold text-white">
                                <i class="fas fa-file-signature mr-2"></i> Fill DOCX Fields
                            </h5>
                            <p class="mb-0 text-white-50 small mt-1">
                                @if(!empty($useTablePreview))
                                    Document table layout — empty cells are editable, filled cells are read-only.
                                @else
                                    Flat field list — empty cells are editable, filled cells are read-only.
                                @endif
                            </p>
                        </div>
                        <span class="badge badge-light px-3 py-2 text-primary shadow-sm font-weight-bold">
                            {{ count($fields) }} Total Fields
                        </span>
                    </div>
                </div>

                <form action="{{ route('reports.docx-import.fill', $report) }}" method="POST" id="docxFillForm" class="esign-required" enctype="multipart/form-data">
                    @csrf
                    <input type="hidden" name="password">
                    {{-- Word header table targets (section.header.tables[0]) — submitted early in POST --}}
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

                        <div class="card mb-4 border">
                            <div class="card-header">Document Mapping</div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
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
                                    </div>
                                    @if(!empty($hasDocumentTitleField))
                                        <div class="col-md-12">
                                            <label class="form-label font-weight-bold">Document Title</label>
                                            <input type="text" name="document_title" id="fill_document_title" class="form-control"
                                                   value="{{ old('document_title', $documentTitle ?? '') }}"
                                                   placeholder="Enter document title">
                                        </div>
                                    @endif
                                    <div class="col-md-4">
                                        <label class="form-label font-weight-bold">Facility / Section</label>
                                        <input type="text" name="facility_section" id="fill_facility_section" class="form-control"
                                               value="{{ old('facility_section', $facilitySection) }}">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label font-weight-bold">Equipment Name</label>
                                        <input type="text" name="equipment_name" id="fill_equipment_name" class="form-control"
                                               value="{{ old('equipment_name', $equipmentName) }}">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label font-weight-bold">Equipment ID</label>
                                        <input type="text" name="equipment_id" id="fill_equipment_id" class="form-control"
                                               value="{{ old('equipment_id', $equipmentId) }}">
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
                            @if(in_array('post_prepared_by_name', $presentApproverRoles, true))
                                <input type="hidden" name="post_prepared_by_name" id="post_prepared_by_name_value"
                                       value="{{ old('post_prepared_by_name', auth()->id()) }}">
                            @endif
                            @if(empty($usesApprovalPage))
                                @foreach ([
                                    'post_checked_by_engineering_name' => 'post_checked_by_engineering_name_value',
                                    'post_checked_by_production_name'  => 'post_checked_by_production_name_value',
                                    'post_checked_by_validation_name'  => 'post_checked_by_validation_name_value',
                                    'post_approved_by_quality_name'    => 'post_approved_by_quality_name_value',
                                ] as $roleKey => $inputId)
                                    @if(in_array($roleKey, $presentApproverRoles, true))
                                        <input type="hidden" name="{{ $roleKey }}" id="{{ $inputId }}"
                                               value="{{ old($roleKey) }}">
                                    @endif
                                @endforeach
                            @endif
                        @endif

                        @if(!empty($usesApprovalPage) && $hasApproverFields)
                            <div class="alert alert-info mb-4">
                                <i class="fas fa-user-check mr-1"></i>
                                <strong>Approval Page:</strong> Name, Signature, and Date are filled automatically when each
                                approval step completes. Select who will perform <strong>Performed By</strong> /
                                <strong>Checked By</strong> /
                                <strong>Reviewed By</strong> and <strong>Approved By</strong> below. The first row
                                (<strong>Recorded By</strong> / <strong>Complied By</strong> / <strong>Performed By</strong>) can be chosen in the table or defaults to you
                                ({{ auth()->user()->first_name ?? '' }} {{ auth()->user()->last_name ?? '' }}).
                            </div>
                            <div class="card mb-4 border-info">
                                <div class="card-body py-3">
                                    <div class="row">
                                        @if(in_array('post_prepared_by_name', $presentApproverRoles, true))
                                            <div class="col-md-4 mb-2 mb-md-0">
                                                <label class="form-label font-weight-bold mb-1">Recorded / Complied By</label>
                                                <div class="form-control-plaintext text-muted">
                                                    {{ trim((auth()->user()->first_name ?? '') . ' ' . (auth()->user()->last_name ?? '')) ?: 'Current user' }}
                                                    <small class="d-block">Auto-filled on submit</small>
                                                </div>
                                            </div>
                                        @endif
                                        @if(in_array('post_checked_by_engineering_name', $presentApproverRoles, true))
                                            <div class="col-md-4">
                                                <label class="form-label font-weight-bold mb-1" for="approval_page_checked_by">Checked / Reviewed By Approver</label>
                                                <select name="post_checked_by_engineering_name" id="approval_page_checked_by"
                                                        class="form-control approval-page-approver-select" required>
                                                    <option value="">-- Select User --</option>
                                                    @forelse($users ?? [] as $user)
                                                        <option value="{{ $user->id }}"
                                                            {{ (string) old('post_checked_by_engineering_name') === (string) $user->id ? 'selected' : '' }}>
                                                            {{ $user->display_name }}
                                                        </option>
                                                    @empty
                                                        <option value="" disabled>No active users found</option>
                                                    @endforelse
                                                </select>
                                            </div>
                                        @endif
                                        @if(in_array('post_approved_by_quality_name', $presentApproverRoles, true))
                                            <div class="col-md-4">
                                                <label class="form-label font-weight-bold mb-1" for="approval_page_approved_by">Approved By Approver</label>
                                                <select name="post_approved_by_quality_name" id="approval_page_approved_by"
                                                        class="form-control approval-page-approver-select" required>
                                                    <option value="">-- Select User --</option>
                                                    @forelse($users ?? [] as $user)
                                                        <option value="{{ $user->id }}"
                                                            {{ (string) old('post_approved_by_quality_name') === (string) $user->id ? 'selected' : '' }}>
                                                            {{ $user->display_name }}
                                                        </option>
                                                    @empty
                                                        <option value="" disabled>No active users found</option>
                                                    @endforelse
                                                </select>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @elseif($hasApproverFields)
                            <div class="alert alert-info mb-4">
                                <i class="fas fa-user-check mr-1"></i>
                                Select approvers in the <strong>Prepared By</strong>, <strong>Checked By</strong>, and
                                <strong>Approved By</strong> tables below. Those users will receive POST approval workflow steps.
                            </div>
                        @endif

                        {{-- ── TABLE LAYOUT PREVIEW (primary) ── --}}
                        @if(!empty($useTablePreview))
                            @php
                                $templateName = $report->template->name ?? 'PERIODIC HVAC VALIDATION REPORT';
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
                                                   value="{{ old('fields.header_0_row_4_col_2', $documentNo) }}">
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
                                            <input type="text" name="fields[header_ref_protocol]" class="docx-input"
                                                   value="{{ old('fields.header_ref_protocol', '') }}">
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
                            <div class="alert alert-warning mb-3">
                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                Table preview unavailable — ensure the Python service is running on port 8001.
                                Showing field list instead.
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

                    <div class="card-footer bg-light px-4 py-3 d-flex justify-content-between align-items-center">
                        <a href="{{ route('reports.show', $report) }}" class="btn btn-light shadow-sm">
                            <i class="fas fa-times mr-1"></i> Cancel
                        </a>
                        @if(count($fields) > 0)
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

    /** Active users for signature-table approver dropdowns (same labels as fill_docx.blade.php). */
    const docxUsers = @json(
        $users->map(fn ($u) => ['id' => $u->id, 'label' => $u->display_name])->values()
    );

    const approverHiddenByRole = {
        post_prepared_by_name: document.getElementById('post_prepared_by_name_value'),
        post_checked_by_engineering_name: document.getElementById('post_checked_by_engineering_name_value'),
        post_checked_by_production_name: document.getElementById('post_checked_by_production_name_value'),
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
                'post_checked_by_validation_name',
                'post_approved_by_quality_name',
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
    bindAttachmentFields();

    if (form && btnSubmit) {
        form.addEventListener('submit', function () {
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

    if (coverLocations.facility_section) {
        syncMappingToField('fill_facility_section', coverLocations.facility_section);
    }
    if (coverLocations.equipment_name) {
        syncMappingToField('fill_equipment_name', coverLocations.equipment_name);
        syncMappingToHeader('fill_equipment_name', 'header_equipment_name', coverLocations.equipment_name);
    }
    if (coverLocations.equipment_id) {
        syncMappingToField('fill_equipment_id', coverLocations.equipment_id);
        syncMappingToHeader('fill_equipment_id', 'header_equipment_id', coverLocations.equipment_id);
    }
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
    syncMappingToHidden('version', 'header_version_hidden');

    const effectiveDateLocation = @json($effectiveDateLocation ?? null);
    const fillEffectiveDate = document.getElementById('fill_effective_date');
    if (effectiveDateLocation && fillEffectiveDate) {
        const previewEffective = document.querySelector('[name="fields[' + effectiveDateLocation + ']"]');
        const applyEffectiveDate = function () {
            if (previewEffective) {
                previewEffective.value = fillEffectiveDate.value;
            }
        };
        fillEffectiveDate.addEventListener('input', applyEffectiveDate);
        fillEffectiveDate.addEventListener('change', applyEffectiveDate);
        if (!fillEffectiveDate.value && previewEffective && previewEffective.value) {
            fillEffectiveDate.value = previewEffective.value;
        }
        applyEffectiveDate();
    }

    const docSelect = document.getElementById('fill_document_id');
    const docHidden = document.getElementById('document_id_hidden');

    const applyDocumentOption = function () {
        if (!docSelect) return;
        const opt = docSelect.options[docSelect.selectedIndex];
        if (docHidden) {
            docHidden.value = (opt && opt.value) ? opt.value : '';
        }
        if (!opt || !opt.value) return;

        const facility = document.getElementById('fill_facility_section');
        const eqName   = document.getElementById('fill_equipment_name');
        const eqId     = document.getElementById('fill_equipment_id');
        const hdrName  = document.getElementById('header_equipment_name');
        const hdrId    = document.getElementById('header_equipment_id');
        if (facility && opt.dataset.facility) facility.value = opt.dataset.facility;
        if (eqName && opt.dataset.equipmentName) {
            eqName.value = opt.dataset.equipmentName;
            if (hdrName) hdrName.value = opt.dataset.equipmentName;
        }
        if (eqId && opt.dataset.equipmentId) {
            eqId.value = opt.dataset.equipmentId;
            if (hdrId) hdrId.value = opt.dataset.equipmentId;
        }
    };

    if (docSelect) {
        docSelect.addEventListener('change', applyDocumentOption);
        applyDocumentOption();
    }
});
</script>
@endsection
