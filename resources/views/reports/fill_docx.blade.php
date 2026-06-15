@extends('layouts.app')

@section('content')
<div class="container">
    <h2>Fill: {{ $template->name }}</h2>
    @php
        $manualMode = empty($fields);
    @endphp

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('reports.store', $template) }}" enctype="multipart/form-data" class="esign-required">
        @csrf
        <input type="hidden" name="template_id" value="{{ $template->id }}">

        <div class="card mb-3">
            <div class="card-header">Pre-Approved Document (Required)</div>
            <div class="card-body">
                <div class="alert alert-info mb-3">
                    Step 1: Upload the pre-approved DOC/DOCX/PDF file. Workflow starts only after this file is uploaded.
                </div>
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Pre-Approved File</label>
                        <input type="file"
                               class="form-control"
                               name="preapproved_doc_file"
                               accept=".doc,.docx,.pdf"
                               required>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Document Mapping</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Approved Document</label>
                        <select class="form-control" name="document_id" id="docx_document_id">
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
                        <label class="form-label">Version</label>
                        <input type="text" name="version" class="form-control" value="{{ old('version') }}">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Facility</label>
                        <input type="text" name="facility_section" class="form-control" value="{{ old('facility_section') }}">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Equipment Name</label>
                        <input type="text" name="equipment_name" class="form-control" value="{{ old('equipment_name') }}">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Equipment ID</label>
                        <input type="text" name="equipment_id" class="form-control" value="{{ old('equipment_id') }}">
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">DOCX Fields</div>
            <div class="card-body">
                @if($manualMode)
                    <div class="alert alert-warning mb-3">
                        Exact layout mode is enabled: download the source DOCX, fill it offline, then upload the completed file below.
                    </div>

                    @if(!empty($template->docx_template_path))
                        @php
                            $sourceDocUrl = route('templates.source-doc', $template);
                        @endphp
                        <div class="d-flex gap-2 mb-3 flex-wrap">
                            <a class="btn btn-outline-primary"
                               href="{{ $sourceDocUrl }}"
                               target="_blank">Download Source Template</a>
                            <a class="btn btn-primary"
                               href="ms-word:ofe|u|{{ $sourceDocUrl }}">Open in MS Word (Exact Layout)</a>
                        </div>
                    @endif

                    <div class="mb-2">
                        <label class="form-label">Upload Completed File</label>
                        <input type="file" class="form-control" name="completed_doc_file" accept=".doc,.docx,.pdf" required>
                    </div>
                @else
                    <div class="alert alert-info mb-3">
                        Fill all detected fields below. On submit, the system generates the final DOCX with the same uploaded layout.
                    </div>
                    @if(!empty($template->docx_template_path))
                        @php
                            $sourceDocUrl = route('templates.source-doc', $template);
                        @endphp
                        <div class="d-flex gap-2 mb-3 flex-wrap">
                            <a class="btn btn-outline-primary"
                               href="{{ $sourceDocUrl }}"
                               target="_blank">View Source Template</a>
                        </div>
                    @endif
                    <div class="row g-3">
                        @foreach($fields as $field)
                           @php
    $fieldKey   = is_array($field) ? ($field['location'] ?? '') : $field;
    $fieldLabel = is_array($field) ? ($field['label'] ?? $fieldKey) : $field;
    $fieldValue = is_array($field) ? ($field['value'] ?? '') : '';
    $isLocked   = is_array($field) ? ($field['locked'] ?? false) : false;
    $isDate     = str_contains(strtolower($fieldLabel), 'date');
@endphp
<div class="col-md-6">
    <label class="form-label">{{ ucwords($fieldLabel) }}</label>
    @if($isLocked)
        <div class="form-control bg-light text-muted">{{ $fieldValue ?: '—' }}</div>
        <input type="hidden" name="fields[{{ $fieldKey }}]" value="{{ $fieldValue }}">
    @else
        <input type="{{ $isDate ? 'date' : 'text' }}"
               class="form-control"
               name="fields[{{ $fieldKey }}]"
               value="{{ old('fields.'.$fieldKey, $fieldValue) }}">
    @endif
</div>
                        @endforeach
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Optional: Upload Manually Completed File</label>
                        <input type="file" class="form-control" name="completed_doc_file" accept=".doc,.docx,.pdf">
                    </div>
                @endif
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Post-Approval Workflow Assignment</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Post Prepared By</label>
                        <select name="post_prepared_by_name" class="form-control" required>
                            <option value="">-- Select User --</option>
                            @foreach($users as $user)
                                <option value="{{ $user->id }}" {{ old('post_prepared_by_name', auth()->id()) == $user->id ? 'selected' : '' }}>
                                    {{ $user->display_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Post Checked By Engineering</label>
                        <select name="post_checked_by_engineering_name" class="form-control" required>
                            <option value="">-- Select User --</option>
                            @foreach($users as $user)
                                <option value="{{ $user->id }}" {{ old('post_checked_by_engineering_name') == $user->id ? 'selected' : '' }}>
                                    {{ $user->display_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Post Checked By Production</label>
                        <select name="post_checked_by_production_name" class="form-control" required>
                            <option value="">-- Select User --</option>
                            @foreach($users as $user)
                                <option value="{{ $user->id }}" {{ old('post_checked_by_production_name') == $user->id ? 'selected' : '' }}>
                                    {{ $user->display_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Post Checked By Validation</label>
                        <select name="post_checked_by_validation_name" class="form-control" required>
                            <option value="">-- Select User --</option>
                            @foreach($users as $user)
                                <option value="{{ $user->id }}" {{ old('post_checked_by_validation_name') == $user->id ? 'selected' : '' }}>
                                    {{ $user->display_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Post Approved By QA</label>
                        <select name="post_approved_by_quality_name" class="form-control" required>
                            <option value="">-- Select User --</option>
                            @foreach($users as $user)
                                <option value="{{ $user->id }}" {{ old('post_approved_by_quality_name') == $user->id ? 'selected' : '' }}>
                                    {{ $user->display_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Attachments (Optional)</div>
            <div class="card-body">
                @for($i = 1; $i <= 3; $i++)
                    <div class="row g-2 mb-2">
                        <div class="col-md-4">
                            <input type="text" class="form-control" name="attachment_name_{{ $i }}" placeholder="Attachment {{ $i }} name" value="{{ old('attachment_name_' . $i) }}">
                        </div>
                        <div class="col-md-8">
                            <input type="file" class="form-control" name="attachment_file_{{ $i }}" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                        </div>
                    </div>
                @endfor
            </div>
        </div>

        <button class="btn btn-success" type="submit">Submit</button>
    </form>
</div>

<script>
document.addEventListener('change', function (e) {
    if (!e.target.matches('#docx_document_id')) return;

    const selected = e.target.selectedOptions[0];
    if (!selected) return;

    const facility = selected.getAttribute('data-facility') || '';
    const eqName = selected.getAttribute('data-equipment-name') || '';
    const eqId = selected.getAttribute('data-equipment-id') || '';

    const facilityInput = document.querySelector('input[name="facility_section"]');
    const eqNameInput = document.querySelector('input[name="equipment_name"]');
    const eqIdInput = document.querySelector('input[name="equipment_id"]');

    if (facilityInput && !facilityInput.value) facilityInput.value = facility;
    if (eqNameInput && !eqNameInput.value) eqNameInput.value = eqName;
    if (eqIdInput && !eqIdInput.value) eqIdInput.value = eqId;
});
</script>
@endsection
