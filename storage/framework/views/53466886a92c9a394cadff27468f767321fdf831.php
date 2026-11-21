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
        gap: 12px;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-page-official-footer .docx-footer-page {
        display: inline-block;
        white-space: nowrap;
        flex: 0 0 auto;
        letter-spacing: normal;
        word-spacing: normal;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-page-official-footer .docx-footer-annex {
        flex: 1 1 auto;
        min-width: 0;
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
    /* Prepared/Checked/Approved By: keep department labels (Head - Quality Assurance)
       in col_0 — never let Name & Designation overflow into that column. */
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table.docx-dept-signature-table td,
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table.docx-dept-signature-table th {
        overflow: hidden !important;
        text-overflow: clip !important;
        vertical-align: middle;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table.docx-dept-signature-table td .docx-readonly-value,
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table.docx-dept-signature-table th .docx-readonly-value {
        display: block;
        max-width: 100%;
        overflow: hidden;
        white-space: normal;
        word-break: break-word;
        overflow-wrap: anywhere;
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
    /* Bold only real column headers — never continuation / Description data rows. */
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table:not(.header-table) tr:first-child td {
        font-weight: normal;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table:not(.header-table) tr[data-row-index]:not([data-row-index="0"]) td,
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table:not(.header-table) tr[data-row-index]:not([data-row-index="0"]) td .docx-readonly-value {
        font-weight: normal !important;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table:not(.header-table) tr:first-child th,
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table:not(.header-table) tr:first-child td.docx-table-header,
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table:not(.header-table) tr[data-row-index="0"] > th,
    body.premium-app #reportReadonlyBody .docx-preview-wrapper table.docx-table:not(.header-table) tr[data-row-index="0"] > td {
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
    /* Att14 TOC: Word-like leaders, one section per line, right-aligned pages */
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-toc-card {
        background: #ffffff;
        border: 1px solid #94a3b8;
        border-radius: 0;
        padding: 12px 16px 14px;
        margin: 12px 0 18px;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-toc-card .docx-toc-heading {
        font-weight: 700;
        font-size: 14px;
        text-align: center;
        letter-spacing: 0.04em;
        margin: 0 0 10px;
        color: #0f172a;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-toc-line {
        display: flex !important;
        align-items: baseline !important;
        width: 100% !important;
        padding: 4px 0 !important;
        margin: 0 !important;
        line-height: 1.55 !important;
        font-size: 13px !important;
        color: #1e293b !important;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-toc-line .toc-title {
        flex: 0 1 auto !important;
        max-width: 72% !important;
        font-weight: 500 !important;
        white-space: nowrap !important;
        overflow: hidden !important;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-toc-line .toc-dots {
        flex: 1 1 auto !important;
        min-width: 28px !important;
        margin: 0 8px !important;
        border: none !important;
        overflow: hidden !important;
        white-space: nowrap !important;
        letter-spacing: 1px !important;
        color: #475569 !important;
        font-size: 12px !important;
        line-height: 1.2 !important;
        align-self: center !important;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-para-bold-title {
        font-weight: 700 !important;
    }
    body.premium-app #reportReadonlyBody .docx-preview-wrapper .docx-toc-line .toc-page {
        flex: 0 0 auto !important;
        font-weight: 600 !important;
        text-align: right !important;
        min-width: 1.5em;
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
                // Keep page label in ONE flex child — bare "Page 1 of 20" nodes
                // under justify-content:space-between spread far apart.
                let pageSpan = footer.querySelector('.docx-footer-page');
                const annex = footer.querySelector('.docx-footer-annex');
                const pageHtml = 'Page <span class="current-page-num">' + num
                    + '</span> of <span class="total-pages-count">' + total + '</span>';
                if (pageSpan) {
                    pageSpan.innerHTML = pageHtml;
                } else if (annex) {
                    pageSpan = document.createElement('span');
                    pageSpan.className = 'docx-footer-page';
                    pageSpan.innerHTML = pageHtml;
                    footer.appendChild(pageSpan);
                } else {
                    const fText = (footer.textContent || '').replace(/\s+/g, ' ').trim();
                    if (/page\s*\d+\s*of\s*\d+/i.test(fText)) {
                        footer.innerHTML = '<span class="docx-footer-page">' + pageHtml + '</span>';
                    }
                }
            }
        });
        wrap.querySelectorAll('.docx-page-break-badge').forEach(function (badge, idx) {
            badge.innerHTML = '<i class="fas fa-file-alt mr-1"></i> Page ' + (idx + 2);
        });
    }

    /** AHU requal / HVAC: keep Python sheet structure — full collapse clipped 9.x–16.0 to ~10 pages. */
    function isRequalHvacPreview() {
        if (!wrap) return false;
        if (
            wrap.querySelector('table.docx-table[data-table-index="12"]')
            || wrap.querySelector('table.docx-table[data-table-index="13"]')
            || wrap.querySelector('table.docx-table[data-table-index="14"]')
        ) {
            return true;
        }
        const sample = ((wrap.innerText || wrap.textContent || '')).slice(0, 12000);
        return /\b9\.0\s+REQUALIFICATION\s+CHECKS\b/i.test(sample)
            || /\bGENERAL\s+CHECKS\s*:\s*VERIFICATION\s+OF\s+MAJOR\s+COMPONENTS\b/i.test(sample);
    }

    /**
     * Python preview bakes Word soft page-breaks into many sparse sheets (7.0 SOP
     * as 5 mini-tables, 8.1 heading without its table). Always flatten into one
     * flow once, then soft-spill. Inventory restore + boundary-safe pack keep
     * every data row (9.2 / 9.3 / Connected Accessories / 8.1) visible.
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

    function rowDedupeFingerprintShow(row) {
        if (!row) return '';
        return Array.from(row.cells || []).map(function (cell) {
            return ((cell.innerText || cell.textContent) || '').replace(/\s+/g, ' ').trim();
        }).join(' | ').toLowerCase().slice(0, 240);
    }

    function captureTableRowInventoryShow() {
        const items = [];
        wrap.querySelectorAll('.docx-preview table.docx-table[data-table-index]:not(.header-table)').forEach(function (table) {
            const tIdx = table.getAttribute('data-table-index') || '';
            if (!tIdx) return;
            Array.from(table.rows || []).forEach(function (row) {
                const rIdx = row.getAttribute('data-row-index');
                if (rIdx === null || rIdx === '') return;
                if (row.getAttribute('data-cont-header') === '1') return;
                items.push({
                    tIdx: tIdx,
                    rIdx: parseInt(rIdx, 10),
                    row: row,
                    // Detached clone survives pack/drop so restore can revive lost CA / 9.x rows.
                    backup: row.cloneNode(true),
                    fingerprint: rowDedupeFingerprintShow(row)
                });
            });
        });
        return items;
    }

    function insertRowBeforeShow(table, row, ref) {
        const parent = (ref && ref.parentNode) || (table.tBodies && table.tBodies[0]) || table;
        parent.insertBefore(row, ref || null);
    }

    function restoreMissingTableRowsShow(inventory) {
        if (!inventory || !inventory.length) return 0;
        let restored = 0;
        const byTable = {};
        inventory.forEach(function (item) {
            (byTable[item.tIdx] = byTable[item.tIdx] || []).push(item);
        });
        Object.keys(byTable).forEach(function (tIdx) {
            const items = byTable[tIdx].slice().sort(function (a, b) { return a.rIdx - b.rIdx; });
            const fragments = Array.from(
                wrap.querySelectorAll('.docx-preview table.docx-table[data-table-index="' + tIdx + '"]')
            ).filter(function (t) { return !t.classList.contains('header-table'); });
            if (!fragments.length) return;

            items.forEach(function (item) {
                if (item.row && document.body.contains(item.row)) return;
                if (!isNaN(item.rIdx)) {
                    const idxLive = fragments.some(function (frag) {
                        return Array.from(frag.rows || []).some(function (r) {
                            return parseInt(r.getAttribute('data-row-index') || '-1', 10) === item.rIdx;
                        });
                    });
                    if (idxLive) return;
                }
                if (item.fingerprint) {
                    const fpLive = fragments.some(function (frag) {
                        return Array.from(frag.rows || []).some(function (r) {
                            return rowDedupeFingerprintShow(r) === item.fingerprint;
                        });
                    });
                    if (fpLive) return;
                }
                if (item.rIdx === 0) return;

                let host = null;
                let insertBefore = null;
                const prevIdx = item.rIdx - 1;
                const nextIdx = item.rIdx + 1;
                for (let fi = 0; fi < fragments.length; fi++) {
                    const frag = fragments[fi];
                    if (!document.body.contains(frag)) continue;
                    const rows = Array.from(frag.rows || []);
                    const prev = rows.find(function (r) {
                        return parseInt(r.getAttribute('data-row-index') || '-1', 10) === prevIdx;
                    });
                    const next = rows.find(function (r) {
                        return parseInt(r.getAttribute('data-row-index') || '-1', 10) === nextIdx;
                    });
                    if (prev) {
                        host = frag;
                        insertBefore = prev.nextElementSibling;
                        break;
                    }
                    if (next) {
                        host = frag;
                        insertBefore = next;
                        break;
                    }
                }
                if (!host) {
                    host = fragments.find(function (f) { return document.body.contains(f); }) || fragments[0];
                    const hdr = lastHeaderRow(host);
                    insertBefore = hdr ? hdr.nextElementSibling : null;
                }
                const live = (item.row && !document.body.contains(item.row))
                    ? item.row
                    : (item.backup ? item.backup.cloneNode(true) : null);
                if (!host || !live) return;
                if (insertBefore && insertBefore.parentNode) {
                    insertRowBeforeShow(host, live, insertBefore);
                } else {
                    const hdr = lastHeaderRow(host);
                    if (hdr) hdr.after(live);
                    else host.appendChild(live);
                }
                item.row = live;
                restored++;
            });
        });
        return restored;
    }

    /** Merge same data-table-index fragments that landed on one A4 sheet (7.0 SOP splits). */
    function mergeSamePageTableFragmentsShow() {
        wrap.querySelectorAll('.docx-preview .docx-a4-page').forEach(function (page) {
            const body = page.querySelector('.docx-page-body');
            if (!body) return;
            const firstByIdx = {};
            Array.from(body.children).forEach(function (el) {
                if (!el.matches || !el.matches('table.docx-table') || el.classList.contains('header-table')) return;
                const idx = el.getAttribute('data-table-index');
                if (idx === null || idx === '') return;
                if (!firstByIdx[idx]) {
                    firstByIdx[idx] = el;
                    return;
                }
                const keep = firstByIdx[idx];
                const keepIndexes = {};
                Array.from(keep.rows || []).forEach(function (r) {
                    const k = r.getAttribute('data-row-index') || '';
                    if (k !== '') keepIndexes[k] = true;
                });
                Array.from(el.rows || []).forEach(function (row) {
                    if (row.querySelector('th') || row.getAttribute('data-cont-header') === '1') return;
                    if (row.getAttribute('data-row-index') === '0') return;
                    const k = row.getAttribute('data-row-index') || '';
                    if (k !== '' && keepIndexes[k]) {
                        row.remove();
                        return;
                    }
                    if (k !== '') keepIndexes[k] = true;
                    keep.appendChild(row);
                });
                el.remove();
            });
        });
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

    function rowPlainLabelShow(row) {
        return ((row && (row.innerText || row.textContent)) || '').replace(/\s+/g, ' ').trim().toLowerCase();
    }

    function rowLooksLikeColumnHeaderShow(row) {
        if (!row) return false;
        if (row.getAttribute('data-cont-header') === '1') return true;
        if (row.querySelector('th, .docx-table-header')) {
            const t = rowPlainLabelShow(row);
            if (
                /^(details|description|connected\s+accessories)\b/.test(t)
                || /\bconnected\s+accessories\b/.test(t)
                || /\binstrument\s+name\b/.test(t)
                || /\bacceptance\s*criteria\b/.test(t)
                || /\bmethodology\b/.test(t)
                || /\bchecks\b/.test(t)
            ) {
                return true;
            }
        }
        const text = rowPlainLabelShow(row);
        if (!text) return false;
        if (/^(details(\s*\|\s*description)?|description)$/.test(text)) return true;
        if (/^details\b/.test(text) && /\bdescription\b/.test(text) && text.length < 40) return true;
        if (/\bconnected\s+accessories\b/.test(text) && /\bdescription\b/.test(text)) return true;
        if (/\binstrument\s+name\b/.test(text) && /\bsr\.?\s*no\b/.test(text)) return true;
        if (/\bacceptance\s*criteria\b/.test(text) && /\bsr\.?\s*no\b/.test(text)) return true;
        if (row.getAttribute('data-row-index') === '0' && text.length < 60) {
            if (
                /^(details|description|connected\s+accessories)\b/.test(text)
                || /\bconnected\s+accessories\b/.test(text)
            ) {
                return true;
            }
        }
        return false;
    }

    function isTableHeaderRowShow(row) {
        if (!row) return false;
        if (row.getAttribute('data-cont-header') === '1') return true;
        if (row.querySelector('th, .docx-table-header')) return true;
        if (rowLooksLikeColumnHeaderShow(row)) return true;
        return row.getAttribute('data-row-index') === '0'
            && !row.querySelector('input:not([type="hidden"]), textarea, select');
    }

    // Att08: clone full 2-row header (Calibration details + Done On/Due On), not only rows[0].
    // Headerless continuations (Connected Accessories / 9.3) must report 0 — never treat
    // the first DATA row as a header (that deleted Heating Coil…Return filter on pack).
    function leadingHeaderRowCount(sourceTable) {
        if (!sourceTable || !sourceTable.rows.length) return 0;
        if (sourceTable.hasAttribute('data-header-row-count')) {
            const marked0 = parseInt(sourceTable.getAttribute('data-header-row-count') || '0', 10);
            if (!isNaN(marked0) && marked0 === 0) return 0;
        }
        let colHdrIdx = -1;
        const scan = Math.min(6, sourceTable.rows.length);
        for (let i = 0; i < scan; i++) {
            if (rowLooksLikeColumnHeaderShow(sourceTable.rows[i])) {
                colHdrIdx = i;
                break;
            }
        }
        if (colHdrIdx >= 0) {
            return Math.max(1, Math.min(colHdrIdx + 1, 3, sourceTable.rows.length));
        }
        const row0 = sourceTable.rows[0];
        const looksHeader = isTableHeaderRowShow(row0);
        if (!looksHeader) return 0;
        let span = 1;
        Array.from(row0.cells).forEach(function (cell) {
            const rs = parseInt(cell.getAttribute('rowspan') || '1', 10);
            if (!isNaN(rs) && rs > span) span = rs;
        });
        if (span < 2 && sourceTable.rows.length > 1) {
            const row1 = sourceTable.rows[1];
            const text = rowPlainLabelShow(row1);
            if (text.indexOf('done on') !== -1 || text.indexOf('due on') !== -1) span = 2;
        }
        const marked = parseInt(sourceTable.getAttribute('data-header-row-count') || '0', 10);
        if (!isNaN(marked) && marked > span) span = marked;
        return Math.max(1, Math.min(span, 3, sourceTable.rows.length - 1 || 1));
    }

    function shouldCloneContinuationHeaders(sourceTable) {
        if (!sourceTable) return false;
        const text = ((sourceTable.innerText || sourceTable.textContent) || '')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();
        if (/\bdone\s+on\b/.test(text) && /\bdue\s+on\b/.test(text)) return true;
        // 8.1 / 8.2: never repeat column headings across soft page breaks.
        if (/\binstrument\s+name\b/.test(text) && /calibrat/.test(text)) return false;
        if (/\bpreventive\s+maintenance\b/.test(text)) return false;
        if (/\bconnected\s+accessories\b/.test(text)) return false;
        const tIdx = sourceTable.getAttribute && sourceTable.getAttribute('data-table-index');
        if (tIdx === '12' || tIdx === '13' || tIdx === '14') return false;
        if (/\bacceptance\s*criteria\b/.test(text) && /\bsr\.?\s*no\b/.test(text)) return false;
        return true;
    }

    function appendClonedHeaderRows(targetTable, sourceTable) {
        if (!shouldCloneContinuationHeaders(sourceTable)) {
            targetTable.setAttribute('data-header-row-count', '0');
            return 0;
        }
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
        if (table.hasAttribute('data-header-row-count')) {
            const n = parseInt(table.getAttribute('data-header-row-count') || '0', 10);
            if (n <= 0) return null;
            if (table.rows[n - 1]) return table.rows[n - 1];
        }
        const computed = leadingHeaderRowCount(table);
        if (computed > 0 && table.rows[computed - 1]) return table.rows[computed - 1];
        // Only fall back to rows[0] when it really is a column header — never a data row.
        if (table.rows[0] && isTableHeaderRowShow(table.rows[0])) return table.rows[0];
        return null;
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

    // HTML rowspan cannot survive moving <tr> to the next A4 sheet — Sr.No /
    // Identification vanish and Acceptance criteria shifts left. Flatten first.
    function expandTableRowspans(table) {
        if (!table || !table.querySelector || !table.querySelector('[rowspan]')) return;
        const rows = Array.from(table.rows || []);
        if (!rows.length) return;
        const matrix = rows.map(function () { return []; });
        rows.forEach(function (row, r) {
            let col = 0;
            Array.from(row.cells).forEach(function (cell) {
                while (matrix[r][col]) col++;
                const rs = Math.max(1, parseInt(cell.getAttribute('rowspan') || '1', 10) || 1);
                const cs = Math.max(1, parseInt(cell.getAttribute('colspan') || '1', 10) || 1);
                for (let dr = 0; dr < rs; dr++) {
                    if (!matrix[r + dr]) continue;
                    for (let dc = 0; dc < cs; dc++) {
                        matrix[r + dr][col + dc] = {
                            cell: cell,
                            isOrigin: dr === 0 && dc === 0,
                            cs: cs,
                            startCol: col
                        };
                    }
                }
                col += cs;
            });
        });
        matrix.forEach(function (slots, r) {
            const row = rows[r];
            if (!row || !slots.length) return;
            const produced = [];
            let c = 0;
            while (c < slots.length) {
                const slot = slots[c];
                if (!slot) {
                    c++;
                    continue;
                }
                if (slot.startCol !== c) {
                    c++;
                    continue;
                }
                if (slot.isOrigin) {
                    slot.cell.removeAttribute('rowspan');
                    produced.push(slot.cell);
                } else {
                    const clone = slot.cell.cloneNode(true);
                    clone.removeAttribute('rowspan');
                    if (slot.cs > 1) clone.setAttribute('colspan', String(slot.cs));
                    else clone.removeAttribute('colspan');
                    produced.push(clone);
                }
                c += slot.cs;
            }
            while (row.firstChild) row.removeChild(row.firstChild);
            produced.forEach(function (el) { row.appendChild(el); });
        });
    }

    function expandAllPreviewRowspans() {
        wrap.querySelectorAll('.docx-preview table.docx-table:not(.header-table)').forEach(expandTableRowspans);
    }

    function isBodySectionBlock(el) {
        if (!el) return false;
        if (el.classList && el.classList.contains('docx-toc-card')) return false;
        const text = ((el.innerText || el.textContent || '')).replace(/\s+/g, ' ').trim();
        // Match "2.0 SCOPE" and Word-export "2.0SCOPE:".
        return /^\d+(?:\.\d+)+\s*\S/.test(text);
    }

    function collectBlocksGluedBeforeTable(table) {
        const glued = [];
        let prev = table ? table.previousElementSibling : null;
        // Only SCOPE/section intro paras — not every preceding <p> (that yanked 1.0/8.x).
        let paraCount = 0;
        while (
            prev
            && !(prev.matches && prev.matches('table.docx-table'))
            && paraCount < 4
            && prev.matches
            && prev.matches('p.docx-para, .docx-para-edit-wrapper')
            && !isBodySectionBlock(prev)
        ) {
            glued.unshift(prev);
            paraCount++;
            prev = prev.previousElementSibling;
        }
        let headingCount = 0;
        while (prev && isBodySectionBlock(prev)) {
            glued.unshift(prev);
            headingCount++;
            prev = prev.previousElementSibling;
            if (headingCount >= 2) break;
        }
        return glued;
    }

    /** Only 2.0 SCOPE Details|Description (Tag No…Area) — never generic small tables. */
    function isCompactKeepTogetherTable(table) {
        if (!table || !table.rows || table.classList.contains('header-table')) return false;
        const rowCount = table.rows.length;
        if (rowCount < 3 || rowCount > 12) return false;
        const labels = Array.from(table.rows).slice(0, Math.min(12, rowCount)).map(function (row) {
            const cell = row.cells && row.cells[0];
            return ((cell && cell.textContent) || '').replace(/\s+/g, ' ').trim().toLowerCase();
        }).join('\n');
        // SCOPE equipment attributes only (Tag No + Plant/Module + MOC/Capacity…).
        const scopeLike = /\btag\.?\s*no\.?\b/.test(labels)
            && (/\bplant\s*\/\s*module\b/.test(labels) || /\bmoc\b/.test(labels));
        const detailsScope = /\bdetails\b/.test(labels)
            && /\b(moc|capacity|elevation|area\s*catering)\b/.test(labels);
        return scopeLike || detailsScope;
    }

    function moveWholeTableWithGlue(page, table) {
        if (!page || !table) return false;
        // Prefer the existing next sheet — creating a fresh page every move
        // caused an endless page-create loop and froze the browser.
        const next = followingPage(page) || continuationPage(page);
        const nextBody = next.querySelector('.docx-page-body');
        if (!nextBody) return false;
        const tIdx = table.getAttribute('data-table-index') || '';
        const existing = tIdx
            ? nextBody.querySelector(':scope > table.docx-table[data-table-index="' + tIdx + '"]')
            : null;
        const glued = collectBlocksGluedBeforeTable(table);
        if (existing && existing !== table) {
            const hdrN = leadingHeaderRowCount(table);
            const dataRows = Array.from(table.rows).slice(hdrN);
            const hostHdrN = leadingHeaderRowCount(existing);
            const firstHostData = existing.rows[hostHdrN] || null;
            dataRows.forEach(function (r) {
                if (firstHostData && existing.contains(firstHostData)) {
                    firstHostData.parentNode.insertBefore(r, firstHostData);
                } else {
                    existing.appendChild(r);
                }
            });
            glued.forEach(function (el) {
                nextBody.insertBefore(el, existing);
            });
            table.remove();
        } else {
            nextBody.insertBefore(table, nextBody.firstChild);
            // Forward insertBefore keeps glued order (8.0 then 8.1). Reverse made 8.1, 8.0.
            glued.forEach(function (el) {
                nextBody.insertBefore(el, table);
            });
        }
        return true;
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
            // 2.0 SCOPE: keep together only when earlier content shares this sheet.
            // If the SCOPE unit is alone and still overfull, row-split — otherwise
            // moveWholeTableWithGlue creates endless new pages and freezes the tab.
            if (isCompactKeepTogetherTable(last)) {
                const glued = collectBlocksGluedBeforeTable(last);
                const onlyUnit = body.childElementCount <= (glued.length + 1);
                if (!onlyUnit) {
                    return moveWholeTableWithGlue(page, last);
                }
            }
            const table = continuationTable(page, last);
            // Bottom-up spill: insert at start of data. appendChild reverses order.
            // Without a cloned header, insert BEFORE the first data row (not after).
            const header = lastHeaderRow(table);
            if (header && header !== row) header.after(row);
            else if (table.rows[0]) table.rows[0].parentNode.insertBefore(row, table.rows[0]);
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
        // Move overflowing last block forward, but never onto a brand-new sheet
        // when the next sheet already exists (that spawned endless pages / freezes).
        const overflowPx = Math.max(0, body.scrollHeight - body.clientHeight);
        if (last.offsetHeight > overflowPx + 48 && body.childElementCount > 1) {
            // Tall block with siblings: still move it — smaller siblings must not stay clipped.
        } else if (last.offsetHeight > overflowPx + 48 && body.childElementCount <= 1) {
            return false;
        }
        const nextPage = followingPage(page) || continuationPage(page);
        const nextBody = nextPage.querySelector('.docx-page-body');
        if (!nextBody) return false;
        if (last.matches && last.matches('table.docx-table')) {
            const glued = collectBlocksGluedBeforeTable(last);
            nextBody.insertBefore(last, nextBody.firstChild);
            // Forward insertBefore keeps section order (8.0 above 8.1, not swapped).
            glued.forEach(function (el) {
                nextBody.insertBefore(el, last);
            });
            return true;
        }
        nextBody.insertBefore(last, nextBody.firstChild);
        return true;
    }

    function contentOverflows(pageBody) {
        if (!pageBody) return false;
        // scrollHeight catches clipped overflow that getBoundingClientRect can miss in flex layouts.
        if ((pageBody.scrollHeight - pageBody.clientHeight) > 4) return true;
        const box = pageBody.getBoundingClientRect();
        const limit = box.top + Math.max(pageBody.clientHeight || 0, box.height || 0) + 6;
        if (pageEdge(pageBody) > limit) return true;
        // Mid-table rows clipped under overflow:hidden (last child may still fit).
        const kids = pageBody.children;
        for (let i = 0; i < kids.length; i++) {
            const el = kids[i];
            if (el.getBoundingClientRect().bottom > limit) return true;
            if (el.rows) {
                for (let r = 0; r < el.rows.length; r++) {
                    if (el.rows[r].getBoundingClientRect().bottom > limit) return true;
                }
            }
        }
        return false;
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
            if (el.matches && el.matches('table.docx-table') && !el.classList.contains('header-table')) {
                const rows = Array.from(el.rows || []);
                const hasDataRow = rows.some(function (r) {
                    const idx = parseInt(r.getAttribute('data-row-index') || '-1', 10);
                    return idx > 0;
                });
                // Never delete a table that still holds real data rows (8.1 / 9.2 / CA).
                if (hasDataRow) return;
                if (rows.length <= 1 && !el.querySelector('[name]')) {
                    const tIdx = el.getAttribute('data-table-index') || '';
                    const next = followingPage(page);
                    const cont = (next && tIdx)
                        ? next.querySelector('.docx-page-body table.docx-table[data-table-index="' + tIdx + '"]')
                        : null;
                    // Only strip orphan header when a continuation already has the data.
                    if (cont && cont !== el && cont.rows && cont.rows.length > 1) {
                        el.remove();
                    }
                }
                return;
            }
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

    /** Connected Accessories / Instrument Name / HVAC checks — do not yank rows back (clips / drops). */
    function isNoPullBackTableShow(table) {
        if (!table || !table.querySelector) return false;
        const tIdx = table.getAttribute && table.getAttribute('data-table-index');
        if (tIdx === '7' || tIdx === '9' || tIdx === '12' || tIdx === '13' || tIdx === '14') return true;
        const text = (table.innerText || table.textContent || '').replace(/\s+/g, ' ').toLowerCase();
        if (/connected\s+accessories/.test(text)) return true;
        if (/instrument\s+name/.test(text) && /calibrat/.test(text)) return true;
        if (/\bacceptance\s*criteria\b/.test(text) && /\bmethodology\b/.test(text)) return true;
        return false;
    }

    function tableHasRealDataRowsShow(table) {
        return Array.from((table && table.rows) || []).some(function (r) {
            if (r.getAttribute('data-cont-header') === '1') return false;
            const idx = parseInt(r.getAttribute('data-row-index') || '-1', 10);
            return idx > 0;
        });
    }

    function pullTableRowBack(body, sourceTable) {
        // Att08: skip full 2-row header; first pullable data row is after Done On/Due On.
        const hdrN = leadingHeaderRowCount(sourceTable);
        const row = sourceTable.rows[hdrN] || null;
        if (!row) {
            // Never delete a fragment that still holds real data (CA / 9.2 / 9.3).
            if (!tableHasRealDataRowsShow(sourceTable) && !sourceTable.querySelector('[name]')) {
                sourceTable.remove();
            }
            return true;
        }
        const last = body.lastElementChild;
        const same = last && last.matches && last.matches('table.docx-table')
            && (last.getAttribute('data-table-index') || '') === (sourceTable.getAttribute('data-table-index') || '');
        // Allow same-index CA / 9.x row pack (fills white bands). Block yanking those
        // tables onto a different host sheet (that dropped Heating Coil… after Cooling).
        if (!same && isNoPullBackTableShow(sourceTable)) {
            return false;
        }
        if (same && rowLabel(row) && last.rows[0] && rowLabel(row) === rowLabel(last.rows[0])) {
            // Only drop true duplicates — never a unique data row.
            const pullIdx = row.getAttribute('data-row-index') || '';
            const hostIdx = last.rows[0].getAttribute('data-row-index') || '';
            if (pullIdx && hostIdx && pullIdx === hostIdx) {
                row.remove();
                return true;
            }
        }
        if (!same && sourceTable.offsetHeight + 6 <= pageFreeSpace(body)) {
            if (isNoPullBackTableShow(sourceTable)) return false;
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
        // Always append at the end of the host table. Prepending after the header
        // reverses Sl.No order across spill→pull cycles (11,10,9…).
        target.appendChild(row);
        if (pageIsOverfull(body) || pageFreeSpace(body) < -4) {
            if (marker) sourceTable.insertBefore(row, marker);
            else sourceTable.appendChild(row);
            if (created) target.remove();
            return false;
        }
        const leftoverHead = sourceTable.rows.length <= hdrN && !sourceTable.querySelector('[name]');
        // Keep leftover data rows — headerless CA continuations used to wipe Heating Coil… here.
        if (!sourceTable.rows.length) {
            sourceTable.remove();
        } else if (leftoverHead && !tableHasRealDataRowsShow(sourceTable)) {
            sourceTable.remove();
        }
        return true;
    }

    function findCompactTableAfter(el) {
        let walk = el;
        let guard = 0;
        while (walk && guard++ < 6) {
            if (walk.matches && walk.matches('table.docx-table')) {
                return isCompactKeepTogetherTable(walk) ? walk : null;
            }
            walk = walk.nextElementSibling;
        }
        return null;
    }

    function pullCompactUnitBack(body, nextBody, first) {
        const table = (first.matches && first.matches('table.docx-table') && isCompactKeepTogetherTable(first))
            ? first
            : findCompactTableAfter(first);
        if (!table) return false;
        const glued = collectBlocksGluedBeforeTable(table);
        const unit = glued.slice();
        unit.push(table);
        if (unit[0] !== first) return false;
        let height = 0;
        unit.forEach(function (el) { height += el.offsetHeight || 0; });
        if (height + 6 > pageFreeSpace(body)) return false;
        unit.forEach(function (el) { body.appendChild(el); });
        if (pageIsOverfull(body)) {
            for (let i = unit.length - 1; i >= 0; i--) {
                nextBody.insertBefore(unit[i], nextBody.firstChild);
            }
            return false;
        }
        return true;
    }

    function headingTextOfQuick(el) {
        if (!el) return '';
        return ((el.dataset && el.dataset.headingText) || el.innerText || el.textContent || '')
            .replace(/\s+/g, ' ')
            .trim()
            .toUpperCase();
    }

    function isRequalChecksHeadingShow(el) {
        return /\b(9\.0\s+REQUALIFICATION\s+CHECKS|9\.1\s+INSTALLATION\s+RE-?QUALIFICATION|9\.2\s+GENERAL\s+CHECKS|GENERAL\s+CHECKS\s*:\s*VERIFICATION\s+OF\s+MAJOR\s+COMPONENTS|9\.3\s+OPERATIONAL\s+QUALIFICATION|OPERATIONAL\s+QUALIFICATION\s+CHECKS\s*:\s*FUNCTIONAL)\b/.test(
            headingTextOfQuick(el)
        );
    }

    function isMajorTailHeadingShow(el) {
        return /\b(DEVIATIONS?\s*\(IF ANY\)|LIST OF ATTACHMENT|SUMMARY\s*&\s*CONCLUSION|REFERENCES|ABBREVIATIONS|REVISION\s+HISTORY|POST-APPROVAL)\b/.test(
            headingTextOfQuick(el)
        );
    }

    /** Never pack across 8↔9 / 9.1↔9.2 / different major-tail boundaries. */
    function shouldBlockRequalPackBoundaryShow(page, next) {
        if (!page || !next) return false;
        const body = page.querySelector('.docx-page-body');
        const nextBody = next.querySelector('.docx-page-body');
        if (!body || !nextBody) return false;
        const first = nextBody.firstElementChild;
        if (!first) return false;
        if (first.getAttribute && first.getAttribute('data-keep-section') === 'requal-91') return true;
        // Always keep 9.0 / 9.1 / 9.2 / 9.3 starts on their own sheets.
        if (isRequalChecksHeadingShow(first)) return true;
        const firstIdx = first.matches && first.matches('table.docx-table')
            ? parseInt(first.getAttribute('data-table-index') || '-1', 10)
            : -1;
        const hasT12 = !!body.querySelector('table.docx-table[data-table-index="12"]');
        const hasT13 = !!body.querySelector('table.docx-table[data-table-index="13"]');
        const hasExec = !!(hasT12 || hasT13
            || body.querySelector('table.docx-table[data-table-index="14"]'));
        if (hasT12 && (firstIdx === 13 || firstIdx === 14)) return true;
        if (hasT13 && firstIdx === 14) return true;
        if (
            !hasExec
            && (firstIdx === 12 || firstIdx === 13 || firstIdx === 14)
        ) {
            return true;
        }
        // Different major-tail sections (Abbreviations ↔ Revision ↔ Post-Approval).
        if (isMajorTailHeadingShow(first)) {
            const hostBlob = ((body.innerText || body.textContent || '')).toUpperCase();
            // Sparse "11.0 LIST OF ATTACHMENT" sheets must pack the next section
            // (or more attachment rows) into the white band — do not hard-block here.
            if (/\bLIST OF ATTACHMENT\b/.test(hostBlob) && !hasExec) {
                return false;
            }
            if (
                hasExec
                || /\b(ABBREVIATIONS|REVISION\s+HISTORY|POST-APPROVAL|REFERENCES|DEVIATIONS?\s*\(IF ANY\)|SUMMARY\s*&\s*CONCLUSION)\b/.test(hostBlob)
            ) {
                return true;
            }
        }
        return false;
    }

    function nextBodyContentHeightShow(nextBody) {
        if (!nextBody) return 0;
        return Array.from(nextBody.children).reduce(function (sum, el) {
            return sum + Math.max(el.offsetHeight || 0, el.scrollHeight || 0);
        }, 0);
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
        if (shouldBlockRequalPackBoundaryShow(page, next)) {
            return false;
        }
        // 2.0 SCOPE block: pull heading + intro + full table together, never row-split.
        if (pullCompactUnitBack(body, nextBody, first)) {
            return true;
        }
        // 11.0 LIST OF ATTACHMENT white band: pull next major-tail section when it fits.
        if (isMajorTailHeadingShow(first)) {
            const hostBlob = ((body.innerText || body.textContent || '')).toUpperCase();
            if (/\bLIST OF ATTACHMENT\b/.test(hostBlob)) {
                const section = [first];
                let walk = first.nextElementSibling;
                while (walk && !isMajorTailHeadingShow(walk) && !isRequalChecksHeadingShow(walk)) {
                    section.push(walk);
                    walk = walk.nextElementSibling;
                }
                const need = section.reduce(function (sum, el) {
                    return sum + Math.max(el.offsetHeight || 0, el.scrollHeight || 0);
                }, 0) + 24;
                if (need > 0 && need <= pageFreeSpace(body)) {
                    let moved = false;
                    for (let si = 0; si < section.length; si++) {
                        if (!pullBlockBack(body, nextBody, section[si])) break;
                        moved = true;
                    }
                    return moved;
                }
                return false;
            }
        }
        // Numbered section (8.3 / 8.4 / …): pull heading + following body until next
        // heading/table-boundary when the whole unit fits (kills sparse 8.2 white bands).
        if (typeof isBodySectionBlock === 'function' && isBodySectionBlock(first) && !isRequalChecksHeadingShow(first)) {
            const unit = [first];
            let walk = first.nextElementSibling;
            while (
                walk
                && !(typeof isBodySectionBlock === 'function' && isBodySectionBlock(walk))
                && !isRequalChecksHeadingShow(walk)
                && !isMajorTailHeadingShow(walk)
            ) {
                unit.push(walk);
                if (walk.matches && walk.matches('table.docx-table')) break;
                walk = walk.nextElementSibling;
            }
            const need = unit.reduce(function (sum, el) {
                return sum + Math.max(el.offsetHeight || 0, el.scrollHeight || 0);
            }, 0) + 16;
            if (need > 0 && need <= pageFreeSpace(body)) {
                let moved = false;
                for (let ui = 0; ui < unit.length; ui++) {
                    if (!pullBlockBack(body, nextBody, unit[ui])) break;
                    moved = true;
                }
                return moved;
            }
            return false;
        }
        if (first.matches('table.docx-table') && !first.classList.contains('header-table')) {
            if (isCompactKeepTogetherTable(first)) return false;
            const tIdx = parseInt(first.getAttribute('data-table-index') || '-1', 10);
            const last = body.lastElementChild;
            const sameHost = last && last.matches && last.matches('table.docx-table')
                && (last.getAttribute('data-table-index') || '') === (first.getAttribute('data-table-index') || '');
            // Connected Accessories / Instrument Name: only continue onto same-index host.
            if (!sameHost && isNoPullBackTableShow(first)) {
                return false;
            }
            // 9.1–9.3: only continue onto a sheet that already hosts the same table
            // (never yank a fresh T12/T13 onto 8.x). Same-index row pack kills 74-page gaps.
            if (!isNaN(tIdx) && tIdx >= 12 && tIdx <= 14 && !sameHost) {
                if (first.offsetHeight + 12 <= pageFreeSpace(body)) {
                    return pullBlockBack(body, nextBody, first);
                }
                return false;
            }
            // Whole short table fits — pull as one block (DETAILS / PM / SOP fragment).
            if (!sameHost && first.offsetHeight + 12 <= pageFreeSpace(body)) {
                return pullBlockBack(body, nextBody, first);
            }
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
        // Original Word order: PRE-APPROVAL → EFFECTIVE DATE → (page break) → TOC → 1.0…
        // Never move blocks that sit BEFORE the TOC onto a later sheet (that put
        // TOC above pre-approval after 1st-approval collapse/reflow).
        Array.from(wrap.querySelectorAll('.docx-preview .docx-a4-page')).forEach(function (page) {
            if (!pageHasTocCard(page)) return;
            const body = page.querySelector('.docx-page-body');
            if (!body) return;
            const kids = Array.from(body.children);
            let tocIdx = -1;
            for (let i = 0; i < kids.length; i++) {
                const el = kids[i];
                if (el.classList && el.classList.contains('docx-toc-card')) {
                    tocIdx = i;
                    break;
                }
                if (el.querySelector && el.querySelector('.docx-toc-line, .docx-toc-card')) {
                    tocIdx = i;
                    break;
                }
            }
            if (tocIdx < 0) return;

            const before = kids.slice(0, tocIdx);
            const tocAndAfter = kids.slice(tocIdx);
            // Only post-TOC body (1.0 OBJECTIVE…) must leave a TOC sheet.
            const afterOnly = tocAndAfter.slice(1).filter(function (el) {
                if (el.classList && el.classList.contains('docx-toc-card')) return false;
                if (el.querySelector && el.querySelector('.docx-toc-line, .docx-toc-card')) return false;
                return true;
            });

            if (before.length && tocAndAfter.length) {
                // Keep pre-approval on this sheet; move TOC (+ trailing) to the next.
                const next = followingPage(page) || continuationPage(page);
                const nextBody = next.querySelector('.docx-page-body');
                for (let i = tocAndAfter.length - 1; i >= 0; i--) {
                    nextBody.insertBefore(tocAndAfter[i], nextBody.firstChild);
                }
                return;
            }

            if (!afterOnly.length) return;
            const next = followingPage(page) || continuationPage(page);
            const nextBody = next.querySelector('.docx-page-body');
            const frag = document.createDocumentFragment();
            afterOnly.forEach(function (el) { frag.appendChild(el); });
            nextBody.insertBefore(frag, nextBody.firstChild);
        });
    }

    function fillPageGaps() {
        // Dense pack toward original Word length (~25 pages), not 70+ sparse sheets.
        // Stay on the same page while it still has free space so DETAILS / SOP
        // continuation rows keep filling the white band.
        let guard = 0;
        while (guard++ < 15) {
            let pulls = 0;
            let page = wrap.querySelector('.docx-preview .docx-a4-page');
            while (page && pulls < 500) {
                const next = followingPage(page);
                if (!next) break;
                const body = page.querySelector('.docx-page-body');
                const free = pageFreeSpace(body);
                if (free < 40 || !pullOneBack(page)) {
                    page = next;
                    continue;
                }
                pulls++;
                dropEmptySheet(followingPage(page) || next);
                // Keep packing this sheet until it is reasonably full.
            }
            if (!pulls) break;
        }
    }

    /**
     * Collapse sparse A4 sheets: whole next page if it fits, else row/block pulls.
     * Target original Word density (~20–30 pages), not 70+ half-empty sheets.
     */
    function packSparsePagesShow() {
        let guard = 0;
        while (guard++ < 12) {
            let moved = 0;
            const pages = Array.from(wrap.querySelectorAll('.docx-preview .docx-a4-page'));
            for (let i = 0; i < pages.length; i++) {
                const page = pages[i];
                if (!document.body.contains(page)) continue;
                const body = page.querySelector('.docx-page-body');
                if (!body || pageFreeSpace(body) < 56) continue;
                const next = followingPage(page);
                if (!next || shouldBlockRequalPackBoundaryShow(page, next)) continue;
                const nextBody = next.querySelector('.docx-page-body');
                if (!nextBody || !nextBody.firstElementChild) continue;
                const need = Math.max(nextBodyContentHeightShow(nextBody), nextBody.scrollHeight || 0) + 24;
                const free = pageFreeSpace(body);
                if (need > 0 && need <= free) {
                    const movedNodes = [];
                    let local = 0;
                    while (nextBody.firstElementChild && local++ < 60) {
                        const el = nextBody.firstElementChild;
                        if (!pullBlockBack(body, nextBody, el)) break;
                        movedNodes.push(el);
                        moved++;
                    }
                    if (pageIsOverfull(body)) {
                        for (let ri = movedNodes.length - 1; ri >= 0; ri--) {
                            nextBody.insertBefore(movedNodes[ri], nextBody.firstChild);
                        }
                    } else {
                        dropEmptySheet(next);
                    }
                    continue;
                }
                let local = 0;
                while (local++ < 50 && pageFreeSpace(body) >= 40 && pullOneBack(page)) {
                    moved++;
                    const nxt = followingPage(page);
                    if (nxt) dropEmptySheet(nxt);
                }
            }
            if (!moved) break;
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

    function findBodyHeadingShow(rx) {
        const nodes = wrap.querySelectorAll(
            '.docx-preview .docx-page-body > p.docx-para, .docx-preview .docx-page-body > p.docx-heading-target, .docx-preview .docx-page-body > .docx-para-edit-wrapper, .docx-preview .docx-page-body > [data-heading-text]'
        );
        for (let i = 0; i < nodes.length; i++) {
            const el = nodes[i];
            if (!document.body.contains(el)) continue;
            const text = headingTextOf(el).toUpperCase();
            if (rx.test(text)) return el;
        }
        return null;
    }

    /**
     * Spill glue used to reverse 8.0 / 8.1 (and similar parent/child headings).
     * Force Word order: 8.0 PRE-QUALIFICATION above 8.1 Calibrations.
     */
    function repairSectionHeadingOrderShow() {
        const pairs = [
            [/\b8\.0\s*PRE-?QUALIFICATION\b/, /\b8\.1\b.*\bCALIBRAT|\b8\.1\s*CALIBRATIONS?\s+OF\s+INSTRUMENTS\b/],
            [/\b8\.0\s*PRE-?QUALIFICATION\b/, /\b8\.1\b/],
            [/\b9\.0\s+REQUALIFICATION\s+CHECKS\b/, /\b9\.1\s+INSTALLATION\b/],
        ];
        pairs.forEach(function (pair) {
            const parent = findBodyHeadingShow(pair[0]);
            const child = findBodyHeadingShow(pair[1]);
            if (!parent || !child || parent === child) return;
            if (!(child.compareDocumentPosition(parent) & Node.DOCUMENT_POSITION_FOLLOWING)) return;
            const host = child.parentNode;
            if (!host) return;
            host.insertBefore(parent, child);
        });
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
        if (wrap.getAttribute('data-docx-paginating') === '1') return;
        const first = wrap.querySelector('.docx-preview .docx-a4-page');
        if (!first) return;
        const firstBody = first.querySelector('.docx-page-body');
        if (!firstBody || firstBody.clientHeight < 80) return;

        wrap.setAttribute('data-docx-paginating', '1');
        try {

        // Snapshot every table data row BEFORE collapse/spill so we can restore
        // any row that pack/drop accidentally removes (8.1 / 9.2 / 9.3 / CA).
        const rowInventory = captureTableRowInventoryShow();
        window.__docxShowRowInventory = rowInventory;

        // Flatten rowspan before any row spill — otherwise Sr.No / Identification
        // stay on the previous sheet and Acceptance criteria shifts left.
        if (!wrap.getAttribute('data-docx-rowspan-expanded')) {
            expandAllPreviewRowspans();
            wrap.setAttribute('data-docx-rowspan-expanded', '1');
        }

        // One-time: flatten Python hard breaks into one flow, then soft-spill.
        if (!wrap.getAttribute('data-docx-flow-collapsed')) {
            collapseHardPageBreaks();
            wrap.setAttribute('data-docx-flow-collapsed', '1');
        }

        moveBodyOffTocPages();
        mergeSamePageTableFragmentsShow();

        let moves = 0;
        const maxMoves = 1200;
        // Keep spilling until every sheet fits (attachments + sections 12–16 become their own pages).
        for (let pass = 0; pass < 8 && moves < maxMoves; pass++) {
            let page = wrap.querySelector('.docx-preview .docx-a4-page');
            let spilledThisPass = 0;
            let stuckGuard = 0;
            while (page && moves < maxMoves && stuckGuard < 500) {
                const pageBody = page.querySelector('.docx-page-body');
                if (!contentOverflows(pageBody) && !pageIsOverfull(pageBody)) {
                    page = followingPage(page);
                    stuckGuard = 0;
                    continue;
                }
                const before = pageBody.scrollHeight;
                const beforeKids = pageBody.childElementCount;
                const beforeLast = pageBody.lastElementChild;
                const beforeRow = beforeLast && beforeLast.rows
                    ? beforeLast.rows[beforeLast.rows.length - 1]
                    : null;
                if (!spillOne(page)) {
                    // Prefer row-spill for tall tables — yanking the whole table left
                    // prior sheets with one orphan row (Page 5 of 74 white bands).
                    if (
                        beforeLast
                        && beforeLast.matches
                        && beforeLast.matches('table.docx-table')
                        && beforeLast.rows.length > 2
                    ) {
                        const row = beforeLast.rows[beforeLast.rows.length - 1];
                        const cont = continuationTable(page, beforeLast);
                        const header = lastHeaderRow(cont);
                        if (header && header !== row) header.after(row);
                        else if (cont.rows[0]) cont.rows[0].parentNode.insertBefore(row, cont.rows[0]);
                        else cont.appendChild(row);
                        moves++;
                        spilledThisPass++;
                        stuckGuard++;
                        continue;
                    }
                    if (
                        beforeLast
                        && pageBody.childElementCount > 1
                        && !(beforeLast.matches && beforeLast.matches('table.docx-table') && beforeLast.rows.length > 1)
                    ) {
                        const next = followingPage(page) || continuationPage(page);
                        const nextBody = next.querySelector('.docx-page-body');
                        nextBody.insertBefore(beforeLast, nextBody.firstChild);
                        moves++;
                        spilledThisPass++;
                        stuckGuard++;
                        continue;
                    }
                    page = followingPage(page);
                    stuckGuard = 0;
                    continue;
                }
                moves++;
                spilledThisPass++;
                stuckGuard++;
                const after = pageBody.scrollHeight;
                const afterKids = pageBody.childElementCount;
                const progressed = after < before - 2 || afterKids < beforeKids;
                const rowStillHere = beforeRow && pageBody.contains(beforeRow);
                // No progress or same row still stuck → advance to avoid freeze loops.
                if (!progressed || (rowStillHere && after >= before)) {
                    page = followingPage(page);
                    stuckGuard = 0;
                }
            }
            if (!spilledThisPass) break;
        }

        // Pack sparse sheets hard — original Word is ~25 pages, not 70+.
        fillPageGaps();
        packSparsePagesShow();
        fillPageGaps();
        packSparsePagesShow();
        mergeSamePageTableFragmentsShow();
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
        // Light final spill only for truly overfull sheets (pageIsOverfull).
        // Avoid contentOverflows mid-row false positives that re-split to 70+ pages.
        (function finalRespillReadonly() {
            let n = 0;
            let p = wrap.querySelector('.docx-preview .docx-a4-page');
            while (p && n < 400) {
                const pb = p.querySelector('.docx-page-body');
                if (pb && pageIsOverfull(pb)) {
                    if (spillOne(p)) {
                        n++;
                        p = wrap.querySelector('.docx-preview .docx-a4-page');
                        continue;
                    }
                }
                p = followingPage(p);
            }
        })();
        // Pack again after the light spill so new continuations fold back.
        fillPageGaps();
        packSparsePagesShow();

        // Put back any data rows lost during pack/drop, then merge SOP splits.
        const restored = restoreMissingTableRowsShow(rowInventory);
        mergeSamePageTableFragmentsShow();
        if (restored > 0) {
            restoreMissingTableRowsShow(rowInventory);
            fillPageGaps();
            packSparsePagesShow();
            let n2 = 0;
            let p2 = wrap.querySelector('.docx-preview .docx-a4-page');
            while (p2 && n2 < 200) {
                const pb = p2.querySelector('.docx-page-body');
                if (pb && pageIsOverfull(pb) && spillOne(p2)) {
                    n2++;
                    p2 = wrap.querySelector('.docx-preview .docx-a4-page');
                    continue;
                }
                p2 = followingPage(p2);
            }
            fillPageGaps();
        }

        // Fix swapped parent/child headings (8.0 above 8.1, not 8.1 then 8.0).
        repairSectionHeadingOrderShow();

        // Restore Word row order if spill/merge reversed any table (DETAILS etc.).
        wrap.querySelectorAll('.docx-preview table.docx-table:not(.header-table)').forEach(function (table) {
            if (!table.rows || table.rows.length < 3) return;
            const hdrN = leadingHeaderRowCount(table);
            const dataRows = Array.from(table.rows).slice(hdrN);
            const indexed = dataRows.filter(function (r) {
                const n = parseInt(r.getAttribute('data-row-index') || '', 10);
                return !isNaN(n) && n >= 0;
            });
            if (indexed.length < 2) return;
            const sorted = indexed.slice().sort(function (a, b) {
                return parseInt(a.getAttribute('data-row-index'), 10) - parseInt(b.getAttribute('data-row-index'), 10);
            });
            let ok = true;
            for (let i = 0; i < indexed.length; i++) {
                if (indexed[i] !== sorted[i]) { ok = false; break; }
            }
            if (ok) return;
            const parent = (sorted[0] && sorted[0].parentNode) || table.tBodies[0] || table;
            sorted.forEach(function (r) { parent.appendChild(r); });
        });
        updatePageNumbers();
        syncReadonlyTocPageNumbers();
        void wrap.offsetHeight;
        updatePageNumbers();
        syncReadonlyTocPageNumbers();
        wrap.setAttribute('data-docx-paginate-done', '1');
        } finally {
            wrap.removeAttribute('data-docx-paginating');
        }
    };

    window.paginateReadonlyPreview();
    // Follow-up: only densify sparse sheets / spill truly overfull ones.
    // Do NOT full re-paginate when already at 40+ pages (that recreated 74 gaps).
    setTimeout(function () {
        const pages = wrap.querySelectorAll('.docx-preview .docx-a4-page');
        if (!pages.length) return;
        let overfull = false;
        for (let i = 0; i < pages.length; i++) {
            const b = pages[i].querySelector('.docx-page-body');
            if (b && pageIsOverfull(b)) {
                overfull = true;
                break;
            }
        }
        if (pages.length > 40) {
            // Too many sparse sheets — pack only, no second full collapse cycle.
            fillPageGaps();
            packSparsePagesShow();
            fillPageGaps();
            if (window.__docxShowRowInventory) {
                restoreMissingTableRowsShow(window.__docxShowRowInventory);
                mergeSamePageTableFragmentsShow();
            }
            repairSectionHeadingOrderShow();
            Array.from(wrap.querySelectorAll('.docx-preview .docx-a4-page')).forEach(function (sheet) {
                if (document.body.contains(sheet)) dropEmptySheet(sheet);
            });
            updatePageNumbers();
            syncReadonlyTocPageNumbers();
            return;
        }
        if (!overfull && wrap.getAttribute('data-docx-paginate-done') === '1') {
            // Still fold obvious white bands once more — then restore any CA / 9.x rows pack dropped.
            fillPageGaps();
            packSparsePagesShow();
            if (window.__docxShowRowInventory) {
                restoreMissingTableRowsShow(window.__docxShowRowInventory);
                mergeSamePageTableFragmentsShow();
            }
            repairSectionHeadingOrderShow();
            updatePageNumbers();
            syncReadonlyTocPageNumbers();
            return;
        }
        if (overfull) {
            wrap.removeAttribute('data-docx-paginate-done');
            window.paginateReadonlyPreview();
        }
    }, 700);
});
</script>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH D:\lupin\latest Lupin2\valiflow-main\resources\views/reports/show.blade.php ENDPATH**/ ?>