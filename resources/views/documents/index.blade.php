@extends('layouts.app')

@section('title', 'Documents')

@section('content')
<div class="container-fluid">

    <!-- Page Heading -->
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Documents</h1>
        <div>
            @if(\App\Services\SidebarPermissionService::userCan('document-create'))
                <a href="{{ route('documents.create') }}" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> New Document
                </a>
            @endif
        </div>
    </div>

    {{-- Alert Messages --}}
    @include('common.alert')

    <!-- Documents Content -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Document Management</h6>
        </div>
        <div class="card-body">
            <div class="text-center py-5">
                <i class="fas fa-file-alt fa-3x text-gray-300 mb-3"></i>
                <h5 class="text-gray-400">Documents Module</h5>
                <p class="text-gray-500">Document management functionality is being redirected to the admin documents section.</p>
                
                <div class="mt-4">
                    @if(\App\Services\SidebarPermissionService::userCan('document-view'))
                        <a href="{{ route('admin.documents') }}" class="btn btn-outline-primary">
                            <i class="fas fa-cog"></i> Go to Admin Documents
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>

</div>
@endsection
