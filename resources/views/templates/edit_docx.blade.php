@extends('layouts.app')

@section('content')
<div class="container">
    <h2>Edit DOCX Template</h2>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('templates.update', $template) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="mb-3">
            <label class="form-label">Template Name</label>
            <input class="form-control" name="name" value="{{ old('name', $template->name) }}" required>
        </div>

        <input type="hidden" name="template_type" value="docx">

        <div class="mb-3">
            <label class="form-label">Replace DOC/DOCX (optional)</label>
            <input type="file" class="form-control" name="docx_file" accept=".doc,.docx">
        </div>

        @if(!empty($template->docx_template_path))
            @php
                $sourceDocUrl = route('templates.source-doc', $template);
            @endphp
            <div class="mb-3">
                <label class="form-label d-block">Edit Source Document</label>
                <div class="d-flex gap-2 flex-wrap">
                    <a class="btn btn-outline-primary" href="{{ $sourceDocUrl }}" target="_blank">
                        Download Current Template
                    </a>
                    <a class="btn btn-primary" href="ms-word:ofe|u|{{ $sourceDocUrl }}">
                        Open in MS Word
                    </a>
                </div>
                <small class="text-muted d-block mt-2">
                    After editing in Word, save the file and upload it again above to update the template.
                </small>
            </div>
        @endif

        <div class="mb-3">
            <label class="form-label">Detected Fields</label>
            @php
                $fields = $template->docx_placeholders ?? [];
            @endphp
            @if(!empty($fields))
                <div class="border rounded p-2" style="max-height: 240px; overflow-y: auto;">
                    @foreach($fields as $field)
                        <span class="badge bg-secondary me-1 mb-1">{{ is_array($field) ? ($field['label'] ?? '') : $field }}</span>
                    @endforeach
                </div>
            @else
                <div class="text-muted">No placeholders detected. This template runs in exact-layout manual upload mode.</div>
            @endif
        </div>

        <div class="mb-3 p-3 border rounded">
            <label class="form-label d-block mb-2">Convert this DOCX to HTML template</label>
            <p class="text-muted mb-2">This will convert the DOCX structure (paragraphs/tables) into editable HTML like other templates.</p>
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label mb-1">Target Template Type</label>
                    <select class="form-control" id="target_template_type_for_convert">
                        <option value="pqr">PQR</option>
                        <option value="dq">DQ</option>
                        <option value="ioq">IOQ</option>
                        <option value="pq">PQ</option>
                        <option value="facility">Facility</option>
                    </select>
                </div>
                <div class="col-md-8">
                    <input type="hidden" name="target_template_type" id="targetTemplateTypeHidden" value="pqr">
                    <button class="btn btn-warning"
                            type="submit"
                            formaction="{{ route('templates.convert-docx-to-html', $template) }}"
                            formmethod="POST"
                            onclick="document.getElementById('targetTemplateTypeHidden').value = document.getElementById('target_template_type_for_convert').value; document.querySelector('input[name=_method]').disabled = true;">
                        Convert DOCX to HTML Template
                    </button>
                </div>
            </div>
        </div>

        <button class="btn btn-primary" type="submit">Update DOCX Template</button>
    </form>
</div>
@endsection

