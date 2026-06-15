@extends('layouts.app')

@section('title', 'Approval Dashboard')

@section('content')
@php
    $pendingCount = $pending->total();
    $approvedCount = $approvedByMe->total();
    $rejectedCount = $rejectedByMe->total();
    $isAdminDashboard = $isAdminDashboard ?? !empty($adminProtocolActivity);
@endphp

<div class="container-fluid px-0">
    <section class="page-hero">
        <span class="page-kicker">
            <i class="fas fa-shield-alt"></i>
            Executive Workspace
        </span>
        <div class="row align-items-end">
            <div class="col-lg-7">
                <h2 class="page-title">{{ $isAdminDashboard ? 'Admin Protocol Dashboard' : 'My Approval Dashboard' }}</h2>
                <p class="page-subtitle">
                    {{ $isAdminDashboard
                        ? 'Monitor all users protocol queues, approved records, and returned items from one admin control view.'
                        : 'Track the protocols waiting on your decision, review completed approvals, and monitor rejected records from a single operational view.' }}
                </p>
            </div>
            <div class="col-lg-5">
                <div class="metric-grid">
                    <div class="metric-card">
                        <span class="metric-label">{{ $isAdminDashboard ? 'All Pending' : 'Pending Now' }}</span>
                        <span class="metric-value">{{ $pendingCount }}</span>
                        <span class="metric-note">{{ $isAdminDashboard ? 'Pending across all users' : 'Items currently routed to you' }}</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label">Approved</span>
                        <span class="metric-value">{{ $approvedCount }}</span>
                        <span class="metric-note">{{ $isAdminDashboard ? 'Fully approved protocols' : 'Protocols you have approved' }}</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label">{{ $isAdminDashboard ? 'Returned' : 'Rejected' }}</span>
                        <span class="metric-value">{{ $rejectedCount }}</span>
                        <span class="metric-note">{{ $isAdminDashboard ? 'Returned for correction' : 'Items returned for correction' }}</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    @if($isAdminDashboard)
        <div class="row">
            <div class="col-12 mb-4">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <div class="section-title">All Protocol Activity</div>
                            <div class="text-muted small mt-1">Admin overview of every protocol, current workflow state, and the latest responsible user.</div>
                        </div>
                        <span class="chip">
                            <span class="status-dot" style="background:#0f4c81;"></span>
                            {{ $adminProtocolStats['total'] ?? 0 }} total protocols
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="metric-grid mb-4">
                            <div class="metric-card">
                                <span class="metric-label">All Protocols</span>
                                <span class="metric-value">{{ $adminProtocolStats['total'] ?? 0 }}</span>
                                <span class="metric-note">Entire protocol register</span>
                            </div>
                            <div class="metric-card">
                                <span class="metric-label">In Progress</span>
                                <span class="metric-value">{{ $adminProtocolStats['pending'] ?? 0 }}</span>
                                <span class="metric-note">Awaiting PRE or POST approval</span>
                            </div>
                            <div class="metric-card">
                                <span class="metric-label">Approved</span>
                                <span class="metric-value">{{ $adminProtocolStats['approved'] ?? 0 }}</span>
                                <span class="metric-note">Fully completed protocols</span>
                            </div>
                            <div class="metric-card">
                                <span class="metric-label">Returned</span>
                                <span class="metric-value">{{ $adminProtocolStats['returned'] ?? 0 }}</span>
                                <span class="metric-note">Sent back for correction</span>
                            </div>
                        </div>

                        @if($adminProtocolActivity->count() === 0)
                            <div class="empty-state">
                                <i class="fas fa-stream"></i>
                                <div class="font-weight-bold mb-1">No protocol activity found.</div>
                                <div>Protocol updates will appear here once users start creating and routing records.</div>
                            </div>
                        @else
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Protocol</th>
                                            <th>Document</th>
                                            <th>Created By</th>
                                            <th>Current Activity</th>
                                            <th>Current Owner</th>
                                            <th>Updated</th>
                                            <th class="text-right">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($adminProtocolActivity as $report)
                                            @php
                                                $currentStep = $report->approvalSteps
                                                    ->first(function ($step) use ($report) {
                                                        return $step->stage === $report->stage && is_null($step->approved_at) && is_null($step->rejected_at);
                                                    });

                                                $ownerName = $currentStep?->user
                                                    ? trim(($currentStep->user->first_name ?? '').' '.($currentStep->user->last_name ?? ''))
                                                    : null;

                                                $creatorName = $report->creator
                                                    ? trim(($report->creator->first_name ?? '').' '.($report->creator->last_name ?? ''))
                                                    : '-';

                                                $activityLabel = match ($report->status) {
                                                    'pre_pending' => 'Awaiting PRE approval',
                                                    'pre_approved' => 'PRE approved',
                                                    'pre_returned', 'returned_to_preparer' => 'Returned in PRE',
                                                    'post_pending' => 'Awaiting POST approval',
                                                    'post_returned' => 'Returned in POST',
                                                    'approved' => 'Fully approved',
                                                    default => strtoupper(str_replace('_', ' ', $report->status)),
                                                };

                                                $statusTone = match ($report->status) {
                                                    'approved' => 'success',
                                                    'pre_returned', 'post_returned', 'returned_to_preparer' => 'danger',
                                                    'pre_approved' => 'info',
                                                    'pre_pending', 'post_pending' => 'warning',
                                                    default => 'secondary',
                                                };

                                                $documentLabel = $report->document?->doc_title ?: ($report->document?->title ?: '-');
                                                $templateLabel = $report->template->name ?? 'Protocol';
                                            @endphp
                                            <tr>
                                                <td>#{{ $report->id }}</td>
                                                <td>
                                                    <div class="dashboard-activity-stack">
                                                        <span class="entity-primary">{{ $templateLabel }}</span>
                                                        <span class="entity-secondary">{{ strtoupper($report->stage ?? '-') }} stage</span>
                                                    </div>
                                                </td>
                                                <td>{{ $documentLabel }}</td>
                                                <td>{{ $creatorName !== '' ? $creatorName : '-' }}</td>
                                                <td>
                                                    <span class="badge badge-{{ $statusTone }} protocol-status-badge">{{ $activityLabel }}</span>
                                                </td>
                                                <td>{{ $ownerName ?: 'Completed / Returned' }}</td>
                                                <td>
                                                    <div class="dashboard-activity-stack">
                                                        <span>{{ optional($report->updated_at)->format('d M Y') }}</span>
                                                        <span class="entity-secondary">{{ optional($report->updated_at)->format('h:i A') }}</span>
                                                    </div>
                                                </td>
                                                <td class="text-right">
                                                    <a href="{{ route('reports.show', $report) }}" class="btn btn-sm btn-primary">Open</a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-4">
                                {{ $adminProtocolActivity->links() }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="row">
        <div class="col-12 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <div class="section-title">{{ $isAdminDashboard ? 'All Pending Protocols' : 'Pending Approvals' }}</div>
                        <div class="text-muted small mt-1">
                            {{ $isAdminDashboard
                                ? 'Global pending protocols with the current responsible approver.'
                                : 'Records that require your action at the current workflow step.' }}
                        </div>
                    </div>
                    <span class="chip">
                        <span class="status-dot" style="background:#b7791f;"></span>
                        {{ $pendingCount }} active
                    </span>
                </div>
                <div class="card-body">
                    @if($pending->count() === 0)
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <div class="font-weight-bold mb-1">{{ $isAdminDashboard ? 'No pending protocols.' : 'No pending approvals.' }}</div>
                            <div>{{ $isAdminDashboard ? 'Pending protocols from all users will appear here.' : 'New approvals assigned to you will appear here.' }}</div>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Template</th>
                                        @if($isAdminDashboard)
                                            <th>Created By</th>
                                            <th>Current Owner</th>
                                        @endif
                                        <th>Status</th>
                                        <th>Stage</th>
                                        <th class="text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($pending as $r)
                                        @php
                                            $currentStep = $isAdminDashboard
                                                ? $r->approvalSteps->first(function ($step) use ($r) {
                                                    return $step->stage === $r->stage && is_null($step->approved_at) && is_null($step->rejected_at);
                                                })
                                                : null;

                                            $pendingCreator = $isAdminDashboard && $r->creator
                                                ? trim(($r->creator->first_name ?? '').' '.($r->creator->last_name ?? ''))
                                                : '-';

                                            $pendingOwner = $isAdminDashboard && $currentStep?->user
                                                ? trim(($currentStep->user->first_name ?? '').' '.($currentStep->user->last_name ?? ''))
                                                : '-';
                                        @endphp
                                        <tr>
                                            <td>#{{ $r->id }}</td>
                                            <td>{{ $r->template->name ?? '-' }}</td>
                                            @if($isAdminDashboard)
                                                <td>{{ $pendingCreator !== '' ? $pendingCreator : '-' }}</td>
                                                <td>{{ $pendingOwner !== '' ? $pendingOwner : '-' }}</td>
                                            @endif
                                            <td>{{ strtoupper(str_replace('_', ' ', $r->status)) }}</td>
                                            <td>{{ strtoupper($r->stage) }}</td>
                                            <td class="text-right">
                                                <a href="{{ route('reports.show', $r) }}" class="btn btn-sm btn-primary">
                                                    Open Report
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4">
                            {{ $pending->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-xl-6 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <div class="section-title">{{ $isAdminDashboard ? 'Approved Protocols' : 'Approved By Me' }}</div>
                        <div class="text-muted small mt-1">
                            {{ $isAdminDashboard
                                ? 'All protocols that have completed the full approval cycle.'
                                : 'Completed decisions recorded in the audit trail.' }}
                        </div>
                    </div>
                    <span class="chip">
                        <span class="status-dot" style="background:#157a5c;"></span>
                        {{ $approvedCount }} approved
                    </span>
                </div>
                <div class="card-body">
                    @if($approvedByMe->count() === 0)
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <div class="font-weight-bold mb-1">{{ $isAdminDashboard ? 'No approved protocols yet.' : 'No approved reports yet.' }}</div>
                            <div>{{ $isAdminDashboard ? 'Approved protocols from all users will be listed here.' : 'Approved protocols will be listed here.' }}</div>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Template</th>
                                        @if($isAdminDashboard)
                                            <th>Created By</th>
                                            <th>Completed On</th>
                                        @endif
                                        <th>Status</th>
                                        <th class="text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($approvedByMe as $r)
                                        @php
                                            $approvedCreator = $isAdminDashboard && $r->creator
                                                ? trim(($r->creator->first_name ?? '').' '.($r->creator->last_name ?? ''))
                                                : '-';
                                        @endphp
                                        <tr>
                                            <td>#{{ $r->id }}</td>
                                            <td>{{ $r->template->name ?? '-' }}</td>
                                            @if($isAdminDashboard)
                                                <td>{{ $approvedCreator !== '' ? $approvedCreator : '-' }}</td>
                                                <td>{{ optional($r->updated_at)->format('d M Y h:i A') }}</td>
                                            @endif
                                            <td>{{ strtoupper(str_replace('_', ' ', $r->status)) }}</td>
                                            <td class="text-right">
                                                <a href="{{ route('reports.show', $r) }}" class="btn btn-sm btn-light">
                                                    View
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4">
                            {{ $approvedByMe->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-xl-6 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <div class="section-title">{{ $isAdminDashboard ? 'Returned Protocols' : 'Rejected By Me' }}</div>
                        <div class="text-muted small mt-1">
                            {{ $isAdminDashboard
                                ? 'All protocols sent back to the creator for revision.'
                                : 'Protocols returned to the creator for revision.' }}
                        </div>
                    </div>
                    <span class="chip">
                        <span class="status-dot" style="background:#b63d3d;"></span>
                        {{ $rejectedCount }} {{ $isAdminDashboard ? 'returned' : 'rejected' }}
                    </span>
                </div>
                <div class="card-body">
                    @if($rejectedByMe->count() === 0)
                        <div class="empty-state">
                            <i class="fas fa-rotate-left"></i>
                            <div class="font-weight-bold mb-1">{{ $isAdminDashboard ? 'No returned protocols.' : 'No rejected reports.' }}</div>
                            <div>{{ $isAdminDashboard ? 'Returned protocols from all users will appear here when needed.' : 'Returned protocols will appear here when required.' }}</div>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Template</th>
                                        @if($isAdminDashboard)
                                            <th>Created By</th>
                                            <th>Returned On</th>
                                        @endif
                                        <th>Status</th>
                                        <th class="text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($rejectedByMe as $r)
                                        @php
                                            $returnedCreator = $isAdminDashboard && $r->creator
                                                ? trim(($r->creator->first_name ?? '').' '.($r->creator->last_name ?? ''))
                                                : '-';
                                        @endphp
                                        <tr>
                                            <td>#{{ $r->id }}</td>
                                            <td>{{ $r->template->name ?? '-' }}</td>
                                            @if($isAdminDashboard)
                                                <td>{{ $returnedCreator !== '' ? $returnedCreator : '-' }}</td>
                                                <td>{{ optional($r->updated_at)->format('d M Y h:i A') }}</td>
                                            @endif
                                            <td>{{ strtoupper(str_replace('_', ' ', $r->status)) }}</td>
                                            <td class="text-right">
                                                <a href="{{ route('reports.show', $r) }}" class="btn btn-sm btn-light">
                                                    View
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4">
                            {{ $rejectedByMe->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
