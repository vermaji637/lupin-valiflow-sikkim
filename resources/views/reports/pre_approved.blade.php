@extends('layouts.app')

@section('title', $protocolListTitle ?? (request()->routeIs('protocol') ? 'All Protocol' : 'Pre Approved Protocol List'))

@section('content')
<div class="container-fluid">

    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            {{ $protocolListTitle ?? (request()->routeIs('protocol') ? 'All Protocol' : 'Pre Approved Protocol') }}
        </h1>
        <div class="d-flex gap-2">
            @if(!empty($protocolImportSource ?? null))
                <a href="{{ route('protocol.docx-import.create', ['source' => $protocolImportSource]) }}" class="btn btn-sm btn-primary shadow-sm mr-2">
                    <i class="fas fa-file-import fa-sm text-white-50"></i> Import
                </a>
            @endif
            <a href="{{ route('home') }}" class="d-none d-sm-inline-block btn btn-sm btn-secondary shadow-sm">
                <i class="fas fa-arrow-left fa-sm text-white-50"></i> Back
            </a>
        </div>
    </div>

    @include('common.alert')

    <div class="card shadow col-sm-12">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Protocol List</h6>
        </div>

        <div class="card-body">

            {{-- ✅ FILTERS --}}
            <form method="GET" action="{{ request()->url() }}" class="mb-3">
                <div class="row g-2 align-items-end">

                    <div class="col-md-3">
                        <label class="small text-muted">Search</label>
                        <input type="text"
                               name="search"
                               value="{{ request('search') }}"
                               class="form-control"
                               placeholder="Title">
                    </div>

                    <div class="col-md-2">
                        <label class="small text-muted">Status</label>
                        <select name="status" class="form-control">
                            <option value="">All</option>
                            @if(\App\Services\SidebarPermissionService::userCanAny(['protocol-view-approved', 'protocol-approved']))
                            <option value="approved" {{ request('status')=='approved'?'selected':'' }}>Approved</option>
                            @endif
                            @if(\App\Services\SidebarPermissionService::userCanAny(['protocol-view-rejected', 'protocol-reject']))
                            <option value="rejected" {{ request('status')=='rejected'?'selected':'' }}>Reject</option>
                            @endif
                            @if(\App\Services\SidebarPermissionService::userCanAny(['protocol-view-returned', 'protocol-send-back']))
                            <option value="send_back" {{ request('status')=='send_back'?'selected':'' }}>Send Back</option>
                            @endif
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="small text-muted">From Date</label>
                        <input type="date"
                               name="from_date"
                               value="{{ request('from_date') }}"
                               class="form-control">
                    </div>

                    <div class="col-md-2">
                        <label class="small text-muted">To Date</label>
                        <input type="date"
                               name="to_date"
                               value="{{ request('to_date') }}"
                               class="form-control">
                    </div>

                    <div class="col-md-3 d-flex gap-2">
                        <button class="btn btn-primary w-50" type="submit">Filter</button>
                        <a class="btn btn-secondary w-50" href="{{ request()->url() }}">Reset</a>
                    </div>

                </div>
            </form>

            {{-- ✅ TABLE --}}
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Protocol Title</th>
                            <th>Equipment Name</th>
                            <th>Equipment ID</th>
                            <th>Created</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>

                    <tbody>
                    @forelse($approvedReports as $r)

                        @php
                            $status = strtoupper($r->status);

                            $badgeClass = match($r->status) {
                                'pre_pending'  => 'badge bg-warning text-dark',
                                'post_pending' => 'badge bg-info text-dark',
                                'pre_approved' => 'badge bg-primary text-light',
                                'approved'     => 'badge bg-success text-light',
                                'rejected'     => 'badge bg-danger text-light',
                                default        => 'badge bg-secondary text-light'
                            };

                            // current step
                            $currentStep = $r->approvalSteps()
                                ->where('stage', $r->stage)
                                ->whereNull('approved_at')
                                ->whereNull('rejected_at')
                                ->where('step_order', $r->current_step_order)
                                ->first();

                            $isCurrentApprover = $currentStep && ((int)$currentStep->user_id === (int)auth()->id());
                            $isCreator = ((int)$r->created_by === (int)auth()->id());
                            $isDocxImport = !empty($r->docx_import_path);
                            $isReturnedForCorrection = in_array($r->status, ['pre_returned', 'post_returned'], true);
                            $isPermanentlyRejected = $r->status === 'rejected';
                            $docxNeedsFill = $isDocxImport && (
                                $r->docx_import_status === 'pending' || $isReturnedForCorrection
                            );

                            $equipmentName = trim((string) ($r->equipment_name ?? ''));
                            if ($equipmentName === '' && $r->document?->equipment) {
                                $equipmentName = trim((string) ($r->document->equipment->equipment_name ?? ''));
                            }

                            $equipmentId = trim((string) ($r->equipment_id ?? ''));
                            if ($equipmentId === '' && $r->document?->equipment) {
                                $equipmentId = trim((string) ($r->document->equipment->equipment_id ?? ''));
                            }

                            $protocolTitle = $r->protocol_title;
                        @endphp

                        <tr>
                            <td>{{ $r->displayCode() }}</td>
                            <td>{{ $protocolTitle !== '' ? $protocolTitle : '-' }}</td>
                            <td>{{ $equipmentName !== '' ? $equipmentName : '-' }}</td>
                            <td>{{ $equipmentId !== '' ? $equipmentId : '-' }}</td>
                            <td>{{ optional($r->created_at)->format('d-m-Y h:i A') }}</td>

                            <td>
                                @if($docxNeedsFill && $r->docx_import_status === 'pending')
                                    <span class="badge bg-warning text-dark">FILL PENDING</span>
                                @elseif($isPermanentlyRejected)
                                    <span class="badge bg-danger text-white">REJECTED</span>
                                @elseif($docxNeedsFill && $isReturnedForCorrection)
                                    <span class="badge bg-warning text-dark">SENT BACK — EDIT REQUIRED</span>
                                @else
                                    <span class="{{ $badgeClass }}">
                                        {{ $status }}
                                    </span>
                                    @if($isDocxImport)
                                        <span class="badge bg-secondary text-light ml-1">DOCX</span>
                                    @endif
                                @endif
                            </td>

                            <td class="text-end">

                                {{-- DOCX import: creator fills or re-edits via the same fill form as fresh import --}}
                                @if($docxNeedsFill && $isCreator)
                                    @php
                                        $requalPreGate = ($r->import_source ?? '') === 'requalification'
                                            && in_array($r->status, ['pre_setup', 'pre_pending', 'pre_returned'], true)
                                            && $r->stage !== 'post';
                                    @endphp
                                    @if($requalPreGate && $r->status === 'pre_pending')
                                        <a href="{{ route('reports.show', $r) }}" class="btn btn-sm btn-secondary">
                                            Pre Approval In Progress
                                        </a>
                                    @else
                                        <a href="{{ route('reports.docx-import.fill-form', $r) }}" class="btn btn-sm btn-primary">
                                            @if($requalPreGate)
                                                Select Pre Approvers
                                            @elseif($isReturnedForCorrection)
                                                Edit &amp; Resubmit
                                            @else
                                                Fill &amp; Submit
                                            @endif
                                        </a>
                                    @endif

                                {{-- Current POST/PRE approver --}}
                                @elseif($isCurrentApprover && !$docxNeedsFill && \App\Services\SidebarPermissionService::userCan('protocol-approve'))
                                    <a href="{{ route('reports.show', $r->id) }}" class="btn btn-sm btn-warning">
                                        Approve Now
                                    </a>

                                @elseif($isCurrentApprover && !$docxNeedsFill)
                                    <a href="{{ route('reports.show', $r->id) }}" class="btn btn-sm btn-info text-white">
                                        @if($r->status === 'pre_pending')
                                            Pending for Pre Approval
                                        @else
                                            Open
                                        @endif
                                    </a>

                                {{-- Permanently rejected: view only --}}
                                @elseif($isPermanentlyRejected)
                                    <a href="{{ route('reports.show', $r->id) }}" class="btn btn-sm btn-secondary">
                                        View
                                    </a>

                                {{-- Final approved --}}
                                @elseif($r->status === 'approved')
                                    <a href="{{ route('reports.show', $r->id) }}" class="btn btn-sm btn-success">
                                        View
                                    </a>

                                {{-- Rejected HTML workflow (non-DOCX) --}}
                                @elseif($isReturnedForCorrection && $isCreator && !$isDocxImport)
                                    <a href="{{ route('reports.show', $r->id) }}" class="btn btn-sm btn-danger">
                                        View / Resubmit
                                    </a>

                                {{-- HTML workflow: pre-approved, fill POST section --}}
                                @elseif($r->status === 'pre_approved' && $isCreator && !$isDocxImport)
                                    <a href="{{ route('reports.editAfterPreApproved', $r->id) }}" class="btn btn-sm btn-primary">
                                        Fill Post Details
                                    </a>

                                {{-- POST pending DOCX (filled, awaiting approval) --}}
                                @elseif($r->status === 'post_pending' && $isDocxImport)
                                    <a href="{{ route('reports.show', $r->id) }}" class="btn btn-sm btn-info text-white">
                                        View
                                    </a>

                                @elseif($r->status === 'pre_pending')
                                    <a href="{{ route('reports.show', $r->id) }}" class="btn btn-sm btn-warning">
                                        Pending for Pre Approval
                                    </a>

                                @else
                                    <a href="{{ route('reports.show', $r->id) }}" class="btn btn-sm btn-secondary">
                                        Open
                                    </a>
                                @endif

                            </td>
                        </tr>

                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                No records found.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination: 10 per page --}}
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mt-3 gap-2">
                <div class="text-muted small">
                    Showing {{ $approvedReports->firstItem() ?? 0 }}
                    to {{ $approvedReports->lastItem() ?? 0 }}
                    of {{ $approvedReports->total() }} protocols
                </div>
                <div>
                    {{ $approvedReports->onEachSide(1)->links() }}
                </div>
            </div>

        </div>
    </div>
</div>
@endsection
