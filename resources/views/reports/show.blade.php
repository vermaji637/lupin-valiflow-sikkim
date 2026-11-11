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
        <h3 class="mb-2 mb-md-0">{{ $report->displayCode() }}</h3>

        <div class="d-flex align-items-center flex-wrap">
            @if(empty($report->docx_import_path) && (int)$report->created_by === (int)auth()->id())
                <button type="button" class="btn btn-primary btn-icon-split shadow-sm mr-2 mb-2" data-toggle="modal" data-target="#importDocxModal">
                    <span class="icon text-white-50">
                        <i class="fas fa-file-import"></i>
                    </span>
                    <span class="text">Import DOCX</span>
                </button>
            @endif

            @php
                $canShowFillFields = $report->docx_import_status === 'pending'
                    && $report->docx_import_path
                    && (int)$report->created_by === (int)auth()->id()
                    && !in_array($report->status, ['pre_pending', 'post_pending', 'rejected', 'pre_returned', 'post_returned'], true);
            @endphp
            @if($canShowFillFields)
                <a href="{{ route('reports.docx-import.fill-form', $report) }}" class="btn btn-warning btn-icon-split shadow-sm mr-2 mb-2">
                    <span class="icon text-white-50">
                        <i class="fas fa-edit"></i>
                    </span>
                    <span class="text">Fill Fields</span>
                </a>
            @endif

            @if($report->docx_import_status === 'completed' && $report->docx_filled_path)
                <!-- <a href="{{ route('reports.downloadApprovedWord', $report->id) }}" class="btn btn-success btn-icon-split shadow-sm mr-2 mb-2">
                    <span class="icon text-white-50">
                        <i class="fas fa-file-word"></i>
                    </span>
                    <span class="text">Download Filled DOCX</span>
                </a> -->
