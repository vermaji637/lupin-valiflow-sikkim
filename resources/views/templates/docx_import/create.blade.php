@extends('layouts.app')

@section('content')
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            
            <!-- Back Button -->
            <div class="mb-4">
                <a href="{{ route('templates.index') }}" class="text-decoration-none text-secondary">
                    <i class="fas fa-arrow-left mr-1"></i> Back to Templates
                </a>
            </div>

            <div class="card shadow border-0">
                <div class="card-header bg-gradient-primary text-white py-3">
                    <h5 class="mb-0 font-weight-bold text-white">
                        <i class="fas fa-file-import mr-2"></i> Import DOCX Template
                    </h5>
                    <p class="mb-0 text-white-50 small mt-1">Upload a DOCX file to automatically detect fillable blanks and tables.</p>
                </div>
                
                <form action="{{ route('templates.docx-import.upload') }}" method="POST" enctype="multipart/form-data" id="importTemplateForm">
                    @csrf
                    <div class="card-body px-4 py-4">
                        @if (session('error'))
                            <div class="alert alert-danger shadow-sm border-left-danger">
                                <i class="fas fa-exclamation-triangle mr-2"></i> {{ session('error') }}
                            </div>
                        @endif

                        @if ($errors->any())
                            <div class="alert alert-danger shadow-sm border-left-danger">
                                <h6 class="font-weight-bold mb-1">Please fix the following validation errors:</h6>
                                <ul class="mb-0 pl-3">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <!-- Template Name Input -->
                        <div class="form-group mb-4">
                            <label for="name" class="font-weight-bold text-dark">Template Name</label>
                            <input type="text" 
                                   name="name" 
                                   id="name" 
                                   class="form-control @error('name') is-invalid @enderror" 
                                   value="{{ old('name') }}" 
                                   placeholder="e.g. Periodic HVAC Validation Report" 
                                   required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- File Input -->
                        <div class="form-group mb-4">
                            <label for="file" class="font-weight-bold text-dark">DOCX File</label>
                            <div class="form-group mb-4">
                            <label for="file" class="font-weight-bold text-dark">DOCX File</label>
                            <input type="file" name="file" id="file" class="form-control" accept=".docx,.doc" required>
                        </div>
                        </div>

                    </div>
                    <div class="card-footer bg-light px-4 py-3 d-flex justify-content-between align-items-center">
                        <a href="{{ route('templates.index') }}" class="btn btn-light btn-icon-split shadow-sm">
                            <span class="icon text-gray-600">
                                <i class="fas fa-times"></i>
                            </span>
                            <span class="text">Cancel</span>
                        </a>
                        <button type="submit" class="btn btn-primary btn-icon-split shadow-sm" id="btnSubmit">
                            <span class="icon text-white-50">
                                <i class="fas fa-upload"></i>
                            </span>
                            <span class="text" id="btnText">Import & Detect Fields</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
    .bg-gradient-primary {
        background: linear-gradient(135deg, #4e73df 0%, #224abe 100%) !important;
    }
    .border-left-danger {
        border-left: 4px solid #e74a3b !important;
    }
    .border-left-info {
        border-left: 4px solid #36b9cc !important;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('importTemplateForm');
        const btnSubmit = document.getElementById('btnSubmit');
        const btnText = document.getElementById('btnText');

        if (form && btnSubmit) {
            form.addEventListener('submit', function () {
                btnSubmit.disabled = true;
                if (btnText) {
                    btnText.innerText = 'Uploading & parsing DOCX...';
                }
                const icon = btnSubmit.querySelector('.icon i');
                if (icon) {
                    icon.className = 'spinner-border spinner-border-sm';
                }
            });
        }

        // Custom file input handler to show selected filename
        document.addEventListener('change', function(e) {
            if (e.target && e.target.classList.contains('custom-file-input')) {
                let fileName = e.target.files[0] ? e.target.files[0].name : 'Choose file...';
                let label = e.target.nextElementSibling;
                if (label) {
                    label.innerText = fileName;
                }
            }
        });
    });
</script>
@endsection

