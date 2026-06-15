@extends('layouts.app')

@section('content')
<div class="container">
    <h2>Create DOCX Template</h2>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="alert alert-info">
        Supported field styles: <code>${field_name}</code>, <code>&#123;&#123;field_name&#125;&#125;</code>, <code>&lt;&lt;FieldName&gt;&gt;</code>, <code>«FieldName»</code>, and Word Mail Merge <code>MERGEFIELD</code>. If placeholders are missing, long blank markers like <code>_____</code> or <code>.....</code> are auto-converted to placeholder fields.
    </div>
    <div class="alert alert-secondary">
        Direct browser editing of DOC/DOCX is not supported in this app. Workflow: upload template → open in MS Word → edit/save → upload updated file.
    </div>

    <form method="POST" action="{{ route('templates.store') }}" enctype="multipart/form-data">
        @csrf

        <div class="mb-3">
            <label class="form-label">Template Name</label>
            <input class="form-control" name="name" value="{{ old('name') }}" required>
        </div>

        <input type="hidden" name="template_type" value="docx">

        <div class="mb-3">
            <label class="form-label">DOC/DOCX File</label>
            <input type="file" class="form-control" name="docx_file" accept=".doc,.docx" required>
        </div>

        <div class="mb-3 p-3 border rounded">
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" value="1" id="create_as_html" name="create_as_html" {{ old('create_as_html', '1') == '1' ? 'checked' : '' }}>
                <label class="form-check-label" for="create_as_html">
                    Create as editable HTML template (same flow as other template types)
                </label>
            </div>
            <div>
                <label class="form-label mb-1">Target Template Type</label>
                <select class="form-control" name="target_template_type" id="target_template_type">
                    <option value="pqr" {{ old('target_template_type') === 'pqr' ? 'selected' : '' }}>Protocol Report (PQR)</option>
                    <option value="facility" {{ old('target_template_type') === 'facility' ? 'selected' : '' }}>Facility</option>
                    <option value="dq" {{ old('target_template_type') === 'dq' ? 'selected' : '' }}>DQ</option>
                    <option value="ioq" {{ old('target_template_type') === 'ioq' ? 'selected' : '' }}>IOQ</option>
                    <option value="pq" {{ old('target_template_type') === 'pq' ? 'selected' : '' }}>PQ</option>
                </select>
            </div>
            <small class="text-muted d-block mt-2">
                If enabled, uploaded DOCX is converted to HTML and opened in normal template editor.
            </small>
        </div>

        <button class="btn btn-primary" type="submit">Create DOCX Template</button>
    </form>
</div>
@endsection
