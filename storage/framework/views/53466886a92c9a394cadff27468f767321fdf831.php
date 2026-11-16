<?php $__env->startSection('content'); ?>
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

    <?php if(session('status')): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm mb-3" role="alert">
            <i class="fas fa-check-circle mr-2"></i> <?php echo e(session('status')); ?>

            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    <?php endif; ?>

    <?php if(session('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-3" role="alert">
            <i class="fas fa-exclamation-triangle mr-2"></i> <?php echo e(session('error')); ?>

            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    <?php endif; ?>

    
     <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <h3 class="mb-2 mb-md-0"><?php echo e($report->displayCode()); ?></h3>

        <div class="d-flex align-items-center flex-wrap">
            <?php if(empty($report->docx_import_path) && (int)$report->created_by === (int)auth()->id()): ?>
                <button type="button" class="btn btn-primary btn-icon-split shadow-sm mr-2 mb-2" data-toggle="modal" data-target="#importDocxModal">
                    <span class="icon text-white-50">
                        <i class="fas fa-file-import"></i>
                    </span>
                    <span class="text">Import DOCX</span>
                </button>
            <?php endif; ?>

            <?php
                $canShowFillFields = $report->docx_import_status === 'pending'
                    && $report->docx_import_path
                    && (int)$report->created_by === (int)auth()->id()
                    && !in_array($report->status, ['pre_pending', 'post_pending', 'rejected', 'pre_returned', 'post_returned'], true);
            ?>
            <?php if($canShowFillFields): ?>
                <a href="<?php echo e(route('reports.docx-import.fill-form', $report)); ?>" class="btn btn-warning btn-icon-split shadow-sm mr-2 mb-2">
                    <span class="icon text-white-50">
                        <i class="fas fa-edit"></i>
                    </span>
                    <span class="text">Fill Fields</span>
                </a>
            <?php endif; ?>

            <?php if($report->docx_import_status === 'completed' && $report->docx_filled_path): ?>
                <!-- <a href="<?php echo e(route('reports.downloadApprovedWord', $report->id)); ?>" class="btn btn-success btn-icon-split shadow-sm mr-2 mb-2">
                    <span class="icon text-white-50">
                        <i class="fas fa-file-word"></i>
                    </span>
                    <span class="text">Download Filled DOCX</span>
                </a> -->
<!-- 
                <a href="<?php echo e(route('reports.docx-import.download-pdf', $report)); ?>" class="btn btn-danger btn-icon-split shadow-sm mr-2 mb-2">
                    <span class="icon text-white-50">
                        <i class="fas fa-file-pdf"></i>
                    </span>
                    <span class="text">Download PDF</span>
                </a> -->
            <?php endif; ?>

            <span class="badge bg-secondary text-dark mr-1 mb-2">
                Stage: <?php echo e(strtoupper($report->stage)); ?>

            </span>

            <span class="badge bg-info text-dark mr-1 mb-2">
                Status: <?php echo e(strtoupper($report->status)); ?>

            </span>

            <span class="badge bg-warning text-dark mb-2">
                <?php if($report->current_step_order): ?>
                    Step: <?php echo e($report->current_step_order); ?>

                <?php else: ?>
                    Step: Completed
                <?php endif; ?>
            </span>
        </div>
    </div>

    <?php if($report->docx_import_status === 'completed' && $report->status === 'post_pending'): ?>
        <div class="alert alert-info mb-3">
            Document generated and submitted for POST approval. Awaiting approver action.
        </div>
    <?php endif; ?>


    
    <?php

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

    ?>


    <?php if($isPreApproved): ?>
        <div class="alert alert-success">
            ✅ This Protocol is Pre Approved
        </div>
    <?php endif; ?>


    
    <?php if($isFinalApproved): ?>
        <div class="alert alert-success">
            ✅ This Protocol is Fully Approved (Final Protocol)
        </div>
    <?php endif; ?>
    
    <?php if($isRejected): ?>
        <div class="alert alert-danger">
            <strong>This protocol was rejected and is closed.</strong><br>
            It cannot be edited or resubmitted.
            <?php
                $rejectStep = $report->approvalSteps
                    ->where('stage', $report->stage)
                    ->filter(fn ($step) => !empty($step->rejected_at))
                    ->sortByDesc('rejected_at')
                    ->first();
            ?>
            <?php if($rejectStep && $rejectStep->remark): ?>
                <div class="mt-2 mb-0"><strong>Remark:</strong> <?php echo e($rejectStep->remark); ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    
    <?php if($report->status === 'pre_pending'): ?>
        <div class="alert alert-info d-flex align-items-center mb-3">
            <i class="fas fa-info-circle mr-2"></i>
            <div>
                <strong>Pre-approval is in progress.</strong> The document is under review by the pre-approval committee. It is locked and cannot be edited unless sent back by an approver.
            </div>
        </div>
    <?php endif; ?>

    
    <?php if(($isPreReturned || $isPostReturned) && $isCreator): ?>

        <div class="alert alert-warning d-flex justify-content-between align-items-center">
            <div>
                <strong>This protocol was sent back for correction.</strong><br>
                Please correct the form and resubmit.
            </div>

            <?php if($isPreReturned): ?>
                <a href="<?php echo e(!empty($report->docx_import_path)
                        ? route('reports.docx-import.fill-form', $report)
                        : route('reports.editReturnedPre', $report)); ?>"
                   class="btn btn-primary">
                    Edit & Resubmit (PRE)
                </a>
            <?php endif; ?>

            <?php if($isPostReturned): ?>
                <a href="<?php echo e(!empty($report->docx_import_path)
                        ? route('reports.docx-import.fill-form', $report)
                        : route('reports.editReturnedPost', $report)); ?>"
                   class="btn btn-primary">
                    Edit & Resubmit (POST)
                </a>
            <?php endif; ?>
        </div>

    <?php endif; ?>


    
    <?php if($isFinalApproved): ?>
        <div class="mb-3">
            <a href="<?php echo e(route('reports.downloadFolderZip',$report->id)); ?>"
               class="btn btn-primary">
                Download Attachments ZIP
            </a>

            <a href="<?php echo e(route('reports.downloadApprovedWord', $report->id)); ?>"
               class="btn btn-info text-white">
                Download Approved Word
            </a>
        </div>
    <?php endif; ?>

    

    
    <?php
        $docxReadonlyHtml = !empty($docxPreviewHtml)
            ? $docxPreviewHtml
            : ($report->rendered_html ?? '');
    ?>

    <?php if(!empty($report->docx_import_path)): ?>
    <?php $__env->startPush('styles'); ?>
    
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
        /* Match fill preview: free space must stay measurable for pull-back. */
        justify-content: flex-start;
        overflow: hidden;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-page-body {
        flex: 1 1 0;
        min-height: 0;
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
    <?php $__env->stopPush(); ?>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body" id="reportReadonlyBody">
            <?php if(!empty($docxPdfPreviewUrl ?? null)): ?>
                
                <div id="docxPdfPreviewShell" style="position:relative;min-height:70vh;background:#525659;border-radius:6px;overflow:hidden;">
                    <div id="docxPdfPreviewLoading" class="text-center text-white-50 py-5" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;z-index:1;">
                        <div>
                            <i class="fas fa-spinner fa-spin fa-2x mb-3"></i>
                            <div>Loading preview…</div>
                            <div class="small mt-2">First load may take a few seconds</div>
                        </div>
                    </div>
                    <iframe
                        id="docxPdfPreviewFrame"
                        src="<?php echo e($docxPdfPreviewUrl); ?>#toolbar=0&navpanes=1&view=FitH"
                        title="<?php echo e($report->displayCode()); ?>.docx"
                        style="width:100%;height:85vh;border:0;background:#525659;"
                        onload="var el=document.getElementById('docxPdfPreviewLoading'); if(el) el.style.display='none';"
                    ></iframe>
                </div>
            <?php elseif(!empty($report->docx_import_path) && !empty($docxReadonlyHtml)): ?>
                
                <div class="docx-preview-wrapper overflow-auto">
                    <?php echo $docxReadonlyHtml; ?>

                </div>
            <?php elseif(!empty($report->docx_import_path)): ?>
                
                <div class="docx-preview-wrapper overflow-auto" id="ap-preview">
                    <p class="text-muted mb-0">Loading document preview...</p>
                </div>
                <script>
                fetch('<?php echo e(route('reports.preview-html', $report)); ?>')
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
            <?php elseif(($report->template->template_type ?? '') === 'docx'): ?>
                <div class="alert alert-info mb-0">
                    <i class="fas fa-file-word mr-2"></i>
                    DOCX fields managed via Import DOCX
                </div>
            <?php else: ?>
                <?php echo $bodyHtml; ?>

            <?php endif; ?>
        </div>
    </div>


    
    <?php if($isPending && $isCurrentApprover): ?>

        <div class="card border-success mb-4">
            <div class="card-body">

                <h5 class="text-success mb-3">
                    You are the current approver
                </h5>

                <?php
                    $canApprove = \App\Services\SidebarPermissionService::userCan('protocol-approve');
                    $canSendBack = \App\Services\SidebarPermissionService::userCan('protocol-send-back');
                    $canReject = \App\Services\SidebarPermissionService::userCan('protocol-reject');
                ?>

                <?php if($canApprove): ?>
                
                <form method="POST"
                      action="<?php echo e(route('reports.approve',$report)); ?>"
                      class="d-inline esign-required">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="password">
                    <button class="btn btn-success">
                        Approve
                    </button>
                </form>
                <?php endif; ?>

                <?php if($canSendBack): ?>
                <button type="button"
                    class="btn btn-warning ml-2"
                    onclick="toggleDecisionBox('sendBackBox');">
                    Send Back
                </button>
                <?php endif; ?>

                <?php if($canReject): ?>
                <button type="button"
                    class="btn btn-danger ml-2"
                    onclick="toggleDecisionBox('rejectBox');">
                    Reject
                </button>
                <?php endif; ?>

                <?php if(!$canApprove && !$canSendBack && !$canReject): ?>
                    <div class="alert alert-warning mb-0">
                        You are the current approver, but no Approve / Send Back / Reject permission is assigned to your role.
                    </div>
                <?php endif; ?>

<?php if($canSendBack): ?>
<div id="sendBackBox" style="display:none;" class="mt-3">
    <form method="POST"
          action="<?php echo e(route('reports.sendBack',$report)); ?>"
          id="sendBackForm"
          class="esign-required">
        <?php echo csrf_field(); ?>
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
<?php endif; ?>

<?php if($canReject): ?>
<div id="rejectBox" style="display:none;" class="mt-3">
    <form method="POST"
          action="<?php echo e(route('reports.reject',$report)); ?>"
          id="rejectForm"
          class="esign-required">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="password">

        <div class="mb-2">
            <label class="form-label">Reject Reason <span class="text-danger">*</span></label>
            <textarea id="rejectRemark"
                      name="remark"
                      class="form-control <?php $__errorArgs = ['remark'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>"
                      rows="3"
                      placeholder="Enter reason for rejection..."><?php echo e(old('remark')); ?></textarea>

            <?php $__errorArgs = ['remark'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                <div class="invalid-feedback"><?php echo e($message); ?></div>
            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>

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
<?php endif; ?>

            </div>
        </div>

    <?php endif; ?>



    
    <div class="card">
        <div class="card-header">
            <strong>Approval History</strong>
        </div>

        <div class="card-body">

            
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
                <?php $__currentLoopData = $preSteps; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $step): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <tr>
                        <td><?php echo e($step->step_order); ?></td>
                        <td>
                            <span class="badge bg-info text-dark"><?php echo e($step->label ?? 'Approver'); ?></span>
                        </td>
                        <td>
                            <?php echo e($step->user->first_name ?? ''); ?>

                            <?php echo e($step->user->last_name ?? ''); ?>

                            <?php if(!empty($step->user->designation)): ?>
                                <div class="small text-muted"><?php echo e($step->user->designation); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($step->approved_at): ?>
                                <span class="badge bg-success text-light">Approved</span>
                            <?php elseif($step->rejected_at): ?>
                                <span class="badge bg-danger text-light">Rejected</span>
                            <?php else: ?>
                                <span class="badge bg-secondary text-light">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo e($step->approved_at ?? $step->rejected_at ?? '-'); ?>

                        </td>
                    </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>


            
            <?php if($postSteps->count()): ?>
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
                    <?php $__currentLoopData = $postSteps; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $step): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <?php
                            $stampPrefix = ($report->docx_import_path && isset($docxPostStampPrefixes[$step->label ?? '']))
                                ? $docxPostStampPrefixes[$step->label]
                                : null;
                            $stampedName = $stampPrefix
                                ? ($docxReportData[$stampPrefix . '_name_text'] ?? null)
                                : null;
                            $stampedDate = $stampPrefix
                                ? ($docxReportData[$stampPrefix . '_date'] ?? null)
                                : null;
                        ?>
                        <tr>
                            <td><?php echo e($step->step_order); ?></td>
                            <td>
                                <?php echo e($step->user->first_name ?? ''); ?>

                                <?php echo e($step->user->last_name ?? ''); ?>

                                <?php if(!empty($step->user->designation)): ?>
                                    <div class="small text-muted"><?php echo e($step->user->designation); ?></div>
                                <?php endif; ?>
                                <?php if(($step->approved_at || $step->rejected_at) && $stampedName): ?>
                                    <div class="small text-muted mt-1">
                                        Stamped: <?php echo e($stampedName); ?>

                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($step->approved_at): ?>
                                    <span class="badge bg-success text-light">Approved</span>
                                <?php elseif($step->rejected_at): ?>
                                    <span class="badge bg-danger text-light">Rejected</span>
                                <?php elseif($currentStep && (int) $currentStep->id === (int) $step->id): ?>
                                    <span class="badge bg-warning text-dark">Pending Approval</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary text-light">Waiting</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($step->approved_at || $step->rejected_at): ?>
                                    <?php echo e($stampedDate ?: ($step->approved_at ?? $step->rejected_at ?? '-')); ?>

                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            <?php endif; ?>

        </div>
    </div>

    
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
                <form action="<?php echo e(route('reports.docx-import.upload', $report)); ?>" method="POST" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
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

    // Fill/download TOC map (Word/Python). Prefer these over soft A4 estimate so view matches DOCX.
    window.__docxStoredTocPages = <?php echo json_encode($docxTocPageMap ?? ['by_index' => [], 'by_sec' => [], 'max' => 0]) ?>;

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

    function previewPages() {
        return Array.from(wrap.querySelectorAll('.docx-preview .docx-a4-page, .docx-a4-page'));
    }

    function updatePageNumbers() {
        const pages = previewPages();
        const storedMax = parseInt(String((window.__docxStoredTocPages && window.__docxStoredTocPages.max) || 0), 10) || 0;
        // Prefer live soft sheet count after reflow; never show fewer than Word/fill TOC max.
        const total = Math.max(pages.length, storedMax);
        pages.forEach(function (page, idx) {
            const num = idx + 1;
            page.id = 'docxPage' + num;
            page.setAttribute('data-page-number', String(num));
            page.querySelectorAll('.current-page-num').forEach(function (el) { el.textContent = String(num); });
            page.querySelectorAll('.total-pages-count').forEach(function (el) { el.textContent = String(total); });
            page.querySelectorAll('.docx-page-official-header td').forEach(function (td) {
                const text = (td.textContent || '').replace(/\s+/g, ' ').trim();
                if (!/page\s*\d+\s*of\s*\d+/i.test(text)) return;
                const keepLabel = /page\s*no\.?/i.test(text);
                // Always rewrite so total tracks live page count (old "of 22" must not stick).
                td.innerHTML = (keepLabel ? 'PAGE NO.<br>' : '')
                    + 'Page <span class="current-page-num">' + num
                    + '</span> of <span class="total-pages-count">' + total + '</span>';
            });
            const footer = page.querySelector('.docx-page-official-footer');
            if (footer) {
                const fText = (footer.textContent || '').replace(/\s+/g, ' ').trim();
                if (/page\s*\d+\s*of\s*\d+/i.test(fText)) {
                    footer.innerHTML = 'Page <span class="current-page-num">' + num
                        + '</span> of <span class="total-pages-count">' + total + '</span>';
                }
            }
        });
        wrap.querySelectorAll('.docx-page-break-badge').forEach(function (badge, idx) {
            badge.innerHTML = '<i class="fas fa-file-alt mr-1"></i> Page ' + (idx + 2);
        });
    }

    /**
     * Python preview bakes Word soft page-breaks (~22 sheets) and packs later
     * sections into one overflowing sheet (attachments clipped by overflow:hidden).
     * Collapse into one flow first, then soft-paginate to match download length.
     */
    function collapseHardPageBreaks() {
        const pages = previewPages();
        if (pages.length <= 1) return;
        const first = pages[0];
        const firstBody = first.querySelector('.docx-page-body');
        if (!firstBody) return;
        for (let i = 1; i < pages.length; i++) {
            const body = pages[i].querySelector('.docx-page-body');
            if (body) {
                while (body.firstChild) {
                    firstBody.appendChild(body.firstChild);
                }
            }
            const prev = pages[i].previousElementSibling;
            if (prev && prev.classList.contains('docx-page-break-indicator')) {
                prev.remove();
            }
            pages[i].remove();
        }
    }

    function continuationPage(sourcePage) {
        const existing = followingPage(sourcePage);
        // Reuse only an empty continuation sheet; never dump overflow onto a full later page.
        if (existing) {
            const existingBody = existing.querySelector('.docx-page-body');
            const empty = existingBody && !existingBody.children.length;
            if (empty) return existing;
        }
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
        // Insert immediately after the source page so document order stays correct.
        if (existing) {
            const prev = existing.previousElementSibling;
            if (prev && prev.classList.contains('docx-page-break-indicator')) {
                prev.before(divider);
                divider.after(page);
            } else {
                existing.before(divider);
                divider.after(page);
            }
        } else {
            sourcePage.after(divider);
            divider.after(page);
        }
        updatePageNumbers();
        return page;
    }

    // Att08: clone full 2-row header (Calibration details + Done On/Due On), not only rows[0].
    function leadingHeaderRowCount(sourceTable) {
        if (!sourceTable || !sourceTable.rows.length) return 0;
        const row0 = sourceTable.rows[0];
        // Always keep at least the first row (previous show.blade behavior).
        let span = 1;
        Array.from(row0.cells).forEach(function (cell) {
            const rs = parseInt(cell.getAttribute('rowspan') || '1', 10);
            if (!isNaN(rs) && rs > span) span = rs;
        });
        if (span < 2 && sourceTable.rows.length > 1) {
            const row1 = sourceTable.rows[1];
            const text = (row1.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
            if (text.indexOf('done on') !== -1 || text.indexOf('due on') !== -1) span = 2;
        }
        const marked = parseInt(sourceTable.getAttribute('data-header-row-count') || '0', 10);
        if (!isNaN(marked) && marked > span) span = marked;
        return Math.max(1, Math.min(span, 3, sourceTable.rows.length - 1 || 1));
    }

    function appendClonedHeaderRows(targetTable, sourceTable) {
        const n = leadingHeaderRowCount(sourceTable);
        for (let i = 0; i < n; i++) {
            const src = sourceTable.rows[i];
            if (!src) break;
            const clone = src.cloneNode(true);
            clone.setAttribute('data-cont-header', '1');
            clone.querySelectorAll('input, textarea, select').forEach(function (el) { el.removeAttribute('name'); });
            targetTable.appendChild(clone);
        }
        targetTable.setAttribute('data-header-row-count', String(n));
        return n;
    }

    function lastHeaderRow(table) {
        const n = parseInt(table.getAttribute('data-header-row-count') || '0', 10);
        if (n > 0 && table.rows[n - 1]) return table.rows[n - 1];
        const computed = leadingHeaderRowCount(table);
        if (computed > 0 && table.rows[computed - 1]) return table.rows[computed - 1];
        return table.rows[0] || null;
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
        appendClonedHeaderRows(table, sourceTable);
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
            const header = lastHeaderRow(table);
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
        // scrollHeight catches clipped overflow that getBoundingClientRect can miss in flex layouts.
        if ((pageBody.scrollHeight - pageBody.clientHeight) > 4) return true;
        const box = pageBody.getBoundingClientRect();
        const limit = box.top + Math.max(pageBody.clientHeight || 0, box.height || 0) + 6;
        return pageEdge(pageBody) > limit;
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

    function pageContentUsed(pageBody) {
        if (!pageBody) return 0;
        const last = pageBody.lastElementChild;
        if (!last) return 0;
        const top = pageBody.getBoundingClientRect().top;
        return Math.max(0, pageEdge(pageBody) - top);
    }

    function pageFreeSpace(pageBody) {
        if (!pageBody) return 0;
        const box = pageBody.getBoundingClientRect();
        const height = Math.max(pageBody.clientHeight || 0, box.height || 0);
        if (height < 40) return 0;
        return height - pageContentUsed(pageBody) - 8;
    }

    function pageIsOverfull(pageBody) {
        if (!pageBody) return false;
        if ((pageBody.scrollHeight - pageBody.clientHeight) > 2) return true;
        const box = pageBody.getBoundingClientRect();
        const limit = box.top + Math.max(pageBody.clientHeight || 0, box.height || 0) - 2;
        return pageEdge(pageBody) > limit;
    }

    function rowLabel(row) {
        return Array.from(row.cells || []).map(function (cell) {
            return (cell.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
        }).join('|');
    }

    function pageHasTocCard(page) {
        return !!(page && page.querySelector && page.querySelector('.docx-page-body .docx-toc-card, .docx-page-body .docx-toc-line'));
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
        // Att08: skip full 2-row header; first pullable data row is after Done On/Due On.
        const hdrN = leadingHeaderRowCount(sourceTable);
        const row = sourceTable.rows[hdrN] || null;
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
        if (!same && sourceTable.offsetHeight + 6 <= pageFreeSpace(body)) {
            return pullBlockBack(body, sourceTable.parentNode, sourceTable);
        }
        let target = same ? last : null;
        let created = false;
        if (!target) {
            target = document.createElement('table');
            target.className = sourceTable.className;
            const tIdx = sourceTable.getAttribute('data-table-index') || '';
            if (tIdx) target.setAttribute('data-table-index', tIdx);
            appendClonedHeaderRows(target, sourceTable);
            body.appendChild(target);
            created = true;
        }
        const marker = row.nextSibling;
        const hostHeader = lastHeaderRow(target);
        if (hostHeader) hostHeader.after(row);
        else target.appendChild(row);
        if (pageIsOverfull(body) || pageFreeSpace(body) < -4) {
            if (marker) sourceTable.insertBefore(row, marker);
            else sourceTable.appendChild(row);
            if (created) target.remove();
            return false;
        }
        const leftoverHead = sourceTable.rows.length <= hdrN && !sourceTable.querySelector('[name]');
        if (!sourceTable.rows.length || leftoverHead) sourceTable.remove();
        return true;
    }

    function pullOneBack(page) {
        const body = page.querySelector('.docx-page-body');
        const next = followingPage(page);
        if (!body || !next || pageFreeSpace(body) < 36) return false;
        const nextBody = next.querySelector('.docx-page-body');
        const first = nextBody && nextBody.firstElementChild;
        if (!first) return false;
        // Never pull protocol body onto the Table of Contents page.
        if (pageHasTocCard(page) && !(first.classList && first.classList.contains('docx-toc-card'))) {
            return false;
        }
        if (first.matches('table.docx-table') && !first.classList.contains('header-table')) {
            return pullTableRowBack(body, first);
        }
        if (first.classList.contains('docx-toc-card')) {
            const last = body.lastElementChild;
            if (last && last.classList.contains('docx-toc-card')) {
                if (first.offsetHeight + 6 <= pageFreeSpace(body)) {
                    return pullBlockBack(body, nextBody, first);
                }
                const line = first.querySelector(':scope > .docx-toc-line');
                if (!line) {
                    first.remove();
                    return true;
                }
                if (line.offsetHeight + 6 > pageFreeSpace(body)) return false;
                const marker = line.nextSibling;
                last.appendChild(line);
                if (pageIsOverfull(body)) {
                    if (marker) first.insertBefore(line, marker);
                    else first.appendChild(line);
                    return false;
                }
                if (!first.querySelector('.docx-toc-line')) first.remove();
                return true;
            }
        }
        if (first.offsetHeight + 6 <= pageFreeSpace(body)) {
            return pullBlockBack(body, nextBody, first);
        }
        return false;
    }

    function moveBodyOffTocPages() {
        Array.from(wrap.querySelectorAll('.docx-preview .docx-a4-page')).forEach(function (page) {
            if (!pageHasTocCard(page)) return;
            const body = page.querySelector('.docx-page-body');
            if (!body) return;
            const movers = Array.from(body.children).filter(function (el) {
                if (el.classList && el.classList.contains('docx-toc-card')) return false;
                if (el.querySelector && el.querySelector('.docx-toc-line, .docx-toc-card')) return false;
                return true;
            });
            if (!movers.length) return;
            const next = followingPage(page) || continuationPage(page);
            const nextBody = next.querySelector('.docx-page-body');
            const frag = document.createDocumentFragment();
            movers.forEach(function (el) { frag.appendChild(el); });
            nextBody.insertBefore(frag, nextBody.firstChild);
        });
    }

    function fillPageGaps() {
        let page = wrap.querySelector('.docx-preview .docx-a4-page');
        let pulls = 0;
        while (page && pulls < 1200) {
            const next = followingPage(page);
            if (!next) break;
            if (pageFreeSpace(page.querySelector('.docx-page-body')) < 36 || !pullOneBack(page)) {
                page = next;
                continue;
            }
            pulls++;
            dropEmptySheet(next);
        }
    }

    function headingTextOf(el) {
        if (!el) return '';
        const prefixEl = el.querySelector && el.querySelector('.docx-num-prefix');
        const prefix = prefixEl ? (prefixEl.textContent || '').replace(/\s+/g, ' ').trim() : '';
        const fromData = (el.dataset && el.dataset.headingText || '').replace(/\s+/g, ' ').trim();
        let fromDom = (el.textContent || '').replace(/\s+/g, ' ').trim();
        if (fromDom.length > 180 && fromData) fromDom = fromData;
        if (prefix && !new RegExp('^' + prefix.replace(/\./g, '\\.') + '\\b').test(fromDom)) {
            return (prefix + ' ' + (fromData || fromDom)).replace(/\s+/g, ' ').trim();
        }
        if (/\d+(?:\.\d+)/.test(fromDom)) return fromDom;
        if (fromData && fromDom && fromData.toUpperCase() !== fromDom.toUpperCase()) {
            return (fromData + ' ' + fromDom).replace(/\s+/g, ' ').trim();
        }
        return fromDom || fromData;
    }

    function pageNumberFromSheet(page, pages) {
        if (!page) return 0;
        const stamped = page.querySelector(
            '.docx-page-official-header .current-page-num, .docx-page-official-footer .current-page-num, .current-page-num'
        );
        if (stamped) {
            const n = parseInt((stamped.textContent || '').replace(/\D+/g, ''), 10);
            if (!isNaN(n) && n > 0) return n;
        }
        const attr = parseInt(page.getAttribute('data-page-number') || '', 10);
        if (!isNaN(attr) && attr > 0) return attr;
        const list = pages || Array.from(wrap.querySelectorAll('.docx-a4-page'));
        const index = list.indexOf(page);
        return index >= 0 ? (index + 1) : 0;
    }

    function realPageNumberOf(el) {
        if (!el || !el.closest) return 0;
        const pages = Array.from(wrap.querySelectorAll('.docx-a4-page'));
        // Owning A4 sheet stamp is ground truth (Revision History on "Page 18 of 19").
        const domPage = el.closest('.docx-a4-page');
        if (domPage && el.closest('.docx-page-body')) {
            const domNum = pageNumberFromSheet(domPage, pages);
            if (domNum > 0) return domNum;
        }
        if (pages.length) {
            const rect = el.getBoundingClientRect();
            const y = rect.top + Math.min(8, Math.max(1, rect.height / 2));
            let best = null;
            let bestEdge = -1;
            for (let i = 0; i < pages.length; i++) {
                const box = pages[i].getBoundingClientRect();
                if (y < box.top - 2 || y > box.bottom + 2) continue;
                const edge = Math.min(y - box.top, box.bottom - y);
                if (edge > bestEdge) {
                    bestEdge = edge;
                    best = pages[i];
                }
            }
            if (best) return pageNumberFromSheet(best, pages);
        }
        return pageNumberFromSheet(domPage, pages);
    }

    function extractSecNum(text) {
        const m = String(text || '').trim().match(/^(\d+(?:\.\d+)+)\b/);
        return m ? m[1] : '';
    }

    function collectReadonlyTocPageNodes() {
        const nodes = Array.from(wrap.querySelectorAll('.docx-toc-page-input, .docx-toc-line .toc-page'));
        if (nodes.length) return nodes;
        // Fallback: edit→readonly conversion left plain numeric spans without .toc-page.
        return Array.from(wrap.querySelectorAll('.docx-toc-line')).map(function (line) {
            const spans = Array.from(line.querySelectorAll('span.docx-readonly-value, span'));
            for (let i = spans.length - 1; i >= 0; i--) {
                const txt = (spans[i].textContent || '').replace(/\s+/g, '').trim();
                if (/^\d{1,3}$/.test(txt)) {
                    spans[i].classList.add('toc-page');
                    return spans[i];
                }
            }
            return null;
        }).filter(Boolean);
    }

    function applyStoredTocPageNumbers() {
        const stored = window.__docxStoredTocPages || {};
        const byIndex = stored.by_index || {};
        const bySec = stored.by_sec || {};
        const keys = Object.keys(byIndex);
        if (!keys.length && !Object.keys(bySec).length) return false;

        const lines = Array.from(wrap.querySelectorAll('.docx-toc-line'));
        if (!lines.length) return false;

        let applied = 0;
        lines.forEach(function (line, idx) {
            let page = byIndex[idx] || byIndex[String(idx)] || '';
            if (!page) {
                const sec = (line.getAttribute('data-toc-sec') || '').trim();
                if (sec && bySec[sec]) page = bySec[sec];
            }
            if (!page) return;
            const node = line.querySelector('.docx-toc-page-input, .toc-page')
                || collectReadonlyTocPageNodes().find(function (n) { return line.contains(n); });
            if (!node) return;
            if (node.tagName === 'INPUT') node.value = String(page);
            else node.textContent = String(page);
            node.setAttribute('data-toc-source', 'fill');
            applied++;
        });
        return applied > 0;
    }

    function syncReadonlyTocPageNumbers() {
        const softCount = wrap.querySelectorAll('.docx-a4-page').length;
        const storedMax = parseInt(String((window.__docxStoredTocPages && window.__docxStoredTocPages.max) || 0), 10) || 0;
        // Use fill/download TOC when it is at least as large as soft A4 sheets (Word truth).
        // Template-default TOC (often ~17) stays below soft count so live soft sync can recalculate.
        if (storedMax > 0 && storedMax >= softCount && applyStoredTocPageNumbers()) {
            return;
        }

        const tocNodes = collectReadonlyTocPageNodes();
        if (!tocNodes.length) return;

        const totalPages = Math.max(softCount, storedMax);
        const anchors = [];
        const seen = new Set();
        const sectionPageMap = {};

        function isTocContext(el) {
            return !!(el && el.closest && (
                el.closest('.docx-toc-card')
                || el.closest('.docx-toc-line')
                || el.closest('.docx-page-official-header, .docx-page-official-footer, header, footer')
            ));
        }

        function pushAnchor(secNum, el, text) {
            if (!secNum || !el || isTocContext(el)) return;
            const pageNo = realPageNumberOf(el);
            const pageEl = el.closest('.docx-a4-page');
            if (pageEl && pageEl.querySelector('.docx-page-body .docx-toc-card, .docx-page-body .docx-toc-line')) {
                return;
            }
            if (pageNo <= 1 && /^\d+\.0$/.test(secNum)) {
                return;
            }
            const flat = String(text || '').replace(/\s+/g, ' ').trim();
            const pageKey = secNum + '::' + pageNo + '::' + flat.slice(0, 60);
            if (seen.has(pageKey)) return;
            seen.add(pageKey);
            const anchor = {
                secNum: secNum,
                el: el,
                text: flat,
                page: pageNo
            };
            anchors.push(anchor);
            if (/^\d+\.0$/.test(secNum) && pageNo > 0) {
                const prev = sectionPageMap[secNum];
                const titled = /^\d+\.0\s+\S/i.test(flat);
                if (!prev || pageNo > prev.page || (pageNo === prev.page && titled)) {
                    sectionPageMap[secNum] = anchor;
                }
            }
        }

        Array.from(wrap.querySelectorAll('.docx-heading-target, h1, h2, h3, h4, h5')).forEach(function (el) {
            const text = headingTextOf(el);
            if (text.length > 160) return;
            pushAnchor(extractSecNum(text), el, text);
        });

        Array.from(wrap.querySelectorAll('.docx-num-prefix')).forEach(function (prefixEl) {
            const sec = (prefixEl.textContent || '').replace(/\s+/g, ' ').trim();
            if (!/^\d+(?:\.\d+)+$/.test(sec)) return;
            if (!/^\d+\.0$/.test(sec)) return;
            const host = prefixEl.closest(
                'p.docx-para, .docx-heading-target, .docx-para-edit-wrapper, h1, h2, h3, h4, h5, td.docx-cell, th.docx-cell'
            ) || prefixEl.parentElement;
            if (!host || isTocContext(host)) return;
            if (host.closest && host.closest('table.docx-table') && !(host.classList && host.classList.contains('docx-heading-target'))) {
                return;
            }
            const text = headingTextOf(host);
            if (text.length > 160 && !(host.classList && host.classList.contains('docx-heading-target'))) return;
            pushAnchor(sec, host, text || sec);
        });

        Array.from(wrap.querySelectorAll('.docx-page-body p.docx-para, .docx-page-body .docx-para-edit-wrapper')).forEach(function (el) {
            if (el.closest('table.docx-table')) return;
            const text = headingTextOf(el);
            if (text.length > 140) return;
            const sec = extractSecNum(text);
            if (!sec || !/^\d+\.0$/.test(sec)) return;
            if (!/^\d+\.0\s+\S/.test(text)) return;
            pushAnchor(sec, el, text);
        });

        function normalizeTitle(s) {
            return String(s || '').toUpperCase().replace(/[\.\:\-_]+/g, ' ').replace(/\s+/g, ' ').trim();
        }

        function scoreAnchorForToc(anchor, words) {
            const raw = anchor.text || '';
            const txt = normalizeTitle(raw);
            let score = 0;
            if (/^\d+\.0\s+\S/i.test(raw)) score += 50;
            if (anchor.el && anchor.el.classList && anchor.el.classList.contains('docx-heading-target')) {
                score += 35;
            }
            if (words && words.length) {
                const hit = words.filter(function (kw) { return txt.includes(kw); }).length;
                score += hit * 30;
                if (hit === 0) score -= 40;
            }
            if (raw.length <= 90) score += 12;
            if (raw.length <= 50) score += 8;
            if (raw.length < 8) score -= 20;
            return score;
        }

        function findAnchor(secNum, words) {
            const major = secNum && /^\d+$/.test(secNum) ? (secNum + '.0') : secNum;
            if (major && sectionPageMap[major]) {
                const mapped = sectionPageMap[major];
                if (words && words.length) {
                    const txt = normalizeTitle(mapped.text);
                    const hit = words.some(function (kw) { return txt.includes(kw); });
                    if (hit || /^\d+\.0\s+\S/i.test(mapped.text)) {
                        return mapped;
                    }
                } else {
                    return mapped;
                }
            }

            const want = [];
            if (secNum) {
                anchors.forEach(function (a) {
                    if (a.secNum === secNum) want.push(a);
                });
                if (!want.length && major) {
                    anchors.forEach(function (a) {
                        if (a.secNum === major) want.push(a);
                    });
                }
            }
            if (want.length) {
                let pool = want;
                if (words && words.length) {
                    const titled = want.filter(function (a) {
                        const txt = normalizeTitle(a.text);
                        return words.some(function (kw) { return txt.includes(kw); });
                    });
                    if (titled.length) pool = titled;
                }
                let best = pool[0];
                let bestScore = scoreAnchorForToc(best, words);
                let bestPage = realPageNumberOf(best.el) || best.page || 0;
                for (let i = 1; i < pool.length; i++) {
                    const score = scoreAnchorForToc(pool[i], words);
                    const page = realPageNumberOf(pool[i].el) || pool[i].page || 0;
                    if (page > bestPage && score >= bestScore - 40) {
                        best = pool[i];
                        bestScore = score;
                        bestPage = page;
                    } else if (score > bestScore && page >= bestPage) {
                        best = pool[i];
                        bestScore = score;
                        bestPage = page;
                    } else if (score > bestScore + 40) {
                        best = pool[i];
                        bestScore = score;
                        bestPage = page;
                    }
                }
                return best;
            }
            if (!words.length) return null;
            let best = null;
            let bestHit = 0;
            let bestPage = 0;
            anchors.forEach(function (a) {
                if (a.text.length > 120) return;
                const txt = normalizeTitle(a.text);
                const hit = words.filter(function (kw) { return txt.includes(kw); }).length;
                if (hit < Math.min(1, words.length)) return;
                const page = realPageNumberOf(a.el) || a.page || 0;
                if (hit > bestHit || (hit === bestHit && page > bestPage)) {
                    bestHit = hit;
                    bestPage = page;
                    best = a;
                }
            });
            return best;
        }

        let lastPage = 0;
        tocNodes.forEach(function (node) {
            const line = node.closest('.docx-toc-line');
            const secNum = ((node.dataset && node.dataset.tocSec) || (line && line.dataset.tocSec) || '').trim();
            const secTitle = ((node.dataset && node.dataset.tocTitle) || (line && line.dataset.tocTitle) || '').trim().toUpperCase();
            const cleanTitle = normalizeTitle(secTitle.replace(/^\d+(?:\.\d+)*\s*/, ''));
            const words = cleanTitle.split(/\s+/).filter(function (w) {
                return w.length > 2 && !['AND', 'THE', 'FOR', 'ANY', 'OF'].includes(w);
            });
            const anchor = findAnchor(secNum, words);
            if (!anchor) return;
            let pageNo = realPageNumberOf(anchor.el) || anchor.page || 0;
            if (pageNo < 1) return;
            if (totalPages > 0 && pageNo > totalPages) pageNo = totalPages;
            if (lastPage > 0 && pageNo < lastPage) pageNo = lastPage;
            lastPage = pageNo;
            if (node.tagName === 'INPUT') node.value = String(pageNo);
            else node.textContent = String(pageNo);
        });
    }

    window.paginateReadonlyPreview = function () {
        const first = wrap.querySelector('.docx-preview .docx-a4-page');
        if (!first) return;
        const firstBody = first.querySelector('.docx-page-body');
        if (!firstBody || firstBody.clientHeight < 80) return;

        // One-time: flatten Python hard breaks so attachment/trailing sections are not clipped.
        if (!wrap.getAttribute('data-docx-flow-collapsed')) {
            collapseHardPageBreaks();
            wrap.setAttribute('data-docx-flow-collapsed', '1');
        }

        moveBodyOffTocPages();

        let moves = 0;
        const maxMoves = 2500;
        // Keep spilling until every sheet fits (attachments + sections 12–16 become their own pages).
        for (let pass = 0; pass < 8 && moves < maxMoves; pass++) {
            let page = wrap.querySelector('.docx-preview .docx-a4-page');
            let spilledThisPass = 0;
            while (page && moves < maxMoves) {
                const pageBody = page.querySelector('.docx-page-body');
                if (!contentOverflows(pageBody) && !pageIsOverfull(pageBody)) {
                    page = followingPage(page);
                    continue;
                }
                const before = pageBody.scrollHeight;
                const beforeLast = pageBody.lastElementChild;
                const beforeRow = beforeLast && beforeLast.rows
                    ? beforeLast.rows[beforeLast.rows.length - 1]
                    : null;
                if (!spillOne(page)) {
                    // Force-move last block onto a fresh sheet when normal spill gives up.
                    if (beforeLast && pageBody.childElementCount > 1) {
                        const nextBody = continuationPage(page).querySelector('.docx-page-body');
                        nextBody.insertBefore(beforeLast, nextBody.firstChild);
                        moves++;
                        spilledThisPass++;
                        continue;
                    }
                    page = followingPage(page);
                    continue;
                }
                moves++;
                spilledThisPass++;
                const rowStillHere = beforeRow && pageBody.contains(beforeRow);
                if ((contentOverflows(pageBody) || pageIsOverfull(pageBody))
                    && rowStillHere
                    && pageBody.scrollHeight >= before) {
                    page = followingPage(page);
                }
            }
            if (!spilledThisPass) break;
        }

        // Light pull-back only — avoid re-packing clipped overflow onto earlier sheets.
        fillPageGaps();
        moveBodyOffTocPages();
        wrap.querySelectorAll('.docx-a4-page').forEach(function (sheet) {
            const sheetBody = sheet.querySelector('.docx-page-body');
            const tail = sheetBody && sheetBody.lastElementChild;
            if (!tail || !tail.matches('table.docx-table') || tail.rows.length > 1) return;
            const tIdx = tail.getAttribute('data-table-index') || '';
            const next = followingPage(sheet);
            const moved = next ? next.querySelector('table.docx-table[data-table-index="' + tIdx + '"]') : null;
            if (moved && moved.rows.length > 1 && !tail.querySelector('[name]')) tail.remove();
        });
        Array.from(wrap.querySelectorAll('.docx-a4-page')).forEach(function (sheet) {
            dropEmptySheet(sheet);
        });
        updatePageNumbers();
        syncReadonlyTocPageNumbers();
        void wrap.offsetHeight;
        updatePageNumbers();
        syncReadonlyTocPageNumbers();
    };

    window.paginateReadonlyPreview();
    setTimeout(window.paginateReadonlyPreview, 400);
    setTimeout(window.paginateReadonlyPreview, 1400);
    setTimeout(window.paginateReadonlyPreview, 2800);
});
</script>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH D:\lupin\latest Lupin2\valiflow-main\resources\views/reports/show.blade.php ENDPATH**/ ?>