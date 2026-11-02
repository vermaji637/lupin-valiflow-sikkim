@extends('layouts.app')

@section('title', 'Approved Protocols')

@section('content')
<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-check-circle"></i>
                    Protocol Workflow
                </span>
                <h2 class="page-title">Approved Protocols</h2>
                <p class="page-subtitle">
                    Review approved protocol records with unified search, status, and date filters.
                </p>
            </div>

            <div class="toolbar-row mt-3 mt-lg-0">
                <a href="{{ route('home') }}" class="btn btn-light btn-compact toolbar-chip">
                    <i class="fas fa-arrow-left"></i>
                    Back to Dashboard
                </a>
            </div>
        </div>
    </section>

    @include('common.alert')

    <div class="card mb-4">
        <div class="card-header d-flex flex-column flex-lg-row align-items-lg-center justify-content-between">
            <div class="section-title">Filter Protocols</div>
            <div class="small text-muted mt-2 mt-lg-0">
                Showing {{ $approvedReports->firstItem() ?? 0 }}-{{ $approvedReports->lastItem() ?? 0 }} of {{ $approvedReports->total() }} records
            </div>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ request()->url() }}">
                <div class="row">
                    <div class="col-lg-3 mb-3">
                        <label class="small text-muted">Search</label>
                        <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="REQ5 / Title / Equipment">
                    </div>
                    <div class="col-lg-3 mb-3">
                        <label class="small text-muted">Status</label>
                        <select name="status" class="form-control">
                            <option value="">All Statuses</option>
                            <option value="pre_pending" @selected(request('status') === 'pre_pending')>PRE_PENDING</option>
                            <option value="post_pending" @selected(request('status') === 'post_pending')>POST_PENDING</option>
                            <option value="pre_approved" @selected(request('status') === 'pre_approved')>PRE_APPROVED</option>
                            <option value="approved" @selected(request('status') === 'approved')>APPROVED</option>
                            <option value="rejected" @selected(request('status') === 'rejected')>REJECTED</option>
                        </select>
                    </div>
                    <div class="col-lg-2 mb-3">
                        <label class="small text-muted">From Date</label>
                        <input type="date" name="from_date" value="{{ request('from_date') }}" class="form-control">
                    </div>
                    <div class="col-lg-2 mb-3">
                        <label class="small text-muted">To Date</label>
                        <input type="date" name="to_date" value="{{ request('to_date') }}" class="form-control">
                    </div>
                    <div class="col-lg-2 mb-3">
                        <label class="small text-muted d-block">&nbsp;</label>
                        <div class="toolbar-row justify-content-lg-end">
                            <button type="submit" class="btn btn-primary btn-compact">Apply</button>
                            <a href="{{ request()->url() }}" class="btn btn-light btn-compact">Reset</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="section-title">Approved Protocol List</div>
        </div>
        <div class="card-body">
            @if($approvedReports->count() > 0)
                <div class="table-responsive">
                    <table class="table table-bordered table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Protocol No</th>
                                <th>Document No</th>
                                <th>Protocol Title</th>
                                <th>Equipment Name</th>
                                <th>Facility Section</th>
                                <th>Status</th>
                                <th>Created Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($approvedReports as $report)
                            <tr>
                                <td>{{ $report->id ?? 'N/A' }}</td>
                                <td>{{ $report->document?->title ?? $report->document_no ?? 'N/A' }}</td>
                                <td>{{ $report->protocol_title !== '' ? $report->protocol_title : 'N/A' }}</td>
                                <td>{{ $report->equipment_name ?? 'N/A' }}</td>
                                <td>{{ $report->facility_section ?? 'N/A' }}</td>
                                <td>
                                    <span class="chip user-role-chip">{{ strtoupper($report->status ?? 'N/A') }}</span>
                                </td>
                                <td>{{ $report->created_at ? $report->created_at->format('d M Y H:i') : 'N/A' }}</td>
                                <td>
                                    <div class="action-row">
                                        @if(\App\Services\SidebarPermissionService::userCan('protocol-view'))
                                        <a href="{{ route('reports.show', $report->id) }}" class="btn btn-primary btn-compact" title="View Protocol">
                                            <i class="fas fa-eye"></i>
                                            View
                                        </a>
                                        @endif
                                        
                                        @if(\App\Services\SidebarPermissionService::userCan('protocol-edit'))
                                        <a href="{{ route('reports.editAfterPreApproved', $report->id) }}" class="btn btn-light btn-compact" title="Edit Protocol">
                                            <i class="fas fa-edit"></i>
                                            Edit
                                        </a>
                                        @endif
                                        
                                        @if($report->attachments)
                                        <a href="{{ route('reports.downloadFolderZip', $report->id) }}" class="btn btn-success btn-compact" title="Download Attachments">
                                            <i class="fas fa-download"></i>
                                            Download
                                        </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $approvedReports->links() }}
                </div>
            @else
                <div class="empty-state">
                    <i class="fas fa-check-circle"></i>
                    <div>No approved protocols found for the current filters.</div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