<!-- 
                <a href="{{ route('reports.docx-import.download-pdf', $report) }}" class="btn btn-danger btn-icon-split shadow-sm mr-2 mb-2">
                    <span class="icon text-white-50">
                        <i class="fas fa-file-pdf"></i>
                    </span>
                    <span class="text">Download PDF</span>
                </a> -->
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


    {{-- Workflow flags: who can approve, resubmit, or see status banners --}}
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
        $isRejected     = $report->status === 'rejected';

        $isPreApproved  = $report->status === 'pre_approved';
        $isFinalApproved = $report->status === 'approved';

        $preSteps  = $report->approvalSteps->where('stage','pre')->sortBy('step_order');
        $postSteps = $report->approvalSteps->where('stage','post')->sortBy('step_order');

        $docxPostStampPrefixes = [
            // Maps POST step labels to reports.data prefix keys for DOCX stamp display
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
    {{-- ================= PERMANENTLY REJECTED ================= --}}
    @if($isRejected)
        <div class="alert alert-danger">
            <strong>This protocol was rejected and is closed.</strong><br>
            It cannot be edited or resubmitted.
            @php
                $rejectStep = $report->approvalSteps
                    ->where('stage', $report->stage)
                    ->filter(fn ($step) => !empty($step->rejected_at))
                    ->sortByDesc('rejected_at')
                    ->first();
            @endphp
            @if($rejectStep && $rejectStep->remark)
                <div class="mt-2 mb-0"><strong>Remark:</strong> {{ $rejectStep->remark }}</div>
            @endif
        </div>
    @endif

    {{-- ================= PRE APPROVAL PENDING ================= --}}
    @if($report->status === 'pre_pending')
        <div class="alert alert-info d-flex align-items-center mb-3">
            <i class="fas fa-info-circle mr-2"></i>
            <div>
                <strong>Pre-approval is in progress.</strong> The document is under review by the pre-approval committee. It is locked and cannot be edited unless sent back by an approver.
            </div>
        </div>
    @endif

    {{-- ================= RETURNED TO CREATOR ================= --}}
    @if(($isPreReturned || $isPostReturned) && $isCreator)

        <div class="alert alert-warning d-flex justify-content-between align-items-center">
            <div>
                <strong>This protocol was sent back for correction.</strong><br>
                Please correct the form and resubmit.
            </div>

            @if($isPreReturned)
                <a href="{{ !empty($report->docx_import_path)
                        ? route('reports.docx-import.fill-form', $report)
                        : route('reports.editReturnedPre', $report) }}"
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


    {{-- Report body: DOCX table preview, async fetch, or HTML template --}}
    @php
        $docxReadonlyHtml = !empty($docxPreviewHtml)
            ? $docxPreviewHtml
            : ($report->rendered_html ?? '');
    @endphp

    @if(!empty($report->docx_import_path))
    @push('styles')
    {{-- DOCX preview table styles — keeps cells readable inside premium-ui theme --}}
    <style>
    /* Match fill form table layout — premium-ui can break table display without !important */
    body.premium-app #reportReadonlyBody .docx-preview-wrapper {
        overflow-x: auto;
        overflow-y: visible;
        background: #525659;
        border: 1px solid #334155;
        border-radius: 8px;
        padding: 28px 16px;
        min-height: 200px;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-preview {
        width: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-a4-page {
        width: 210mm;
        max-width: 100%;
        min-height: 297mm;
        max-height: 297mm;
        height: 297mm;
        background: #ffffff !important;
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.28), 0 1px 4px rgba(0, 0, 0, 0.16);
        border-radius: 2px;
        padding: 10mm 14mm 10mm 14mm;
        margin-bottom: 24px;
        position: relative;
        box-sizing: border-box;
        font-family: inherit;
        break-after: page;
        page-break-after: always;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        overflow: hidden;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-page-body {
        flex: 1 1 auto;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-page-body > * {
        flex-shrink: 0;
        max-width: 100%;
        min-width: 0;
        box-sizing: border-box;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-page-official-header {
        width: 100%;
        margin-bottom: 8px;
        flex-shrink: 0;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-page-official-header table.header-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 0;
        table-layout: fixed;
        background: #ffffff;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-page-official-header table.header-table td {
        border: 1px solid #334155 !important;
        padding: 3px 6px !important;
        font-size: 11px !important;
        vertical-align: middle !important;
        line-height: 1.25;
        background: #ffffff;
        color: #0f172a;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-summary-header-card {
        width: 210mm;
        max-width: 100%;
        background: #ffffff !important;
        color: #0f172a !important;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        padding: 12px 14px;
        margin: 0 auto 20px auto;
        box-shadow: 0 3px 12px rgba(0, 0, 0, 0.22);
        box-sizing: border-box;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-summary-header-card table.header-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 0;
        background: #ffffff !important;
        color: #0f172a !important;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-summary-header-card table.header-table td {
        border: 1px solid #cbd5e1 !important;
        padding: 8px 10px !important;
        color: #0f172a !important;
        font-size: 13px !important;
        line-height: 1.4;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-page-official-footer {
        width: 100%;
        margin-top: auto;
        padding-top: 6px;
        border-top: 1.5px solid #64748b;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 10px;
        font-weight: 600;
        color: #475569;
        flex-shrink: 0;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-page-break-indicator {
        display: flex;
        align-items: center;
        width: 210mm;
        max-width: 100%;
        margin: 4px 0 20px 0;
        user-select: none;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-page-break-line {
        flex-grow: 1;
        border-top: 2px dashed #94a3b8;
        height: 0;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-page-break-badge {
        background: #1e293b;
        color: #f8fafc;
        font-size: 11px;
        font-weight: 700;
        padding: 4px 16px;
        border-radius: 14px;
        margin: 0 14px;
        letter-spacing: 0.5px;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.35);
        white-space: nowrap;
    }
    @media print {
        body.premium-app #reportReadonlyBody .docx-preview-wrapper {
            background: transparent !important;
            padding: 0 !important;
            border: none !important;
        }
        body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-a4-page {
            box-shadow: none !important;
            margin: 0 !important;
            padding: 12mm !important;
            width: 100% !important;
            min-height: auto !important;
            page-break-after: always !important;
            break-after: page !important;
        }
        .no-print,
        .docx-page-break-indicator,
        .docx-page-header-info {
            display: none !important;
        }
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table {
        display: table !important;
        width: 100%;
        max-width: 100%;
        border-collapse: collapse;
        margin-bottom: 16px;
        font-size: 13px;
        table-layout: fixed;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table.header-table {
        table-layout: fixed;
        margin-bottom: 0;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table tr {
        display: table-row !important;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table td,
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table th {
        display: table-cell !important;
        min-width: 0;
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: break-word;
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
                {{-- Server-rendered DOCX table preview from buildShowPreviewHtml --}}
                <div class="docx-preview-wrapper overflow-auto">
                    {!! $docxReadonlyHtml !!}
                </div>
            @elseif(!empty($report->docx_import_path))
                {{-- Fallback: load preview HTML via AJAX when not pre-rendered --}}
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
                          if (typeof window.paginateReadonlyPreview === 'function') {
                              window.paginateReadonlyPreview();
                          }
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


    {{-- E-sign approve and reject forms for the current pending approver --}}
    @if($isPending && $isCurrentApprover)

        <div class="card border-success mb-4">
            <div class="card-body">

                <h5 class="text-success mb-3">
                    You are the current approver
                </h5>

                @php
                    $canApprove = \App\Services\SidebarPermissionService::userCan('protocol-approve');
                    $canSendBack = \App\Services\SidebarPermissionService::userCan('protocol-send-back');
                    $canReject = \App\Services\SidebarPermissionService::userCan('protocol-reject');
                @endphp

                @if($canApprove)
                {{-- Approve: POST to reports.approve with e-sign password --}}
                <form method="POST"
                      action="{{ route('reports.approve',$report) }}"
                      class="d-inline esign-required">
                    @csrf
                    <input type="hidden" name="password">
                    <button class="btn btn-success">
                        Approve
                    </button>
                </form>
                @endif

                @if($canSendBack)
                <button type="button"
                    class="btn btn-warning ml-2"
                    onclick="toggleDecisionBox('sendBackBox');">
                    Send Back
                </button>
                @endif

                @if($canReject)
                <button type="button"
                    class="btn btn-danger ml-2"
                    onclick="toggleDecisionBox('rejectBox');">
                    Reject
                </button>
                @endif

                @if(!$canApprove && !$canSendBack && !$canReject)
                    <div class="alert alert-warning mb-0">
                        You are the current approver, but no Approve / Send Back / Reject permission is assigned to your role.
                    </div>
                @endif

@if($canSendBack)
<div id="sendBackBox" style="display:none;" class="mt-3">
    <form method="POST"
          action="{{ route('reports.sendBack',$report) }}"
          id="sendBackForm"
          class="esign-required">
        @csrf
        <input type="hidden" name="password">

        <div class="mb-2">
            <label class="form-label">Send Back Reason <span class="text-danger">*</span></label>
            <textarea id="sendBackRemark"
                      name="remark"
                      class="form-control"
                      rows="3"
                      placeholder="Enter reason to send back for correction..."></textarea>
            <div id="sendBackNotification" class="alert alert-warning mt-2 py-2" style="display:none;">
                Please enter a reason before sending back.
            </div>
        </div>

        <button type="button" class="btn btn-warning" onclick="submitDecision('sendBack')">
            Confirm Send Back
        </button>
    </form>
</div>
@endif

@if($canReject)
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

            <div class="alert alert-danger mt-2 py-2 mb-0">
                Reject will close this protocol. The filler cannot edit or resubmit it.
            </div>

            <div id="rejectNotification" class="alert alert-warning mt-2 py-2" style="display:none;">
                Please enter a reason before rejecting.
            </div>
        </div>

        <button type="button" class="btn btn-danger" onclick="submitDecision('reject')">
            Confirm Reject
        </button>
    </form>
</div>
@endif

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
                    <th style="width: 70px;">Step</th>
                    <th>Role / Stage</th>
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
                            <span class="badge bg-info text-dark">{{ $step->label ?? 'Approver' }}</span>
                        </td>
                        <td>
                            {{ $step->user->first_name ?? '' }}
                            {{ $step->user->last_name ?? '' }}
                            @if(!empty($step->user->designation))
                                <div class="small text-muted">{{ $step->user->designation }}</div>
                            @endif
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
                                @if(!empty($step->user->designation))
                                    <div class="small text-muted">{{ $step->user->designation }}</div>
                                @endif
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
function toggleDecisionBox(id) {
    var target = document.getElementById(id);
    if (!target) return;
    var show = target.style.display === 'none' || target.style.display === '';
    ['sendBackBox', 'rejectBox'].forEach(function (boxId) {
        var box = document.getElementById(boxId);
        if (box) box.style.display = 'none';
    });
    if (show) target.style.display = 'block';
}

function submitDecision(type) {
    var remarkId = type === 'sendBack' ? 'sendBackRemark' : 'rejectRemark';
    var noteId = type === 'sendBack' ? 'sendBackNotification' : 'rejectNotification';
    var formId = type === 'sendBack' ? 'sendBackForm' : 'rejectForm';
    var remarkEl = document.getElementById(remarkId);
    var notification = document.getElementById(noteId);
    var remark = remarkEl ? remarkEl.value.trim() : '';

    if (!remark) {
        if (notification) notification.style.display = 'block';
        if (remarkEl) {
            remarkEl.classList.add('is-invalid');
            remarkEl.focus();
        }
        setTimeout(function () {
            if (notification) notification.style.display = 'none';
        }, 4000);
        return;
    }

    if (notification) notification.style.display = 'none';
    if (remarkEl) remarkEl.classList.remove('is-invalid');
    document.getElementById(formId).requestSubmit();
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

    function followingPage(page) {
        let next = page ? page.nextElementSibling : null;
        while (next && !next.classList.contains('docx-a4-page')) next = next.nextElementSibling;
        return next;
    }

    function updatePageNumbers() {
        const pages = Array.from(wrap.querySelectorAll('.docx-a4-page'));
        const total = pages.length;
        pages.forEach(function (page, idx) {
            const num = idx + 1;
            page.id = 'docxPage' + num;
            page.setAttribute('data-page-number', String(num));
            page.querySelectorAll('.current-page-num').forEach(function (el) { el.textContent = String(num); });
            page.querySelectorAll('.total-pages-count').forEach(function (el) { el.textContent = String(total); });
        });
        wrap.querySelectorAll('.docx-page-break-badge').forEach(function (badge, idx) {
            badge.textContent = 'Page ' + (idx + 2);
        });
    }

    function continuationPage(sourcePage) {
        const existing = followingPage(sourcePage);
        if (existing) return existing;
        const page = document.createElement('div');
        page.className = 'docx-a4-page';
        const header = sourcePage.querySelector('.docx-page-official-header');
        if (header) {
            const clone = header.cloneNode(true);
            clone.querySelectorAll('[id]').forEach(function (el) { el.removeAttribute('id'); });
            page.appendChild(clone);
        }
        const body = document.createElement('div');
        body.className = 'docx-page-body';
        page.appendChild(body);
        const footer = sourcePage.querySelector('.docx-page-official-footer');
        if (footer) {
            const clone = footer.cloneNode(true);
            clone.querySelectorAll('[id]').forEach(function (el) { el.removeAttribute('id'); });
            page.appendChild(clone);
        }
        const divider = document.createElement('div');
        divider.className = 'docx-page-break-indicator no-print';
        divider.innerHTML = '<div class="docx-page-break-line"></div><div class="docx-page-break-badge">Page</div><div class="docx-page-break-line"></div>';
        sourcePage.after(divider);
        divider.after(page);
        return page;
    }

    function continuationTable(page, sourceTable) {
        const next = continuationPage(page);
        const body = next.querySelector('.docx-page-body');
        const tIdx = sourceTable.getAttribute('data-table-index') || '';
        const first = body.firstElementChild;
        if (first && first.matches('table.docx-table') && (first.getAttribute('data-table-index') || '') === tIdx) {
            return first;
        }
        const table = document.createElement('table');
        table.className = sourceTable.className;
        if (tIdx !== '') table.setAttribute('data-table-index', tIdx);
        if (sourceTable.rows[0]) {
            const header = sourceTable.rows[0].cloneNode(true);
            header.querySelectorAll('input, textarea, select').forEach(function (el) { el.removeAttribute('name'); });
            table.appendChild(header);
        }
        body.insertBefore(table, body.firstChild);
        return table;
    }

    function spillOne(page) {
        const body = page.querySelector('.docx-page-body');
        if (!body) return false;
        const last = body.lastElementChild;
        if (!last) return false;
        if (last.classList.contains('docx-toc-card')) {
            const lines = last.querySelectorAll(':scope > .docx-toc-line');
            if (lines.length > 1) {
                const line = lines[lines.length - 1];
                const next = continuationPage(page);
                const nextBody = next.querySelector('.docx-page-body');
                let card = nextBody.firstElementChild;
                if (!card || !card.classList.contains('docx-toc-card') || card.getAttribute('data-toc-cont') !== '1') {
                    card = document.createElement('div');
                    card.className = 'docx-toc-card';
                    card.setAttribute('data-toc-cont', '1');
                    nextBody.insertBefore(card, nextBody.firstChild);
                }
                const firstLine = card.querySelector('.docx-toc-line');
                if (firstLine) card.insertBefore(line, firstLine);
                else card.appendChild(line);
                return true;
            }
        }
        if (last.matches('table.docx-table') && !last.classList.contains('header-table') && last.rows.length > 1) {
            const row = last.rows[last.rows.length - 1];
            if (row.offsetHeight + 12 >= body.clientHeight && last.rows.length <= 2 && body.childElementCount === 1) return false;
            const table = continuationTable(page, last);
            const header = table.rows[0];
            if (header && header !== row) header.after(row);
            else table.appendChild(row);
            return true;
        }
        if (last.matches('table.docx-table') && last.rows.length <= 1) {
            const tIdx = last.getAttribute('data-table-index') || '';
            const next = followingPage(page);
            const moved = next ? next.querySelector('table.docx-table[data-table-index="' + tIdx + '"]') : null;
            const named = last.querySelector('[name]');
            if (!named && moved && moved.rows.length > 1) {
                last.remove();
                return true;
            }
            if (body.children.length === 1) return false;
        }
        const overflowPx = body.scrollHeight - body.clientHeight;
        if (last.offsetHeight > Math.max(overflowPx, 0) + 48 && body.childElementCount > 1) {
            return false;
        }
        const nextBody = continuationPage(page).querySelector('.docx-page-body');
        nextBody.insertBefore(last, nextBody.firstChild);
        return true;
    }

    function contentOverflows(pageBody) {
        if (!pageBody) return false;
        return pageEdge(pageBody) > pageBody.getBoundingClientRect().bottom + 6;
    }

    function pageEdge(pageBody) {
        const last = pageBody.lastElementChild;
        if (!last) return pageBody.getBoundingClientRect().top;
        if (last.matches && last.matches('table.docx-table') && last.rows && last.rows.length) {
            return last.rows[last.rows.length - 1].getBoundingClientRect().bottom;
        }
        if (last.classList && last.classList.contains('docx-toc-card')) {
            const lines = last.querySelectorAll('.docx-toc-line');
            if (lines.length) return lines[lines.length - 1].getBoundingClientRect().bottom;
        }
        return last.getBoundingClientRect().bottom;
    }

    function pageFreeSpace(pageBody) {
        if (!pageBody) return 0;
        return pageBody.getBoundingClientRect().bottom - 8 - pageEdge(pageBody);
    }

    function pageIsOverfull(pageBody) {
        if (!pageBody) return false;
        return pageEdge(pageBody) > pageBody.getBoundingClientRect().bottom - 2;
    }

    function rowLabel(row) {
        return Array.from(row.cells || []).map(function (cell) {
            return (cell.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
        }).join('|');
    }

    function dropEmptySheet(page) {
        const body = page.querySelector('.docx-page-body');
        if (!body) return false;
        Array.from(body.children).forEach(function (el) {
            if (el.matches && el.matches('table.docx-table') && el.rows.length <= 1 && !el.querySelector('[name]')) el.remove();
            if (el.classList && el.classList.contains('docx-toc-card') && !el.querySelector('.docx-toc-line')) el.remove();
        });
        if (body.children.length) return false;
        const prev = page.previousElementSibling;
        if (prev && prev.classList.contains('docx-page-break-indicator')) prev.remove();
        page.remove();
        return true;
    }

    function pullBlockBack(body, nextBody, el) {
        const marker = el.nextSibling;
        body.appendChild(el);
        if (pageIsOverfull(body)) {
            if (marker) nextBody.insertBefore(el, marker);
            else nextBody.appendChild(el);
            return false;
        }
        return true;
    }

    function pullTableRowBack(body, sourceTable) {
        const head = sourceTable.rows[0] && sourceTable.rows[0].querySelector('th') ? sourceTable.rows[0] : null;
        const row = head ? sourceTable.rows[1] : sourceTable.rows[0];
        if (!row) {
            if (!sourceTable.querySelector('[name]')) sourceTable.remove();
            return true;
        }
        const last = body.lastElementChild;
        const same = last && last.matches && last.matches('table.docx-table')
            && (last.getAttribute('data-table-index') || '') === (sourceTable.getAttribute('data-table-index') || '');
        if (same && rowLabel(row) && last.rows[0] && rowLabel(row) === rowLabel(last.rows[0])) {
            row.remove();
            return true;
        }
        if (!same && sourceTable.offsetHeight + 6 < pageFreeSpace(body)) {
            return pullBlockBack(body, sourceTable.parentNode, sourceTable);
        }
        let target = same ? last : null;
        let created = false;
        if (!target) {
            target = document.createElement('table');
            target.className = sourceTable.className;
            const tIdx = sourceTable.getAttribute('data-table-index') || '';
            if (tIdx) target.setAttribute('data-table-index', tIdx);
            if (head) {
                const clone = head.cloneNode(true);
                clone.querySelectorAll('[id]').forEach(function (el) { el.removeAttribute('id'); });
                clone.querySelectorAll('[name]').forEach(function (el) { el.removeAttribute('name'); });
                target.appendChild(clone);
            }
            body.appendChild(target);
            created = true;
        }
        const marker = row.nextSibling;
        target.appendChild(row);
        if (pageIsOverfull(body)) {
            if (marker) sourceTable.insertBefore(row, marker);
            else sourceTable.appendChild(row);
            if (created) target.remove();
            return false;
        }
        const leftoverHead = sourceTable.rows.length === 1 && sourceTable.rows[0].querySelector('th') && !sourceTable.querySelector('[name]');
        if (!sourceTable.rows.length || leftoverHead) sourceTable.remove();
        return true;
    }

    function pullOneBack(page) {
        const body = page.querySelector('.docx-page-body');
        const next = followingPage(page);
        if (!body || !next || pageFreeSpace(body) < 28) return false;
        const nextBody = next.querySelector('.docx-page-body');
        const first = nextBody && nextBody.firstElementChild;
        if (!first) return false;
        if (first.matches('table.docx-table') && !first.classList.contains('header-table')) {
            return pullTableRowBack(body, first);
        }
        return pullBlockBack(body, nextBody, first);
    }

    function fillPageGaps() {
        let page = wrap.querySelector('.docx-preview .docx-a4-page');
        let pulls = 0;
        while (page && pulls < 700) {
            const next = followingPage(page);
            if (!next) break;
            if (pageFreeSpace(page.querySelector('.docx-page-body')) < 28 || !pullOneBack(page)) {
                page = next;
                continue;
            }
            pulls++;
            dropEmptySheet(next);
        }
    }

    window.paginateReadonlyPreview = function () {
        const first = wrap.querySelector('.docx-preview .docx-a4-page');
        if (!first) return;
        const firstBody = first.querySelector('.docx-page-body');
        if (!firstBody || firstBody.clientHeight < 80) return;
        fillPageGaps();
        let page = wrap.querySelector('.docx-preview .docx-a4-page');
        let moves = 0;
        while (page && moves < 250) {
            const pageBody = page.querySelector('.docx-page-body');
            if (!contentOverflows(pageBody)) {
                page = followingPage(page);
                continue;
            }
            const before = pageBody.scrollHeight;
            const beforeLast = pageBody.lastElementChild;
            const beforeRow = beforeLast && beforeLast.rows ? beforeLast.rows[beforeLast.rows.length - 1] : null;
            if (!spillOne(page)) {
                page = followingPage(page);
                continue;
            }
            moves++;
            const rowStillHere = beforeRow && pageBody.contains(beforeRow);
            if (contentOverflows(pageBody) && rowStillHere && pageBody.scrollHeight >= before) {
                page = followingPage(page);
            }
        }
        wrap.querySelectorAll('.docx-a4-page').forEach(function (sheet) {
            const sheetBody = sheet.querySelector('.docx-page-body');
            const tail = sheetBody && sheetBody.lastElementChild;
            if (!tail || !tail.matches('table.docx-table') || tail.rows.length > 1) return;
            const tIdx = tail.getAttribute('data-table-index') || '';
            const next = followingPage(sheet);
            const moved = next ? next.querySelector('table.docx-table[data-table-index="' + tIdx + '"]') : null;
            if (moved && moved.rows.length > 1 && !tail.querySelector('[name]')) tail.remove();
        });
        updatePageNumbers();
    };

    window.paginateReadonlyPreview();
    setTimeout(window.paginateReadonlyPreview, 300);
});
</script>

@endsection
