@extends('layouts.app')

@section('title', request()->routeIs('protocol') ? 'All Protocol' : 'Pre Approved Protocol List')

@section('content')
<div class="container-fluid">

    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            {{ request()->routeIs('protocol') ? 'All Protocol' : 'Pre Approved Protocol' }}
        </h1>
        <div class="d-flex gap-2">
            @if(request()->routeIs('protocol'))
                <a href="{{ route('protocol.docx-import.create') }}" class="btn btn-sm btn-primary shadow-sm mr-2">
                    <i class="fas fa-file-import fa-sm text-white-50"></i> Import DOCX
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
                               placeholder="ID / Equipment / Facility / Equip ID / Template">
                    </div>

                    <div class="col-md-2">
                        <label class="small text-muted">Status</label>
                        <select name="status" class="form-control">
                            <option value="">All</option>
                            <option value="pre_pending"  {{ request('status')=='pre_pending'?'selected':'' }}>PRE_PENDING</option>
                            <option value="post_pending" {{ request('status')=='post_pending'?'selected':'' }}>POST_PENDING</option>
                            <option value="pre_approved" {{ request('status')=='pre_approved'?'selected':'' }}>PRE_APPROVED</option>
                            <option value="approved"     {{ request('status')=='approved'?'selected':'' }}>APPROVED</option>
                            <option value="rejected"     {{ request('status')=='rejected'?'selected':'' }}>REJECTED</option>
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
                            <th>Facility/Section</th>
                            <th>Document No</th>
                            <th>Template</th>
                            <th>Version</th>
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
                            $docxNeedsFill = $isDocxImport && ($r->docx_import_status === 'pending');
                        @endphp

                        <tr>
                            <td>{{ $r->id }}</td>
                            <td>{{ $r->facility_section }}</td>
                            <td>{{ $r->document?->title }}</td>
                            <td>{{ $r->template->name ?? $r->template->title ?? '-' }}</td>
                            <td>{{ $r->version }}</td>
                            <td>{{ $r->equipment_name }}</td>
                            <td>{{ $r->equipment_id }}</td>
                            <td>{{ optional($r->created_at)->format('d-m-Y h:i A') }}</td>

                            <td>
                                @if($docxNeedsFill)
                                    <span class="badge bg-warning text-dark">FILL PENDING</span>
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

                                {{-- DOCX import: creator must fill blank fields before POST approval --}}
                                @if($docxNeedsFill && $isCreator)
                                    <a href="{{ route('reports.docx-import.fill-form', $r) }}" class="btn btn-sm btn-primary">
                                        Fill &amp; Submit
                                    </a>

                                {{-- Current POST/PRE approver --}}
                                @elseif($isCurrentApprover && !$docxNeedsFill)
                                    <a href="{{ route('reports.show', $r->id) }}" class="btn btn-sm btn-warning">
                                        Approve Now
                                    </a>

                                {{-- Final approved --}}
                                @elseif($r->status === 'approved')
                                    <a href="{{ route('reports.show', $r->id) }}" class="btn btn-sm btn-success">
                                        View
                                    </a>

                                {{-- Rejected and creator --}}
                                @elseif(in_array($r->status, ['pre_returned', 'post_returned', 'rejected'], true) && $isCreator)
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

                                @else
                                    <a href="{{ route('reports.show', $r->id) }}" class="btn btn-sm btn-secondary">
                                        Open
                                    </a>
                                @endif

                            </td>
                        </tr>

                    @empty
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">
                                No records found.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{-- ✅ Pagination --}}
            <div class="mt-3">
                {{ $approvedReports->links() }}
            </div>

        </div>
    </div>
</div>
@endsection
