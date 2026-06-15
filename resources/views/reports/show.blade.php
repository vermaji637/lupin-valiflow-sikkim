@extends('layouts.app')

@section('content')
<div class="container">
    <style>
        #reportReadonlyBody a[href] {
            pointer-events: auto !important;
            cursor: pointer !important;
            position: relative;
            z-index: 20;
            text-decoration: underline;
        }

        #reportReadonlyBody .stretched-link::after {
            pointer-events: none !important;
        }

        #reportReadonlyBody [contenteditable] {
            -webkit-user-modify: read-only;
        }

        #reportReadonlyBody .docx-signature-font {
            font-family: "Segoe Script", "Brush Script MT", cursive;
            font-style: italic;
            font-size: 1.05em;
        }

        #reportReadonlyBody .docx-approval-pending,
        #reportReadonlyBody .approval-stamp-cell .docx-approval-pending {
            color: #999;
            font-style: italic;
        }

        #reportReadonlyBody .approval-stamp-cell {
            background: #f0f0f0;
        }
    </style>

    @if(session('status'))
        <div class="alert alert-success alert-dismissible fade show shadow-sm mb-3" role="alert">
            <i class="fas fa-check-circle mr-2"></i> {{ session('status') }}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-3" role="alert">
            <i class="fas fa-exclamation-triangle mr-2"></i> {{ session('error') }}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    @endif

    {{-- ================= HEADER ================= --}}
     <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <h3 class="mb-2 mb-md-0">Report #{{ $report->id }}</h3>

        <div class="d-flex align-items-center flex-wrap">
            @if(empty($report->docx_import_path))
                <button type="button" class="btn btn-primary btn-icon-split shadow-sm mr-2 mb-2" data-toggle="modal" data-target="#importDocxModal">
                    <span class="icon text-white-50">
                        <i class="fas fa-file-import"></i>
                    </span>
                    <span class="text">Import DOCX</span>
                </button>
            @endif

            @if($report->docx_import_status === 'pending' && $report->docx_import_path)
                <a href="{{ route('reports.docx-import.fill-form', $report) }}" class="btn btn-warning btn-icon-split shadow-sm mr-2 mb-2">
                    <span class="icon text-white-50">
                        <i class="fas fa-edit"></i>
                    </span>
                    <span class="text">Fill Fields</span>
                </a>
            @endif

            @if($report->docx_import_status === 'completed' && $report->docx_filled_path)
                <a href="{{ route('reports.downloadApprovedWord', $report->id) }}" class="btn btn-success btn-icon-split shadow-sm mr-2 mb-2">
                    <span class="icon text-white-50">
                        <i class="fas fa-file-word"></i>
                    </span>
                    <span class="text">Download Filled DOCX</span>
                </a>

                <a href="{{ route('reports.docx-import.download-pdf', $report) }}" class="btn btn-danger btn-icon-split shadow-sm mr-2 mb-2">
                    <span class="icon text-white-50">
                        <i class="fas fa-file-pdf"></i>
                    </span>
                    <span class="text">Download PDF</span>
                </a>
            @endif

            <span class="badge bg-secondary text-dark mr-1 mb-2">
                Stage: {{ strtoupper($report->stage) }}
            </span>

            <span class="badge bg-info text-dark mr-1 mb-2">
                Status: {{ strtoupper($report->status) }}
            </span>

            <span class="badge bg-warning text-dark mb-2">
                @if($report->current_step_order)
                    Step: {{ $report->current_step_order }}
                @else
                    Step: Completed
                @endif
            </span>
        </div>
    </div>

    @if($report->docx_import_status === 'completed' && $report->status === 'post_pending')
        <div class="alert alert-info mb-3">
            Document generated and submitted for POST approval. Awaiting approver action.
        </div>
    @endif


    {{-- ================= WORKFLOW VARIABLES ================= --}}
    @php

        $currentStep = $report->approvalSteps()
            ->where('stage', $report->stage)
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->orderBy('step_order')
            ->first();

        $isPending = in_array($report->status, ['pre_pending','post_pending'], true);

        $isCurrentApprover = $currentStep &&
            ((int)$currentStep->user_id === (int)auth()->id());

        $isCreator = (int)$report->created_by === (int)auth()->id();

        $isPreReturned  = $report->status === 'pre_returned';
        $isPostReturned = $report->status === 'post_returned';

        $isPreApproved  = $report->status === 'pre_approved';
        $isFinalApproved = $report->status === 'approved';

        $preSteps  = $report->approvalSteps->where('stage','pre')->sortBy('step_order');
        $postSteps = $report->approvalSteps->where('stage','post')->sortBy('step_order');

        $docxPostStampPrefixes = [
            'POST_PREPARED'    => 'post_prepared_by',
            'POST_ENGINEERING' => 'post_checked_by_engineering',
            'POST_PRODUCTION'  => 'post_checked_by_production',
            'POST_VALIDATION'  => 'post_checked_by_validation',
            'POST_APPROVED_QA' => 'post_approved_by_quality',
        ];
        $docxReportData = is_array($report->data ?? null) ? $report->data : [];

        $attachments = is_array($report->attachments ?? null) ? $report->attachments : [];
        $preApprovedAttachment = collect($attachments)->first(function ($attachment) {
            if (!is_array($attachment)) {
                return false;
            }

            $name = strtolower(trim((string) ($attachment['name'] ?? '')));
            $storedName = strtolower(trim((string) ($attachment['stored_name'] ?? '')));
            $index = isset($attachment['index']) ? (int) $attachment['index'] : null;

            return $index === 98
                || $name === 'pre-approved document'
                || str_contains($storedName, 'preapproved_');
        });
        $preApprovedAttachmentIndex = isset($preApprovedAttachment['index']) ? (int) $preApprovedAttachment['index'] : null;

        $isWordAttachment = function (array $attachment): bool {
            $filePath = strtolower((string) ($attachment['file_path'] ?? ''));
            $storedName = strtolower((string) ($attachment['stored_name'] ?? ''));
            $originalName = strtolower((string) ($attachment['original_name'] ?? ''));
            $mime = strtolower((string) ($attachment['mime'] ?? ''));

            if ($mime === 'application/msword'
                || $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                return true;
            }

            return str_ends_with($filePath, '.doc')
                || str_ends_with($filePath, '.docx')
                || str_ends_with($storedName, '.doc')
                || str_ends_with($storedName, '.docx')
                || str_ends_with($originalName, '.doc')
                || str_ends_with($originalName, '.docx');
        };

        $approvedWordAttachment = collect($attachments)->first(function ($attachment) use ($isWordAttachment) {
            if (!is_array($attachment)) {
                return false;
            }

            $index = isset($attachment['index']) ? (int) $attachment['index'] : null;
            return $index === 100 && $isWordAttachment($attachment);
        });

        if (!$approvedWordAttachment) {
            $approvedWordAttachment = collect($attachments)->first(function ($attachment) use ($isWordAttachment) {
                if (!is_array($attachment)) {
                    return false;
                }

                $index = isset($attachment['index']) ? (int) $attachment['index'] : null;
                return $index === 99 && $isWordAttachment($attachment);
            });
        }

        if (!$approvedWordAttachment) {
            $approvedWordAttachment = collect($attachments)->first(function ($attachment) use ($isWordAttachment) {
                if (!is_array($attachment)) {
                    return false;
                }

                $index = isset($attachment['index']) ? (int) $attachment['index'] : null;
                return $index !== 98 && $isWordAttachment($attachment);
            });
        }

        $approvedWordAttachmentIndex = isset($approvedWordAttachment['index']) ? (int) $approvedWordAttachment['index'] : null;

        // User-uploaded DOCX attachments (exclude system slots 98/99/100).
        $userAttachments = collect($attachments)->filter(function ($att) {
            if (! is_array($att) || empty($att['file_path'])) {
                return false;
            }
            $index = isset($att['index']) ? (int) $att['index'] : 0;
            return ! in_array($index, [98, 99, 100], true);
        })->values();

    @endphp

