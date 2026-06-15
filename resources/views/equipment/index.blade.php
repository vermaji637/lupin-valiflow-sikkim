@extends('layouts.app')

@section('title', $isMyApprovedView ? 'My Approved Equipment' : 'Equipment Master')

@section('content')
<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-cogs"></i>
                    Master Control
                </span>
                <h2 class="page-title">{{ $isMyApprovedView ? 'My Approved Equipment' : 'Equipment Master' }}</h2>
                <p class="page-subtitle">
                    {{ $isMyApprovedView
                        ? 'Review equipment records approved by you and track approval completion dates.'
                        : 'Maintain equipment identities, assigned approvers, and approval status in one compact list.' }}
                </p>
            </div>

            <div class="toolbar-row mt-3 mt-lg-0">
                @if(!$isMyApprovedView)
                    <a href="{{ route('equipment.create') }}" class="btn btn-primary btn-compact toolbar-chip">
                        <i class="fas fa-plus"></i>
                        Add Equipment
                    </a>
                @endif

                <a href="{{ route('equipment.index') }}" class="btn btn-light btn-compact toolbar-chip">
                    All Equipment
                </a>
                <a href="{{ route('equipment.my-approved') }}" class="btn btn-light btn-compact toolbar-chip">
                    My Approved
                </a>

                @if(!$isMyApprovedView)
                    <form action="{{ route('equipment.import.preview') }}" method="POST" enctype="multipart/form-data" id="equipmentImportForm" style="display:inline-flex; gap:8px;">
                        @csrf
                        <input
                            type="file"
                            name="file"
                            id="equipmentImportFile"
                            required
                            accept=".csv,.xlsx,.xls"
                            class="form-control form-control-sm toolbar-chip"
                            style="width:180px;"
                        >
                        <button type="submit" class="btn btn-primary btn-compact toolbar-chip">
                            <i class="fas fa-upload"></i>
                            Upload
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <div class="metric-grid">
            <div class="metric-card">
                <span class="metric-label">Total Equipment</span>
                <span class="metric-value">{{ $stats['total'] }}</span>
                <span class="metric-note">Registered equipment records</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">{{ $isMyApprovedView ? 'Pending For You' : 'Pending Approval' }}</span>
                <span class="metric-value">{{ $stats['pending'] }}</span>
                <span class="metric-note">{{ $isMyApprovedView ? 'Assigned to you and still pending' : 'Records waiting for approver action' }}</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">{{ $isMyApprovedView ? 'Approved By You' : 'Approved' }}</span>
                <span class="metric-value">{{ $stats['approved'] }}</span>
                <span class="metric-note">{{ $isMyApprovedView ? 'Equipment approved by your account' : 'Records with completed approval' }}</span>
            </div>
        </div>
    </section>

    @include('common.alert')

    <div class="card mb-4">
        <div class="card-header d-flex flex-column flex-lg-row align-items-lg-center justify-content-between">
            <div class="section-title">Find Equipment</div>
            <div class="small text-muted mt-2 mt-lg-0">
                Showing {{ $equipment->firstItem() ?? 0 }}-{{ $equipment->lastItem() ?? 0 }} of {{ $equipment->total() }} records
            </div>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ $isMyApprovedView ? route('equipment.my-approved') : route('equipment.index') }}">
                <div class="row">
                    <div class="col-lg-8 mb-3">
                        <label class="small text-muted">Search</label>
                        <input
                            type="text"
                            name="search"
                            value="{{ request('search') }}"
                            class="form-control"
                            placeholder="Equipment name, ID, facility, approver, approved by"
                        >
                    </div>
                    <div class="col-lg-2 mb-3">
                        <label class="small text-muted">Status</label>
                        <select name="status" class="form-control">
                            <option value="">All</option>
                            <option value="pending" @selected(request('status') === 'pending')>Pending</option>
                            <option value="approved" @selected(request('status') === 'approved')>Approved</option>
                        </select>
                    </div>
                    <div class="col-lg-2 mb-3">
                        <label class="small text-muted d-block">&nbsp;</label>
                        <div class="toolbar-row justify-content-lg-end">
                            <button type="submit" class="btn btn-primary btn-compact">Apply</button>
                            <a href="{{ $isMyApprovedView ? route('equipment.my-approved') : route('equipment.index') }}" class="btn btn-light btn-compact">Reset</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="section-title">Equipment Records</div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0">
                    <thead>
                        <tr>
                            <th style="min-width: 220px;">Equipment</th>
                            <th style="min-width: 160px;">Code</th>
                            <th style="min-width: 200px;">Facility</th>
                            <th style="min-width: 220px;">Assigned Approver</th>
                            <th style="min-width: 150px;">Approval Status</th>
                            <th style="min-width: 220px;">Approved By</th>
                            <th style="min-width: 180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($equipment as $eq)
                            @php
                                $status = strtolower((string) ($eq->approval_status ?? 'pending'));
                                if (!in_array($status, ['pending', 'approved'], true)) {
                                    $status = 'pending';
                                }
                                $canApprove = $status !== 'approved' && ((int) ($eq->approver_username ?? 0) === (int) auth()->id() || strtolower((string) ($eq->approver_username ?? '')) === strtolower((string) (auth()->user()->email ?? '')));
                                $approverName = trim((string) (($eq->approverUser->first_name ?? '') . ' ' . ($eq->approverUser->last_name ?? '')));
                                $approvedByName = trim((string) (($eq->approvedByUser->first_name ?? '') . ' ' . ($eq->approvedByUser->last_name ?? '')));
                            @endphp
                            <tr>
                                <td>
                                    <div class="entity-stack">
                                        <span class="entity-primary">{{ $eq->equipment_name }}</span>
                                        <span class="entity-secondary">Equipment record #{{ $eq->id }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="chip master-code-chip">{{ $eq->equipment_id }}</span>
                                </td>
                                <td>
                                    <div class="entity-stack">
                                        <span class="entity-primary">{{ $eq->facility }}</span>
                                        <span class="entity-secondary">Mapped facility</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="entity-stack">
                                        <span class="entity-primary">{{ $approverName !== '' ? $approverName : ($eq->approver_username ?? '-') }}</span>
                                        <span class="entity-secondary">{{ $eq->approverUser->email ?? 'Approval owner' }}</span>
                                    </div>
                                </td>
                                <td>
                                    @if($status === 'approved')
                                        <span class="badge badge-success text-light">Approved</span>
                                    @else
                                        <span class="badge badge-warning text-dark">Pending</span>
                                    @endif
                                    @if($eq->approved_at)
                                        <div class="small text-muted mt-1">{{ $eq->approved_at->format('d M Y h:i A') }}</div>
                                    @endif
                                </td>
                                <td>
                                    <div class="entity-stack">
                                        <span class="entity-primary">{{ $approvedByName !== '' ? $approvedByName : '-' }}</span>
                                        <span class="entity-secondary">{{ $eq->approvedByUser->email ?? ($status === 'approved' ? 'Approved user' : 'Not approved yet') }}</span>
                                    </div>
                                </td>
                                <td>
                                    @if($canApprove)
                                        <form action="{{ route('equipment.approve', $eq) }}" method="POST" class="mb-0">
                                            @csrf
                                            <button type="submit" class="btn btn-success btn-sm btn-compact" onclick="return confirm('Approve this equipment?')">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                        </form>
                                    @elseif($status === 'approved')
                                        <span class="small text-success">Already approved</span>
                                    @else
                                        <span class="small text-muted">Waiting for assigned approver</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">No equipment records found for the current filter.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-4">
        {{ $equipment->links() }}
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const fileInput = document.getElementById('equipmentImportFile');
    if (!fileInput) return;

    fileInput.addEventListener('change', function() {
        const file = this.files[0];
        if (!file) return;

        const allowed = ['csv', 'xlsx', 'xls'];
        const ext = file.name.split('.').pop().toLowerCase();

        if (!allowed.includes(ext)) {
            alert('❌ Please upload only CSV or Excel file (.csv, .xlsx, .xls)');
            this.value = '';
        }
    });
});
</script>
@endsection
