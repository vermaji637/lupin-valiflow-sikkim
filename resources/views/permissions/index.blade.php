@extends('layouts.app')

@section('title', 'Permissions')

@section('content')
<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-key"></i>
                    Access Control
                </span>
                <h2 class="page-title">Permissions</h2>
                <p class="page-subtitle">
                    Manage platform permission keys, guard mapping, and access actions in the same premium admin layout.
                </p>
            </div>

            <div class="toolbar-row mt-3 mt-lg-0">
                <a href="{{ route('permissions.create') }}" class="btn btn-primary btn-compact toolbar-chip">
                    <i class="fas fa-plus"></i>
                    Add New
                </a>
            </div>
        </div>

        <div class="metric-grid">
            <div class="metric-card">
                <span class="metric-label">Total Permissions</span>
                <span class="metric-value">{{ $stats['total'] }}</span>
                <span class="metric-note">All permission records in the system</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Guard Types</span>
                <span class="metric-value">{{ $stats['guards'] }}</span>
                <span class="metric-note">Distinct permission guards currently used</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Filtered Result</span>
                <span class="metric-value">{{ $stats['shown'] }}</span>
                <span class="metric-note">Permissions returned for the current filter</span>
            </div>
        </div>
    </section>

    @include('common.alert')

    <div class="card mb-4">
        <div class="card-header d-flex flex-column flex-lg-row align-items-lg-center justify-content-between">
            <div class="section-title">Filter Permissions</div>
            <div class="small text-muted mt-2 mt-lg-0">
                Showing {{ $permissions->firstItem() ?? 0 }}-{{ $permissions->lastItem() ?? 0 }} of {{ $permissions->total() }} permissions
            </div>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('permissions.index') }}">
                <div class="row">
                    <div class="col-lg-7 mb-3">
                        <label class="small text-muted">Search</label>
                        <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="Permission name or guard">
                    </div>
                    <div class="col-lg-3 mb-3">
                        <label class="small text-muted">Guard</label>
                        <select name="guard_name" class="form-control">
                            <option value="">All Guards</option>
                            @foreach($guards as $guard)
                                <option value="{{ $guard }}" @selected(request('guard_name') === $guard)>{{ $guard }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2 mb-3">
                        <label class="small text-muted d-block">&nbsp;</label>
                        <div class="toolbar-row justify-content-lg-end">
                            <button type="submit" class="btn btn-primary btn-compact">Apply</button>
                            <a href="{{ route('permissions.index') }}" class="btn btn-light btn-compact">Reset</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="section-title">All Permissions</div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0">
                    <thead>
                        <tr>
                            <th style="width: 84px;">#</th>
                            <th>Name</th>
                            <th>Guard Name</th>
                            {{-- <th style="min-width: 180px;">Action</th> --}}
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($permissions as $permission)
                            <tr>
                                <td>{{ ($permissions->firstItem() ?? 1) + $loop->index }}</td>
                                <td>
                                    <div class="entity-stack">
                                        <span class="entity-primary">{{ $permission->name }}</span>
                                        <span class="entity-secondary">Permission key</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="chip user-role-chip">{{ $permission->guard_name }}</span>
                                </td>
                                {{-- <td>
                                    <div class="action-row">
                                        <a href="{{ route('permissions.edit', ['permission' => $permission->id]) }}" class="btn btn-primary btn-compact">
                                            <i class="fas fa-pen"></i>
                                            Edit
                                        </a>
                                        <form method="POST" action="{{ route('permissions.destroy', ['permission' => $permission->id]) }}" class="mb-0">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-danger btn-compact" type="submit" onclick="return confirm('Delete this permission?')">
                                                <i class="fas fa-trash"></i>
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td> --}}
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center py-5 text-muted">No permissions found for the current filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-4">
        {{ $permissions->links() }}
    </div>
</div>
@endsection
