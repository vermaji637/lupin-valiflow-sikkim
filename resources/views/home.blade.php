@extends('layouts.app')

@section('title', 'Approval Dashboard')

@section('content')
@php
    $neqPending = $neqPending ?? collect();
    $neqApproved = $neqApproved ?? collect();
    $neqReturned = $neqReturned ?? collect();
    $pendingCount = $pending->total() + $neqPending->count();
    $approvedCount = $approvedByMe->total() + $neqApproved->count();
    $rejectedCount = $rejectedByMe->total() + $neqReturned->count();
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
                    @if($pending->count() === 0 && $neqPending->count() === 0)
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
                                        <th>Protocol Title</th>
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
                                            <td>{{ $r->displayCode() }}</td>
                                            <td>{{ $r->protocol_title !== '' ? $r->protocol_title : '-' }}</td>
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
                                    @foreach($neqPending as $doc)
                                        @php
                                            $neqCreator = $doc->uploader
                                                ? trim(($doc->uploader->first_name ?? '').' '.($doc->uploader->last_name ?? ''))
                                                : '-';
                                            $neqStep = $doc->approvals->sortBy('step_order')->first(function ($step) {
                                                return ! empty($step->user_id) && is_null($step->approved_at) && is_null($step->rejected_at);
                                            });
                                            $neqOwner = $neqStep?->user
                                                ? trim(($neqStep->user->first_name ?? '').' '.($neqStep->user->last_name ?? ''))
                                                : '-';
                                            $neqTitle = pathinfo((string) $doc->original_filename, PATHINFO_FILENAME);
                                        @endphp
                                        <tr>
                                            <td>{{ $doc->display_code ?: ('NEQ'.$doc->id) }}</td>
                                            <td>{{ $neqTitle !== '' ? $neqTitle : 'New Equipment Qualification' }}</td>
                                            @if($isAdminDashboard)
                                                <td>{{ $neqCreator !== '' ? $neqCreator : '-' }}</td>
                                                <td>{{ $neqOwner !== '' ? $neqOwner : '-' }}</td>
                                            @endif
                                            <td>PENDING</td>
                                            <td>NEQ</td>
                                            <td class="text-right">
                                                <a href="{{ route('protocol.new-equipment-qualification.view', $doc) }}" class="btn btn-sm btn-primary">
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
                    @if($approvedByMe->count() === 0 && $neqApproved->count() === 0)
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
                                        <th>Protocol Title</th>
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
                                            <td>{{ $r->displayCode() }}</td>
                                            <td>{{ $r->protocol_title !== '' ? $r->protocol_title : '-' }}</td>
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
                                    @foreach($neqApproved as $doc)
                                        @php
                                            $neqCreator = $doc->uploader
                                                ? trim(($doc->uploader->first_name ?? '').' '.($doc->uploader->last_name ?? ''))
                                                : '-';
                                            $neqTitle = pathinfo((string) $doc->original_filename, PATHINFO_FILENAME);
                                        @endphp
                                        <tr>
                                            <td>{{ $doc->display_code ?: ('NEQ'.$doc->id) }}</td>
                                            <td>{{ $neqTitle !== '' ? $neqTitle : 'New Equipment Qualification' }}</td>
                                            @if($isAdminDashboard)
                                                <td>{{ $neqCreator !== '' ? $neqCreator : '-' }}</td>
                                                <td>{{ optional($doc->updated_at)->format('d M Y h:i A') }}</td>
                                            @endif
                                            <td>APPROVED</td>
                                            <td class="text-right">
                                                <a href="{{ route('protocol.new-equipment-qualification.view', $doc) }}" class="btn btn-sm btn-light">
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
                    @if($rejectedByMe->count() === 0 && $neqReturned->count() === 0)
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
                                        <th>Protocol Title</th>
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
                                            $isReturnedCreator = (int) $r->created_by === (int) auth()->id();
                                            $isReturnedDocx = ! empty($r->docx_import_path);
                                        @endphp
                                        <tr>
                                            <td>{{ $r->displayCode() }}</td>
                                            <td>{{ $r->protocol_title !== '' ? $r->protocol_title : '-' }}</td>
                                            @if($isAdminDashboard)
                                                <td>{{ $returnedCreator !== '' ? $returnedCreator : '-' }}</td>
                                                <td>{{ optional($r->updated_at)->format('d M Y h:i A') }}</td>
                                            @endif
                                            <td>{{ strtoupper(str_replace('_', ' ', $r->status)) }}</td>
                                            <td class="text-right">
                                                @if($isReturnedDocx && $isReturnedCreator)
                                                    <a href="{{ route('reports.docx-import.fill-form', $r) }}" class="btn btn-sm btn-primary">
                                                        Edit &amp; Resubmit
                                                    </a>
                                                @endif
                                                <a href="{{ route('reports.show', ['report' => $r, 'view' => 1]) }}" class="btn btn-sm btn-light">
                                                    View
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                    @foreach($neqReturned as $doc)
                                        @php
                                            $neqCreator = $doc->uploader
                                                ? trim(($doc->uploader->first_name ?? '').' '.($doc->uploader->last_name ?? ''))
                                                : '-';
                                            $neqTitle = pathinfo((string) $doc->original_filename, PATHINFO_FILENAME);
                                        @endphp
                                        <tr>
                                            <td>{{ $doc->display_code ?: ('NEQ'.$doc->id) }}</td>
                                            <td>{{ $neqTitle !== '' ? $neqTitle : 'New Equipment Qualification' }}</td>
                                            @if($isAdminDashboard)
                                                <td>{{ $neqCreator !== '' ? $neqCreator : '-' }}</td>
                                                <td>{{ optional($doc->updated_at)->format('d M Y h:i A') }}</td>
                                            @endif
                                            <td>RETURNED</td>
                                            <td class="text-right">
                                                <a href="{{ route('protocol.new-equipment-qualification.view', $doc) }}" class="btn btn-sm btn-light">
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