{{-- ================= PRE APPROVED MESSAGE ================= --}}
    @if($isPreApproved)
        <div class="alert alert-success">
            ✅ This Protocol is Pre Approved
        </div>
    @endif


    {{-- ================= FINAL APPROVED MESSAGE ================= --}}
    @if($isFinalApproved)
        <div class="alert alert-success">
            ✅ This Protocol is Fully Approved (Final Protocol)
        </div>
    @endif
    {{-- ================= RETURNED TO CREATOR ================= --}}
    @if(($isPreReturned || $isPostReturned) && $isCreator)

        <div class="alert alert-danger d-flex justify-content-between align-items-center">
            <div>
                <strong>This Protocol was rejected.</strong><br>
                Please correct the form and resubmit.
            </div>

            @if($isPreReturned)
                <a href="{{ route('reports.editReturnedPre',$report) }}"
                   class="btn btn-primary">
                    Edit & Resubmit (PRE)
                </a>
            @endif

            @if($isPostReturned)
                <a href="{{ !empty($report->docx_import_path)
                        ? route('reports.docx-import.fill-form', $report)
                        : route('reports.editReturnedPost', $report) }}"
                   class="btn btn-primary">
                    Edit & Resubmit (POST)
                </a>
            @endif
        </div>

    @endif


    {{-- ================= DOWNLOAD BUTTONS (FINAL APPROVED ONLY) ================= --}}
    @if($isFinalApproved)
        <div class="mb-3">
            <a href="{{ route('reports.downloadFolderZip',$report->id) }}"
               class="btn btn-primary">
                Download Attachments ZIP
            </a>

            <a href="{{ route('reports.downloadApprovedWord', $report->id) }}"
               class="btn btn-info text-white">
                Download Approved Word
            </a>
        </div>
    @endif

    {{-- ================= UPLOADED ATTACHMENTS (visible during POST approval + final) ================= --}}
    @if($userAttachments->count() > 0)
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong><i class="fas fa-paperclip mr-1"></i> Report Attachments</strong>
                @if($isFinalApproved)
                    <a href="{{ route('reports.downloadFolderZip', $report->id) }}" class="btn btn-sm btn-outline-primary">
                        Download All (ZIP)
                    </a>
                @endif
            </div>
            <div class="card-body py-2">
                <ul class="list-group list-group-flush">
                    @foreach($userAttachments as $att)
                        @php
                            $attIndex = (int) ($att['index'] ?? 0);
                            $attLabel = $att['original_name'] ?? $att['name'] ?? ('Attachment #' . $attIndex);
                        @endphp
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span class="text-break mr-3">{{ $attLabel }}</span>
                            <a href="{{ route('reports.attachments.download', ['report' => $report->id, 'index' => $attIndex]) }}"
                               class="btn btn-sm btn-outline-primary flex-shrink-0"
                               target="_blank">
                                <i class="fas fa-external-link-alt mr-1"></i> Open
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif


    {{-- ================= REPORT BODY ================= --}}
    @php
        $docxReadonlyHtml = !empty($docxPreviewHtml)
            ? $docxPreviewHtml
            : ($report->rendered_html ?? '');
    @endphp

    @if(!empty($report->docx_import_path))
    @push('styles')
    <style>
    /* Match fill form table layout — premium-ui can break table display without !important */
    body.premium-app #reportReadonlyBody .docx-preview-wrapper {
        overflow-x: auto;
        overflow-y: visible;
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 24px;
        min-height: 200px;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table {
        display: table !important;
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 16px;
        font-size: 13px;
        table-layout: auto;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table tr {
        display: table-row !important;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table td,
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table th {
        display: table-cell !important;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table.header-table {
        margin-bottom: 0;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table:not(.header-table) tr:first-child td,
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table:not(.header-table) tr:first-child th {
        font-weight: bold;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper td.docx-cell,
    body.premium-app #reportReadonlyBody .docx-preview-wrapper th.docx-cell {
        border: 1px solid #000;
        padding: 6px 8px;
        vertical-align: middle;
        min-width: 80px;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper td.docx-cell.readonly,
    body.premium-app #reportReadonlyBody .docx-preview-wrapper td.docx-cell[data-field] {
        background-color: #f9f9f9 !important;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper td.docx-cell.locked {
        background-color: #f5f5f5 !important;
        color: #444 !important;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-para {
        margin: 6px 0;
        font-size: 13px;
        line-height: 1.5;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-readonly-value {
        display: inline-block;
        min-width: 60px;
        color: #212529;
        white-space: pre-wrap;
        word-break: break-word;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper a.docx-attachment-link {
        color: #0d6efd !important;
        text-decoration: underline !important;
        pointer-events: auto !important;
        cursor: pointer !important;
    }
    </style>
    @endpush
    @endif

    <div class="card mb-4">
        <div class="card-body" id="reportReadonlyBody">
            @if(!empty($report->docx_import_path) && !empty($docxReadonlyHtml))
                <div class="docx-preview-wrapper overflow-auto">
                    {!! $docxReadonlyHtml !!}
                </div>
            @elseif(!empty($report->docx_import_path))
                <div class="docx-preview-wrapper overflow-auto" id="ap-preview">
                    <p class="text-muted mb-0">Loading document preview...</p>
                </div>
                <script>
                fetch('{{ route('reports.preview-html', $report) }}')
                  .then(function (r) { return r.json(); })
                  .then(function (d) {
                      var el = document.getElementById('ap-preview');
                      if (el && d.html) {
                          el.innerHTML = d.html;
                      } else if (el) {
                          el.innerHTML = '<div class="alert alert-info mb-0"><i class="fas fa-file-word mr-2"></i>DOCX import report — preview unavailable. Use Download Filled DOCX above.</div>';
                      }
                  })
                  .catch(function () {
                      var el = document.getElementById('ap-preview');
                      if (el) {
                          el.innerHTML = '<div class="alert alert-info mb-0"><i class="fas fa-file-word mr-2"></i>DOCX import report — preview unavailable. Use Download Filled DOCX above.</div>';
                      }
                  });
                </script>
            @elseif(($report->template->template_type ?? '') === 'docx')
                <div class="alert alert-info mb-0">
                    <i class="fas fa-file-word mr-2"></i>
                    DOCX fields managed via Import DOCX
                </div>
            @else
                {!! $bodyHtml !!}
            @endif
        </div>
    </div>


    {{-- ================= APPROVE / REJECT SECTION ================= --}}
    @if($isPending && $isCurrentApprover)

        <div class="card border-success mb-4">
            <div class="card-body">

                <h5 class="text-success mb-3">
                    You are the current approver
                </h5>

                <form method="POST"
                      action="{{ route('reports.approve',$report) }}"
                      class="d-inline esign-required">
                    @csrf
                    <input type="hidden" name="password">
                    <button class="btn btn-success">
                        Approve
                    </button>
                </form>

                <button type="button"
        class="btn btn-danger ml-2"
        onclick="var box=document.getElementById('rejectBox'); box.style.display = box.style.display === 'none' ? 'block' : 'none';">
    Reject
</button>

<div id="rejectBox" style="display:none;" class="mt-3">

    <form method="POST"
          action="{{ route('reports.reject',$report) }}"
          id="rejectForm"
          class="esign-required">
        @csrf
        <input type="hidden" name="password">

        <div class="mb-2">
            <label class="form-label">Reject Reason <span class="text-danger">*</span></label>
            <textarea id="rejectRemark"
                      name="remark"
                      class="form-control @error('remark') is-invalid @enderror"
                      rows="3"
                      placeholder="Enter reason for rejection...">{{ old('remark') }}</textarea>

            @error('remark')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror

            <div id="rejectNotification" class="alert alert-warning mt-2 py-2" style="display:none;">
                ⚠️ Please enter a reason before rejecting.
            </div>
        </div>

        <button type="button" class="btn btn-danger" onclick="submitReject()">
            Confirm Reject
        </button>
    </form>
</div>

            </div>
        </div>

    @endif



    {{-- ================= APPROVAL HISTORY ================= --}}
    <div class="card">
        <div class="card-header">
            <strong>Approval History</strong>
        </div>

        <div class="card-body">

            {{-- PRE APPROVAL --}}
            <h6>Pre Approval</h6>
            <table class="table table-bordered">
                <thead>
                <tr>
                    <th>Step</th>
                    <th>User</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
                </thead>
                <tbody>
                @foreach($preSteps as $step)
                    <tr>
                        <td>{{ $step->step_order }}</td>
                        <td>
                            {{ $step->user->first_name ?? '' }}
                            {{ $step->user->last_name ?? '' }}
                        </td>
                        <td>
                            @if($step->approved_at)
                                <span class="badge bg-success text-light">Approved</span>
                            @elseif($step->rejected_at)
                                <span class="badge bg-danger text-light">Rejected</span>
                            @else
                                <span class="badge bg-secondary text-light">Pending</span>
                            @endif
                        </td>
                        <td>
                            {{ $step->approved_at ?? $step->rejected_at ?? '-' }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>


            {{-- POST APPROVAL --}}
            @if($postSteps->count())
                <h6 class="mt-4">Post Approval</h6>
                <table class="table table-bordered">
                    <thead>
                    <tr>
                        <th>Step</th>
                        <th>User</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($postSteps as $step)
                        @php
                            $stampPrefix = ($report->docx_import_path && isset($docxPostStampPrefixes[$step->label ?? '']))
                                ? $docxPostStampPrefixes[$step->label]
                                : null;
                            $stampedName = $stampPrefix
                                ? ($docxReportData[$stampPrefix . '_name_text'] ?? null)
                                : null;
                            $stampedDate = $stampPrefix
                                ? ($docxReportData[$stampPrefix . '_date'] ?? null)
                                : null;
                        @endphp
                        <tr>
                            <td>{{ $step->step_order }}</td>
                            <td>
                                {{ $step->user->first_name ?? '' }}
                                {{ $step->user->last_name ?? '' }}
                                @if($stampedName)
                                    <div class="small text-muted mt-1">
                                        Stamped: {{ $stampedName }}
                                    </div>
                                @endif
                            </td>
                            <td>
                                @if($step->approved_at)
                                    <span class="badge bg-success text-light">Approved</span>
                                @elseif($step->rejected_at)
                                    <span class="badge bg-danger text-light">Rejected</span>
                                @elseif($currentStep && (int) $currentStep->id === (int) $step->id)
                                    <span class="badge bg-warning text-dark">Pending Approval</span>
                                @else
                                    <span class="badge bg-secondary text-light">Waiting</span>
                                @endif
                            </td>
                            <td>
                                @if($stampedDate)
                                    {{ $stampedDate }}
                                @else
                                    {{ $step->approved_at ?? $step->rejected_at ?? '-' }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif

        </div>
    </div>

    {{-- ================= IMPORT DOCX MODAL ================= --}}
    <div class="modal fade" id="importDocxModal" tabindex="-1" role="dialog" aria-labelledby="importDocxModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold" id="importDocxModalLabel">
                        <i class="fas fa-file-word mr-2"></i> Import DOCX Template
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form action="{{ route('reports.docx-import.upload', $report) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="modal-body py-4">
                        <div class="form-group mb-0">
                            <label for="docx_file" class="font-weight-bold text-gray-800">Select DOCX Template File</label>
                            <input type="file"
                                   class="form-control"
                                   name="docx_file"
                                   id="docx_file"
                                   accept=".doc,.docx"
                                   required>
                            <small class="form-text text-muted mt-2">
                                Upload a <code>.docx</code> file to parse and detect fillable fields.
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="fas fa-upload mr-1"></i> Upload & Parse
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>

<script>
function submitReject() {
    const remark = document.getElementById('rejectRemark').value.trim();
    const notification = document.getElementById('rejectNotification');

    if (!remark) {
        notification.style.display = 'block';
        document.getElementById('rejectRemark').classList.add('is-invalid');
        document.getElementById('rejectRemark').focus();
        setTimeout(() => { notification.style.display = 'none'; }, 4000);
        return;
    }

    notification.style.display = 'none';
    document.getElementById('rejectRemark').classList.remove('is-invalid');
    document.getElementById('rejectForm').requestSubmit();
}

document.addEventListener('DOMContentLoaded', function () {
    const wrap = document.getElementById('reportReadonlyBody');
    if (!wrap) return;

    wrap.querySelectorAll('[contenteditable]').forEach(function (el) {
        el.setAttribute('contenteditable', 'false');
    });

    wrap.querySelectorAll('a[href]').forEach(function (a) {
        a.style.pointerEvents = 'auto';
        a.style.cursor = 'pointer';
        a.setAttribute('contenteditable', 'false');
    });
});
</script>

@endsection
