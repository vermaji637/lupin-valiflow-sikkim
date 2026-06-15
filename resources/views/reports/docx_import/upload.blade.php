@extends('layouts.app')
@section('content')
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">

            <div class="mb-3">
                <a href="{{ route('reports.show', $report) }}" class="text-decoration-none text-secondary">
                    <i class="fas fa-arrow-left mr-1"></i> Back to Report
                </a>
            </div>

            <div class="card shadow border-0">
                <div class="card-header py-3" style="background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);">
                    <h5 class="mb-0 font-weight-bold text-white">
                        <i class="fas fa-file-import mr-2"></i> Import Pre-Approved DOCX
                    </h5>
                </div>
                <div class="card-body p-4">

                    @if(session('error'))
                        <div class="alert alert-danger">{{ session('error') }}</div>
                    @endif

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-1"></i>
                        Upload your pre-approved DOCX file from VIVA software.
                        Blank fields will be detected automatically and shown as editable.
                        Pre-filled fields will be locked.
                    </div>

                    <form method="POST"
                          action="{{ route('reports.docx-import.upload', $report) }}"
                          enctype="multipart/form-data">
                        @csrf

                        <div class="form-group">
                            <label class="font-weight-bold">
                                Select DOC/DOCX File <span class="text-danger">*</span>
                            </label>
                            <input type="file"
       class="form-control"
       name="docx_file"
       id="docxFile"
       accept=".docx,.doc"
       required
       style="padding: 8px;">
                            <small class="text-muted">Supported: .doc, .docx (Max: 10MB)</small>
                        </div>

                        <div class="d-flex justify-content-between mt-4">
                            <a href="{{ route('reports.show', $report) }}"
                               class="btn btn-light shadow-sm">
                                <i class="fas fa-times mr-1"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary shadow-sm" id="btnUpload">
                                <i class="fas fa-upload mr-1"></i> Upload & Detect Fields
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('docxFile').addEventListener('change', function(e) {
    var fileName = e.target.files[0]?.name || 'Choose file...';
    document.querySelector('.custom-file-label').textContent = fileName;
});

document.getElementById('btnUpload').addEventListener('click', function() {
    this.disabled = true;
    this.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span> Uploading...';
});
</script>
@endsection
