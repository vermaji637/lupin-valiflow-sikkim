@extends('layouts.app')

@section('title', 'Roles')

@section('content')
<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-user-tag"></i>
                    Access Control
                </span>
                <h2 class="page-title">Roles</h2>
                <p class="page-subtitle">
                    Manage role definitions, guard mapping, and permission assignment from the same premium layout used across the admin screens.
                </p>
            </div>

            <div class="toolbar-row mt-3 mt-lg-0">
                <a href="{{ route('roles.create') }}" class="btn btn-primary btn-compact toolbar-chip">
                    <i class="fas fa-plus"></i>
                    Add New
                </a>
            </div>
        </div>

        <div class="metric-grid user-metric-grid">
            <div class="metric-card">
                <span class="metric-label">Total Roles</span>
                <span class="metric-value">{{ $stats['total'] }}</span>
                <span class="metric-note">All role profiles configured in the system</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Guard Types</span>
                <span class="metric-value">{{ $stats['guards'] }}</span>
                <span class="metric-note">Distinct guards used by role definitions</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Filtered Result</span>
                <span class="metric-value">{{ $stats['shown'] }}</span>
                <span class="metric-note">Roles returned for the current filter</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Permission Ready</span>
                <span class="metric-value">{{ $stats['permission_assignable'] }}</span>
                <span class="metric-note">Roles already linked with at least one permission</span>
            </div>
        </div>
    </section>

    @include('common.alert')

    <div class="card mb-4">
        <div class="card-header d-flex flex-column flex-lg-row align-items-lg-center justify-content-between">
            <div class="section-title">Filter Roles</div>
            <div class="small text-muted mt-2 mt-lg-0">
                Showing {{ $roles->firstItem() ?? 0 }}-{{ $roles->lastItem() ?? 0 }} of {{ $roles->total() }} roles
            </div>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('roles.index') }}">
                <div class="row">
                    <div class="col-lg-7 mb-3">
                        <label class="small text-muted">Search</label>
                        <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="Role name or guard">
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
                            <a href="{{ route('roles.index') }}" class="btn btn-light btn-compact">Reset</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="section-title">All Roles</div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0">
                    <thead>
                        <tr>
                            <th style="width: 84px;">#</th>
                            <th>Role</th>
                            <th>Guard</th>
                            <th>Permissions</th>
                            <th style="min-width: 290px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($roles as $role)
                            <tr>
                                <td>{{ ($roles->firstItem() ?? 1) + $loop->index }}</td>
                                <td>
                                    <div class="user-listing">
                                        <div class="user-avatar">{{ strtoupper(substr($role->name, 0, 1)) }}</div>
                                        <div>
                                            <div class="user-name">{{ $role->name }}</div>
                                            <div class="user-meta">Role ID #{{ $role->id }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="chip user-role-chip">{{ $role->guard_name }}</span>
                                </td>
                                <td>
                                    <span class="chip master-code-chip">{{ $role->permissions_count ?? 0 }} permissions</span>
                                </td>
                                <td>
                                    <div class="action-row">
                                        @if(auth()->user()->can('permission-assign'))
                                            <a href="{{ route('admin.roles.permissions', $role->id) }}" class="btn btn-primary btn-compact">
                                                <i class="fas fa-key"></i>
                                                Assign Permissions
                                            </a>
                                        @endif

                                        <!-- <a href="{{ route('roles.edit', ['role' => $role->id]) }}" class="btn btn-light btn-compact">
                                            <i class="fas fa-pen"></i>
                                            Edit
                                        </a> -->

                                        {{-- <form method="POST" action="{{ route('roles.destroy', ['role' => $role->id]) }}" class="d-inline esign-required mb-0">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="password">
                                            <button class="btn btn-danger btn-compact" type="submit" onclick="return confirm('Delete this role?')">
                                                <i class="fas fa-trash"></i>
                                                Delete
                                            </button>
                                        </form> --}}
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">No roles found for the current filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-4">
        {{ $roles->links() }}
    </div>
</div>

@endsection
