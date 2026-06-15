@extends('layouts.app')

@section('title', 'All Documents')

@section('content')
<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-folder-open"></i>
                    Document Control
                </span>
                <h2 class="page-title">All Documents</h2>
                <p class="page-subtitle">
                    Review protocol-linked documents, QA progress, and title metadata from a cleaner admin workspace.
                </p>
            </div>

            <div class="toolbar-row mt-3 mt-lg-0">
                <a href="{{ route('admin.documents.create') }}" class="btn btn-primary btn-compact toolbar-chip">
                    <i class="fas fa-plus"></i>
                    Add New
                </a>
            </div>
        </div>

        <div class="metric-grid user-metric-grid">
            <div class="metric-card">
                <span class="metric-label">Total Documents</span>
                <span class="metric-value">{{ $stats['total'] }}</span>
                <span class="metric-note">Document records in the system</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Pending</span>
                <span class="metric-value">{{ $stats['pending'] }}</span>
                <span class="metric-note">Waiting for QA completion</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Completed</span>
                <span class="metric-value">{{ $stats['completed'] }}</span>
                <span class="metric-note">QA completed documents</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Protocol Mappings</span>
                <span class="metric-value">{{ $stats['protocols'] }}</span>
                <span class="metric-note">Protocol types used in documents</span>
            </div>
        </div>
    </section>

    @include('common.alert')

    <div class="card mb-4">
        <div class="card-header d-flex flex-column flex-lg-row align-items-lg-center justify-content-between">
            <div class="section-title">Find Documents</div>
            <div class="small text-muted mt-2 mt-lg-0">
                Showing {{ $documents->firstItem() ?? 0 }}-{{ $documents->lastItem() ?? 0 }} of {{ $documents->total() }} records
            </div>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('admin.documents') }}">
                <div class="row">
                    <div class="col-lg-7 mb-3">
                        <label class="small text-muted">Search</label>
                        <input
                            type="text"
                            name="search"
                            value="{{ request('search') }}"
                            class="form-control"
                            placeholder="Title, protocol, equipment, facility, or QA text"
                        >
                    </div>
                    <div class="col-lg-3 mb-3">
                        <label class="small text-muted">Status</label>
                        <select name="status" class="form-control">
                            <option value="">All Statuses</option>
                            <option value="pending" @selected(request('status') === 'pending')>Pending</option>
                            <option value="completed" @selected(request('status') === 'completed')>Completed</option>
                        </select>
                    </div>
                    <div class="col-lg-2 mb-3">
                        <label class="small text-muted d-block">&nbsp;</label>
                        <div class="toolbar-row justify-content-lg-end">
                            <button type="submit" class="btn btn-primary btn-compact">Apply</button>
                            <a href="{{ route('admin.documents') }}" class="btn btn-light btn-compact">Reset</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="section-title">Document Records</div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0">
                    <thead>
                        <tr>
                            <th style="min-width: 320px;">Document</th>
                            <th style="min-width: 180px;">Protocol</th>
                            <th style="min-width: 200px;">Equipment</th>
                            <th style="min-width: 180px;">Facility</th>
                            <th style="min-width: 120px;">Status</th>
                            <th style="min-width: 260px;">QA Text</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($documents as $doc)
                        <tr>
                            <td>
                                <div class="entity-stack">
                                    <span class="entity-primary">{{ $doc->title }}</span>
                                    <span class="entity-secondary">Document #{{ $doc->id }}</span>
                                </div>
                            </td>
                            <td>
                                <span class="chip user-role-chip">{{ $doc->protocolType->protocol_type_name ?? '-' }}</span>
                            </td>
                            <td>
                                <div class="entity-stack">
                                    <span class="entity-primary">{{ $doc->equipment->equipment_name ?? '-' }}</span>
                                    <span class="entity-secondary">{{ $doc->equipment->equipment_id ?? '-' }}</span>
                                </div>
                            </td>
                            <td>
                                <div class="entity-stack">
                                    <span class="entity-primary">{{ $doc->facility->facility_name ?? '-' }}</span>
                                    <span class="entity-secondary">Facility mapping</span>
                                </div>
                            </td>
                            <td>
                                @if($doc->status === 'completed')
                                    <span class="badge bg-success text-light user-status-badge">Completed</span>
                                @else
                                    <span class="badge bg-warning text-dark user-status-badge">Pending</span>
                                @endif
                            </td>
                            <td>
                                @if($doc->doc_title)
                                    <div class="document-text-preview">{{ $doc->doc_title }}</div>
                                @else
                                    <span class="chip document-pending-chip">Pending from QA</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">No document records found for the current filters.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-4">
        {{ $documents->links() }}
    </div>
</div>
@endsection
