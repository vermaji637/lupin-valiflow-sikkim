@extends('layouts.app')

@section('title', 'Approved Proposal')

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
                    Review approved protocol records with template, equipment, and date filters in one consistent workflow screen.
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
                        <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="ID / Equipment / Facility / Template">
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
            <div class="section-title">Protocol List</div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Facility/Section</th>
                            <th>Document No</th>
                            <th>Template</th>
                            <th>Version</th>
                            <th>Equipment Name</th>
                            <th>Equipment ID</th>
                            <th>Created</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($approvedReports as $r)
                            @php
                                $status = strtoupper($r->status);
                                $badgeClass = match($r->status) {
                                    'pre_pending'  => 'bg-warning text-dark',
                                    'post_pending' => 'bg-info text-dark',
                                    'pre_approved' => 'bg-primary',
                                    'approved'     => 'bg-success',
                                    'rejected'     => 'bg-danger',
                                    default        => 'bg-secondary'
                                };
                            @endphp
                            <tr>
                                <td><a href="{{ route('reports.show', $r) }}">{{ $r->id }}</a></td>
                                <td>{{ $r->facility_section }}</td>
                                <td>{{ $r->document?->title }}</td>
                                <td>{{ $r->template->name ?? $r->template->title ?? '-' }}</td>
                                <td>{{ $r->version }}</td>
                                <td>{{ $r->equipment_name }}</td>
                                <td>{{ $r->equipment_id }}</td>
                                <td>{{ optional($r->created_at)->format('d-m-Y h:i A') }}</td>
                                <td><span class="badge {{ $badgeClass }} text-light">{{ $status }}</span></td>
                                <td>
                                    <div class="action-row">
                                        <a href="{{ route('reports.show', $r) }}" class="btn btn-primary btn-compact">View</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center py-5 text-muted">No approved protocols found for the current filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-4">
        {{ $approvedReports->links() }}
    </div>
</div>
@endsection
