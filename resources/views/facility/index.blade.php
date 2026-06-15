@extends('layouts.app')

@section('title', 'Facility Master')

@section('content')
<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-building"></i>
                    Master Control
                </span>
                <h2 class="page-title">Facility Master</h2>
                <p class="page-subtitle">
                    Manage facility names used across equipment mapping, documents, and protocol reporting.
                </p>
            </div>

            <div class="toolbar-row mt-3 mt-lg-0">
                <a href="{{ route('facility.create') }}" class="btn btn-primary btn-compact toolbar-chip">
                    <i class="fas fa-plus"></i>
                    Add Facility
                </a>
            </div>
        </div>

        <div class="metric-grid">
            <div class="metric-card">
                <span class="metric-label">Total Facilities</span>
                <span class="metric-value">{{ $stats['total'] }}</span>
                <span class="metric-note">Facility records available</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Mapped Documents</span>
                <span class="metric-value">{{ $stats['mapped_documents'] }}</span>
                <span class="metric-note">Facilities already used in documents</span>
            </div>
        </div>
    </section>

    @include('common.alert')

    <div class="card mb-4">
        <div class="card-header d-flex flex-column flex-lg-row align-items-lg-center justify-content-between">
            <div class="section-title">Find Facilities</div>
            <div class="small text-muted mt-2 mt-lg-0">
                Showing {{ $facilities->firstItem() ?? 0 }}-{{ $facilities->lastItem() ?? 0 }} of {{ $facilities->total() }} records
            </div>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('facility.index') }}">
                <div class="row">
                    <div class="col-lg-10 mb-3">
                        <label class="small text-muted">Search</label>
                        <input
                            type="text"
                            name="search"
                            value="{{ request('search') }}"
                            class="form-control"
                            placeholder="Facility name"
                        >
                    </div>
                    <div class="col-lg-2 mb-3">
                        <label class="small text-muted d-block">&nbsp;</label>
                        <div class="toolbar-row justify-content-lg-end">
                            <button type="submit" class="btn btn-primary btn-compact">Apply</button>
                            <a href="{{ route('facility.index') }}" class="btn btn-light btn-compact">Reset</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="section-title">Facility Records</div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0">
                    <thead>
                        <tr>
                            <th style="min-width: 120px;">ID</th>
                            <th style="min-width: 280px;">Facility</th>
                            <th style="min-width: 220px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($facilities as $f)
                            <tr>
                                <td><span class="chip master-code-chip">#{{ $f->id }}</span></td>
                                <td>
                                    <div class="entity-stack">
                                        <span class="entity-primary">{{ $f->facility_name }}</span>
                                        <span class="entity-secondary">Facility master record</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-row">
                                        <a href="{{ route('facility.edit', $f->id) }}" class="btn btn-primary btn-compact">
                                            <i class="fas fa-pen"></i>
                                            Edit
                                        </a>
                                        {{-- <form action="{{ route('facility.destroy', $f->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-danger btn-compact" onclick="return confirm('Delete this facility?')">
                                                <i class="fas fa-trash"></i>
                                                Delete
                                            </button>
                                        </form>
                                    </div> --}}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center py-5 text-muted">No facilities found for the current filter.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-4">
        {{ $facilities->links() }}
    </div>
</div>
@endsection
