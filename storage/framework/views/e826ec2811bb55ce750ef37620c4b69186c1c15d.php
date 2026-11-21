<?php $__env->startSection('title', $protocolListTitle ?? (request()->routeIs('protocol') ? 'All Protocol' : 'Pre Approved Protocol List')); ?>

<?php $__env->startSection('content'); ?>
<div class="container-fluid">

    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <?php echo e($protocolListTitle ?? (request()->routeIs('protocol') ? 'All Protocol' : 'Pre Approved Protocol')); ?>

        </h1>
        <div class="d-flex gap-2">
            <?php if(!empty($protocolImportSource ?? null)): ?>
                <a href="<?php echo e(route('protocol.docx-import.create', ['source' => $protocolImportSource])); ?>" class="btn btn-sm btn-primary shadow-sm mr-2">
                    <i class="fas fa-file-import fa-sm text-white-50"></i> Import
                </a>
            <?php endif; ?>
            <a href="<?php echo e(route('home')); ?>" class="d-none d-sm-inline-block btn btn-sm btn-secondary shadow-sm">
                <i class="fas fa-arrow-left fa-sm text-white-50"></i> Back
            </a>
        </div>
    </div>

    <?php echo $__env->make('common.alert', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>

    <div class="card shadow col-sm-12">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Protocol List</h6>
        </div>

        <div class="card-body">

            
            <form method="GET" action="<?php echo e(request()->url()); ?>" class="mb-3">
                <div class="row g-2 align-items-end">

                    <div class="col-md-3">
                        <label class="small text-muted">Search</label>
                        <input type="text"
                               name="search"
                               value="<?php echo e(request('search')); ?>"
                               class="form-control"
                               placeholder="Title">
                    </div>

                    <div class="col-md-2">
                        <label class="small text-muted">Status</label>
                        <select name="status" class="form-control">
                            <option value="">All</option>
                            <?php if(\App\Services\SidebarPermissionService::userCanAny(['protocol-view-approved', 'protocol-approved'])): ?>
                            <option value="approved" <?php echo e(request('status')=='approved'?'selected':''); ?>>Approved</option>
                            <?php endif; ?>
                            <?php if(\App\Services\SidebarPermissionService::userCanAny(['protocol-view-rejected', 'protocol-reject'])): ?>
                            <option value="rejected" <?php echo e(request('status')=='rejected'?'selected':''); ?>>Reject</option>
                            <?php endif; ?>
                            <?php if(\App\Services\SidebarPermissionService::userCanAny(['protocol-view-returned', 'protocol-send-back'])): ?>
                            <option value="send_back" <?php echo e(request('status')=='send_back'?'selected':''); ?>>Send Back</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="small text-muted">From Date</label>
                        <input type="date"
                               name="from_date"
                               value="<?php echo e(request('from_date')); ?>"
                               class="form-control">
                    </div>

                    <div class="col-md-2">
                        <label class="small text-muted">To Date</label>
                        <input type="date"
                               name="to_date"
                               value="<?php echo e(request('to_date')); ?>"
                               class="form-control">
                    </div>

                    <div class="col-md-3 d-flex gap-2">
                        <button class="btn btn-primary w-50" type="submit">Filter</button>
                        <a class="btn btn-secondary w-50" href="<?php echo e(request()->url()); ?>">Reset</a>
                    </div>

                </div>
            </form>

            
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
                    <?php $__empty_1 = true; $__currentLoopData = $approvedReports; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>

                        <?php
                            $status = strtoupper($r->status);

                            $badgeClass = match($r->status) {
                                'post_setup'   => 'badge bg-warning text-dark',
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
                        ?>

                        <tr>
                            <td><?php echo e($r->displayCode()); ?></td>
                            <td><?php echo e($protocolTitle !== '' ? $protocolTitle : '-'); ?></td>
                            <td><?php echo e($equipmentName !== '' ? $equipmentName : '-'); ?></td>
                            <td><?php echo e($equipmentId !== '' ? $equipmentId : '-'); ?></td>
                            <td><?php echo e(optional($r->created_at)->timezone('Asia/Kolkata')->format('d-m-Y H:i')); ?></td>

                            <td>
                                <?php if($docxNeedsFill && $r->docx_import_status === 'pending'): ?>
                                    <span class="badge bg-warning text-dark">FILL PENDING</span>
                                <?php elseif($isPermanentlyRejected): ?>
                                    <span class="badge bg-danger text-white">REJECTED</span>
                                <?php elseif($docxNeedsFill && $isReturnedForCorrection): ?>
                                    <span class="badge bg-warning text-dark">SENT BACK — EDIT REQUIRED</span>
                                <?php else: ?>
                                    <span class="<?php echo e($badgeClass); ?>">
                                        <?php echo e($status); ?>

                                    </span>
                                    <?php if($isDocxImport): ?>
                                        <span class="badge bg-secondary text-light ml-1">DOCX</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>

                            <td class="text-end">

                                
                                <?php if($docxNeedsFill && $isCreator): ?>
                                    <?php
                                        $requalPreGate = in_array(($r->import_source ?? ''), ['requalification', 'revalidation'], true)
                                            && in_array($r->status, ['pre_setup', 'pre_pending', 'pre_returned'], true)
                                            && $r->stage !== 'post';
                                    ?>
                                    <?php if($r->status === 'pre_pending'): ?>
                                        <a href="<?php echo e(route('reports.show', $r)); ?>" class="btn btn-sm btn-secondary">
                                            Pre Approval In Progress
                                        </a>
                                    <?php elseif($r->status === 'post_pending'): ?>
                                        <a href="<?php echo e(route('reports.show', $r)); ?>" class="btn btn-sm btn-info text-white">
                                            Post Approval In Progress
                                        </a>
                                    <?php elseif($requalPreGate && $r->status === 'pre_setup'): ?>
                                        <a href="<?php echo e(route('reports.docx-import.fill-form', $r)); ?>" class="btn btn-sm btn-primary">
                                            Edit Protocol &amp; Send for Pre-Approval
                                        </a>
                                    <?php elseif($isReturnedForCorrection): ?>
                                        <a href="<?php echo e(route('reports.docx-import.fill-form', $r)); ?>" class="btn btn-sm btn-primary">
                                            Edit &amp; Resubmit
                                        </a>
                                    <?php elseif($r->status === 'pre_approved'): ?>
                                        <a href="<?php echo e(route('reports.docx-import.fill-form', $r)); ?>" class="btn btn-sm btn-primary">
                                            Fill Execution &amp; Submit
                                        </a>
                                    <?php elseif(($r->import_source ?? '') === 'new_equipment_qualification'
                                        && ($r->status === 'post_setup' || ($r->docx_import_status === 'pending' && $r->stage === 'post'))): ?>
                                        <a href="<?php echo e(route('reports.docx-import.fill-form', $r)); ?>" class="btn btn-sm btn-primary">
                                            Fill &amp; Submit for Approval
                                        </a>
                                    <?php else: ?>
                                        <a href="<?php echo e(route('reports.show', $r)); ?>" class="btn btn-sm btn-secondary">
                                            View Report
                                        </a>
                                    <?php endif; ?>

                                
                                <?php elseif($isCurrentApprover && !$docxNeedsFill && \App\Services\SidebarPermissionService::userCan('protocol-approve')): ?>
                                    <a href="<?php echo e(route('reports.show', $r->id)); ?>" class="btn btn-sm btn-warning">
                                        Approve Now
                                    </a>

                                <?php elseif($isCurrentApprover && !$docxNeedsFill): ?>
                                    <a href="<?php echo e(route('reports.show', $r->id)); ?>" class="btn btn-sm btn-info text-white">
                                        <?php if($r->status === 'pre_pending'): ?>
                                            Pending for Pre Approval
                                        <?php else: ?>
                                            Open
                                        <?php endif; ?>
                                    </a>

                                
                                <?php elseif($isPermanentlyRejected): ?>
                                    <a href="<?php echo e(route('reports.show', $r->id)); ?>" class="btn btn-sm btn-secondary">
                                        View
                                    </a>

                                
                                <?php elseif($r->status === 'approved'): ?>
                                    <a href="<?php echo e(route('reports.show', $r->id)); ?>" class="btn btn-sm btn-success">
                                        View
                                    </a>

                                
                                <?php elseif($isReturnedForCorrection && $isCreator && !$isDocxImport): ?>
                                    <a href="<?php echo e(route('reports.show', $r->id)); ?>" class="btn btn-sm btn-danger">
                                        View / Resubmit
                                    </a>

                                
                                <?php elseif($r->status === 'pre_approved' && $isCreator && !$isDocxImport): ?>
                                    <a href="<?php echo e(route('reports.editAfterPreApproved', $r->id)); ?>" class="btn btn-sm btn-primary">
                                        Fill Post Details
                                    </a>

                                
                                <?php elseif($r->status === 'post_pending' && $isDocxImport): ?>
                                    <a href="<?php echo e(route('reports.show', $r->id)); ?>" class="btn btn-sm btn-info text-white">
                                        View
                                    </a>

                                <?php elseif($r->status === 'pre_pending'): ?>
                                    <a href="<?php echo e(route('reports.show', $r->id)); ?>" class="btn btn-sm btn-warning">
                                        Pending for Pre Approval
                                    </a>

                                <?php else: ?>
                                    <a href="<?php echo e(route('reports.show', $r->id)); ?>" class="btn btn-sm btn-secondary">
                                        Open
                                    </a>
                                <?php endif; ?>

                            </td>
                        </tr>

                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                No records found.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mt-3 gap-2">
                <div class="text-muted small">
                    Showing <?php echo e($approvedReports->firstItem() ?? 0); ?>

                    to <?php echo e($approvedReports->lastItem() ?? 0); ?>

                    of <?php echo e($approvedReports->total()); ?> protocols
                </div>
                <div>
                    <?php echo e($approvedReports->onEachSide(1)->links()); ?>

                </div>
            </div>

        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH D:\lupin\latest Lupin2\valiflow-main\resources\views/reports/pre_approved.blade.php ENDPATH**/ ?>